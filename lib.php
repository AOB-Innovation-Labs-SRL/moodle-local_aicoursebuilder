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

/**
 * Library of callbacks for local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the course generation wizard to the navigation of a course, for those who may add generated content to it.
 *
 * @param navigation_node $navigation The navigation node of the course.
 * @param stdClass $course The course.
 * @param context $context The context of the course.
 */
function local_aicoursebuilder_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    if (
        !has_capability('local/aicoursebuilder:use', $context)
        || !has_capability('local/aicoursebuilder:generateincourse', $context)
        || !has_capability('moodle/course:manageactivities', $context)
    ) {
        return;
    }
    $navigation->add(
        get_string('wizard:title', 'local_aicoursebuilder'),
        new moodle_url('/local/aicoursebuilder/wizard.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_aicoursebuilder_wizard',
        new pix_icon('i/settings', '')
    );
}

/**
 * Tells Moodle which status checks the plugin adds to Site administration > Reports > System status.
 *
 * @return \core\check\check[]
 */
function local_aicoursebuilder_status_checks(): array {
    return [new \local_aicoursebuilder\check\ocr()];
}
