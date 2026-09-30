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
 * Checks tests/fixtures/blueprint_golden.json: that the validator accepts it, and that it is still
 * worth validating, which means covering every activity type, question type and condition the MVP
 * builds.
 *
 * The structural checks this file used to do by hand now belong to the validator. What is left is
 * the part a validator cannot do: a golden fixture that has stopped exercising half the schema
 * still validates, and would quietly stop being a golden fixture.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\blueprint\validator
 * @covers     \local_aicoursebuilder\blueprint\schema_store
 * @covers     \local_aicoursebuilder\blueprint\validation_error
 */
final class golden_blueprint_test extends \basic_testcase {
    /** @var string[] Activity types of the MVP (h5pactivity and scorm are reserved). */
    private const MVP_TYPES = ['page', 'book', 'label', 'lesson', 'quiz', 'assign', 'glossary', 'forum', 'wiki',
        'choice', 'feedback', 'url', 'resource', 'folder'];

    /** @var string[] Question types of the MVP. */
    private const MVP_QTYPES = ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay',
        'gapselect', 'ddwtos'];

    /** @var array Decoded golden blueprint. */
    private array $golden;

    /** @var string[] Source text per source id, holding the URLs the golden blueprint cites. */
    private array $sourcetexts;

    /**
     * Loads the golden blueprint and the sources a real job would carry with it.
     */
    protected function setUp(): void {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        $this->golden = json_decode(
            file_get_contents($root . '/tests/fixtures/blueprint_golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        // The sources a real job would carry: enough of them that every source_refs entry and every
        // URL of the golden blueprint resolves.
        $urls = [];
        foreach ($this->activities() as $activity) {
            if (isset($activity['content']['externalurl'])) {
                $urls[] = $activity['content']['externalurl'];
            }
        }
        $this->sourcetexts = [
            'src1' => 'Material despre energia regenerabilă. ' . implode(' ', $urls),
            'src2' => 'Al doilea material sursă.',
            'src3' => 'Al treilea material sursă.',
        ];
    }

    /**
     * Returns every section and subsection of the golden blueprint.
     *
     * @return array[]
     */
    private function containers(): array {
        $containers = [];
        foreach ($this->golden['sections'] as $section) {
            $containers[] = $section;
            array_push($containers, ...($section['subsections'] ?? []));
        }
        return $containers;
    }

    /**
     * Returns every activity of the golden blueprint.
     *
     * @return array[]
     */
    private function activities(): array {
        return array_merge(...array_map(fn($container) => $container['activities'], $this->containers()));
    }

    /**
     * The validator accepts the golden blueprint, schema layer and rules alike.
     */
    public function test_the_validator_accepts_the_golden_blueprint(): void {
        $errors = (new validator())->validate($this->golden, $this->sourcetexts);

        $this->assertSame(
            [],
            array_map(fn(validation_error $error) => $error->to_array(), $errors),
            'the golden blueprint is the contract: it has to validate',
        );
    }

    /**
     * The schema is the v1 contract written in JSON Schema 2020-12.
     */
    public function test_schema_header(): void {
        $schema = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/schema/blueprint.v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('local_aicoursebuilder/blueprint.v1', $schema['$id']);
        $this->assertSame(['version', 'course', 'sections'], $schema['required']);
        $this->assertSame($schema['properties']['version']['const'], $this->golden['version']);
    }

    /**
     * The golden blueprint still uses every activity type the MVP builds.
     */
    public function test_every_mvp_activity_type_is_covered(): void {
        $types = array_column($this->activities(), 'type');

        $this->assertEqualsCanonicalizing(
            self::MVP_TYPES,
            array_values(array_unique($types)),
            'the golden blueprint has to exercise every type the builders support',
        );
    }

    /**
     * The golden blueprint still uses every question type the MVP builds.
     */
    public function test_every_mvp_question_type_is_covered(): void {
        $qtypes = [];
        foreach ($this->activities() as $activity) {
            foreach ($activity['content']['questions'] ?? [] as $question) {
                $qtypes[] = $question['qtype'];
            }
        }

        $this->assertEqualsCanonicalizing(self::MVP_QTYPES, $qtypes);
    }

    /**
     * The golden blueprint still uses all three availability conditions and course completion.
     */
    public function test_every_availability_condition_is_covered(): void {
        $conditions = [];
        foreach (array_merge($this->containers(), $this->activities()) as $node) {
            $conditions = array_merge($conditions, array_keys($node['availability'] ?? []));
        }

        $this->assertEqualsCanonicalizing(
            ['require_completion_of', 'min_grade', 'date_from'],
            array_unique($conditions),
        );
        $this->assertTrue($this->golden['course']['enablecompletion']);
        $this->assertNotEmpty($this->golden['course']['completion_activities']);
        $this->assertNotEmpty($this->golden['course']['competencies']);
        $this->assertNotEmpty($this->golden['course']['badges']);
    }

    /**
     * Every URL of the golden blueprint comes from a source, which is what the rule exists for.
     */
    public function test_urls_come_from_the_sources(): void {
        $urls = [];
        foreach ($this->activities() as $activity) {
            if (($activity['type'] ?? '') === 'url') {
                $urls[] = $activity['content']['externalurl'];
                $this->assertNotEmpty($activity['source_refs'], 'a url activity says where its address came from');
            }
        }
        $this->assertNotEmpty($urls, 'the golden blueprint has to exercise the URL rule');

        // Drop the URLs from the sources and the validator has to notice.
        $errors = (new validator())->validate($this->golden, ['src1' => 'no urls here', 'src2' => '', 'src3' => '']);
        $codes = array_map(fn(validation_error $error) => $error->code, $errors);
        $this->assertContains(validation_error::CODE_URL_NOT_IN_SOURCES, $codes);
    }
}
