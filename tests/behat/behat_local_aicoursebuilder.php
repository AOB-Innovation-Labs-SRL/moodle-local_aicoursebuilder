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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps and page names of local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_aicoursebuilder extends behat_base {
    /**
     * Returns the URL of a page that has no instance, for the step "I am on the "..." page".
     *
     * @param string $page Name of the page.
     * @return moodle_url
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower($page)) {
            case 'wizard':
                return new moodle_url('/local/aicoursebuilder/wizard.php');
            default:
                throw new Exception('Unrecognised local_aicoursebuilder page "' . $page . '".');
        }
    }

    /**
     * Returns the URL of a page of a course, for the step "I am on the "..." "..." page".
     *
     * @param string $type Type of the page.
     * @param string $identifier Short name of the course.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;

        switch (strtolower($type)) {
            case 'wizard':
                $courseid = $DB->get_field('course', 'id', ['shortname' => $identifier], MUST_EXIST);
                return new moodle_url('/local/aicoursebuilder/wizard.php', ['courseid' => $courseid]);
            default:
                throw new Exception('Unrecognised local_aicoursebuilder page type "' . $type . '".');
        }
    }
}
