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
 * Builds a folder: several of the teacher's source documents, published together.
 *
 * Like a file, the folder saves its files from a draft area, which here holds every source the blueprint lists.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class folder_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'folder';
    }

    /**
     * Adds the files and the display options of the folder.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     * @throws \moodle_exception When a source of the folder is not one of the job.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        $sources = array_values(array_map('strval', $node['content']['sources'] ?? []));
        $info->files = (new source_files($context->jobid))->to_draft_area($sources);

        $defaults = $this->get_defaults(['showexpanded', 'showdownloadfolder', 'forcedownload']);
        $info->showexpanded = (int) ($defaults['showexpanded'] ?? 1);
        $info->showdownloadfolder = (int) ($defaults['showdownloadfolder'] ?? 1);
        $info->forcedownload = (int) ($defaults['forcedownload'] ?? 1);
        $info->revision = 1;
    }
}
