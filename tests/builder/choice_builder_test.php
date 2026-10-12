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
 * Tests of the choice builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\choice_builder
 */
final class choice_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * The choice of the golden blueprint has its options in order.
     */
    public function test_builds_the_choice_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new choice_builder())->build($this->node('s3.choice1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame([], $result->warnings);
        $this->assertSame('choice', $this->get_cm($result)->modname);
        $choice = $DB->get_record('choice', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('Sondaj: sursa preferată', $choice->name);
        $this->assertEquals(1, $choice->allowupdate);
        $this->assertEquals(0, $choice->allowmultiple);
        $this->assertEquals(0, $choice->limitanswers);
        $this->assertEquals(CHOICE_PUBLISH_ANONYMOUS, $choice->publish);
        $this->assertEquals(CHOICE_SHOWRESULTS_AFTER_ANSWER, $choice->showresults);

        $options = array_values($DB->get_records('choice_options', ['choiceid' => $choice->id], 'id'));
        $this->assertSame(['Solară', 'Eoliană', 'Hidro', 'Biomasă'], array_column($options, 'text'));
        $this->assertEquals([0, 0, 0, 0], array_column($options, 'maxanswers'));
    }

    /**
     * A choice that cannot be changed after the answer is one.
     */
    public function test_allowupdate_follows_the_blueprint(): void {
        global $DB;
        $node = $this->node('s3.choice1');
        $node['content']['allowupdate'] = false;

        $result = (new choice_builder())->build($node, $this->make_context());

        $this->assertEquals(0, $DB->get_field('choice', 'allowupdate', ['id' => $result->instanceid]));
    }

    /**
     * An answer can be given: the choice is usable, not only created.
     */
    public function test_a_student_can_answer(): void {
        global $DB;
        $result = (new choice_builder())->build($this->node('s3.choice1'), $this->make_context());
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $options = $DB->get_records('choice_options', ['choiceid' => $result->instanceid], 'id');
        $option = array_values($options)[1];
        $this->assertSame('Eoliană', $option->text);

        $choice = choice_get_choice($result->instanceid);
        choice_user_submit_response($option->id, $choice, $student->id, $this->course, $this->get_cm($result));

        $this->assertTrue($DB->record_exists('choice_answers', ['choiceid' => $result->instanceid, 'userid' => $student->id]));
    }

    /**
     * Blank options are left out and the others are plain text.
     */
    public function test_options_are_cleaned(): void {
        global $DB;
        $node = $this->node('s3.choice1');
        $node['content']['options'] = ['<b>Da</b>', '  ', 'Nu'];

        $result = (new choice_builder())->build($node, $this->make_context());

        $options = $DB->get_fieldset_select('choice_options', 'text', 'choiceid = ? ORDER BY id', [$result->instanceid]);
        $this->assertSame(['Da', 'Nu'], array_values($options));
    }

    /**
     * The manual completion of the golden blueprint is set.
     */
    public function test_completion_is_set(): void {
        $result = (new choice_builder())->build($this->node('s3.choice1'), $this->make_context());

        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->get_cm($result)->completion);
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new choice_builder();
        $this->record($context, $builder->build($this->node('s3.choice1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s3.choice1'), $context)->status);
        $this->assertSame(1, $DB->count_records('choice'));
    }
}
