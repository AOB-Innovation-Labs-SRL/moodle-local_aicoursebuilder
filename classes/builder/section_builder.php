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

use core_courseformat\formatactions;

/**
 * Builds a section of the course: a new section at the end, with the title and the summary of the blueprint.
 *
 * The section is added with the course format's own section API, which puts it after the last regular section and
 * before the delegated ones (the sections of subsections), and then named and described with the same API the
 * section editing of the course page uses.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_builder implements builder_interface {
    /**
     * Builds one section.
     *
     * @param array|\stdClass $node The blueprint section.
     * @param build_context $context The build context.
     * @return build_result The section number is in the result; the section row id is its instance id.
     */
    #[\Override]
    public function build(array|\stdClass $node, build_context $context): build_result {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $node = json_decode(json_encode($node), true);
        $nodeid = (string) ($node['id'] ?? '');
        if ($context->is_built($nodeid)) {
            return new build_result($nodeid, build_result::STATUS_SKIPPED);
        }

        $course = $context->get_course();
        $section = formatactions::section($course)->create();
        course_update_section($course, $section, [
            'name' => module_builder::clean_name((string) ($node['title'] ?? '')),
            'summary' => module_builder::clean_html((string) ($node['summary'] ?? '')),
            'summaryformat' => FORMAT_HTML,
        ]);

        return new build_result(
            $nodeid,
            build_result::STATUS_CREATED,
            instanceid: (int) $section->id,
            sectionnum: (int) $section->section,
        );
    }
}
