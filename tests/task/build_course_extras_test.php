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

namespace local_aicoursebuilder\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/badgeslib.php');

/**
 * Tests of what build_course adds once every module exists, on the golden blueprint: the completion of the activities
 * and of the course, the access restrictions, the competencies and the badges.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\task\build_course
 * @covers     \local_aicoursebuilder\builder\availability_mapper
 * @covers     \local_aicoursebuilder\builder\competency_builder
 * @covers     \local_aicoursebuilder\builder\badge_builder
 * @covers     \local_aicoursebuilder\builder\build_plan
 */
final class build_course_extras_test extends \advanced_testcase {
    /** @var \stdClass Owner of the job. */
    private \stdClass $user;

    /** @var int Job id. */
    private int $jobid;

    /** @var string What the last build wrote with mtrace(), which is where its warnings are. */
    private string $output = '';

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        set_config('enablebadges', 1);
        set_config('enabled', 1, 'core_competency');

        $category = $this->getDataGenerator()->create_category();
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        $this->getDataGenerator()->role_assign('manager', $this->user->id, \context_coursecat::instance($category->id)->id);
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'mode' => 'newcourse',
            'categoryid' => $category->id,
            'status' => 'approved',
            'prompt' => 'Test',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $sources = [];
        foreach (['src1' => 'manual.pdf', 'src2' => 'anexa.docx'] as $key => $filename) {
            $id = $DB->insert_record('local_aicb_source', (object) [
                'jobid' => $this->jobid,
                'filename' => $filename,
                'mimetype' => 'application/octet-stream',
                'filesize' => 10,
                'contenthash' => sha1($filename),
                'status' => 'digested',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            get_file_storage()->create_file_from_string([
                'contextid' => \context_system::instance()->id,
                'component' => 'local_aicoursebuilder',
                'filearea' => 'source',
                'itemid' => $id,
                'filepath' => '/',
                'filename' => $filename,
            ], 'content of ' . $filename);
            $sources['"' . $key . '"'] = '"src' . $id . '"';
        }
        $content = strtr(file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json'), $sources);
        $blueprintid = $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $this->jobid,
            'version' => 1,
            'schemaversion' => '1.0',
            'content' => $content,
            'contenthash' => hash('sha256', $content),
            'status' => 'approved',
            'usermodified' => $this->user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field('local_aicb_job', 'blueprintid', $blueprintid, ['id' => $this->jobid]);
    }

    /**
     * Runs the build of the job as cron does.
     *
     * @return array [\stdClass $job, array $map]: the job, and its build map with the course module of each node.
     */
    private function run_build(): array {
        global $DB;
        \core\task\manager::queue_adhoc_task(build_course::instance($this->jobid, (int) $this->user->id));
        ob_start();
        try {
            $this->runAdhocTasks(build_course::class);
        } finally {
            $this->output = (string) ob_get_clean();
        }
        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
        return [$job, json_decode((string) $job->buildmap, true)];
    }

    /**
     * Changes the text of the approved blueprint of the job.
     *
     * @param string[] $replacements Text to find => text to put.
     */
    private function edit_blueprint(array $replacements): void {
        global $DB;
        $blueprint = $DB->get_record('local_aicb_blueprint', ['jobid' => $this->jobid], '*', MUST_EXIST);
        $DB->set_field('local_aicb_blueprint', 'content', strtr($blueprint->content, $replacements), ['id' => $blueprint->id]);
    }

    /**
     * Creates a competency framework with competencies of the given idnumbers and chooses it in the settings.
     *
     * @param string[] $idnumbers Idnumbers of the competencies.
     * @return int[] Competency id by idnumber.
     */
    private function choose_framework(array $idnumbers): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        set_config('competencyframework', $framework->get('id'), 'local_aicoursebuilder');
        $ids = [];
        foreach ($idnumbers as $idnumber) {
            $ids[$idnumber] = (int) $generator->create_competency([
                'competencyframeworkid' => $framework->get('id'),
                'idnumber' => $idnumber,
            ])->get('id');
        }
        return $ids;
    }

    /**
     * The completion of the activities is on the course modules, as the blueprint says: tracking, view, grade, the
     * grade the learner has to pass and the rules of the module.
     */
    public function test_the_completion_of_the_activities(): void {
        global $DB;
        [, $map] = $this->run_build();

        $quiz = $DB->get_record('course_modules', ['id' => $map['s1.quiz1']['cmid']], '*', MUST_EXIST);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $quiz->completion);
        $this->assertEquals(0, $quiz->completiongradeitemnumber, 'A grade is the completion of the quiz');
        $this->assertEquals(1, $quiz->completionpassgrade);

        $page = $DB->get_record('course_modules', ['id' => $map['s1.page1']['cmid']], '*', MUST_EXIST);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $page->completion);
        $this->assertEquals(COMPLETION_VIEW_REQUIRED, $page->completionview);
        $this->assertNull($page->completiongradeitemnumber);

        $manual = $DB->get_record('course_modules', ['id' => $map['s1.url1']['cmid']], '*', MUST_EXIST);
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $manual->completion);

        $forum = $DB->get_record('course_modules', ['id' => $map['s2.forum1']['cmid']], '*', MUST_EXIST);
        $this->assertEquals(1, $DB->get_field('forum', 'completionposts', ['id' => $forum->instance]));
    }

    /**
     * Course completion has the activities the blueprint names as criteria, all of them required.
     */
    public function test_course_completion_criteria(): void {
        global $DB;
        [$job, $map] = $this->run_build();

        $criteria = $DB->get_records('course_completion_criteria', [
            'course' => $job->courseid,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
        ]);
        $cmids = array_map(fn($row) => (int) $row->moduleinstance, $criteria);
        sort($cmids);
        $expected = [$map['s1.quiz1']['cmid'], $map['s2.assign1']['cmid'], $map['s3.feedback1']['cmid']];
        sort($expected);
        $this->assertSame($expected, $cmids);
        $this->assertEqualsCanonicalizing(['quiz', 'assign', 'feedback'], array_column($criteria, 'module'));

        $overall = $DB->get_record('course_completion_aggr_methd', [
            'course' => $job->courseid,
            'criteriatype' => null,
        ], '*', MUST_EXIST);
        $this->assertEquals(COMPLETION_AGGREGATION_ALL, $overall->method);
        $activity = $DB->get_record('course_completion_aggr_methd', [
            'course' => $job->courseid,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
        ], '*', MUST_EXIST);
        $this->assertEquals(COMPLETION_AGGREGATION_ALL, $activity->method);
    }

    /**
     * An activity that does not track completion is not a criterion, with a warning, and the rest are.
     */
    public function test_an_activity_that_does_not_track_completion_is_left_out_of_the_course(): void {
        global $DB;
        $this->edit_blueprint([
            '"completion_activities": ["s1.quiz1", "s2.assign1", "s3.feedback1"]'
                => '"completion_activities": ["s1.quiz1", "s3.choice1"]',
            '{"mode": "manual"}' => '{"mode": "none"}',
        ]);

        [$job, $map] = $this->run_build();

        $criteria = $DB->get_records('course_completion_criteria', ['course' => $job->courseid]);
        $cmids = array_values(array_map(fn($row) => (int) $row->moduleinstance, $criteria));
        $this->assertSame([(int) $map['s1.quiz1']['cmid']], $cmids);
        $this->assertStringContainsString('s3.choice1', $this->output);
    }

    /**
     * The three kinds of rule become the JSON of Moodle's restrictions: a section that waits for an activity, an
     * activity that needs a grade in another one, a section that opens on a date.
     */
    public function test_access_restrictions(): void {
        global $DB;
        [$job, $map] = $this->run_build();

        // Section 2 waits for the quiz to be completed.
        $section2 = $DB->get_field('course_sections', 'availability', [
            'course' => $job->courseid,
            'section' => $map['s2']['sectionnum'],
        ]);
        $tree = json_decode($section2);
        $this->assertSame('&', $tree->op);
        $this->assertSame([true], $tree->showc);
        $this->assertSame('completion', $tree->c[0]->type);
        $this->assertEquals($map['s1.quiz1']['cmid'], $tree->c[0]->cm);
        $this->assertEquals(COMPLETION_COMPLETE, $tree->c[0]->e);

        // The assignment needs 50 % in the quiz.
        $assign = json_decode($DB->get_field('course_modules', 'availability', ['id' => $map['s2.assign1']['cmid']]));
        $this->assertSame('grade', $assign->c[0]->type);
        $gradeitem = $DB->get_record('grade_items', [
            'courseid' => $job->courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $map['s1.quiz1']['instanceid'],
            'itemnumber' => 0,
        ], '*', MUST_EXIST);
        $this->assertEquals($gradeitem->id, $assign->c[0]->id);
        $this->assertEquals(50, $assign->c[0]->min);

        // Section 3 opens on 1 November 2026, at the start of the day of the user who built the course.
        $section3 = json_decode($DB->get_field('course_sections', 'availability', [
            'course' => $job->courseid,
            'section' => $map['s3']['sectionnum'],
        ]));
        $this->assertSame('date', $section3->c[0]->type);
        $this->assertSame('>=', $section3->c[0]->d);
        $this->assertEquals(make_timestamp(2026, 11, 1), $section3->c[0]->t);

        // The rules are in force: the restrictions are in the course cache, not only in the rows.
        $modinfo = get_fast_modinfo($job->courseid);
        $this->assertNotEmpty($modinfo->get_section_info($map['s2']['sectionnum'])->availability);
        $this->assertNotEmpty($modinfo->get_cm($map['s2.assign1']['cmid'])->availability);
    }

    /**
     * A rule that waits for an activity that does not track completion is not written: it would lock the learner out.
     */
    public function test_a_rule_on_an_activity_that_does_not_track_completion_is_left_out(): void {
        global $DB;
        $this->edit_blueprint([
            '"completion": {"mode": "auto", "usegrade": true, "passgrade": true}' => '"completion": {"mode": "none"}',
        ]);

        [$job, $map] = $this->run_build();

        $section2 = $DB->get_field('course_sections', 'availability', [
            'course' => $job->courseid,
            'section' => $map['s2']['sectionnum'],
        ]);
        $this->assertEmpty($section2);
    }

    /**
     * The competencies are linked to the course, and to the activities that develop them.
     */
    public function test_competencies(): void {
        global $DB;
        $ids = $this->choose_framework(['ENREG-C1', 'ENREG-C2']);

        [$job, $map] = $this->run_build();

        $linked = $DB->get_fieldset_select('competency_coursecomp', 'competencyid', 'courseid = ?', [$job->courseid]);
        $this->assertEqualsCanonicalizing(array_values($ids), $linked);

        $onmodules = [];
        foreach ($DB->get_records('competency_modulecomp') as $row) {
            $onmodules[(int) $row->competencyid][] = (int) $row->cmid;
        }
        $this->assertEqualsCanonicalizing(
            [$map['s1.quiz1']['cmid'], $map['s2.lesson1']['cmid']],
            $onmodules[$ids['ENREG-C1']],
        );
        $this->assertEqualsCanonicalizing([$map['s2.assign1']['cmid']], $onmodules[$ids['ENREG-C2']]);
    }

    /**
     * The competencies named in the activities of the blueprint are linked too, once each.
     */
    public function test_competencies_named_in_an_activity(): void {
        global $DB;
        $ids = $this->choose_framework(['ENREG-C1', 'ENREG-C2', 'ENREG-C3']);
        $this->edit_blueprint(['"id": "s1.page1",' => '"id": "s1.page1", "competencies": ["ENREG-C3", "ENREG-C3"],']);

        [$job, $map] = $this->run_build();

        $this->assertTrue($DB->record_exists('competency_coursecomp', [
            'courseid' => $job->courseid,
            'competencyid' => $ids['ENREG-C3'],
        ]));
        $this->assertSame(1, $DB->count_records('competency_modulecomp', [
            'cmid' => $map['s1.page1']['cmid'],
            'competencyid' => $ids['ENREG-C3'],
        ]));
    }

    /**
     * Without a framework chosen, or with an idnumber it does not have, nothing is linked and the course is built.
     */
    public function test_competencies_without_a_framework_or_an_idnumber_are_left_out(): void {
        global $DB;
        [$job] = $this->run_build();
        $this->assertSame('finished', $job->status, (string) $job->error);
        $this->assertSame(0, $DB->count_records('competency_coursecomp', ['courseid' => $job->courseid]));
    }

    /**
     * A competency that the chosen framework does not have is left out, the others are linked.
     */
    public function test_a_competency_that_is_not_in_the_framework_is_left_out(): void {
        global $DB;
        $ids = $this->choose_framework(['ENREG-C1']);

        [$job] = $this->run_build();

        $linked = $DB->get_fieldset_select('competency_coursecomp', 'competencyid', 'courseid = ?', [$job->courseid]);
        $this->assertEquals([$ids['ENREG-C1']], array_values($linked));
        $this->assertStringContainsString('ENREG-C2', $this->output);
        $this->assertSame('finished', $job->status);
    }

    /**
     * The badges are created in the course, inactive, with their criteria.
     */
    public function test_badges(): void {
        global $DB;
        [$job, $map] = $this->run_build();

        $badges = $DB->get_records('badge', ['courseid' => $job->courseid], 'id');
        $this->assertCount(2, $badges);
        [$completion, $quiz] = array_values($badges);
        foreach ([$completion, $quiz] as $badge) {
            $this->assertEquals(BADGE_TYPE_COURSE, $badge->type);
            $this->assertEquals(BADGE_STATUS_INACTIVE, $badge->status);
            $this->assertNotEmpty($badge->issuername);
        }
        $this->assertSame('Absolvent Energie regenerabilă', $completion->name);
        $this->assertSame('Acordat la finalizarea cursului.', $completion->description);

        // Completing the course: the aggregation, and the course criterion.
        $types = $DB->get_records_menu('badge_criteria', ['badgeid' => $completion->id], 'criteriatype', 'id, criteriatype');
        $this->assertEqualsCanonicalizing([BADGE_CRITERIA_TYPE_OVERALL, BADGE_CRITERIA_TYPE_COURSE], array_values($types));
        $course = $DB->get_record('badge_criteria', ['badgeid' => $completion->id, 'criteriatype' => BADGE_CRITERIA_TYPE_COURSE]);
        $this->assertTrue($DB->record_exists('badge_criteria_param', [
            'critid' => $course->id,
            'name' => 'course_' . $job->courseid,
            'value' => $job->courseid,
        ]));

        // Completing the quiz.
        $activity = $DB->get_record('badge_criteria', [
            'badgeid' => $quiz->id,
            'criteriatype' => BADGE_CRITERIA_TYPE_ACTIVITY,
        ], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('badge_criteria_param', [
            'critid' => $activity->id,
            'name' => 'module_' . $map['s1.quiz1']['cmid'],
            'value' => $map['s1.quiz1']['cmid'],
        ]));
        $this->assertTrue($DB->record_exists('badge_criteria', [
            'badgeid' => $quiz->id,
            'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
        ]));

        // The badge has an image, which a badge needs before the teacher can switch it on.
        $files = get_file_storage()->get_area_files(
            \context_course::instance($job->courseid)->id,
            'badges',
            'badgeimage',
            $completion->id,
            'filename',
            false
        );
        $this->assertNotEmpty($files);
    }

    /**
     * Badges switched off on the site are not created, and the course is built all the same.
     */
    public function test_badges_switched_off_on_the_site(): void {
        global $DB;
        set_config('enablebadges', 0);

        [$job] = $this->run_build();

        $this->assertSame('finished', $job->status, (string) $job->error);
        $this->assertSame(0, $DB->count_records('badge', ['courseid' => $job->courseid]));
    }

    /**
     * Building again makes no second criterion, link, restriction or badge.
     */
    public function test_a_second_run_adds_nothing(): void {
        global $DB;
        $this->choose_framework(['ENREG-C1', 'ENREG-C2']);
        [$job] = $this->run_build();
        $counts = fn() => [
            $DB->count_records('course_completion_criteria', ['course' => $job->courseid]),
            $DB->count_records('competency_coursecomp', ['courseid' => $job->courseid]),
            $DB->count_records('competency_modulecomp'),
            $DB->count_records('badge', ['courseid' => $job->courseid]),
            $DB->count_records('badge_criteria'),
            $DB->count_records_select('course_modules', 'course = ? AND availability IS NOT NULL', [$job->courseid]),
        ];
        $before = $counts();
        $this->assertSame(3, $before[0]);
        $this->assertSame(2, $before[1]);
        $this->assertSame(2, $before[3]);

        $DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $this->jobid]);
        $this->run_build();

        $this->assertSame($before, $counts());
    }
}
