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

/**
 * Contract for the builders that turn one blueprint node into Moodle objects.
 *
 * A builder must be idempotent: when $context->is_built($nodeid) is true it returns a
 * skipped result and creates nothing. The caller records the result in the context.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface builder_interface {
    /**
     * Builds one blueprint node (section, subsection or activity).
     *
     * @param array|\stdClass $node The blueprint node, as defined by schema/blueprint.v1.json.
     * @param build_context $context Course, section and cm maps, question bank context.
     * @return build_result
     */
    public function build(array|\stdClass $node, build_context $context): build_result;
}
