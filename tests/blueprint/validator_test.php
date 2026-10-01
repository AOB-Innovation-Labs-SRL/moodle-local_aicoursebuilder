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
 * Tests of the validator: the schema layer, the rules a schema cannot express, and the step
 * fragments. Every rule is tested by breaking a blueprint that was valid a line earlier.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\blueprint\validator
 * @covers     \local_aicoursebuilder\blueprint\validation_error
 * @covers     \local_aicoursebuilder\blueprint\schema_store
 */
final class validator_test extends \basic_testcase {
    /** @var array Decoded golden blueprint, the starting point of every mutation. */
    private array $golden;

    /** @var string[] Source text per source id. */
    private array $sourcetexts;

    /** @var validator The validator under test. */
    private validator $validator;

    /**
     * Loads the golden blueprint and the sources its URLs come from.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->validator = new validator();
        $this->golden = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/blueprint_golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $urls = [];
        foreach ($this->golden['sections'] as $section) {
            foreach ($section['activities'] as $activity) {
                if (isset($activity['content']['externalurl'])) {
                    $urls[] = $activity['content']['externalurl'];
                }
            }
        }
        $this->sourcetexts = [
            'src1' => 'Material sursă. ' . implode(' ', $urls),
            'src2' => 'Al doilea material.',
            'src3' => 'Al treilea material.',
        ];
    }

    /**
     * Returns the codes the validator reports for a blueprint.
     *
     * @param array|null $blueprint The blueprint.
     * @return string[]
     */
    private function codes(?array $blueprint): array {
        return array_values(array_unique(array_map(
            fn(validation_error $error) => $error->code,
            $this->validator->validate($blueprint, $this->sourcetexts),
        )));
    }

    /**
     * Returns the index of the first activity of a type in section 0.
     *
     * @param string $type Activity type.
     * @return int
     */
    private function index_of(string $type): int {
        foreach ($this->golden['sections'][0]['activities'] as $index => $activity) {
            if ($activity['type'] === $type) {
                return $index;
            }
        }
        $this->fail("The golden blueprint has no {$type} activity in its first section");
    }

    /**
     * A valid blueprint reports nothing at all.
     */
    public function test_a_valid_blueprint_has_no_errors(): void {
        $this->assertSame([], $this->validator->validate($this->golden, $this->sourcetexts));
    }

    /**
     * Output that is not JSON is reported as such, not as a pile of schema errors.
     */
    public function test_output_that_is_not_json(): void {
        $errors = $this->validator->validate(null);

        $this->assertCount(1, $errors);
        $this->assertSame(validation_error::CODE_NOT_JSON, $errors[0]->code);
        $this->assertSame('', $errors[0]->path);
    }

    /**
     * The schema layer reports the JSON Pointer of what it rejected.
     */
    public function test_the_schema_layer_reports_a_json_pointer(): void {
        $blueprint = $this->golden;
        $blueprint['sections'][0]['id'] = 'X1';

        $errors = $this->validator->validate($blueprint, $this->sourcetexts);
        $paths = array_map(fn(validation_error $error) => $error->path, $errors);

        $this->assertContains('/sections/0/id', $paths);
        $this->assertContains(validation_error::CODE_SCHEMA, array_column(
            array_map(fn(validation_error $error) => $error->to_array(), $errors),
            'code',
        ));
    }

    /**
     * An id used twice is reported once per repeat, and never for the first use.
     */
    public function test_duplicate_ids(): void {
        $blueprint = $this->golden;
        $blueprint['sections'][1]['id'] = 's1';

        $errors = $this->validator->validate($blueprint, $this->sourcetexts);
        $duplicates = array_values(array_filter(
            $errors,
            fn(validation_error $error) => $error->code === validation_error::CODE_DUPLICATE_ID,
        ));

        $this->assertCount(1, $duplicates, 'the repeat is reported, the first use is not');
        $this->assertSame('/sections/1/id', $duplicates[0]->path);
    }

    /**
     * Every kind of reference is checked against the ids that were actually declared.
     *
     * @dataProvider broken_reference_provider
     * @param string $path Dotted path of the field to break.
     * @param string $value The broken value.
     */
    public function test_broken_references(string $path, string $value): void {
        $blueprint = $this->golden;
        $keys = explode('.', $path);
        $target = &$blueprint;
        foreach ($keys as $key) {
            $target = &$target[is_numeric($key) ? (int) $key : $key];
        }
        $target = $value;
        unset($target);

        $this->assertContains(validation_error::CODE_BROKEN_REF, $this->codes($blueprint), "breaking {$path}");
    }

    /**
     * The references a blueprint carries, one per kind.
     *
     * @return array[]
     */
    public static function broken_reference_provider(): array {
        return [
            'course completion' => ['course.completion_activities.0', 's9.nope1'],
            'competency activity' => ['course.competencies.0.activities.0', 's9.nope1'],
            'badge criteria' => ['course.badges.1.criteria.activities.0', 's9.nope1'],
            'availability completion' => ['sections.1.availability.require_completion_of.0', 's9.nope1'],
            'section source ref' => ['sections.0.source_refs.0.source', 'src99'],
        ];
    }

    /**
     * A name longer than Moodle stores is reported in characters, not bytes.
     */
    public function test_lengths_are_measured_in_characters(): void {
        $blueprint = $this->golden;
        // 200 Romanian characters: 400 bytes, well under any byte limit, but a valid title.
        $blueprint['sections'][0]['title'] = str_repeat('ă', 200);
        $this->assertNotContains(validation_error::CODE_MAX_LENGTH, $this->codes($blueprint));

        $blueprint['sections'][0]['title'] = str_repeat('ă', 256);
        $this->assertContains(validation_error::CODE_MAX_LENGTH, $this->codes($blueprint));
    }

    /**
     * A URL that is in no source document is reported, because models invent them.
     */
    public function test_a_url_must_come_from_a_source(): void {
        $blueprint = $this->golden;
        $index = $this->index_of('url');
        $blueprint['sections'][0]['activities'][$index]['content']['externalurl'] = 'https://inventat.example/pagina';

        $errors = $this->validator->validate($blueprint, $this->sourcetexts);
        $urlerrors = array_values(array_filter(
            $errors,
            fn(validation_error $error) => $error->code === validation_error::CODE_URL_NOT_IN_SOURCES,
        ));

        $this->assertCount(1, $urlerrors);
        $this->assertStringContainsString('externalurl', $urlerrors[0]->path);
    }

    /**
     * Choice, Feedback and Lesson reject options, types and jumps Moodle cannot use.
     */
    public function test_interactive_activity_limits(): void {
        $blueprint = $this->golden;
        $blueprint['sections'][2]['activities'][0]['content']['options'] = ['One'];
        $this->assertContains(validation_error::CODE_SCHEMA, $this->codes($blueprint));

        $blueprint = $this->golden;
        $blueprint['sections'][2]['activities'][1]['content']['items'][0]['type'] = 'survey';
        $this->assertContains(validation_error::CODE_SCHEMA, $this->codes($blueprint));

        $blueprint = $this->golden;
        $blueprint['sections'][2]['activities'][1]['content']['items'][] = [
            'type' => 'numeric', 'name' => 'Scor', 'min' => 10, 'max' => 1,
        ];
        $this->assertContains(validation_error::CODE_MOODLE_LIMIT, $this->codes($blueprint));

        $blueprint = $this->golden;
        $blueprint['sections'][1]['activities'][0]['content']['pages'][0]['answers'][0]['jumpto'] = 'p99';
        $this->assertContains(validation_error::CODE_BROKEN_REF, $this->codes($blueprint));
    }

    /**
     * Gap questions reject missing, repeated and out-of-range choice markers.
     */
    public function test_gap_markers_must_match_choices(): void {
        $blueprint = $this->golden;
        $quiz = $this->index_of('quiz');
        foreach ($blueprint['sections'][0]['activities'][$quiz]['content']['questions'] as $index => $question) {
            if (($question['qtype'] ?? '') === 'gapselect') {
                $blueprint['sections'][0]['activities'][$quiz]['content']['questions'][$index]['questiontext'] =
                    '<p>[[1]] și [[1]]</p>';
                $this->assertContains(validation_error::CODE_MOODLE_LIMIT, $this->codes($blueprint));
                $blueprint['sections'][0]['activities'][$quiz]['content']['questions'][$index]['questiontext'] =
                    '<p>[[99]]</p>';
                $this->assertContains(validation_error::CODE_MOODLE_LIMIT, $this->codes($blueprint));
                return;
            }
        }
        $this->fail('Golden fixture has no gapselect question');
    }

    /**
     * A reference is broken even when the blueprint declares no activities at all.
     *
     * This is what a section that could not be written leaves behind, so it is the case where a
     * dangling reference is most likely and least excusable.
     */
    public function test_a_reference_into_an_empty_blueprint_is_broken(): void {
        $blueprint = [
            'version' => '1.0',
            'course' => [
                'fullname' => 'Curs', 'shortname' => 'C1', 'summary' => '<p>x</p>', 'format' => 'topics',
            ],
            'sections' => [[
                'id' => 's1',
                'title' => 'Secțiune',
                'activities' => [],
                'availability' => ['require_completion_of' => ['s9.nope1']],
            ]],
        ];

        $errors = (new validator())->validate($blueprint);
        $codes = array_map(fn(validation_error $error) => $error->code, $errors);

        $this->assertContains(validation_error::CODE_BROKEN_REF, $codes);
    }

    /**
     * Source ids are only checked when sources were given, because they are context, not content.
     */
    public function test_source_ids_are_only_checked_against_sources_that_were_given(): void {
        $blueprint = $this->golden;

        $codes = array_map(fn(validation_error $error) => $error->code, (new validator())->validate($blueprint));
        $this->assertNotContains(validation_error::CODE_BROKEN_REF, $codes, 'with no sources, nothing to check');

        $codes = $this->codes($blueprint);
        $this->assertNotContains(validation_error::CODE_BROKEN_REF, $codes, 'with the right sources, all resolve');

        $codes = array_map(
            fn(validation_error $error) => $error->code,
            (new validator())->validate($blueprint, ['src9' => 'o sursă care nu e citată nicăieri']),
        );
        $this->assertContains(validation_error::CODE_BROKEN_REF, $codes, 'with the wrong sources, they do not');
    }

    /**
     * With no sources given, the URL rule does not run: there is nothing to check against.
     */
    public function test_the_url_rule_needs_sources(): void {
        $blueprint = $this->golden;
        $index = $this->index_of('url');
        $blueprint['sections'][0]['activities'][$index]['content']['externalurl'] = 'https://inventat.example/pagina';

        $codes = array_map(fn(validation_error $error) => $error->code, $this->validator->validate($blueprint));

        $this->assertNotContains(validation_error::CODE_URL_NOT_IN_SOURCES, $codes);
    }

    /**
     * The fraction rules are the ones the question type edit forms enforce.
     *
     * @dataProvider fraction_provider
     * @param string $qtype Question type.
     * @param float[] $fractions Fraction of each answer.
     * @param array $extra Extra fields of the question, such as single.
     * @param bool $valid Whether Moodle would accept the question.
     */
    public function test_fraction_rules(string $qtype, array $fractions, array $extra, bool $valid): void {
        $answers = array_map(fn(float $fraction) => ['text' => 'a', 'fraction' => $fraction], $fractions);
        $blueprint = [
            'version' => '1.0',
            'course' => [
                'fullname' => 'Curs', 'shortname' => 'C1', 'summary' => '<p>x</p>', 'format' => 'topics',
            ],
            'sections' => [[
                'id' => 's1',
                'title' => 'Secțiune',
                'activities' => [[
                    'id' => 's1.quiz1',
                    'type' => 'quiz',
                    'name' => 'Test',
                    'content' => ['questions' => [$extra + [
                        'qtype' => $qtype,
                        'name' => 'Întrebare',
                        'questiontext' => '<p>Text</p>',
                        'answers' => $answers,
                    ]]],
                ]],
            ]],
        ];

        $codes = array_map(
            fn(validation_error $error) => $error->code,
            (new validator())->validate($blueprint),
        );

        if ($valid) {
            $this->assertNotContains(validation_error::CODE_FRACTION_SUM, $codes);
        } else {
            $this->assertContains(validation_error::CODE_FRACTION_SUM, $codes);
        }
    }

    /**
     * The fraction cases, taken from what the Moodle question forms accept.
     *
     * @return array[]
     */
    public static function fraction_provider(): array {
        return [
            'single multichoice with one right answer' => ['multichoice', [1.0, 0.0, 0.0], ['single' => true], true],
            'single multichoice with no full mark' => ['multichoice', [0.5, 0.5, 0.0], ['single' => true], false],
            'multiple multichoice adding to one' => ['multichoice', [0.5, 0.5, -1.0], ['single' => false], true],
            'multiple multichoice adding to more' => ['multichoice', [0.5, 1.0, -1.0], ['single' => false], false],
            'shortanswer with two spellings' => ['shortanswer', [1.0, 1.0, 0.5], [], true],
            'shortanswer with no full mark' => ['shortanswer', [0.8, 0.5], [], false],
            'truefalse with one right answer' => ['truefalse', [1.0, 0.0], [], true],
            'truefalse with both right' => ['truefalse', [1.0, 1.0], [], false],
        ];
    }

    /**
     * A step fragment is checked against its own schema, not the whole blueprint.
     */
    public function test_step_fragments(): void {
        $root = dirname(__DIR__, 2) . '/tests/fixtures/ai';
        $brief = json_decode(file_get_contents($root . '/brief.json'), true, 512, JSON_THROW_ON_ERROR);
        $outline = json_decode(file_get_contents($root . '/outline.json'), true, 512, JSON_THROW_ON_ERROR);
        $section = json_decode(file_get_contents($root . '/sections/s1.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $this->validator->validate_step('brief', $brief));
        $this->assertSame([], $this->validator->validate_step('outline', $outline));
        $this->assertSame([], $this->validator->validate_step('sections', $section, $this->sourcetexts));
    }

    /**
     * An outline that already carries activities is rejected: they belong to the next step.
     */
    public function test_an_outline_cannot_carry_activities(): void {
        $outline = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/ai/outline.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $outline['sections'][0]['activities'] = [];

        $this->assertNotSame([], $this->validator->validate_step('outline', $outline));
    }

    /**
     * A sections fragment may only hold the types this step writes.
     */
    public function test_a_sections_fragment_rejects_a_later_type(): void {
        $section = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/ai/sections/s1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $section['activities'][1]['type'] = 'quiz';

        $this->assertNotSame([], $this->validator->validate_step('sections', $section, $this->sourcetexts));
    }

    /**
     * A step with no schema of its own is a coding error, not a validation failure.
     */
    public function test_an_unknown_step_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        $this->validator->validate_step('unknown_step', ['sections' => []]);
    }

    /**
     * An error carries the path, the code and the message, and a list of them encodes for a prompt.
     */
    public function test_errors_encode_for_the_repair_prompt(): void {
        $blueprint = $this->golden;
        $blueprint['sections'][0]['id'] = 'X1';

        $errors = $this->validator->validate($blueprint, $this->sourcetexts);
        $decoded = json_decode(validation_error::list_to_json($errors), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($decoded);
        $this->assertSame(['path', 'code', 'message'], array_keys($decoded[0]));
        $this->assertContains($decoded[0]['code'], validation_error::CODES);
    }
}
