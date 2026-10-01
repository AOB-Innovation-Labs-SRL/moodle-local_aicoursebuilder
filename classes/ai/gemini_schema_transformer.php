<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_aicoursebuilder\ai;

/**
 * Turns a schema/ document (plugin-style $ref, full JSON Schema 2020-12) into the self-contained,
 * narrower subset documented for Gemini's responseSchema (spec 3.3).
 *
 * Confirmed from https://ai.google.dev/api/generate-content and
 * https://ai.google.dev/gemini-api/docs/structured-output: responseSchema accepts only the types
 * string/number/integer/boolean/object/array/null and the keywords properties, required,
 * additionalProperties, enum, format, minimum, maximum, items, prefixItems, minItems, maxItems,
 * title, description. $ref, $defs, const, pattern, allOf, oneOf, anyOf, if/then, uniqueItems and
 * exclusiveMinimum are not in that documented inventory. This class therefore (1) inlines every
 * $ref by reading the referenced schema/ file directly (the request already carries the schema as
 * rewritten by \local_aicoursebuilder\blueprint\schema_store: a cross-document $ref becomes
 * "aicb:///{id}#/...", a same-document one stays a bare "#/...", which is why transform() takes the
 * step name: to know which document id a bare $ref belongs to (the only coupling to
 * \local_aicoursebuilder\blueprint\schema_store::STEP_SCHEMAS), and to seed that id with the
 * already-rewritten $schema itself rather than re-reading schema/{id}.json, whose own $ref values
 * are still in the original, unrewritten form) and (2) drops every unsupported
 * keyword, rewriting the one case that has a lossless equivalent in the supported subset: const: X
 * becomes enum: [X]. The result is a generation hint only; our own validator still checks every AI
 * answer against the full schema, so dropping keywords here never weakens validation.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_schema_transformer {
    /** @var string Prefix a cross-document $ref was rewritten to by schema_store. */
    public const URI_PREFIX = 'aicb:///';

    /** @var string[] Keywords whose child schemas need resolving (properties, items and the like). */
    public const KEEP_STRUCTURAL = ['properties', 'additionalProperties', 'items', 'prefixItems'];

    /** @var string[] Keywords kept as-is: scalars, and required, whose value is a plain list of names. */
    public const KEEP_SCALAR = ['type', 'enum', 'format', 'minimum', 'maximum', 'minItems', 'maxItems',
        'title', 'description', 'required'];

    /** @var int Maximum $ref resolution depth, guarding against an unexpected cycle. */
    public const MAX_DEPTH = 50;

    /** @var string Directory holding the schema/ files. */
    protected string $schemadir;

    /** @var array<string, mixed> Decoded schema/ documents, keyed by id (without the URI prefix). */
    protected array $documents = [];

    /**
     * Creates the transformer.
     *
     * @param string|null $schemadir Directory with the schema/ files, null for the plugin's schema/.
     */
    public function __construct(?string $schemadir = null) {
        $this->schemadir = $schemadir ?? dirname(__DIR__, 2) . '/schema';
    }

    /**
     * Transforms a schema into Gemini's responseSchema subset.
     *
     * @param array $schema The schema, as given on a request (plugin-style $ref already rewritten
     *                       to aicb:/// by schema_store, or a bare schema with no $ref at all).
     * @param string|null $step Pipeline step the schema belongs to, one of request::STEP_*, used to
     *                           resolve a bare "#/..." $ref back to its own document (schema_store
     *                           only rewrites a cross-document $ref; a step schema's own $ref to its
     *                           own $defs stays bare); null when the schema has none of those.
     * @return array
     */
    public function transform(array $schema, ?string $step = null): array {
        $indocument = $step !== null ? (\local_aicoursebuilder\blueprint\schema_store::STEP_SCHEMAS[$step] ?? null) : null;
        if ($indocument !== null) {
            // A bare "#/..." $ref inside $schema, or inside anything it points at that belongs to
            // the same document, must resolve against this already-rewritten copy, not against the
            // schema/ file on disk (which still has the original plugin-style $ref prefixes).
            $this->documents[$indocument] = $schema;
        }
        $resolved = $this->resolve($schema, $indocument, 0);
        return is_array($resolved) ? $resolved : [];
    }

    /**
     * Resolves $refs and strips unsupported keywords, recursively.
     *
     * @param mixed $node Node of the schema being walked.
     * @param string|null $indocument Id of the document $node belongs to, for a same-document $ref;
     *                                null while walking the top-level request schema.
     * @param int $depth Current $ref resolution depth.
     * @return mixed
     * @throws \coding_exception When $ref resolution exceeds MAX_DEPTH (a likely cycle) or a
     *                            referenced document or pointer cannot be found.
     */
    protected function resolve(mixed $node, ?string $indocument, int $depth): mixed {
        if (!is_array($node)) {
            return $node;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            if ($depth >= self::MAX_DEPTH) {
                throw new \coding_exception('Schema $ref resolution exceeded the maximum depth, likely a cycle');
            }
            [$target, $targetdocument] = $this->follow_ref($node['$ref'], $indocument);
            return $this->resolve($target, $targetdocument, $depth + 1);
        }

        $out = [];
        foreach ($node as $key => $value) {
            if ($key === 'const') {
                $out['enum'] = [$value];
                continue;
            }
            if (!in_array($key, self::KEEP_STRUCTURAL, true) && !in_array($key, self::KEEP_SCALAR, true)) {
                continue;
            }
            $out[$key] = in_array($key, self::KEEP_STRUCTURAL, true)
                ? $this->resolve_structural($key, $value, $indocument, $depth)
                : $value;
        }
        return $out;
    }

    /**
     * Resolves the child schemas of a structural keyword (properties, items and the like).
     *
     * @param string $key The keyword.
     * @param mixed $value Its raw value.
     * @param string|null $indocument Id of the document the parent node belongs to.
     * @param int $depth Current $ref resolution depth.
     * @return mixed
     */
    protected function resolve_structural(string $key, mixed $value, ?string $indocument, int $depth): mixed {
        if ($key === 'additionalProperties' && is_bool($value)) {
            return $value;
        }
        if ($key === 'properties' && is_array($value)) {
            $out = [];
            foreach ($value as $name => $propschema) {
                $out[$name] = $this->resolve($propschema, $indocument, $depth);
            }
            return $out;
        }
        if ($key === 'prefixItems' && is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->resolve($item, $indocument, $depth), $value);
        }
        return $this->resolve($value, $indocument, $depth);
    }

    /**
     * Follows one $ref to the schema node it points at.
     *
     * @param string $ref The $ref value: "aicb:///{id}#/{pointer}" for another document, or
     *                     "#/{pointer}" for the document $indocument belongs to.
     * @param string|null $indocument Id of the document the $ref was found in, null at the top level.
     * @return array{0: array, 1: string} The target node (still unresolved, the caller recurses into
     *                                     it) and the id of the document it belongs to, so any
     *                                     same-document $ref inside it resolves against that document
     *                                     rather than $indocument.
     * @throws \coding_exception When the $ref cannot be parsed or resolved.
     */
    protected function follow_ref(string $ref, ?string $indocument): array {
        if (str_starts_with($ref, self::URI_PREFIX)) {
            [$id, $pointer] = $this->split_ref(substr($ref, strlen(self::URI_PREFIX)));
        } else if (str_starts_with($ref, '#/')) {
            if ($indocument === null) {
                throw new \coding_exception("Same-document \$ref '{$ref}' outside of any document");
            }
            $id = $indocument;
            $pointer = substr($ref, 2);
        } else {
            throw new \coding_exception("Unsupported \$ref form: {$ref}");
        }

        $node = $this->document($id);
        foreach (explode('/', $pointer) as $segment) {
            if ($segment === '') {
                continue;
            }
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                throw new \coding_exception("Cannot resolve \$ref '{$ref}': missing segment '{$segment}'");
            }
            $node = $node[$segment];
        }
        if (!is_array($node)) {
            throw new \coding_exception("\$ref '{$ref}' does not point at a schema object");
        }
        return [$node, $id];
    }

    /**
     * Splits an "{id}#/{pointer}" string into its id and pointer.
     *
     * @param string $value The string, without its URI prefix.
     * @return array{0: string, 1: string}
     * @throws \coding_exception When the string has no '#'.
     */
    protected function split_ref(string $value): array {
        $hash = strpos($value, '#');
        if ($hash === false) {
            throw new \coding_exception("\$ref '{$value}' has no JSON pointer");
        }
        return [substr($value, 0, $hash), ltrim(substr($value, $hash + 1), '/')];
    }

    /**
     * Returns a schema/ document as a decoded associative array, loaded once per id.
     *
     * @param string $id Schema id, such as "blueprint.v1".
     * @return array
     * @throws \coding_exception When the file is missing or is not valid JSON.
     */
    protected function document(string $id): array {
        if (isset($this->documents[$id])) {
            return $this->documents[$id];
        }
        $path = $this->schemadir . '/' . $id . '.json';
        if (!is_readable($path)) {
            throw new \coding_exception("Missing JSON schema file: {$path}");
        }
        try {
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \coding_exception("Invalid JSON in schema file {$path}: " . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new \coding_exception("Schema file {$path} does not hold a JSON object");
        }
        return $this->documents[$id] = $decoded;
    }
}
