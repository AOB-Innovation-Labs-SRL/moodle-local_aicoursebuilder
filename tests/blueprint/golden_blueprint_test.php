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
 * Structural checks of tests/fixtures/blueprint_golden.json against schema/blueprint.v1.json.
 *
 * Moodle ships no JSON Schema validator, so this test reads required fields, enums, id patterns
 * and allowed properties from the schema and checks the golden blueprint against them.
 * TODO: replace with the blueprint validator from task 1.4 when it exists.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class golden_blueprint_test extends \basic_testcase {
    /** @var string[] Activity types of the MVP (h5pactivity and scorm are reserved). */
    private const MVP_TYPES = ['page', 'book', 'label', 'lesson', 'quiz', 'assign', 'glossary', 'forum', 'wiki',
        'choice', 'feedback', 'url', 'resource', 'folder'];

    /** @var string[] Question types of the MVP. */
    private const MVP_QTYPES = ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay',
        'gapselect', 'ddwtos'];

    /** @var array Decoded schema. */
    private array $schema;

    /** @var array Decoded golden blueprint. */
    private array $golden;

    /**
     * Loads the schema and the golden blueprint.
     */
    protected function setUp(): void {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        $this->schema = json_decode(file_get_contents($root . '/schema/blueprint.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->golden = json_decode(
            file_get_contents($root . '/tests/fixtures/blueprint_golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Returns a definition of the schema.
     *
     * @param string $name Name under $defs.
     * @return array
     */
    private function def(string $name): array {
        $this->assertArrayHasKey($name, $this->schema['$defs'], "Schema has no \$defs/{$name}");
        return $this->schema['$defs'][$name];
    }

    /**
     * Asserts that an object has the required fields and only allowed properties of a definition.
     *
     * @param array $definition Schema definition with required and properties.
     * @param array $value The object.
     * @param string $where Location, for failure messages.
     */
    private function assert_object(array $definition, array $value, string $where): void {
        foreach ($definition['required'] ?? [] as $field) {
            $this->assertArrayHasKey($field, $value, "{$where}: missing {$field}");
        }
        if (($definition['additionalProperties'] ?? true) === false) {
            $extra = array_diff(array_keys($value), array_keys($definition['properties']));
            $this->assertSame([], array_values($extra), "{$where}: properties not in the schema");
        }
    }

    /**
     * Asserts that an id matches a schema id pattern.
     *
     * @param string $def Id definition name (sectionid, activityid...).
     * @param string $id The id.
     */
    private function assert_id(string $def, string $id): void {
        $this->assertMatchesRegularExpression('/' . $this->def($def)['pattern'] . '/', $id, "{$def}: {$id}");
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
     * The schema is the v1 contract written in JSON Schema 2020-12.
     */
    public function test_schema_header(): void {
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $this->schema['$schema']);
        $this->assertSame('local_aicoursebuilder/blueprint.v1', $this->schema['$id']);
        $this->assertSame(['version', 'course', 'sections'], $this->schema['required']);
    }

    /**
     * Root, course, sections and subsections follow the schema.
     */
    public function test_root_course_and_sections(): void {
        $this->assert_object($this->schema, $this->golden, 'root');
        $this->assertSame($this->schema['properties']['version']['const'], $this->golden['version']);
        $this->assert_object($this->def('course'), $this->golden['course'], 'course');
        $this->assertContains($this->golden['course']['format'], $this->def('course')['properties']['format']['enum']);
        $this->assertLessThanOrEqual(254, \core_text::strlen($this->golden['course']['fullname']));
        $this->assertLessThanOrEqual(100, \core_text::strlen($this->golden['course']['shortname']));

        $this->assertNotEmpty($this->golden['sections']);
        foreach ($this->golden['sections'] as $section) {
            $this->assert_object($this->def('section'), $section, $section['id']);
            $this->assert_id('sectionid', $section['id']);
            foreach ($section['objectives'] ?? [] as $objective) {
                $this->assert_object($this->def('objective'), $objective, $objective['id']);
                $this->assert_id('objectiveid', $objective['id']);
            }
            foreach ($section['subsections'] ?? [] as $subsection) {
                $this->assert_object($this->def('subsection'), $subsection, $subsection['id']);
                $this->assert_id('subsectionid', $subsection['id']);
            }
        }
    }

    /**
     * Every MVP activity type is present once or more and follows its content definition.
     */
    public function test_activities(): void {
        $types = [];
        $ids = [];
        foreach ($this->activities() as $activity) {
            $this->assert_object($this->def('activity'), $activity, $activity['id']);
            $this->assert_id('activityid', $activity['id']);
            $this->assertContains($activity['type'], $this->def('activity')['properties']['type']['enum']);
            $this->assertContains($activity['type'], self::MVP_TYPES, 'Reserved types stay out of the golden blueprint');
            $this->assert_object($this->def('content_' . $activity['type']), $activity['content'], $activity['id'] . '.content');
            if (isset($activity['completion'])) {
                $this->assert_object($this->def('completion'), $activity['completion'], $activity['id'] . '.completion');
                $this->assertContains($activity['completion']['mode'], ['none', 'manual', 'auto']);
            }
            $types[] = $activity['type'];
            $ids[] = $activity['id'];
        }
        $this->assertEqualsCanonicalizing(self::MVP_TYPES, array_values(array_unique($types)));
        $this->assertSame(count($ids), count(array_unique($ids)), 'Activity ids are unique');
        $url = array_values(array_filter($this->activities(), fn($a) => $a['type'] === 'url'))[0];
        $this->assertNotEmpty($url['source_refs'], 'URLs come only from the sources');
    }

    /**
     * Every MVP question type is present and questions follow the schema.
     */
    public function test_questions(): void {
        $objectives = [];
        foreach ($this->golden['sections'] as $section) {
            $objectives = array_merge($objectives, array_column($section['objectives'] ?? [], 'id'));
        }
        $qtypes = [];
        $ids = [];
        foreach ($this->activities() as $activity) {
            foreach ($activity['content']['questions'] ?? [] as $question) {
                $this->assert_object($this->def('question'), $question, $question['id']);
                $this->assert_id('questionid', $question['id']);
                $this->assertContains($question['qtype'], self::MVP_QTYPES);
                $this->assertContains($question['objective_ref'], $objectives);
                foreach ($question['answers'] ?? [] as $answer) {
                    $this->assert_object($this->def('answer'), $answer, $question['id'] . '.answer');
                }
                $qtypes[] = $question['qtype'];
                $ids[] = $question['id'];
            }
        }
        $this->assertEqualsCanonicalizing(self::MVP_QTYPES, $qtypes);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Question ids are unique');
    }

    /**
     * Completion and the three availability conditions are present and refer to existing activities.
     */
    public function test_availability_and_references(): void {
        $ids = array_column($this->activities(), 'id');
        $conditions = [];
        $references = $this->golden['course']['completion_activities'];
        foreach ($this->golden['course']['competencies'] as $competency) {
            $references = array_merge($references, $competency['activities'] ?? []);
        }
        foreach ($this->golden['course']['badges'] as $badge) {
            $references = array_merge($references, $badge['criteria']['activities'] ?? []);
        }

        $nodes = array_merge($this->containers(), $this->activities());
        foreach ($nodes as $node) {
            if (!isset($node['availability'])) {
                continue;
            }
            $this->assert_object($this->def('availability'), $node['availability'], $node['id'] . '.availability');
            $conditions = array_merge($conditions, array_keys($node['availability']));
            $references = array_merge($references, $node['availability']['require_completion_of'] ?? []);
            if (isset($node['availability']['min_grade'])) {
                $references[] = $node['availability']['min_grade']['activity'];
            }
            if (isset($node['availability']['date_from'])) {
                $pattern = '/' . $this->def('date')['pattern'] . '/';
                $this->assertMatchesRegularExpression($pattern, $node['availability']['date_from']);
            }
        }
        $this->assertEqualsCanonicalizing(['require_completion_of', 'min_grade', 'date_from'], array_unique($conditions));
        foreach ($references as $reference) {
            $this->assertContains($reference, $ids, "Reference to a missing activity: {$reference}");
        }
        $this->assertTrue($this->golden['course']['enablecompletion']);
    }
}
