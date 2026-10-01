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

namespace local_aicoursebuilder\blueprint;

/**
 * Loads the JSON Schema documents of schema/ and hands the validator a resolver that knows them.
 *
 * The schema files carry plugin-style ids ("local_aicoursebuilder/blueprint.v1"), which are not
 * resolvable URIs, so every document is registered under the aicb:/// scheme and every $ref
 * between documents is rewritten to match. Nothing is ever fetched over the network: a $ref to an
 * unregistered document fails instead.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_store {
    /** @var string Id prefix used inside the schema files. */
    public const ID_PREFIX = 'local_aicoursebuilder/';

    /** @var string Scheme the documents are registered under, so opis/json-schema can resolve them. */
    public const URI_PREFIX = 'aicb:///';

    /** @var string Id of the blueprint schema, without the prefix. */
    public const BLUEPRINT = 'blueprint.v1';

    /** @var string Schema version the blueprint documents describe, stored on local_aicb_blueprint. */
    public const SCHEMA_VERSION = '1.0';

    /** @var array<string, string> Step name => schema id, without the prefix. */
    public const STEP_SCHEMAS = [
        'digest' => 'steps/digest.v1',
        'brief' => 'steps/brief.v1',
        'outline' => 'steps/outline.v1',
        'sections' => 'steps/sections.v1',
    ];

    /** @var string|null Directory holding the schema files, null for the plugin's schema/. */
    protected ?string $schemadir;

    /** @var \Opis\JsonSchema\Validator|null The validator, built on first use. */
    protected ?\Opis\JsonSchema\Validator $validator = null;

    /**
     * Creates the store.
     *
     * @param string|null $schemadir Directory with the schema files, null for the plugin's schema/.
     */
    public function __construct(?string $schemadir = null) {
        $this->schemadir = $schemadir;
    }

    /**
     * Returns the validator, with every schema document registered.
     *
     * @return \Opis\JsonSchema\Validator
     */
    public function validator(): \Opis\JsonSchema\Validator {
        if ($this->validator === null) {
            require_once(dirname(__DIR__, 2) . '/thirdparty/autoload.php');
            $this->validator = new \Opis\JsonSchema\Validator();
            $this->register(self::BLUEPRINT);
            foreach (self::STEP_SCHEMAS as $id) {
                $this->register($id);
            }
        }
        return $this->validator;
    }

    /**
     * Returns the URI a schema id is registered under.
     *
     * @param string $id Schema id without the prefix, such as blueprint.v1 or steps/outline.v1.
     * @return string
     */
    public function uri(string $id): string {
        return self::URI_PREFIX . $id;
    }

    /**
     * Returns the URI of the schema validating a pipeline step's output.
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @return string
     * @throws \coding_exception When the step has no schema.
     */
    public function step_uri(string $step): string {
        if (!isset(self::STEP_SCHEMAS[$step])) {
            throw new \coding_exception("No JSON schema for pipeline step '{$step}'");
        }
        return $this->uri(self::STEP_SCHEMAS[$step]);
    }

    /**
     * Tells whether a pipeline step has a schema of its own.
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @return bool
     */
    public function has_step_schema(string $step): bool {
        return isset(self::STEP_SCHEMAS[$step]);
    }

    /**
     * Returns a schema document as a decoded object, with its $refs rewritten.
     *
     * @param string $id Schema id without the prefix.
     * @return \stdClass
     * @throws \coding_exception When the file is missing or is not valid JSON.
     */
    public function document(string $id): \stdClass {
        $dir = $this->schemadir ?? dirname(__DIR__, 2) . '/schema';
        $path = $dir . '/' . $id . '.json';
        if (!is_readable($path)) {
            throw new \coding_exception("Missing JSON schema file: {$path}");
        }
        try {
            $schema = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \coding_exception("Invalid JSON in schema file {$path}: " . $e->getMessage());
        }
        if (!($schema instanceof \stdClass)) {
            throw new \coding_exception("Schema file {$path} does not hold a JSON object");
        }
        $schema->{'$id'} = $this->uri($id);
        $this->rewrite_refs($schema);
        return $schema;
    }

    /**
     * Returns the raw JSON schema the connectors send to a model for a step, or null when the step
     * has none.
     *
     * The document keeps its plugin-style ids and its cross-document $refs, which a provider cannot
     * resolve; only providers that take a self-contained schema are given one, so the caller decides.
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @return array|null Decoded schema as an associative array, or null.
     */
    public function step_schema_array(string $step): ?array {
        if (!$this->has_step_schema($step)) {
            return null;
        }
        return json_decode(json_encode($this->document(self::STEP_SCHEMAS[$step])), true);
    }

    /**
     * Registers a schema document with the validator's resolver.
     *
     * @param string $id Schema id without the prefix.
     */
    protected function register(string $id): void {
        $this->validator->resolver()->registerRaw($this->document($id), $this->uri($id));
    }

    /**
     * Rewrites every $ref of a decoded schema from the plugin id prefix to the registered URI prefix.
     *
     * @param mixed $node Node of the decoded schema, walked recursively.
     */
    protected function rewrite_refs(mixed $node): void {
        if ($node instanceof \stdClass) {
            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value) && str_starts_with($value, self::ID_PREFIX)) {
                    $node->{'$ref'} = self::URI_PREFIX . substr($value, strlen(self::ID_PREFIX));
                } else {
                    $this->rewrite_refs($value);
                }
            }
        } else if (is_array($node)) {
            foreach ($node as $value) {
                $this->rewrite_refs($value);
            }
        }
    }
}
