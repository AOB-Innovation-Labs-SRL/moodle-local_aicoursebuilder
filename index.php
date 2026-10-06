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
 * The generation jobs: the entry point of the plugin.
 *
 * It lists the jobs of the user, with the way to the page of each and a button for a new course. A manager can switch
 * to the jobs of everybody, which are only to be read: the job and the review pages keep the owner as the only one
 * who may change a job.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
// The answer kept for the navigation link is not what decides access, so require_use() works it out again.
(new \local_aicoursebuilder\job_manager())->require_use((int) $USER->id);

$showall = optional_param('all', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);
$list = new \local_aicoursebuilder\output\job_list((int) $USER->id, (bool) $showall, $page);

$url = new moodle_url('/local/aicoursebuilder/index.php', array_filter([
    'all' => $list->is_showing_all() ? 1 : 0,
    'page' => max(0, $page),
]));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('index:title', 'local_aicoursebuilder'));
$PAGE->set_heading(get_string('index:title', 'local_aicoursebuilder'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_aicoursebuilder/job_list', $list->export_for_template($OUTPUT));
echo $OUTPUT->footer();
