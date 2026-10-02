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

use local_aicoursebuilder\blueprint\schema_store;

/**
 * Turns a schema/ document into a self-contained schema that can be sent to a provider.
 *
 * A step schema refers to the blueprint schema by a cross-document $ref (rewritten by
 * \local_aicoursebuilder\blueprint\schema_store to "aicb:///blueprint.v1#/$defs/..."), a URI no
 * provider can fetch or has registered, so a provider that validates the schema it is given rejects
 * the request. The bundle has no external reference left: every definition a $ref reaches, in this
 * document or another, is copied once into the $defs of the root schema and the $ref becomes a local
 * "#/$defs/{name}"; a $ref to anything else than a definition is inlined. Definitions of another
 * document are named "{document}__{name}" (blueprint_v1__course), so they cannot collide with the
 * root's own. The root $id is dropped: it is the registered aicb:/// URI of the document, meaningful
 * only to our own validator, and it would make a provider resolve every $ref against it.
 *
 * Nothing else is changed, not even keywords a given provider ignores: narrowing the schema to what
 * one provider understands is that connector's job (gemini_schema_transformer). Our own validator
 * keeps validating every answer against the original documents, never against the bundle. A schema
 * with no $ref and no $id comes back as it went in.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_bundler {
    /** @var string Key of the root document in the document table, which no schema id can be. */
    protected const ROOT = '';

    /** @var int Maximum depth of inlined $refs, guarding against a cycle that does not go through $defs. */
    public const MAX_DEPTH = 50;

    /** @var string[] Keywords whose value is a map of name => schema. */
    protected const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'dependentSchemas'];

    /** @var string[] Keywords whose value is one schema. */
    protected const SCHEMAS = ['items', 'additionalProperties', 'additionalItems', 'unevaluatedItems',
        'unevaluatedProperties', 'contains', 'propertyNames', 'if', 'then', 'else', 'not'];

    /** @var string[] Keywords whose value is a list of schemas. */
    protected const SCHEMA_LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** @var schema_store Store the documents other than the root are read from. */
    protected schema_store $store;

    /** @var array<string, array> Decoded documents of the current bundle, by id, ROOT being the schema bundled. */
    protected array $documents = [];

    /** @var array<string, string> "{document}#{name}" => name of the definition in the bundle. */
    protected array $names = [];

    /** @var array<string, mixed> Definitions collected so far, by their name in the bundle. */
    protected array $defs = [];

    /**
     * Creates the bundler.
     *
     * @param string|null $schemadir Directory with the schema/ files, null for the plugin's schema/.
     */
    public function __construct(?string $schemadir = null) {
        $this->store = new schema_store($schemadir);
    }

    /**
     * Returns the self-contained form of a schema.
     *
     * @param array $schema The schema, as given on a request: $refs between documents already
     *                      rewritten to aicb:/// by schema_store, or still in the plugin-style form.
     * @return array The schema with every $ref local to it and no $id.
     * @throws \coding_exception When a $ref cannot be resolved or the references run too deep.
     */
    public function bundle(array $schema): array {
        $this->documents = [self::ROOT => $schema];
        $this->names = [];
        $this->defs = [];

        $root = $schema;
        unset($root['$id'], $root['$defs']);
        $out = $this->walk($root, self::ROOT, 0);
        if ($this->defs) {
            $out['$defs'] = $this->defs;
        }
        return $out;
    }

    /**
     * Rewrites the $refs of a schema node, recursively.
     *
     * @param mixed $node A schema, or a boolean schema.
     * @param string $document Id of the document the node belongs to, which a "#/..." $ref resolves against.
     * @param int $depth Depth of $refs inlined so far.
     * @return mixed
     */
    protected function walk(mixed $node, string $document, int $depth): mixed {
        if (!is_array($node)) {
            return $node;
        }
        $out = [];
        foreach ($node as $keyword => $value) {
            if ($keyword === '$ref' && is_string($value)) {
                // A $ref may sit beside other keywords (a description, say); they are kept.
                $inlined = $this->follow($value, $document, $depth);
                if (isset($inlined['$ref'])) {
                    $out['$ref'] = $inlined['$ref'];
                } else {
                    $out += $inlined;
                }
            } else if (in_array($keyword, self::SCHEMA_MAPS, true) && is_array($value)) {
                $out[$keyword] = $this->walk_map($value, $document, $depth);
            } else if (in_array($keyword, self::SCHEMAS, true)) {
                $out[$keyword] = $this->walk($value, $document, $depth);
            } else if (in_array($keyword, self::SCHEMA_LISTS, true) && is_array($value)) {
                $out[$keyword] = array_map(fn(mixed $item): mixed => $this->walk($item, $document, $depth), $value);
            } else {
                // Scalars, and the values that are data, not schemas (enum, const, default, examples).
                $out[$keyword] = $value;
            }
        }
        return $out;
    }

    /**
     * Rewrites a map of name => schema.
     *
     * @param array $map The map, such as the value of properties.
     * @param string $document Id of the document the map belongs to.
     * @param int $depth Depth of $refs inlined so far.
     * @return array|\stdClass An empty map stays an object, not the list an empty array encodes to.
     */
    protected function walk_map(array $map, string $document, int $depth): array|\stdClass {
        if (!$map) {
            return new \stdClass();
        }
        $out = [];
        foreach ($map as $name => $schema) {
            $out[$name] = $this->walk($schema, $document, $depth);
        }
        return $out;
    }

    /**
     * Resolves one $ref.
     *
     * @param string $ref The $ref value.
     * @param string $document Id of the document the $ref was found in.
     * @param int $depth Depth of $refs inlined so far.
     * @return array Either ['$ref' => '#/$defs/{name}'], or the keywords of the target to put in its place.
     * @throws \coding_exception When the $ref cannot be parsed or resolved, or the nesting is too deep.
     */
    protected function follow(string $ref, string $document, int $depth): array {
        [$id, $pointer] = $this->split($ref, $document);
        $segments = $pointer === '' ? [] : array_map(
            fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', ltrim($pointer, '/')),
        );

        if (count($segments) === 2 && $segments[0] === '$defs') {
            return ['$ref' => '#/$defs/' . $this->define($id, $segments[1], $ref)];
        }

        // Anything that is not a definition (a property, a whole document) is inlined.
        if ($depth >= self::MAX_DEPTH) {
            throw new \coding_exception("Schema \$ref '{$ref}' nests deeper than " . self::MAX_DEPTH . ', likely a cycle');
        }
        $target = $this->document($id);
        foreach ($segments as $segment) {
            if (!is_array($target) || !array_key_exists($segment, $target)) {
                throw new \coding_exception("Cannot resolve \$ref '{$ref}': missing segment '{$segment}'");
            }
            $target = $target[$segment];
        }
        if (!is_array($target)) {
            throw new \coding_exception("\$ref '{$ref}' does not point at a schema object");
        }
        unset($target['$id'], $target['$defs']);
        if (!$segments) {
            unset($target['$schema']);
        }
        return $this->walk($target, $id, $depth + 1);
    }

    /**
     * Splits a $ref into the id of its document and its JSON pointer.
     *
     * @param string $ref The $ref value: "aicb:///{id}#/{pointer}", the plugin-style
     *                    "local_aicoursebuilder/{id}#/{pointer}", or "#/{pointer}" for the current document.
     * @param string $document Id of the document the $ref was found in.
     * @return array{0: string, 1: string} Document id and pointer.
     * @throws \coding_exception When the $ref has a form that is not supported.
     */
    protected function split(string $ref, string $document): array {
        if (str_starts_with($ref, '#')) {
            return [$document, substr($ref, 1)];
        }
        foreach ([schema_store::URI_PREFIX, schema_store::ID_PREFIX] as $prefix) {
            if (str_starts_with($ref, $prefix)) {
                $rest = substr($ref, strlen($prefix));
                $hash = strpos($rest, '#');
                return $hash === false ? [$rest, ''] : [substr($rest, 0, $hash), substr($rest, $hash + 1)];
            }
        }
        throw new \coding_exception("Unsupported \$ref form: {$ref}");
    }

    /**
     * Copies a definition into the bundle, with everything it refers to, unless it is already there.
     *
     * @param string $document Id of the document holding the definition.
     * @param string $name Name of the definition in that document.
     * @param string $ref The $ref being resolved, for the error message.
     * @return string Name of the definition in the bundle.
     * @throws \coding_exception When the document has no such definition.
     */
    protected function define(string $document, string $name, string $ref): string {
        $key = $document . '#' . $name;
        if (isset($this->names[$key])) {
            return $this->names[$key];
        }
        $source = $this->document($document)['$defs'][$name] ?? null;
        if (!is_array($source)) {
            throw new \coding_exception("Cannot resolve \$ref '{$ref}': no definition '{$name}'");
        }

        $local = $document === self::ROOT ? $name : preg_replace('/[^A-Za-z0-9]+/', '_', $document) . '__' . $name;
        while (array_key_exists($local, $this->defs)) {
            $local .= '_';
        }
        // Registered before it is walked, so a definition that refers to itself ends here.
        $this->names[$key] = $local;
        $this->defs[$local] = null;
        $this->defs[$local] = $this->walk($source, $document, 0);
        return $local;
    }

    /**
     * Returns a document: the root schema, or a schema/ file with its $refs rewritten.
     *
     * @param string $id Document id, ROOT for the schema being bundled.
     * @return array
     * @throws \coding_exception When the file is missing or is not valid JSON.
     */
    protected function document(string $id): array {
        if (!isset($this->documents[$id])) {
            $this->documents[$id] = json_decode(json_encode($this->store->document($id)), true);
        }
        return $this->documents[$id];
    }
}
