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
 * Tests of the feedback builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\feedback_builder
 */
final class feedback_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * Returns the items of a feedback in order.
     *
     * @param build_result $result The result of the builder.
     * @return \stdClass[]
     */
    private function items(build_result $result): array {
        global $DB;
        return array_values($DB->get_records('feedback_item', ['feedback' => $result->instanceid], 'position'));
    }

    /**
     * The feedback of the golden blueprint is anonymous and has its five questions in order.
     */
    public function test_builds_the_feedback_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new feedback_builder())->build($this->node('s3.feedback1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame([], $result->warnings);
        $this->assertSame('feedback', $this->get_cm($result)->modname);
        $feedback = $DB->get_record('feedback', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(FEEDBACK_ANONYMOUS_YES, $feedback->anonymous);

        $items = $this->items($result);
        $this->assertSame(['info', 'multichoice', 'numeric', 'textfield', 'textarea'], array_column($items, 'typ'));
        $this->assertEquals([1, 2, 3, 4, 5], array_column($items, 'position'));
        $this->assertEquals([0, 0, 0, 0, 0], array_column($items, 'template'));
        $this->assertSame('Chestionarul este anonim.', $items[0]->name);
    }

    /**
     * Each type of question has the presentation the module reads.
     */
    public function test_presentations(): void {
        $items = $this->items((new feedback_builder())->build($this->node('s3.feedback1'), $this->make_context()));

        $this->assertSame('2', $items[0]->presentation);
        $this->assertSame('r>>>>>Foarte util|Util|Puțin util', $items[1]->presentation);
        $this->assertSame('0|100', $items[2]->presentation);
        $this->assertSame('30|255', $items[3]->presentation);
        $this->assertSame('40|4', $items[4]->presentation);
        $this->assertSame('h', $items[1]->options, 'No empty choice is offered');
    }

    /**
     * The required flag, the label and the value flag of each question.
     */
    public function test_flags_and_labels(): void {
        $items = $this->items((new feedback_builder())->build($this->node('s3.feedback1'), $this->make_context()));

        $this->assertEquals([0, 1, 0, 0, 0], array_column($items, 'required'));
        $this->assertSame(['', 'utilitate', 'ore', 'cuvant', 'sugestii'], array_column($items, 'label'));
        $expected = array_map(fn($item) => feedback_get_item_class($item->typ)->get_hasvalue(), $items);
        $this->assertEquals($expected, array_column($items, 'hasvalue'), 'The module says which items hold a value');
    }

    /**
     * A number with only one limit has a dash for the other.
     */
    public function test_a_range_with_one_limit(): void {
        $node = $this->node('s3.feedback1');
        $node['content']['items'] = [
            ['type' => 'numeric', 'name' => 'A', 'min' => 1],
            ['type' => 'numeric', 'name' => 'B', 'max' => 9.5],
            ['type' => 'numeric', 'name' => 'C'],
        ];

        $items = $this->items((new feedback_builder())->build($node, $this->make_context()));

        $this->assertSame(['1|-', '-|9.5', '-|-'], array_column($items, 'presentation'));
    }

    /**
     * A question of a type the module has but the blueprint does not know is left out, with a warning.
     */
    public function test_an_unknown_type_is_left_out(): void {
        $node = $this->node('s3.feedback1');
        $node['content']['items'][] = ['type' => 'captcha', 'name' => 'Cod'];

        $result = (new feedback_builder())->build($node, $this->make_context());

        $this->assertCount(5, $this->items($result));
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('captcha', $result->warnings[0]);
    }

    /**
     * The labels are cut to fit the column, and the choices are plain text.
     */
    public function test_labels_and_choices_are_cleaned(): void {
        $node = $this->node('s3.feedback1');
        $node['content']['items'] = [
            ['type' => 'multichoice', 'name' => 'Q', 'label' => str_repeat('x', 300), 'options' => ['<b>Da</b>', 'Nu']],
        ];

        $items = $this->items((new feedback_builder())->build($node, $this->make_context()));

        $this->assertSame(255, strlen($items[0]->label));
        $this->assertSame('r>>>>>Da|Nu', $items[0]->presentation);
    }

    /**
     * A student can answer the feedback that was built: it is usable, not only stored.
     */
    public function test_a_student_can_open_the_feedback(): void {
        global $DB;
        $result = (new feedback_builder())->build($this->node('s3.feedback1'), $this->make_context());
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $feedback = $DB->get_record('feedback', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame(5, $DB->count_records('feedback_item', ['feedback' => $feedback->id]));
        $this->assertFalse(feedback_is_already_submitted($feedback->id));
    }

    /**
     * The completion by submitting is set, as in the golden blueprint.
     */
    public function test_completion_is_set(): void {
        global $DB;

        $result = (new feedback_builder())->build($this->node('s3.feedback1'), $this->make_context());

        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $this->get_cm($result)->completion);
        $this->assertEquals(1, $DB->get_field('feedback', 'completionsubmit', ['id' => $result->instanceid]));
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new feedback_builder();
        $this->record($context, $builder->build($this->node('s3.feedback1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s3.feedback1'), $context)->status);
        $this->assertSame(5, $DB->count_records('feedback_item'));
    }
}
