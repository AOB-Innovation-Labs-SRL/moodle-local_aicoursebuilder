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
 * Narrows a self-contained schema (the output of schema_bundler) to the subset documented for
 * Gemini's responseSchema (spec 3.3).
 *
 * Confirmed from https://ai.google.dev/api/generate-content and
 * https://ai.google.dev/gemini-api/docs/structured-output: responseSchema accepts only the types
 * string/number/integer/boolean/object/array/null and the keywords properties, required,
 * additionalProperties, enum, format, minimum, maximum, items, prefixItems, minItems, maxItems,
 * title, description. $ref, $defs, const, pattern, allOf, oneOf, anyOf, if/then, uniqueItems and
 * exclusiveMinimum are not in that documented inventory. This class therefore (1) bundles the schema
 * with schema_bundler, which is where every cross-document $ref is resolved, (2) inlines the local
 * "#/$defs/{name}" $refs the bundle is left with, and (3) drops every unsupported keyword, rewriting
 * the one case that has a lossless equivalent in the supported subset: const: X becomes enum: [X].
 * The result is a generation hint only; our own validator still checks every AI answer against the
 * full schema, so dropping keywords here never weakens validation.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_schema_transformer {
    /** @var string Prefix of a local $ref into the $defs of the bundle. */
    protected const DEFS_PREFIX = '#/$defs/';

    /** @var string[] Keywords whose child schemas need resolving (properties, items and the like). */
    public const KEEP_STRUCTURAL = ['properties', 'additionalProperties', 'items', 'prefixItems'];

    /** @var string[] Keywords kept as-is: scalars, and required, whose value is a plain list of names. */
    public const KEEP_SCALAR = ['type', 'enum', 'format', 'minimum', 'maximum', 'minItems', 'maxItems',
        'title', 'description', 'required'];

    /** @var int Maximum $ref resolution depth, guarding against an unexpected cycle. */
    public const MAX_DEPTH = 50;

    /** @var schema_bundler Resolves the cross-document $refs. */
    protected schema_bundler $bundler;

    /** @var array<string, mixed> The $defs of the bundle being transformed. */
    protected array $defs = [];

    /**
     * Creates the transformer.
     *
     * @param string|null $schemadir Directory with the schema/ files, null for the plugin's schema/.
     */
    public function __construct(?string $schemadir = null) {
        $this->bundler = new schema_bundler($schemadir);
    }

    /**
     * Transforms a schema into Gemini's responseSchema subset.
     *
     * @param array $schema The schema, as given on a request (plugin-style $ref already rewritten
     *                       to aicb:/// by schema_store, or a bare schema with no $ref at all).
     * @return array
     */
    public function transform(array $schema): array {
        $bundle = $this->bundler->bundle($schema);
        $this->defs = $bundle['$defs'] ?? [];
        unset($bundle['$defs']);
        $resolved = $this->resolve($bundle, 0);
        return is_array($resolved) ? $resolved : [];
    }

    /**
     * Resolves $refs and strips unsupported keywords, recursively.
     *
     * @param mixed $node Node of the schema being walked.
     * @param int $depth Current $ref resolution depth.
     * @return mixed
     * @throws \coding_exception When $ref resolution exceeds MAX_DEPTH (a likely cycle) or a
     *                            referenced definition cannot be found.
     */
    protected function resolve(mixed $node, int $depth): mixed {
        if (!is_array($node)) {
            return $node;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            if ($depth >= self::MAX_DEPTH) {
                throw new \coding_exception('Schema $ref resolution exceeded the maximum depth, likely a cycle');
            }
            return $this->resolve($this->definition($node['$ref']), $depth + 1);
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
                ? $this->resolve_structural($key, $value, $depth)
                : $value;
        }
        return $out;
    }

    /**
     * Resolves the child schemas of a structural keyword (properties, items and the like).
     *
     * @param string $key The keyword.
     * @param mixed $value Its raw value.
     * @param int $depth Current $ref resolution depth.
     * @return mixed
     */
    protected function resolve_structural(string $key, mixed $value, int $depth): mixed {
        if ($key === 'additionalProperties' && is_bool($value)) {
            return $value;
        }
        if ($key === 'properties' && is_array($value)) {
            $out = [];
            foreach ($value as $name => $propschema) {
                $out[$name] = $this->resolve($propschema, $depth);
            }
            return $out;
        }
        if ($key === 'prefixItems' && is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->resolve($item, $depth), $value);
        }
        return $this->resolve($value, $depth);
    }

    /**
     * Returns the definition a local $ref points at.
     *
     * @param string $ref The $ref value, "#/$defs/{name}".
     * @return array The definition, still unresolved: the caller recurses into it.
     * @throws \coding_exception When the $ref is not local to the bundle or names no definition.
     */
    protected function definition(string $ref): array {
        $definition = str_starts_with($ref, self::DEFS_PREFIX)
            ? ($this->defs[substr($ref, strlen(self::DEFS_PREFIX))] ?? null)
            : null;
        if (!is_array($definition)) {
            throw new \coding_exception("Cannot resolve \$ref '{$ref}' in a bundled schema");
        }
        return $definition;
    }
}
