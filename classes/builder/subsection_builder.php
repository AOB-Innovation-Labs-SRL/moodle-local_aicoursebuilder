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
 * Builds a subsection: a module of its section that opens a section of its own.
 *
 * A subsection is the module mod_subsection, made like any other module, and Moodle gives it a delegated section for
 * the activities it holds. The result records both: the module, which is what the course page shows in the section,
 * and the number of the delegated section, which is where the activities of the subsection are built.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subsection_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'subsection';
    }

    /**
     * Finds the section the subsection module is in: the section whose id its own id starts with.
     *
     * The id of a subsection is s1-1, and the section it is in is s1.
     *
     * @param string $nodeid Blueprint node id of the subsection.
     * @param build_context $context The build context.
     * @return int Section number.
     */
    #[\Override]
    protected function find_sectionnum(string $nodeid, build_context $context): int {
        $sectionid = strstr($nodeid, '-', true);
        $sectionnum = $sectionid === false ? null : $context->locate_section($sectionid);
        return $sectionnum ?? parent::find_sectionnum($nodeid, $context);
    }

    /**
     * A subsection has nothing besides its name.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        // The blueprint calls the name of a subsection its title.
        $info->name = self::clean_name((string) ($node['title'] ?? $node['name'] ?? ''));
    }

    /**
     * Records the delegated section as the section of the node, and gives it its summary.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param int $sectionnum The section the subsection module was built in.
     * @param build_context $context The build context.
     * @return int Number of the delegated section.
     */
    #[\Override]
    protected function result_sectionnum(\stdClass $created, int $sectionnum, build_context $context): int {
        $course = $context->get_course();
        $section = get_fast_modinfo($course)->get_section_info_by_component('mod_subsection', (int) $created->instance);
        if (!$section) {
            throw new \moodle_exception('buildersubsectionmissing', 'local_aicoursebuilder');
        }
        $summary = self::clean_html((string) ($this->summary ?? ''));
        if ($summary !== '') {
            course_update_section($course, $section, ['summary' => $summary, 'summaryformat' => FORMAT_HTML]);
        }
        return (int) $section->sectionnum;
    }

    /** @var string|null Summary of the subsection being built, written to its section once it exists. */
    protected ?string $summary = null;

    /**
     * Keeps the summary of the node until the section exists.
     *
     * @param array|\stdClass $node The blueprint subsection.
     * @param build_context $context The build context.
     * @return build_result
     */
    #[\Override]
    public function build(array|\stdClass $node, build_context $context): build_result {
        $this->summary = (string) (((array) $node)['summary'] ?? '');
        return parent::build($node, $context);
    }
}
