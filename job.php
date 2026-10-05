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
 * The page of a generation job: how far it is, and where to go from there.
 *
 * It is where the notification of a failed job leads, and where a teacher comes back to a job they left running. The
 * progress is read through the web services by the progress module, so the page only says which job it is.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$jobid = required_param('id', PARAM_INT);

require_login();

$job = $DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST);
$context = \local_aicoursebuilder\job_manager::get_context($job);
require_capability('local/aicoursebuilder:use', $context);
if ((int) $job->userid !== (int) $USER->id) {
    require_capability('local/aicoursebuilder:manage', context_system::instance());
}

$url = new moodle_url('/local/aicoursebuilder/job.php', ['id' => $job->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('job:title', 'local_aicoursebuilder'));
$PAGE->set_heading(get_string('job:title', 'local_aicoursebuilder'));

$PAGE->requires->js_call_amd('local_aicoursebuilder/progress', 'init', [[
    'jobid' => (int) $job->id,
    'reviewurl' => (new moodle_url('/local/aicoursebuilder/review.php', ['id' => $job->id]))->out(false),
    // Only the owner may resume a job that a cost limit paused.
    'canresume' => (int) $job->userid === (int) $USER->id,
    'courseurl' => $job->courseid ? (new moodle_url('/course/view.php', ['id' => $job->courseid]))->out(false) : '',
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_aicoursebuilder/job', [
    'prompt' => format_string($job->prompt),
]);
echo $OUTPUT->footer();
