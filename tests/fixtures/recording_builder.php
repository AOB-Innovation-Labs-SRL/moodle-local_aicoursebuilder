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
 * Builder for tests: records the order it is called in and creates just enough of Moodle to be believable.
 *
 * It creates a real course for the course node, so that the enrolment of the owner and the course of the job
 * are testable, and fake but unique ids for everything else: what the tests check is the orchestration, not
 * what a real builder writes.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_builder implements builder_interface {
    /** @var string[] Node ids this builder was called with, in order. */
    public array $calls = [];

    /** @var array Anything the test wants to record per node, filled by the oncall callback. */
    public array $notes = [];

    /** @var string|null Node id to throw on, to test how a failing node is isolated. */
    public ?string $throwon = null;

    /** @var callable|null function(string $nodeid): void, called before each node is built. */
    public $oncall = null;

    /** @var int Next fake course module id. */
    protected int $nextid = 1000;

    /** @var int Next section number. */
    protected int $nextsectionnum = 1;

    /**
     * Builds one node, recording the call.
     *
     * @param array|\stdClass $node The blueprint node.
     * @param build_context $context The build context.
     * @return build_result
     */
    #[\Override]
    public function build(array|\stdClass $node, build_context $context): build_result {
        $node = (array) $node;
        // The course node has no id of its own in the schema.
        $nodeid = (string) ($node['id'] ?? build_plan::COURSE_NODEID);
        $this->calls[] = $nodeid;
        if ($this->oncall !== null) {
            ($this->oncall)($nodeid);
        }
        if ($this->throwon === $nodeid) {
            throw new \RuntimeException('boom');
        }
        if ($nodeid === build_plan::COURSE_NODEID) {
            return new build_result($nodeid, build_result::STATUS_CREATED, instanceid: $this->make_course($node));
        }
        if (preg_match('/^s[0-9]+$/', $nodeid)) {
            // A section: a section number, no course module.
            return new build_result(
                $nodeid,
                build_result::STATUS_CREATED,
                sectionnum: $this->nextsectionnum++,
            );
        }
        // A module: an activity, or the module of a subsection, which also opens a section.
        $id = $this->nextid++;
        $sectionnum = isset($node['activities']) ? $this->nextsectionnum++ : null;
        return new build_result($nodeid, build_result::STATUS_CREATED, $id, $id, $sectionnum);
    }

    /**
     * Creates the real course of the course node, as the course builder will.
     *
     * @param array $node The course node of the blueprint.
     * @return int Course id.
     */
    protected function make_course(array $node): int {
        $course = \core\test\testing_util::get_data_generator()->create_course([
            'fullname' => $node['fullname'] ?? 'Course',
            'shortname' => $node['shortname'] ?? 'C1',
            'format' => $node['format'] ?? 'topics',
            'enablecompletion' => !empty($node['enablecompletion']) ? 1 : 0,
            'visible' => 0,
        ]);
        return (int) $course->id;
    }
}
