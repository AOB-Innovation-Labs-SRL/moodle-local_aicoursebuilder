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
 * The AI usage dashboard: tokens and cost per job, user, month and model, and how the cost limits stand.
 *
 * It is for those who hold local/aicoursebuilder:viewusage, which the managers do by default.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/aicoursebuilder:viewusage', $context);

$period = optional_param('period', \local_aicoursebuilder\ai\budget_guard::current_period(), PARAM_ALPHANUMEXT);
// The selector sends "all" for all time; anything that is not a month is the current one.
if ($period === 'all') {
    $period = '';
} else if (!\local_aicoursebuilder\ai\usage_report::is_period($period)) {
    $period = \local_aicoursebuilder\ai\budget_guard::current_period();
}

$PAGE->set_url(new moodle_url('/local/aicoursebuilder/usage.php', ['period' => $period === '' ? 'all' : $period]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('usage:title', 'local_aicoursebuilder'));
$PAGE->set_heading(get_string('usage:title', 'local_aicoursebuilder'));

echo $OUTPUT->header();
echo $OUTPUT->render(new \local_aicoursebuilder\output\usage_dashboard($period));
echo $OUTPUT->footer();
