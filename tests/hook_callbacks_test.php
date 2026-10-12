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

use core\hook\navigation\primary_extend;

/**
 * Tests for the hook callbacks: the link to the jobs in the primary navigation.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Runs the callback on a primary navigation and returns the node it added, if it did.
     *
     * @return \navigation_node|false
     */
    private function run_callback(): \navigation_node|false {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_url('/');
        $primary = new \core\navigation\views\primary($PAGE);

        hook_callbacks::extend_primary_navigation(new primary_extend($primary));

        return $primary->get('local_aicoursebuilder');
    }

    /**
     * A teacher gets the link, and it leads to the list of jobs.
     */
    public function test_a_teacher_gets_the_link(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $node = $this->run_callback();

        $this->assertNotFalse($node);
        $this->assertSame(get_string('pluginname', 'local_aicoursebuilder'), $node->text);
        $this->assertStringContainsString('/local/aicoursebuilder/index.php', $node->action()->out(false));
    }

    /**
     * A manager and an administrator get it too.
     */
    public function test_a_manager_and_an_administrator_get_the_link(): void {
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);
        $this->setUser($manager);
        $this->assertNotFalse($this->run_callback());

        $this->setAdminUser();
        $this->assertNotFalse($this->run_callback());
    }

    /**
     * A student, somebody who is not enrolled anywhere, a guest and a visitor do not.
     */
    public function test_others_do_not_get_the_link(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->assertFalse($this->run_callback());

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse($this->run_callback());

        $this->setGuestUser();
        $this->assertFalse($this->run_callback());

        $this->setUser(0);
        $this->assertFalse($this->run_callback());
    }
}
