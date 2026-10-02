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
 * Tests of the schema bundler: that nothing external is left in what a provider is sent, and that
 * the bundle accepts and rejects exactly what the schema/ documents do.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\schema_bundler
 */
final class schema_bundler_test extends \basic_testcase {
    /** @var schema_bundler The bundler under test. */
    private schema_bundler $bundler;

    /** @var schema_store Store of the real schema/ documents. */
    private schema_store $store;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->bundler = new schema_bundler();
        $this->store = new schema_store();
    }

    /**
     * Collects every $ref value of a schema, at any depth.
     *
     * @param mixed $node Node of the schema, walked recursively.
     * @return string[]
     */
    private function refs(mixed $node): array {
        $refs = [];
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $refs[] = $value;
                } else {
                    array_push($refs, ...$this->refs($value));
                }
            }
        }
        return $refs;
    }

    /**
     * A schema with nothing to resolve and no $id comes back exactly as it went in.
     */
    public function test_a_schema_without_references_is_unchanged(): void {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'required' => ['titlu'],
            'properties' => ['titlu' => ['type' => 'string', 'maxLength' => 100, 'enum' => ['a', 'b']]],
            'additionalProperties' => false,
        ];

        $this->assertSame($schema, $this->bundler->bundle($schema));
        $this->assertSame([], $this->bundler->bundle([]));
    }

    /**
     * A cross-document $ref becomes a local one, and its target a definition of the bundle.
     */
    public function test_a_cross_document_ref_becomes_a_local_definition(): void {
        $out = $this->bundler->bundle([
            'type' => 'object',
            'properties' => ['id' => ['$ref' => 'aicb:///blueprint.v1#/$defs/sectionid']],
        ]);

        $this->assertSame('#/$defs/blueprint_v1__sectionid', $out['properties']['id']['$ref']);
        $this->assertSame('string', $out['$defs']['blueprint_v1__sectionid']['type']);
        $this->assertSame('^s[0-9]+$', $out['$defs']['blueprint_v1__sectionid']['pattern']);
    }

    /**
     * The plugin-style $ref a schema has before schema_store rewrites it is understood too.
     */
    public function test_the_plugin_style_ref_is_accepted(): void {
        $out = $this->bundler->bundle([
            'properties' => ['id' => ['$ref' => 'local_aicoursebuilder/blueprint.v1#/$defs/sectionid']],
        ]);

        $this->assertSame('#/$defs/blueprint_v1__sectionid', $out['properties']['id']['$ref']);
    }

    /**
     * A definition is copied with what it refers to, once, however many times it is referred to.
     */
    public function test_a_definition_is_copied_once_with_its_dependencies(): void {
        $out = $this->bundler->bundle([
            'properties' => [
                'a' => ['$ref' => 'aicb:///blueprint.v1#/$defs/course'],
                'b' => ['$ref' => 'aicb:///blueprint.v1#/$defs/course'],
                'c' => ['$ref' => 'aicb:///blueprint.v1#/$defs/date'],
            ],
        ]);

        $this->assertSame($out['properties']['a'], $out['properties']['b']);
        // Course refers to date, activityids and name, and activityids to activityid.
        foreach (['course', 'date', 'name', 'activityids', 'activityid'] as $name) {
            $this->assertArrayHasKey("blueprint_v1__{$name}", $out['$defs']);
        }
        $this->assertSame(
            'string',
            $out['$defs']['blueprint_v1__course']['properties']['fullname']['type'],
        );
        foreach ($this->refs($out) as $ref) {
            $this->assertStringStartsWith('#/$defs/', $ref);
            $this->assertArrayHasKey(substr($ref, strlen('#/$defs/')), $out['$defs']);
        }
    }

    /**
     * The root's own definitions keep their names, and only the ones that are used are kept.
     */
    public function test_own_definitions_keep_their_names(): void {
        $out = $this->bundler->bundle([
            'properties' => ['x' => ['$ref' => '#/$defs/used']],
            '$defs' => [
                'used' => ['type' => 'string'],
                'unused' => ['type' => 'integer'],
            ],
        ]);

        $this->assertSame('#/$defs/used', $out['properties']['x']['$ref']);
        $this->assertSame(['used' => ['type' => 'string']], $out['$defs']);
    }

    /**
     * A definition of another document cannot take the name of one of the root's own.
     */
    public function test_a_foreign_definition_cannot_collide_with_an_own_one(): void {
        $out = $this->bundler->bundle([
            'properties' => [
                'x' => ['$ref' => '#/$defs/blueprint_v1__date'],
                'y' => ['$ref' => 'aicb:///blueprint.v1#/$defs/date'],
            ],
            '$defs' => ['blueprint_v1__date' => ['type' => 'integer']],
        ]);

        $this->assertNotSame($out['properties']['x']['$ref'], $out['properties']['y']['$ref']);
        $this->assertSame('integer', $out['$defs']['blueprint_v1__date']['type']);
        $this->assertSame('string', $out['$defs'][substr($out['properties']['y']['$ref'], 8)]['type']);
    }

    /**
     * The $id goes, since it is an aicb:/// URI; the rest of the root is kept as it was.
     */
    public function test_the_id_is_dropped_and_the_rest_of_the_root_is_kept(): void {
        $out = $this->bundler->bundle([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'aicb:///steps/outline.v1',
            'title' => 'Outline',
            'type' => 'object',
        ]);

        $this->assertSame([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Outline',
            'type' => 'object',
        ], $out);
    }

    /**
     * Keywords beside a $ref are kept, since 2020-12 allows them.
     */
    public function test_keywords_beside_a_ref_are_kept(): void {
        $out = $this->bundler->bundle([
            'properties' => ['id' => [
                'description' => 'Id of the section.',
                '$ref' => 'aicb:///blueprint.v1#/$defs/sectionid',
            ]],
        ]);

        $this->assertSame('Id of the section.', $out['properties']['id']['description']);
        $this->assertSame('#/$defs/blueprint_v1__sectionid', $out['properties']['id']['$ref']);
    }

    /**
     * A $ref to something other than a definition is inlined, and a whole document loses its $id and $defs.
     */
    public function test_a_ref_to_a_non_definition_is_inlined(): void {
        $out = $this->bundler->bundle([
            'properties' => [
                'language' => ['$ref' => 'aicb:///blueprint.v1#/properties/language'],
                'whole' => ['$ref' => 'aicb:///blueprint.v1#'],
            ],
        ]);

        $this->assertSame('string', $out['properties']['language']['type']);
        $this->assertSame([], $this->refs($out['properties']['language']));
        $this->assertSame(['version', 'course', 'sections'], $out['properties']['whole']['required']);
        $this->assertArrayNotHasKey('$id', $out['properties']['whole']);
        $this->assertArrayNotHasKey('$defs', $out['properties']['whole']);
        $this->assertSame('#/$defs/blueprint_v1__course', $out['properties']['whole']['properties']['course']['$ref']);
    }

    /**
     * A definition that refers to itself ends at the local $ref instead of recursing forever.
     */
    public function test_a_recursive_definition_terminates(): void {
        $out = $this->bundler->bundle([
            '$ref' => '#/$defs/node',
            '$defs' => ['node' => [
                'type' => 'object',
                'properties' => ['child' => ['$ref' => '#/$defs/node']],
            ]],
        ]);

        $this->assertSame('#/$defs/node', $out['$ref']);
        $this->assertSame('#/$defs/node', $out['$defs']['node']['properties']['child']['$ref']);
    }

    /**
     * Data keywords are not schemas: an enum value that looks like a $ref is left alone.
     */
    public function test_data_keywords_are_not_walked(): void {
        $schema = [
            'enum' => [['$ref' => 'aicb:///nowhere#/x']],
            'const' => ['$ref' => 'aicb:///nowhere#/y'],
            'default' => ['$ref' => 'aicb:///nowhere#/z'],
            'properties' => ['$ref' => ['type' => 'string']],
        ];

        $this->assertSame($schema, $this->bundler->bundle($schema));
    }

    /**
     * An empty map of properties stays an object, which an empty PHP array would not encode to.
     */
    public function test_an_empty_properties_map_stays_an_object(): void {
        $out = $this->bundler->bundle(['type' => 'object', 'properties' => []]);

        $this->assertSame('{"type":"object","properties":{}}', json_encode($out));
    }

    /**
     * Bundling a bundle changes nothing.
     */
    public function test_bundling_is_idempotent(): void {
        foreach (schema_store::STEP_SCHEMAS as $step => $id) {
            $once = $this->bundler->bundle($this->store->step_schema_array($step));
            $this->assertSame($once, $this->bundler->bundle($once), $id);
        }
    }

    /**
     * A $ref that cannot be resolved is a coding error, never a schema silently sent with a hole.
     *
     * @dataProvider unresolvable_ref_provider
     * @param string $ref The $ref.
     */
    public function test_an_unresolvable_ref_is_a_coding_error(string $ref): void {
        $this->expectException(\coding_exception::class);
        $this->bundler->bundle(['properties' => ['x' => ['$ref' => $ref]]]);
    }

    /**
     * The $refs that cannot be resolved.
     *
     * @return array[]
     */
    public static function unresolvable_ref_provider(): array {
        return [
            'unknown document' => ['aicb:///nope.v1#/$defs/x'],
            'unknown definition' => ['aicb:///blueprint.v1#/$defs/nope'],
            'unknown pointer' => ['aicb:///blueprint.v1#/properties/nope'],
            'local definition that is not there' => ['#/$defs/nope'],
            'a remote URL' => ['https://example.com/schema.json#/$defs/x'],
        ];
    }

    /**
     * Nothing external is left in any step schema: no aicb:/// anywhere, every $ref local and
     * resolvable, no $id.
     */
    public function test_every_step_schema_is_self_contained(): void {
        foreach (schema_store::STEP_SCHEMAS as $step => $id) {
            $raw = $this->store->step_schema_array($step);
            $rawjson = json_encode($raw, JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString('aicb:///', $rawjson, "{$id}: the raw schema refers outside itself");

            $out = $this->bundler->bundle($raw);
            $encoded = json_encode($out);

            $this->assertStringNotContainsString('aicb:', $encoded, $id);
            $this->assertStringNotContainsString('local_aicoursebuilder', $encoded, $id);
            $this->assertArrayNotHasKey('$id', $out, $id);
            foreach ($this->refs($out) as $ref) {
                $this->assertStringStartsWith('#/$defs/', $ref, $id);
                $this->assertArrayHasKey(substr($ref, strlen('#/$defs/')), $out['$defs'] ?? [], "{$id}: {$ref}");
            }
        }
    }

    /**
     * The fixtures that are valid output of a step: its file, or every file of its directory.
     *
     * @param string $step Pipeline step.
     * @return array[] Label => decoded fixture.
     */
    private function fixtures(string $step): array {
        $root = dirname(__DIR__) . '/fixtures/ai';
        $files = is_dir("{$root}/{$step}") ? glob("{$root}/{$step}/*.json") : ["{$root}/{$step}.json"];
        $fixtures = [];
        foreach ($files as $file) {
            $fixtures["{$step}/" . basename($file)] = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }
        return $fixtures;
    }

    /**
     * Returns a copy of a value with one entry replaced or, for null, removed.
     *
     * @param array $data The value.
     * @param array $path Keys leading to the entry.
     * @param mixed $replacement New value of the entry, null to remove it.
     * @return array
     */
    private function with(array $data, array $path, mixed $replacement): array {
        $key = array_shift($path);
        if ($path) {
            $data[$key] = $this->with($data[$key], $path, $replacement);
        } else if ($replacement === null) {
            unset($data[$key]);
        } else {
            $data[$key] = $replacement;
        }
        return $data;
    }

    /**
     * Breaks a fixture in the ways the schema layer has to catch, deep inside the referenced blueprint
     * definitions as well as at the top.
     *
     * @param string $step Pipeline step.
     * @param array $fixture A fixture that is valid output of the step.
     * @return array[] Label => broken fixture, each invalid against the step schema.
     */
    private function mutations(string $step, array $fixture): array {
        $schema = $this->store->step_schema_array($step);
        $required = $schema['required'] ?? [];
        $mutations = [];
        if (($schema['additionalProperties'] ?? true) === false) {
            $mutations['an unknown top-level key'] = $fixture + ['unknown' => 1];
        }
        foreach ($required as $key) {
            $mutations["no {$key}"] = $this->with($fixture, [$key], null);
        }

        switch ($step) {
            case 'outline':
                $mutations['an invalid section id'] = $this->with($fixture, ['sections', 0, 'id'], 'X1');
                $mutations['no objectives'] = $this->with($fixture, ['sections', 0, 'objectives'], []);
                $mutations['a bad course format'] = $this->with($fixture, ['course', 'format'], 'months');
                $mutations['activities in an outline'] = $this->with($fixture, ['sections', 0, 'activities'], []);
                break;
            case 'sections':
            case 'activities':
                $mutations['an invalid container id'] = $this->with($fixture, ['id'], 'X1');
                // A subsection can come back with no activities, and then there is nothing deep to break.
                if (!empty($fixture['activities'])) {
                    $mutations['an invalid activity id'] = $this->with($fixture, ['activities', 0, 'id'], 'nope');
                    $mutations['an unknown activity type'] = $this->with($fixture, ['activities', 0, 'type'], 'nonsense');
                    $mutations['an empty activity name'] = $this->with($fixture, ['activities', 0, 'name'], '');
                    $mutations['a type of another step'] = $this->with(
                        $fixture,
                        ['activities', 0, 'type'],
                        $step === 'sections' ? 'quiz' : 'page',
                    );
                }
                break;
            case 'questions':
                $mutations['an invalid container id'] = $this->with($fixture, ['id'], 'X1');
                $mutations['an unknown question type'] = $this->with(
                    $fixture,
                    ['quiz', 'content', 'questions', 0, 'qtype'],
                    'nonsense',
                );
                // The first multichoice question, the one with the if/then rule on its answers.
                $questions = $fixture['quiz']['content']['questions'];
                $multichoice = array_search('multichoice', array_column($questions, 'qtype'), true);
                if ($multichoice !== false) {
                    $path = ['quiz', 'content', 'questions', $multichoice, 'answers'];
                    $mutations['a fraction above one'] = $this->with($fixture, [...$path, 0, 'fraction'], 5);
                    $mutations['a multichoice with one answer'] = $this->with(
                        $fixture,
                        $path,
                        [['text' => 'Unul', 'fraction' => 1]],
                    );
                }
                $mutations['a quiz that is another type'] = $this->with($fixture, ['quiz', 'type'], 'page');
                break;
        }
        return $mutations;
    }

    /**
     * Validates a decoded value against a schema registered under a URI.
     *
     * @param \Opis\JsonSchema\Validator $validator Validator holding the schema.
     * @param string $uri URI the schema is registered under.
     * @param array $data The value.
     * @return bool
     */
    private function is_valid(\Opis\JsonSchema\Validator $validator, string $uri, array $data): bool {
        return $validator->validate(json_decode(json_encode($data), false), $uri)->isValid();
    }

    /**
     * The bundle of every step schema accepts the golden fixtures and rejects the broken ones, as
     * the original documents do.
     */
    public function test_every_step_bundle_validates_like_the_original(): void {
        $original = $this->store->validator();
        foreach (schema_store::STEP_SCHEMAS as $step => $id) {
            $uri = 'aicb:///bundle/' . $step;
            $bundled = new \Opis\JsonSchema\Validator();
            $bundled->resolver()->registerRaw(
                json_decode(json_encode($this->bundler->bundle($this->store->step_schema_array($step))), false),
                $uri,
            );

            foreach ($this->fixtures($step) as $label => $fixture) {
                $this->assertTrue($this->is_valid($original, $this->store->step_uri($step), $fixture), "{$label}: original");
                $this->assertTrue($this->is_valid($bundled, $uri, $fixture), "{$label}: bundle accepts it");

                foreach ($this->mutations($step, $fixture) as $mutation => $broken) {
                    $this->assertFalse(
                        $this->is_valid($original, $this->store->step_uri($step), $broken),
                        "{$label}, {$mutation}: original rejects it",
                    );
                    $this->assertFalse($this->is_valid($bundled, $uri, $broken), "{$label}, {$mutation}: bundle rejects it");
                }
            }
        }
    }
}
