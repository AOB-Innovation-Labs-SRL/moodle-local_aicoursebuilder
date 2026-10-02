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
 * Tests for the schema transformation that narrows a schema/ document to Gemini's responseSchema
 * subset.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\gemini_schema_transformer
 */
final class gemini_schema_transformer_test extends \advanced_testcase {
    /**
     * const is rewritten to its lossless enum equivalent.
     */
    public function test_const_becomes_enum(): void {
        $out = (new gemini_schema_transformer())->transform(['type' => 'object', 'properties' => [
            'kind' => ['const' => 'quiz'],
        ]]);
        $this->assertSame(['enum' => ['quiz']], $out['properties']['kind']);
    }

    /**
     * Unsupported keywords (pattern, allOf, if/then, uniqueItems, exclusiveMinimum) are dropped.
     */
    public function test_unsupported_keywords_are_dropped(): void {
        $out = (new gemini_schema_transformer())->transform([
            'type' => 'string',
            'pattern' => '^s[0-9]+$',
            'uniqueItems' => true,
            'exclusiveMinimum' => 0,
            'allOf' => [['type' => 'string']],
        ]);
        $this->assertSame(['type' => 'string'], $out);
    }

    /**
     * Supported scalar and structural keywords survive untouched.
     */
    public function test_supported_keywords_survive(): void {
        $schema = [
            'type' => 'object',
            'title' => 'Thing',
            'description' => 'A thing.',
            'required' => ['name'],
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 3],
                'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
            ],
        ];
        $out = (new gemini_schema_transformer())->transform($schema);
        $this->assertSame('object', $out['type']);
        $this->assertSame('Thing', $out['title']);
        $this->assertSame(['name'], $out['required']);
        $this->assertFalse($out['additionalProperties']);
        $this->assertSame('string', $out['properties']['name']['type']);
        $this->assertArrayNotHasKey('minLength', $out['properties']['name']);
        $this->assertSame(0, $out['properties']['count']['minimum']);
        $this->assertSame(10, $out['properties']['count']['maximum']);
    }

    /**
     * A cross-document $ref (rewritten by schema_store to aicb:///{id}#/{pointer}) is inlined from
     * the real schema/blueprint.v1.json file.
     */
    public function test_cross_document_ref_is_inlined(): void {
        $out = (new gemini_schema_transformer())->transform([
            'type' => 'object',
            'properties' => [
                'id' => ['$ref' => 'aicb:///blueprint.v1#/$defs/sectionid'],
            ],
        ]);
        $this->assertSame('string', $out['properties']['id']['type']);
        $this->assertArrayNotHasKey('pattern', $out['properties']['id']);
    }

    /**
     * A same-document $ref inside the inlined target (blueprint.v1's own course -> date) keeps
     * resolving against blueprint.v1, not against the step document the top-level $ref came from.
     */
    public function test_nested_same_document_ref_resolves_against_its_own_document(): void {
        $out = (new gemini_schema_transformer())->transform([
            '$ref' => 'aicb:///blueprint.v1#/$defs/course',
        ]);
        $this->assertSame('string', $out['properties']['startdate']['type']);
        $this->assertSame('date', $out['properties']['startdate']['format']);
    }

    /**
     * A schema that is already a bundle (local definitions, no external ref) is inlined and narrowed.
     */
    public function test_local_definitions_of_a_bundle_are_inlined(): void {
        $out = (new gemini_schema_transformer())->transform([
            'type' => 'object',
            'properties' => ['id' => ['$ref' => '#/$defs/sectionid']],
            '$defs' => ['sectionid' => ['type' => 'string', 'pattern' => '^s[0-9]+$']],
        ]);

        $this->assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'string']]], $out);
    }

    /**
     * A $ref the bundle has no definition for is a coding error.
     */
    public function test_a_missing_definition_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        (new gemini_schema_transformer())->transform(['properties' => ['id' => ['$ref' => '#/$defs/nope']]]);
    }

    /**
     * The real questions.v1.json step schema (cross-document $refs to blueprint.v1) transforms
     * without error and without leaving $ref, $defs, pattern or const behind.
     */
    public function test_real_questions_schema_transforms_cleanly(): void {
        $raw = json_decode(file_get_contents(dirname(__DIR__, 2) . '/schema/steps/questions.v1.json'), true);
        $raw = $this->rewrite_refs_like_schema_store($raw);

        $out = (new gemini_schema_transformer())->transform($raw);

        $this->assert_schema_is_clean($out);
    }

    /**
     * The real outline.v1.json step schema has both a same-document $ref ("#/$defs/outline_section")
     * and cross-document ones, and transforms without error.
     */
    public function test_real_outline_schema_transforms_cleanly(): void {
        $raw = json_decode(file_get_contents(dirname(__DIR__, 2) . '/schema/steps/outline.v1.json'), true);
        $raw = $this->rewrite_refs_like_schema_store($raw);

        $out = (new gemini_schema_transformer())->transform($raw);

        $this->assert_schema_is_clean($out);
    }

    /**
     * Simulates schema_store's rewrite of a raw schema/ document: the plugin-style id prefix of a
     * cross-document $ref becomes the aicb:/// URI prefix this class expects, $schema and $id are
     * dropped. json_encode()'s default slash-escaping is turned off, since a plain str_replace()
     * on an escaped "local_aicoursebuilder\/..." string would never match.
     *
     * @param array $raw The decoded schema/ document.
     * @return array
     */
    private function rewrite_refs_like_schema_store(array $raw): array {
        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES);
        $rewritten = str_replace('local_aicoursebuilder/', schema_store::URI_PREFIX, $encoded);
        $raw = json_decode($rewritten, true);
        unset($raw['$schema'], $raw['$id']);
        return $raw;
    }

    /**
     * Asserts a transformed schema has none of the keywords Gemini's responseSchema does not support.
     *
     * @param mixed $node Node of the transformed schema, walked recursively.
     */
    private function assert_schema_is_clean(mixed $node): void {
        if (!is_array($node)) {
            return;
        }
        $forbiddenkeys = ['$ref', '$defs', '$schema', '$id', 'pattern', 'const', 'allOf', 'oneOf', 'anyOf',
            'if', 'then', 'else', 'uniqueItems', 'exclusiveMinimum', 'exclusiveMaximum'];
        foreach ($forbiddenkeys as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $node);
        }
        foreach ($node as $value) {
            $this->assert_schema_is_clean($value);
        }
    }
}
