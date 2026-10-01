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

namespace local_aicoursebuilder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Tests of the callbacks in lib.php.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_aicoursebuilder_extend_navigation_course
 */
final class lib_test extends \advanced_testcase {
    /**
     * Builds the navigation node of a course and lets the plugin extend it.
     *
     * @param \stdClass $course The course.
     * @return \navigation_node|null The wizard node, null when the plugin added none.
     */
    private function wizard_node(\stdClass $course): ?\navigation_node {
        $node = \navigation_node::create('course', null, \navigation_node::TYPE_COURSE);
        local_aicoursebuilder_extend_navigation_course($node, $course, \context_course::instance($course->id));
        $found = $node->get('local_aicoursebuilder_wizard');
        return $found ?: null;
    }

    /**
     * A teacher gets a link to the wizard, with the course in it.
     */
    public function test_a_teacher_gets_the_wizard_link(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $node = $this->wizard_node($course);

        $this->assertNotNull($node);
        $this->assertSame(get_string('wizard:title', 'local_aicoursebuilder'), $node->text);
        $this->assertSame($course->id, (string) $node->action->get_param('courseid'));
        $this->assertStringContainsString('/local/aicoursebuilder/wizard.php', $node->action->out(false));
    }

    /**
     * A student does not.
     */
    public function test_a_student_gets_no_link(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->assertNull($this->wizard_node($course));
    }
}
