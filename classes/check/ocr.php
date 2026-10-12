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

namespace local_aicoursebuilder\check;

use core\check\check;
use core\check\result;
use local_aicoursebuilder\ingest\ocr_status;

/**
 * Status check of Moodle (Site administration > Reports > System status): can scanned PDF files be read on this server?
 *
 * OCR is optional, so a server without it is a warning and never an error: the plugin works, and only the PDF files
 * that are scans cannot be read. The state comes from ocr_status, the same that the settings page shows.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ocr extends check {
    /**
     * Returns the short name of the check.
     *
     * @return string
     */
    #[\Override]
    public function get_name(): string {
        return get_string('check_ocr', 'local_aicoursebuilder');
    }

    /**
     * Returns the link to the place where the OCR is set.
     *
     * @return \action_link|null
     */
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'local_aicoursebuilder']),
            get_string('check_ocr_action', 'local_aicoursebuilder')
        );
    }

    /**
     * Returns the result: OK when scanned PDF files can be read, a warning when they cannot.
     *
     * @return result
     */
    #[\Override]
    public function get_result(): result {
        $state = ocr_status::evaluate();
        $details = get_string('check_ocr_details', 'local_aicoursebuilder');
        return new result(
            $state['status'] === ocr_status::OK ? result::OK : result::WARNING,
            $state['summary'],
            $details
        );
    }
}
