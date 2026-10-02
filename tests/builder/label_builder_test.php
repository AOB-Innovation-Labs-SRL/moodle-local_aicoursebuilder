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
 * Tests of the label builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\label_builder
 */
final class label_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * The text of the label is its intro, and the module is in the course.
     */
    public function test_builds_the_label_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new label_builder())->build($this->node('s1.label1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $label = $DB->get_record('label', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('<p>Bine ați venit! Parcurgeți materialele în ordinea de mai jos.</p>', $label->intro);
        $this->assertEquals(FORMAT_HTML, $label->introformat);
        $cm = $this->get_cm($result);
        $this->assertSame('label', $cm->modname);
        $this->assertEquals(1, $cm->sectionnum);
        $this->assertNotSame('', $cm->name, 'Moodle names a label from its text');
    }

    /**
     * The text is cleaned like every text the model writes.
     */
    public function test_the_text_is_cleaned(): void {
        global $DB;
        $node = $this->node('s1.label1');
        $node['content']['text'] = '<p>Ok</p><iframe src="https://example.com"></iframe><script>x()</script>';

        $result = (new label_builder())->build($node, $this->make_context());

        $intro = $DB->get_field('label', 'intro', ['id' => $result->instanceid]);
        $this->assertStringContainsString('<p>Ok</p>', $intro);
        $this->assertStringNotContainsString('script', $intro);
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new label_builder();
        $this->record($context, $builder->build($this->node('s1.label1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s1.label1'), $context)->status);
        $this->assertSame(1, $DB->count_records('label'));
    }
}
