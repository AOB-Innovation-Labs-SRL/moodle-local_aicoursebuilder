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
 * The course generation wizard: where the course goes, what it is about, the sources, the cost, then the progress.
 *
 * The page only draws the form and hands the rest to the wizard script, which creates the job, shows its estimated
 * cost and follows its progress through the web services. The page of a job that was started is job.php.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);

require_login();

$context = context_system::instance();
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
}

$url = new moodle_url('/local/aicoursebuilder/wizard.php', array_filter(['courseid' => $courseid]));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('wizard:title', 'local_aicoursebuilder'));
$PAGE->set_heading(get_string('wizard:title', 'local_aicoursebuilder'));

// New courses can go to the categories where the user may create courses and use the plugin.
$categories = [];
foreach (core_course_category::make_categories_list('moodle/course:create') as $id => $name) {
    if (has_capability('local/aicoursebuilder:use', context_coursecat::instance($id))) {
        $categories[$id] = $name;
    }
}
if (!$categories) {
    // Without a category the wizard can only add to a course, which needs the capability in some course.
    require_capability('local/aicoursebuilder:use', $courseid ? $context : context_system::instance());
}

$form = new \local_aicoursebuilder\form\wizard_form($url, ['categories' => $categories]);
$options = \local_aicoursebuilder\form\wizard_form::filemanager_options();
$draftitemid = file_get_submitted_draft_itemid('sources');
file_prepare_draft_area($draftitemid, $context->id, 'local_aicoursebuilder', 'wizard', null, $options);
$defaults = ['sources' => $draftitemid, 'mode' => $categories ? 'newcourse' : 'existingcourse'];
if ($courseid) {
    $defaults['mode'] = 'existingcourse';
    $defaults['courseid'] = $courseid;
}
$form->set_data($defaults);

$PAGE->requires->js_call_amd('local_aicoursebuilder/wizard', 'init', [[
    'contextid' => $context->id,
    'policyaccepted' => \core_ai\manager::get_user_policy_status((int) $USER->id),
    'courseid' => $courseid,
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_aicoursebuilder/wizard', [
    'form' => $form->render(),
]);
echo $OUTPUT->footer();
