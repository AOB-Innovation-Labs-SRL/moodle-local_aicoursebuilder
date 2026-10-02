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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/builder_test_helpers.php');

/**
 * Tests of the assignment builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\assign_builder
 */
final class assign_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
    }

    /**
     * Returns the assignment object of a result, as the module's own pages make it.
     *
     * @param build_result $result The result of the builder.
     * @return \assign
     */
    private function assign(build_result $result): \assign {
        $cm = get_coursemodule_from_id('assign', $result->cmid, 0, false, MUST_EXIST);
        return new \assign(\context_module::instance($cm->id), $cm, $this->course);
    }

    /**
     * The assignment of the golden blueprint is created with its grade, its due date and its submission types.
     */
    public function test_builds_the_assignment_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new assign_builder())->build($this->node('s2.assign1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame([], $result->warnings);
        $this->assertSame('assign', $this->get_cm($result)->modname);
        $record = $DB->get_record('assign', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('Temă: comparație între două surse', $record->name);
        $this->assertSame('<p>Comparați două surse regenerabile, în maximum o pagină.</p>', $record->intro);
        $this->assertEquals(100, $record->grade);
        $this->assertEquals(make_timestamp(2026, 11, 15, 23, 59), $record->duedate);
        $this->assertEquals(0, $record->cutoffdate);
        $this->assertEquals(0, $record->allowsubmissionsfromdate);
        $this->assertEquals(0, $record->nosubmissions);
        $this->assertEquals(0, $record->teamsubmission);
    }

    /**
     * Online text and files are switched on, and the file settings are the blueprint's.
     */
    public function test_submission_plugins(): void {
        $assign = $this->assign((new assign_builder())->build($this->node('s2.assign1'), $this->make_context()));

        $onlinetext = $assign->get_submission_plugin_by_type('onlinetext');
        $file = $assign->get_submission_plugin_by_type('file');
        $this->assertTrue((bool) $onlinetext->is_enabled());
        $this->assertTrue((bool) $file->is_enabled());
        $this->assertEquals(1, $file->get_config('maxfilesubmissions'));
        $this->assertSame('.pdf,.docx', $file->get_config('filetypeslist'));
        $this->assertGreaterThanOrEqual(0, (int) $file->get_config('maxsubmissionsizebytes'));
    }

    /**
     * A type the blueprint does not ask for is switched off.
     */
    public function test_a_submission_type_that_is_not_asked_for_is_off(): void {
        $node = $this->node('s2.assign1');
        $node['content']['submission_types'] = ['onlinetext'];

        $assign = $this->assign((new assign_builder())->build($node, $this->make_context()));

        $this->assertTrue((bool) $assign->get_submission_plugin_by_type('onlinetext')->is_enabled());
        $this->assertFalse((bool) $assign->get_submission_plugin_by_type('file')->is_enabled());
    }

    /**
     * An assignment with files only has no online text.
     */
    public function test_files_only(): void {
        $node = $this->node('s2.assign1');
        $node['content']['submission_types'] = ['file'];
        unset($node['content']['filetypes']);

        $assign = $this->assign((new assign_builder())->build($node, $this->make_context()));

        $this->assertFalse((bool) $assign->get_submission_plugin_by_type('onlinetext')->is_enabled());
        $this->assertTrue((bool) $assign->get_submission_plugin_by_type('file')->is_enabled());
        $this->assertSame('', (string) $assign->get_submission_plugin_by_type('file')->get_config('filetypeslist'));
    }

    /**
     * The other plugins are on when the site has them on by default.
     */
    public function test_other_plugins_follow_the_site_defaults(): void {
        set_config('default', 1, 'assignfeedback_comments');
        set_config('default', 0, 'assignfeedback_file');

        $assign = $this->assign((new assign_builder())->build($this->node('s2.assign1'), $this->make_context()));

        $this->assertTrue((bool) $assign->get_feedback_plugin_by_type('comments')->is_enabled());
        $this->assertFalse((bool) $assign->get_feedback_plugin_by_type('file')->is_enabled());
    }

    /**
     * The grade item of the assignment exists in the gradebook with the grade of the blueprint.
     */
    public function test_the_gradebook_has_the_assignment(): void {
        $node = $this->node('s2.assign1');
        $node['content']['grade'] = 50;

        $result = (new assign_builder())->build($node, $this->make_context());

        $item = \grade_item::fetch(['courseid' => $this->course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $result->instanceid]);
        $this->assertNotFalse($item);
        $this->assertEquals(50, $item->grademax);
    }

    /**
     * The settings of a new assignment are the site's.
     */
    public function test_settings_come_from_the_site_defaults(): void {
        global $DB;
        set_config('submissiondrafts', 1, 'assign');
        set_config('sendnotifications', 1, 'assign');
        set_config('maxattempts', 3, 'assign');

        $result = (new assign_builder())->build($this->node('s2.assign1'), $this->make_context());

        $record = $DB->get_record('assign', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(1, $record->submissiondrafts);
        $this->assertEquals(1, $record->sendnotifications);
        $this->assertEquals(3, $record->maxattempts);
    }

    /**
     * An assignment with no grade and no date gets the default grade and no due date.
     */
    public function test_defaults_when_the_blueprint_gives_none(): void {
        global $DB;
        $node = $this->node('s2.assign1');
        unset($node['content']['grade'], $node['content']['duedate']);

        $result = (new assign_builder())->build($node, $this->make_context());

        $record = $DB->get_record('assign', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(assign_builder::DEFAULT_GRADE, $record->grade);
        $this->assertEquals(0, $record->duedate);
    }

    /**
     * Completion by submitting and by grade, as in the golden blueprint, is set.
     */
    public function test_completion_is_set(): void {
        global $DB;

        $result = (new assign_builder())->build($this->node('s2.assign1'), $this->make_context());

        $cm = $this->get_cm($result);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $cm->completion);
        $this->assertNotNull($cm->completiongradeitemnumber, 'Completion by grade is tracked');
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $result->instanceid]));
    }

    /**
     * A student can see the assignment and start a submission: it is usable, not only stored.
     */
    public function test_a_student_can_open_the_assignment(): void {
        $result = (new assign_builder())->build($this->node('s2.assign1'), $this->make_context());
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $assign = $this->assign($result);

        $this->assertTrue($assign->can_view_submission($student->id));
        $submission = $assign->get_user_submission($student->id, true);
        $this->assertEquals($student->id, $submission->userid);
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new assign_builder();
        $this->record($context, $builder->build($this->node('s2.assign1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s2.assign1'), $context)->status);
        $this->assertSame(1, $DB->count_records('assign'));
    }
}
