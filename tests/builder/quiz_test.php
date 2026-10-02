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

namespace local_aicoursebuilder\builder;

use core_question\local\bank\question_bank_helper;

/**
 * Tests for the quiz builder: the module, its question bank, its questions and a safe re-run.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\quiz
 * @covers     \local_aicoursebuilder\builder\qbank_resolver
 * @covers     \local_aicoursebuilder\builder\question_importer
 * @covers     \local_aicoursebuilder\builder\build_key
 */
final class quiz_test extends \advanced_testcase {
    /** @var \stdClass The course being built. */
    private \stdClass $course;

    /** @var int Id of the job that owns the build. */
    private int $jobid;

    /**
     * Creates the course and the job every test builds into.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Completion is enabled on the course, as the course builder does from the blueprint: Moodle only
        // stores the completion rules of an activity when the course it is in tracks completion at all.
        $this->course = $this->getDataGenerator()->create_course([
            'numsections' => 3,
            'enablecompletion' => 1,
        ]);
        $this->jobid = (int) $DB->insert_record('local_aicb_job', (object) [
            'userid' => (int) $DB->get_field('user', 'id', ['username' => 'admin']),
            'prompt' => 'Test',
            'courseid' => $this->course->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the quiz node of the golden blueprint, which covers every qtype the generator writes.
     *
     * @return array The blueprint node.
     */
    private function golden_quiz_node(): array {
        $blueprint = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($blueprint['sections'] as $section) {
            foreach ($section['activities'] ?? [] as $activity) {
                if ($activity['type'] === 'quiz') {
                    return $activity;
                }
            }
        }
        throw new \coding_exception('The golden blueprint has no quiz node.');
    }

    /**
     * Returns a build context for the course, with the section map a section builder would have left.
     *
     * @return build_context
     */
    private function make_context(): build_context {
        global $DB;

        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid]);
        $context = build_context::from_job($job, $this->course);
        $context->record(new build_result('s1', build_result::STATUS_CREATED, sectionnum: 1));
        return $context;
    }

    /**
     * Returns the questions of a quiz, in slot order, through the reference table.
     *
     * quiz_slots has no questionid in Moodle 5: a slot points at a question bank entry through
     * question_references, and the entry points at the versions of the question.
     *
     * @param int $quizid Quiz instance id.
     * @param int $cmid Course module id of the quiz.
     * @return \stdClass[] Rows of slot, page, maxmark, qtype, name, questionid.
     */
    private function slots_with_questions(int $quizid, int $cmid): array {
        global $DB;

        $sql = "SELECT slot.slot, slot.page, slot.maxmark, q.id AS questionid, q.qtype, q.name
                  FROM {quiz_slots} slot
                  JOIN {question_references} qr
                    ON qr.itemid = slot.id AND qr.component = :component AND qr.questionarea = :area
                   AND qr.usingcontextid = :contextid
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE slot.quizid = :quizid
              ORDER BY slot.slot";
        return array_values($DB->get_records_sql($sql, [
            'component' => 'mod_quiz',
            'area' => 'slot',
            'contextid' => \context_module::instance($cmid)->id,
            'quizid' => $quizid,
        ]));
    }

    /**
     * Returns the questions sitting in the subcategory of a node, whatever their version status.
     *
     * @param string $key Idnumber of the subcategory, from build_key.
     * @return \stdClass[] Rows of questionid, name, qtype, status.
     */
    private function questions_in_category(string $key): array {
        global $DB;

        $sql = "SELECT q.id AS questionid, q.name, q.qtype, qv.status
                  FROM {question_categories} qc
                  JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qc.idnumber = :idnumber
              ORDER BY q.id";
        return array_values($DB->get_records_sql($sql, ['idnumber' => $key]));
    }

    /**
     * The quiz is created with every field quiz_add_instance() reads, and reloads without a warning.
     */
    public function test_quiz_is_created_from_the_blueprint(): void {
        global $DB;

        $node = $this->golden_quiz_node();
        $context = $this->make_context();
        $result = (new quiz())->build($node, $context);

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertNotNull($result->cmid);
        $this->assertSame(1, $result->sectionnum);

        $quiz = $DB->get_record('quiz', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('Test: noțiuni de bază', $quiz->name);
        $this->assertEquals(10, $quiz->grade);
        $this->assertSame(2, (int) $quiz->attempts);
        $this->assertSame(20 * 60, (int) $quiz->timelimit, 'timelimit_minutes is stored in seconds');
        $this->assertSame(1, (int) $quiz->questionsperpage);
        // A field quiz_process_options() needs but the blueprint never carries.
        $this->assertSame('', $quiz->password);
        $this->assertGreaterThan(0, (int) $quiz->reviewattempt, 'The review options come from the site defaults');

        // Reloading through modinfo is what catches a field the module needed and did not get.
        $modinfo = get_fast_modinfo($this->course);
        $cm = $modinfo->get_cm($result->cmid);
        $this->assertSame('quiz', $cm->modname);
        $this->assertSame('Test: noțiuni de bază', $cm->name);
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, (int) $cm->completion);
        $this->assertSame(build_key::for_node($this->jobid, 's1.quiz1'), $cm->idnumber);

        // The blueprint asked for a pass grade, which the gradebook has to know about.
        $gradeitem = $DB->get_record('grade_items', [
            'itemmodule' => 'quiz',
            'iteminstance' => $result->instanceid,
        ], '*', MUST_EXIST);
        $this->assertEquals(5, $gradeitem->gradepass);
    }

    /**
     * Every question type of the blueprint is imported, in order, with its slots and its total.
     */
    public function test_every_qtype_is_imported(): void {
        global $DB;

        $node = $this->golden_quiz_node();
        $context = $this->make_context();
        $result = (new quiz())->build($node, $context);

        $slots = $this->slots_with_questions((int) $result->instanceid, (int) $result->cmid);
        $this->assertCount(8, $slots);
        $this->assertSame(
            ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay', 'gapselect', 'ddwtos'],
            array_column($slots, 'qtype'),
            'The questions keep the order of the blueprint'
        );
        $this->assertSame(
            ['Sursă regenerabilă', 'Soarele se epuizează', 'Termenul pentru panouri', 'Număr de surse',
                'Sursă și echipament', 'Avantaje', 'Completați sursa', 'Trageți termenul'],
            array_column($slots, 'name')
        );

        // The blueprint sets questionsperpage to 1, so every question is on a page of its own.
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_map('intval', array_column($slots, 'page')));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_map('intval', array_column($slots, 'slot')));

        // Every question is worth its default mark of 1, so the quiz totals 8.
        $quiz = $DB->get_record('quiz', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(8, $quiz->sumgrades);

        $this->assert_question_details($slots);
    }

    /**
     * Checks the answers of the imported questions, type by type.
     *
     * @param \stdClass[] $slots Slots with their questions, in order.
     */
    private function assert_question_details(array $slots): void {
        global $DB;

        $byqtype = array_column($slots, 'questionid', 'qtype');

        // One right answer out of three. A single answer multichoice is a class of its own, which is
        // how the imported question says that <single> was 1.
        $multichoice = \question_bank::load_question($byqtype['multichoice']);
        $this->assertInstanceOf(\qtype_multichoice_single_question::class, $multichoice);
        $this->assertCount(3, $multichoice->answers);
        $fractions = array_map(fn($a): float => (float) $a->fraction, array_values($multichoice->answers));
        $this->assertEqualsWithDelta(1.0, max($fractions), 0.0001, 'A fraction of 1 survives the percentage in XML');
        $this->assertEqualsWithDelta(0.0, min($fractions), 0.0001);

        // True is the right answer of the true/false question.
        $truefalse = \question_bank::load_question($byqtype['truefalse']);
        $this->assertTrue((bool) $truefalse->rightanswer);

        // Both spellings are accepted by the short answer question.
        $shortanswer = \question_bank::load_question($byqtype['shortanswer']);
        $this->assertSame(
            ['fotovoltaic', 'fotovoltaică'],
            array_map(fn($a): string => $a->answer, array_values($shortanswer->answers))
        );

        // The tolerance of the numerical answer is kept.
        $numerical = \question_bank::load_question($byqtype['numerical']);
        $answer = reset($numerical->answers);
        $this->assertEquals(5, $answer->answer);
        $this->assertEqualsWithDelta(0.0, (float) $answer->tolerance, 0.0001);

        // Three pairs in the matching question.
        $match = \question_bank::load_question($byqtype['match']);
        $this->assertCount(3, $match->stems);
        $this->assertContains('Panou fotovoltaic', $match->choices);

        // The response format and the grader information of the essay.
        $essay = \question_bank::load_question($byqtype['essay']);
        $this->assertSame('editor', $essay->responseformat);
        $this->assertStringContainsString('emisii reduse', $essay->graderinfo);

        // The choices of gapselect and ddwtos, in the order the gaps refer to.
        $gapselect = \question_bank::load_question($byqtype['gapselect']);
        $this->assertSame(
            ['eoliană', 'solară', 'nucleară'],
            array_map(fn($c): string => $c->text, array_values($gapselect->choices[1]))
        );
        $ddwtos = \question_bank::load_question($byqtype['ddwtos']);
        $this->assertSame(
            ['biomasă', 'geotermie'],
            array_map(fn($c): string => $c->text, array_values($ddwtos->choices[1]))
        );

        // The questions really are in the subcategory of this node, not in the default one.
        $key = build_key::for_node($this->jobid, 's1.quiz1');
        $this->assertCount(8, $this->questions_in_category($key));
        $category = $DB->get_record('question_categories', ['idnumber' => $key], '*', MUST_EXIST);
        $this->assertStringContainsString('Test: noțiuni de bază', $category->name);
    }

    /**
     * A multichoice question with several right answers is imported as a multiple answer question.
     */
    public function test_multichoice_with_several_answers(): void {
        $node = $this->golden_quiz_node();
        $node['content']['questions'] = [[
            'id' => 'q1',
            'qtype' => 'multichoice',
            'name' => 'Mai multe surse',
            'questiontext' => '<p>Care sunt regenerabile?</p>',
            'single' => false,
            'answers' => [
                ['text' => 'Vântul', 'fraction' => 0.5],
                ['text' => 'Soarele', 'fraction' => 0.5],
                ['text' => 'Cărbunele', 'fraction' => -1],
            ],
        ]];
        $context = $this->make_context();
        $result = (new quiz())->build($node, $context);

        $slots = $this->slots_with_questions((int) $result->instanceid, (int) $result->cmid);
        $this->assertCount(1, $slots);
        $question = \question_bank::load_question($slots[0]->questionid);
        $this->assertInstanceOf(\qtype_multichoice_multi_question::class, $question);
        $fractions = array_map(fn($a): float => (float) $a->fraction, array_values($question->answers));
        sort($fractions);
        $this->assertEqualsWithDelta([-1.0, 0.5, 0.5], $fractions, 0.0001);
    }

    /**
     * Questions go on a new page every questionsperpage questions.
     */
    public function test_pagination_follows_questionsperpage(): void {
        $node = $this->golden_quiz_node();
        $node['content']['questionsperpage'] = 3;
        $context = $this->make_context();
        $result = (new quiz())->build($node, $context);

        $slots = $this->slots_with_questions((int) $result->instanceid, (int) $result->cmid);
        $this->assertSame([1, 1, 1, 2, 2, 2, 3, 3], array_map('intval', array_column($slots, 'page')));
    }

    /**
     * The questions go into the default question bank of the course, which the context keeps.
     */
    public function test_default_question_bank_is_used(): void {
        $node = $this->golden_quiz_node();
        $context = $this->make_context();
        (new quiz())->build($node, $context);

        $bankcontext = $context->get_qbankcontext();
        $this->assertInstanceOf(\context_module::class, $bankcontext);

        $defaultbank = question_bank_helper::get_default_open_instance_system_type($this->course, false);
        $this->assertNotNull($defaultbank, 'The default bank of the course exists');
        $this->assertSame(
            \context_module::instance($defaultbank->id)->id,
            $bankcontext->id,
            'The questions went into the default bank, not into a bank of our own'
        );
    }

    /**
     * A second quiz of the same build shares the question bank of the first.
     */
    public function test_second_quiz_shares_the_bank(): void {
        $node = $this->golden_quiz_node();
        $context = $this->make_context();
        $builder = new quiz();
        $builder->build($node, $context);
        $bankcontextid = $context->get_qbankcontext()->id;

        $second = $node;
        $second['id'] = 's1.quiz2';
        $second['name'] = 'Al doilea test';
        $result = $builder->build($second, $context);

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame($bankcontextid, $context->get_qbankcontext()->id);
        // Each quiz has a subcategory of its own, so one never empties the other.
        $this->assertCount(8, $this->questions_in_category(build_key::for_node($this->jobid, 's1.quiz1')));
        $this->assertCount(8, $this->questions_in_category(build_key::for_node($this->jobid, 's1.quiz2')));
    }

    /**
     * Rebuilding one quiz leaves the questions of the other quizzes of the same bank alone.
     */
    public function test_rebuilding_one_quiz_spares_the_others(): void {
        $node = $this->golden_quiz_node();
        $context = $this->make_context();
        $builder = new quiz();
        $builder->build($node, $context);

        $second = $node;
        $second['id'] = 's1.quiz2';
        $second['name'] = 'Al doilea test';
        $secondresult = $builder->build($second, $context);
        $secondkey = build_key::for_node($this->jobid, 's1.quiz2');
        $untouched = array_column($this->questions_in_category($secondkey), 'questionid');

        // The first quiz is built again, as a resumed run would: its own questions are replaced.
        $firstkey = build_key::for_node($this->jobid, 's1.quiz1');
        $before = array_column($this->questions_in_category($firstkey), 'questionid');
        $rebuilt = (new quiz())->build($node, $context);

        $this->assertSame(build_result::STATUS_CREATED, $rebuilt->status);
        $after = array_column($this->questions_in_category($firstkey), 'questionid');
        $this->assertCount(8, $after);
        $this->assertSame([], array_intersect($before, $after), 'The first quiz got fresh questions');

        // The second quiz kept its questions, its slots and its module.
        $this->assertSame($untouched, array_column($this->questions_in_category($secondkey), 'questionid'));
        $this->assertCount(8, $this->slots_with_questions((int) $secondresult->instanceid, (int) $secondresult->cmid));
        $this->assertSame(2, $this->quiz_count());
    }

    /**
     * While the site is still migrating its questions, a bank of our own is used and the admin is warned.
     */
    public function test_incomplete_migration_uses_our_own_bank(): void {
        // The migration is judged incomplete while its ad-hoc task is still queued.
        $task = new \mod_qbank\task\transfer_question_categories();
        \core\task\manager::queue_adhoc_task($task);
        $this->assertFalse(question_bank_helper::has_bank_migration_task_completed_successfully());

        $node = $this->golden_quiz_node();
        $context = $this->make_context();
        $result = (new quiz())->build($node, $context);

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertNotEmpty($result->warnings, 'The administrator is warned');
        $this->assertSame(
            [get_string('buildwarnqbankmigration', 'local_aicoursebuilder')],
            $result->warnings
        );

        $bankcontext = $context->get_qbankcontext();
        $this->assertInstanceOf(\context_module::class, $bankcontext);
        $bankcm = get_coursemodule_from_id('qbank', $bankcontext->instanceid, 0, false, MUST_EXIST);
        $this->assertSame(
            get_string('qbankname', 'local_aicoursebuilder', $this->course->shortname),
            $bankcm->name,
            'The bank is the one this plugin creates, not the default one of the course'
        );
        // The questions are still all there, in our bank.
        $this->assertCount(8, $this->questions_in_category(build_key::for_node($this->jobid, 's1.quiz1')));
    }

    /**
     * The build task registers this builder for the quiz type, so a real build uses it.
     */
    public function test_the_build_task_registers_the_builder(): void {
        $task = new class extends \local_aicoursebuilder\task\build_course {
            /**
             * Exposes the registry the task builds with.
             *
             * @return \local_aicoursebuilder\builder\builder_registry
             */
            public function expose_registry(): builder_registry {
                return $this->registry();
            }
        };

        $registry = $task->expose_registry();
        $this->assertTrue($registry->has('quiz'));
        $this->assertInstanceOf(quiz::class, $registry->get('quiz'));
    }

    /**
     * A node already in the build map is not built again.
     */
    public function test_a_built_node_is_skipped(): void {
        $node = $this->golden_quiz_node();
        $context = $this->make_context();
        $context->record(new build_result('s1.quiz1', build_result::STATUS_CREATED, 42, 43, 1));

        $result = (new quiz())->build($node, $context);

        $this->assertSame(build_result::STATUS_SKIPPED, $result->status);
        $this->assertSame(0, $this->quiz_count(), 'Nothing was created');
    }

    /**
     * A run interrupted after the module was created, and after some questions were imported, leaves
     * neither a second quiz nor a duplicated or hidden question behind.
     */
    public function test_rerun_after_a_partial_build_leaves_no_duplicates(): void {
        global $DB;

        $node = $this->golden_quiz_node();
        $key = build_key::for_node($this->jobid, 's1.quiz1');

        // First run: the module is created and the questions are imported, then it dies before the
        // slots are added and before the build map is written. That is the worst case: the quiz exists,
        // the questions exist, and nothing has been checkpointed.
        $failing = new class extends quiz {
            /**
             * Imports the questions, then fails the way an interrupted run does.
             *
             * @param int[] $questionids Ids of the imported questions.
             * @param \stdClass $quiz The quiz record.
             */
            #[\Override]
            protected function add_questions(array $questionids, \stdClass $quiz): void {
                // Half of the questions make it into the quiz, then the run dies.
                parent::add_questions(array_slice($questionids, 0, 4), $quiz);
                throw new \moodle_exception('error');
            }
        };
        $context = $this->make_context();
        try {
            $failing->build($node, $context);
            $this->fail('The first run was supposed to fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        $this->assertSame(1, $this->quiz_count(), 'The first run left a quiz behind');
        $this->assertCount(8, $this->questions_in_category($key), 'and its questions');
        $this->assertFalse($context->is_built('s1.quiz1'), 'but nothing was checkpointed');

        // Second run: it finds the orphan by its idnumber, removes it with its questions, and rebuilds.
        $result = (new quiz())->build($node, $context);
        $this->assertSame(build_result::STATUS_CREATED, $result->status);

        $this->assertSame(1, $this->quiz_count(), 'Exactly one quiz carries the key of this node');
        $quizzes = $DB->get_records('course_modules', ['course' => $this->course->id, 'idnumber' => $key]);
        $this->assertCount(1, $quizzes);
        $this->assertSame($result->cmid, (int) reset($quizzes)->id);

        // Exactly the questions of the blueprint, once each, and none of them a hidden leftover.
        $questions = $this->questions_in_category($key);
        $this->assertCount(8, $questions);
        $this->assertSame(
            ['ready'],
            array_values(array_unique(array_column($questions, 'status'))),
            'No question of the first run survived as a hidden version'
        );

        $slots = $this->slots_with_questions((int) $result->instanceid, (int) $result->cmid);
        $this->assertCount(8, $slots);
        $this->assertSame(
            array_column($questions, 'questionid'),
            array_values(array_unique(array_column($slots, 'questionid'))),
            'The slots point at the questions of the second run'
        );
        $quiz = $DB->get_record('quiz', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(8, $quiz->sumgrades);
    }

    /**
     * Returns the number of quiz modules in the course.
     *
     * @return int
     */
    private function quiz_count(): int {
        global $DB;

        return $DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = ? AND m.name = ?",
            [$this->course->id, 'quiz']
        );
    }
}
