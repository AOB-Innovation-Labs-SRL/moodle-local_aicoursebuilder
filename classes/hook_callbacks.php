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

namespace local_aicoursebuilder;

use core\hook\navigation\primary_extend;

/**
 * Callbacks of the Moodle hooks the plugin listens to.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Adds the list of the generation jobs to the primary navigation, for those who may use the plugin.
     *
     * @param primary_extend $hook The hook, which holds the primary navigation.
     */
    public static function extend_primary_navigation(primary_extend $hook): void {
        global $USER;

        if (!isloggedin() || isguestuser() || !(new job_manager())->can_use((int) $USER->id)) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('pluginname', 'local_aicoursebuilder'),
            new \moodle_url('/local/aicoursebuilder/index.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_aicoursebuilder'
        );
    }
}
