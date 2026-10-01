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
 * The blueprint editor: the teacher reviews the generated course, edits it, regenerates parts of it and approves it.
 *
 * The page only draws the frame of the editor. The blueprint itself is read, edited and saved through the web
 * services by the blueprint_editor module. A job whose blueprint is not ready yet is sent to its job page; one that
 * was approved is shown, but cannot be changed any more, and neither can a job that is not the teacher's own.
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
$isowner = (int) $job->userid === (int) $USER->id;
if (!$isowner) {
    require_capability('local/aicoursebuilder:manage', context_system::instance());
}

// Until there is a blueprint, the job page is where the teacher belongs.
$hasblueprint = $DB->record_exists('local_aicb_blueprint', ['jobid' => $job->id]);
if (!$hasblueprint) {
    redirect(new moodle_url('/local/aicoursebuilder/job.php', ['id' => $job->id]));
}

$url = new moodle_url('/local/aicoursebuilder/review.php', ['id' => $job->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('editor:title', 'local_aicoursebuilder'));
$PAGE->set_heading(get_string('editor:title', 'local_aicoursebuilder'));

// Only the owner changes a blueprint, and only while the job waits for the review.
$editable = $isowner && $job->status === \local_aicoursebuilder\blueprint\blueprint_service::JOB_REVIEW;

$PAGE->requires->js_call_amd('local_aicoursebuilder/blueprint_editor', 'init', [[
    'jobid' => (int) $job->id,
    'editable' => $editable,
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_aicoursebuilder/blueprint_editor', [
    'editable' => $editable,
    'prompt' => format_string($job->prompt),
]);
echo $OUTPUT->footer();
