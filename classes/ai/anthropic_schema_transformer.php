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
 * Narrows a self-contained schema (the output of schema_bundler) to what Anthropic's structured outputs accept.
 *
 * https://platform.claude.com/docs/en/build-with-claude/structured-outputs lists what the API refuses with a 400:
 * recursive schemas, an external $ref, numerical constraints (minimum, maximum, multipleOf), string constraints
 * (minLength, maxLength), array constraints beyond a minItems of 0 or 1, and an additionalProperties other than false.
 * It also says allOf is supported but not with a $ref, and that string formats are limited to date-time, time, date,
 * duration, email, hostname, uri, ipv4, ipv6 and uuid. A local $ref into $defs is supported, which is what the bundle is
 * left with, so references are kept; only the ones inside an allOf are inlined, and the allOf is merged into one schema.
 *
 * The SDKs of Anthropic do the same: they remove the constraints the API does not take and write them into the
 * description, so that the model still reads them. The page says nothing of oneOf, if/then/else and not; they are
 * not guessed at: a oneOf becomes the anyOf the page documents, and a conditional is left out, which loses the rule that
 * the content of an activity follows its type. Our own validator keeps checking every answer against the original
 * documents, so what is narrowed here is a hint for the generation, never a weaker validation.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anthropic_schema_transformer {
    /** @var string Prefix of a local $ref into the $defs of the bundle. */
    protected const DEFS_PREFIX = '#/$defs/';

    /** @var string[] Constraints the API refuses, which are removed and written into the description. */
    public const UNSUPPORTED_CONSTRAINTS = [
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'maxItems', 'uniqueItems', 'minProperties', 'maxProperties',
    ];

    /** @var string[] Keywords with no equivalent in the documented subset, which are left out. */
    public const DROPPED = ['if', 'then', 'else', 'not', 'title', '$schema', '$id', '$comment', 'dependentSchemas',
        'dependentRequired', 'patternProperties', 'propertyNames', 'contains', 'unevaluatedProperties', 'unevaluatedItems',
        'additionalItems', 'examples', 'deprecated', 'readOnly', 'writeOnly'];

    /** @var string[] String formats the API accepts. */
    public const FORMATS = ['date-time', 'time', 'date', 'duration', 'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid'];

    /** @var int Maximum depth of inlined $refs, guarding against a cycle. */
    public const MAX_DEPTH = 50;

    /** @var schema_bundler Resolves the cross-document $refs. */
    protected schema_bundler $bundler;

    /** @var array<string, mixed> The $defs of the bundle being transformed, as the bundle has them. */
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
     * Transforms a schema into the subset Anthropic's structured outputs accept.
     *
     * @param array $schema The schema, as given on a request.
     * @return array The schema, with local references only.
     * @throws \coding_exception When a reference cannot be resolved or the references nest too deep.
     */
    public function transform(array $schema): array {
        $bundle = $this->bundler->bundle($schema);
        $this->defs = $bundle['$defs'] ?? [];
        unset($bundle['$defs']);

        $out = $this->narrow($bundle, 0);
        $defs = [];
        foreach ($this->defs as $name => $definition) {
            $defs[$name] = $this->narrow($definition, 0);
        }
        if ($defs) {
            $out['$defs'] = $defs;
        }
        return $out;
    }

    /**
     * Narrows one schema node, recursively.
     *
     * @param mixed $node A schema, or a boolean schema.
     * @param int $depth Depth of $refs inlined so far.
     * @return mixed
     */
    protected function narrow(mixed $node, int $depth): mixed {
        if (!is_array($node)) {
            return $node;
        }
        if (isset($node['allOf']) && is_array($node['allOf'])) {
            $node = $this->merge_all_of($node, $depth);
        }

        $out = [];
        $notes = [];
        foreach ($node as $keyword => $value) {
            if (in_array($keyword, self::DROPPED, true)) {
                continue;
            }
            if (in_array($keyword, self::UNSUPPORTED_CONSTRAINTS, true)) {
                $notes[] = $keyword . ' ' . json_encode($value);
                continue;
            }
            switch ($keyword) {
                case 'minItems':
                    if ($value > 1) {
                        $notes[] = 'minItems ' . $value;
                    } else {
                        $out[$keyword] = $value;
                    }
                    break;
                case 'format':
                    if (in_array($value, self::FORMATS, true)) {
                        $out[$keyword] = $value;
                    } else {
                        $notes[] = 'format ' . json_encode($value);
                    }
                    break;
                case 'pattern':
                    if ($this->is_simple_pattern((string) $value)) {
                        $out[$keyword] = $value;
                    } else {
                        $notes[] = 'pattern ' . json_encode($value);
                    }
                    break;
                case 'oneOf':
                    $out['anyOf'] = array_map(fn(mixed $item): mixed => $this->narrow($item, $depth), (array) $value);
                    break;
                case 'additionalProperties':
                    // Only false is accepted; a schema here describes an open map, which is left open.
                    if ($value === false) {
                        $out[$keyword] = false;
                    }
                    break;
                case 'properties':
                    $out[$keyword] = $this->narrow_map((array) $value, $depth);
                    break;
                case 'items':
                    $out[$keyword] = $this->narrow($value, $depth);
                    break;
                case 'anyOf':
                case 'prefixItems':
                    $out[$keyword] = array_map(fn(mixed $item): mixed => $this->narrow($item, $depth), (array) $value);
                    break;
                default:
                    $out[$keyword] = $value;
            }
        }

        // An object that names its properties has no others; that is what the API wants said.
        if (isset($out['properties']) && !array_key_exists('additionalProperties', $out)) {
            $out['additionalProperties'] = false;
        }
        if ($notes) {
            $out['description'] = trim(($out['description'] ?? '') . ' Constraints: ' . implode(', ', $notes) . '.');
        }
        return $out;
    }

    /**
     * Narrows a map of name => schema.
     *
     * @param array $map The map, such as the value of properties.
     * @param int $depth Depth of $refs inlined so far.
     * @return array|\stdClass An empty map stays an object.
     */
    protected function narrow_map(array $map, int $depth): array|\stdClass {
        if (!$map) {
            return new \stdClass();
        }
        $out = [];
        foreach ($map as $name => $schema) {
            $out[$name] = $this->narrow($schema, $depth);
        }
        return $out;
    }

    /**
     * Turns an allOf into one schema: its members, the references among them inlined, merged into the node.
     *
     * @param array $node The schema that has the allOf.
     * @param int $depth Depth of $refs inlined so far.
     * @return array The node without allOf.
     * @throws \coding_exception When a reference cannot be resolved or nests too deep.
     */
    protected function merge_all_of(array $node, int $depth): array {
        $members = $node['allOf'];
        unset($node['allOf']);
        $merged = $node;
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            if (isset($member['$ref']) && is_string($member['$ref'])) {
                $member = $this->definition($member['$ref'], $depth);
            }
            if (isset($member['allOf'])) {
                $member = $this->merge_all_of($member, $depth + 1);
            }
            $merged = $this->merge($merged, $member);
        }
        return $merged;
    }

    /**
     * Merges two schemas as the conjunction they are: properties and required are added, an enum is the common part.
     *
     * @param array $first The schema that wins when both have the same scalar keyword.
     * @param array $second The schema merged into it.
     * @return array
     */
    protected function merge(array $first, array $second): array {
        foreach ($second as $keyword => $value) {
            if (!array_key_exists($keyword, $first)) {
                $first[$keyword] = $value;
            } else if ($keyword === 'properties' && is_array($value) && is_array($first[$keyword])) {
                foreach ($value as $name => $property) {
                    $first[$keyword][$name] = isset($first[$keyword][$name]) && is_array($property)
                        ? $this->merge((array) $first[$keyword][$name], $property)
                        : $property;
                }
            } else if ($keyword === 'required' && is_array($value)) {
                $first[$keyword] = array_values(array_unique(array_merge((array) $first[$keyword], $value)));
            } else if ($keyword === 'enum' && is_array($value)) {
                $first[$keyword] = array_values(array_intersect((array) $first[$keyword], $value));
            }
        }
        return $first;
    }

    /**
     * Returns the definition a local $ref points at, with its own allOf and nested references still unresolved.
     *
     * @param string $ref The $ref value, "#/$defs/{name}".
     * @param int $depth Depth of $refs inlined so far.
     * @return array The definition.
     * @throws \coding_exception When the $ref is not local, names no definition, or nests too deep.
     */
    protected function definition(string $ref, int $depth): array {
        if ($depth >= self::MAX_DEPTH) {
            throw new \coding_exception('Schema $ref resolution exceeded the maximum depth, likely a cycle');
        }
        $definition = str_starts_with($ref, self::DEFS_PREFIX)
            ? ($this->defs[substr($ref, strlen(self::DEFS_PREFIX))] ?? null)
            : null;
        if (!is_array($definition)) {
            throw new \coding_exception("Cannot resolve \$ref '{$ref}' in a bundled schema");
        }
        return $definition;
    }

    /**
     * Tells whether a regular expression only uses what the API documents: anchors, quantifiers, character classes
     * and groups. Backreferences, lookarounds and word boundaries are not documented.
     *
     * @param string $pattern The pattern.
     * @return bool
     */
    protected function is_simple_pattern(string $pattern): bool {
        return !preg_match('/\(\?|\\\\[1-9bB]/', $pattern);
    }
}
