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
 * Tests for the schema transformation that narrows a schema/ document to what Anthropic's structured outputs accept.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\anthropic_schema_transformer
 */
final class anthropic_schema_transformer_test extends \advanced_testcase {
    /**
     * Numerical and string constraints, and the array ones beyond minItems 0 or 1, are removed and written into the
     * description, where the model still reads them.
     */
    public function test_unsupported_constraints_become_a_note_in_the_description(): void {
        $out = (new anthropic_schema_transformer())->transform([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'A name.', 'minLength' => 1, 'maxLength' => 255],
                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'exclusiveMinimum' => 0],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 2,
                    'maxItems' => 5,
                    'uniqueItems' => true,
                ],
            ],
        ]);

        $this->assertSame(
            ['type' => 'string', 'description' => 'A name. Constraints: minLength 1, maxLength 255.'],
            $out['properties']['name']
        );
        $this->assertSame(
            ['type' => 'number', 'description' => 'Constraints: minimum 0, maximum 100, exclusiveMinimum 0.'],
            $out['properties']['score']
        );
        $this->assertSame(
            [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Constraints: minItems 2, maxItems 5, uniqueItems true.',
            ],
            $out['properties']['tags']
        );
    }

    /**
     * What the API takes is left as it is: a minItems of 0 or 1, enum, const, anyOf, default, a documented format and a
     * simple pattern.
     */
    public function test_supported_keywords_survive(): void {
        $schema = [
            'type' => 'object',
            'required' => ['a'],
            'additionalProperties' => false,
            'properties' => [
                'a' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x', 'y']], 'minItems' => 1],
                'b' => ['const' => 'quiz'],
                'c' => ['anyOf' => [['type' => 'string', 'format' => 'uri'], ['type' => 'null']]],
                'd' => ['type' => 'string', 'pattern' => '^s[0-9]+(-[0-9]+)?$', 'default' => 's1'],
            ],
        ];

        $this->assertSame($schema, (new anthropic_schema_transformer())->transform($schema));
    }

    /**
     * A format the API does not list, and a pattern it does not document, are removed with a note.
     */
    public function test_a_format_and_a_pattern_that_are_not_documented_are_removed(): void {
        $out = (new anthropic_schema_transformer())->transform(['type' => 'object', 'properties' => [
            'when' => ['type' => 'string', 'format' => 'regex'],
            'text' => ['type' => 'string', 'pattern' => '^(?!draft)[a-z]+$'],
        ]]);

        $this->assertSame(['type' => 'string', 'description' => 'Constraints: format "regex".'], $out['properties']['when']);
        $this->assertArrayNotHasKey('pattern', $out['properties']['text']);
    }

    /**
     * An object that names its properties has no others, which is what the API asks to be said; an open map keeps no
     * schema for its values, because the API takes only false.
     */
    public function test_additional_properties_are_false_or_absent(): void {
        $out = (new anthropic_schema_transformer())->transform(['type' => 'object', 'properties' => [
            'rules' => ['type' => 'object', 'additionalProperties' => ['type' => ['integer', 'boolean']]],
            'content' => ['type' => 'object'],
            'item' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ]]);

        $this->assertFalse($out['additionalProperties']);
        $this->assertSame(['type' => 'object'], $out['properties']['rules']);
        $this->assertSame(['type' => 'object'], $out['properties']['content']);
        $this->assertFalse($out['properties']['item']['additionalProperties']);
    }

    /**
     * An allOf with a $ref is merged into one schema: the reference is inlined, the properties and the required lists are
     * added, and an enum that both give is the part they have in common.
     */
    public function test_an_all_of_with_a_reference_is_merged(): void {
        $out = (new anthropic_schema_transformer())->transform([
            'type' => 'object',
            'properties' => ['activity' => ['allOf' => [
                ['$ref' => '#/$defs/activity'],
                ['properties' => ['type' => ['enum' => ['page', 'label']]], 'required' => ['content']],
            ]]],
            '$defs' => ['activity' => [
                'type' => 'object',
                'required' => ['id', 'type'],
                'properties' => ['id' => ['type' => 'string'], 'type' => ['enum' => ['page', 'label', 'quiz']]],
            ]],
        ]);

        $activity = $out['properties']['activity'];
        $this->assertArrayNotHasKey('allOf', $activity);
        $this->assertSame(['id', 'type', 'content'], $activity['required']);
        $this->assertSame(['page', 'label'], $activity['properties']['type']['enum']);
        $this->assertSame(['type' => 'string'], $activity['properties']['id']);
    }

    /**
     * Conditionals the page does not document are left out, and a oneOf becomes the anyOf it does.
     */
    public function test_conditionals_are_left_out_and_one_of_becomes_any_of(): void {
        $out = (new anthropic_schema_transformer())->transform([
            'type' => 'object',
            'properties' => [
                'id' => ['oneOf' => [['type' => 'string', 'pattern' => '^s1$'], ['type' => 'string', 'pattern' => '^s2$']]],
                'content' => [
                    'type' => 'object',
                    'if' => ['properties' => ['type' => ['const' => 'page']]],
                    'then' => ['required' => ['text']],
                    'not' => ['required' => ['x']],
                ],
            ],
            'title' => 'A schema',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        ]);

        $this->assertArrayHasKey('anyOf', $out['properties']['id']);
        $this->assertArrayNotHasKey('oneOf', $out['properties']['id']);
        $this->assertSame(['type' => 'object'], $out['properties']['content']);
        $this->assertArrayNotHasKey('title', $out);
        $this->assertArrayNotHasKey('$schema', $out);
    }

    /**
     * A local $ref into $defs stays, with the definition narrowed as well; a $ref between documents is resolved by the
     * bundle first, so none is left that points outside.
     */
    public function test_references_stay_local(): void {
        $out = (new anthropic_schema_transformer())->transform([
            'type' => 'object',
            'properties' => ['name' => ['$ref' => '#/$defs/name']],
            '$defs' => ['name' => ['type' => 'string', 'maxLength' => 100]],
        ]);

        $this->assertSame('#/$defs/name', $out['properties']['name']['$ref']);
        $this->assertSame(
            ['type' => 'string', 'description' => 'Constraints: maxLength 100.'],
            $out['$defs']['name']
        );
    }

    /**
     * An allOf that refers to itself is not followed for ever.
     */
    public function test_an_all_of_that_refers_to_itself_is_refused(): void {
        $this->expectException(\coding_exception::class);

        (new anthropic_schema_transformer())->transform([
            'type' => 'object',
            'properties' => ['a' => ['$ref' => '#/$defs/loop']],
            '$defs' => ['loop' => ['allOf' => [['$ref' => '#/$defs/loop']]]],
        ]);
    }
}
