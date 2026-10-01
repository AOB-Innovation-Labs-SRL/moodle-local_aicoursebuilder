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
 * One node of the build plan: what to build, with which builder, and what it depends on.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class build_step {
    /**
     * Creates a step.
     *
     * @param string $nodeid Blueprint node id, or build_plan::COURSE_NODEID for the course itself.
     * @param string $type Node type, a key of builder_registry.
     * @param array $node The blueprint node passed to the builder.
     * @param string|null $parentid Node id of the section or subsection the node is built in, null for the
     *                              course and for top level sections. A failed parent fails the step without
     *                              calling its builder: there would be no section to build it in.
     */
    public function __construct(
        /** @var string Blueprint node id. */
        public readonly string $nodeid,
        /** @var string Node type. */
        public readonly string $type,
        /** @var array The blueprint node. */
        public readonly array $node,
        /** @var string|null Parent node id. */
        public readonly ?string $parentid = null,
    ) {
    }
}
