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
 * Builds a file: one of the teacher's source documents, published as a course file.
 *
 * The file module takes its file from a draft area, as the form of the module does. The blueprint names the source,
 * its file is copied into a draft area of the user the build runs as, and the module saves it from there.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'resource';
    }

    /**
     * Adds the file and the display options of the module.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     * @throws \moodle_exception When the source of the file is not one of the job.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $sourceid = (string) ($node['content']['source'] ?? '');
        $info->files = (new source_files($context->jobid))->to_draft_area([$sourceid]);

        $defaults = $this->get_defaults([
            'display', 'popupwidth', 'popupheight', 'printintro', 'showsize', 'showtype', 'showdate', 'filterfiles',
        ]);
        $info->display = (int) ($defaults['display'] ?? RESOURCELIB_DISPLAY_AUTO);
        $info->popupwidth = (int) ($defaults['popupwidth'] ?? 620);
        $info->popupheight = (int) ($defaults['popupheight'] ?? 450);
        $info->printintro = (int) ($defaults['printintro'] ?? 1);
        $info->showsize = (int) ($defaults['showsize'] ?? 0);
        $info->showtype = (int) ($defaults['showtype'] ?? 0);
        $info->showdate = (int) ($defaults['showdate'] ?? 0);
        $info->filterfiles = (int) ($defaults['filterfiles'] ?? 0);
        $info->revision = 1;
    }
}
