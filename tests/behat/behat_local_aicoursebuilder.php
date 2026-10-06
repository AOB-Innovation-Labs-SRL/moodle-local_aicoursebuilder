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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps and page names of local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_aicoursebuilder extends behat_base {
    /**
     * Returns the URL of a page that has no instance, for the step "I am on the "..." page".
     *
     * @param string $page Name of the page.
     * @return moodle_url
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower($page)) {
            case 'wizard':
                return new moodle_url('/local/aicoursebuilder/wizard.php');
            case 'usage':
                return new moodle_url('/local/aicoursebuilder/usage.php');
            case 'index':
                return new moodle_url('/local/aicoursebuilder/index.php');
            default:
                throw new Exception('Unrecognised local_aicoursebuilder page "' . $page . '".');
        }
    }

    /**
     * Returns the URL of a page of a course, for the step "I am on the "..." "..." page".
     *
     * @param string $type Type of the page.
     * @param string $identifier Short name of the course for the wizard, the request of the job for the editor.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;

        switch (strtolower($type)) {
            case 'wizard':
                $courseid = $DB->get_field('course', 'id', ['shortname' => $identifier], MUST_EXIST);
                return new moodle_url('/local/aicoursebuilder/wizard.php', ['courseid' => $courseid]);
            case 'review':
            case 'job':
                $jobid = $DB->get_field_select(
                    'local_aicb_job',
                    'id',
                    $DB->sql_compare_text('prompt') . ' = ' . $DB->sql_compare_text(':prompt'),
                    ['prompt' => $identifier],
                    MUST_EXIST
                );
                return new moodle_url('/local/aicoursebuilder/' . strtolower($type) . '.php', ['id' => $jobid]);
            default:
                throw new Exception('Unrecognised local_aicoursebuilder page type "' . $type . '".');
        }
    }

    /**
     * Creates a job in review, owned by a user, with the golden blueprint as its first version.
     *
     * One activity of the blueprint is marked for the teacher to check, as the review step of the generation does.
     * The job has no source files, which the golden blueprint does not need to be valid.
     *
     * @Given /^a generated blueprint for "(?P<prompt_string>[^"]*)" is ready for review by "(?P<username_string>[^"]*)"$/
     * @param string $prompt What the teacher asked for, which names the job.
     * @param string $username Username of the owner of the job.
     */
    public function a_generated_blueprint_is_ready_for_review(string $prompt, string $username): void {
        global $DB;

        $user = \core_user::get_user_by_username($username, '*', null, MUST_EXIST);
        $now = time();
        $jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $user->id,
            'mode' => 'newcourse',
            'categoryid' => \core_course_category::get_default()->id,
            'status' => 'review',
            'stage' => 'generate',
            'progress' => 100,
            'prompt' => $prompt,
            'language' => 'ro',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $blueprint = json_decode(file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json'), true);
        $blueprint['sections'][0]['activities'][1]['review_flag'] = true;
        $content = json_encode($blueprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $jobid,
            'version' => 1,
            'content' => $content,
            'contenthash' => hash('sha256', $content),
            'status' => 'draft',
            'usermodified' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Creates a job that a cost limit paused, and makes its owner accept the AI policy so that the job can be resumed.
     *
     * The job stopped in the generation, with nothing paid for yet.
     *
     * @Given /^"(?P<username_string>[^"]*)" has a job for "(?P<prompt_string>[^"]*)" that a cost limit paused$/
     * @param string $username Username of the owner of the job.
     * @param string $prompt What the teacher asked for, which names the job.
     */
    public function a_job_paused_by_a_cost_limit(string $username, string $prompt): void {
        global $DB;

        $user = \core_user::get_user_by_username($username, '*', null, MUST_EXIST);
        \core_ai\manager::user_policy_accepted((int) $user->id, \context_system::instance()->id);
        $now = time();
        $DB->insert_record('local_aicb_job', (object) [
            'userid' => $user->id,
            'mode' => 'newcourse',
            'categoryid' => \core_course_category::get_default()->id,
            'status' => 'paused',
            'stage' => 'generate',
            'progress' => 0,
            'prompt' => $prompt,
            'language' => 'ro',
            'error' => get_string('budgetexceeded', 'local_aicoursebuilder', (object) ['scope' => 'job', 'limit' => 2.5]),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Creates a job in an existing course, owned by a user, with a status and the cost it has run up.
     *
     * @Given /^"(?P<user>[^"]*)" has a "(?P<status>[^"]*)" job "(?P<prompt>[^"]*)" in "(?P<course>[^"]*)" cost (?P<cost>[0-9.]+)$/
     * @param string $user Username of the owner of the job.
     * @param string $status The status of the job.
     * @param string $prompt What the teacher asked for, which names the job.
     * @param string $course Short name of the course the job is in.
     * @param string $cost What it has cost, in USD.
     */
    public function a_job_in_a_course(string $user, string $status, string $prompt, string $course, string $cost): void {
        global $DB;

        $owner = \core_user::get_user_by_username($user, '*', null, MUST_EXIST);
        $courseid = $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        $now = time();
        $DB->insert_record('local_aicb_job', (object) [
            'userid' => $owner->id,
            'mode' => 'existingcourse',
            'courseid' => $courseid,
            'status' => $status,
            'stage' => 'generate',
            'progress' => $status === 'review' ? 100 : 0,
            'prompt' => $prompt,
            'language' => 'ro',
            'actualcost' => $cost,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Takes a user out of a course, which is how they lose what they could do there.
     *
     * @Given /^"(?P<username_string>[^"]*)" is no longer enrolled in "(?P<course_string>[^"]*)"$/
     * @param string $username Username of the user.
     * @param string $shortname Short name of the course.
     */
    public function a_user_is_no_longer_enrolled(string $username, string $shortname): void {
        global $DB;

        $user = \core_user::get_user_by_username($username, '*', null, MUST_EXIST);
        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $user->id);
    }

    /**
     * Records AI spending of a user in the current month: a call of a job in the log, and the budget row of the month.
     *
     * @Given /^"(?P<username_string>[^"]*)" has spent (?P<cost>[0-9.]+) USD on AI this month for "(?P<prompt_string>[^"]*)"$/
     * @param string $username Username of the user who spent.
     * @param string $cost The amount, in USD.
     * @param string $prompt What the job was asked for, which names the job.
     */
    public function a_user_has_spent_on_ai(string $username, string $cost, string $prompt): void {
        global $DB;

        $user = \core_user::get_user_by_username($username, '*', null, MUST_EXIST);
        $now = time();
        $jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $user->id,
            'mode' => 'newcourse',
            'categoryid' => \core_course_category::get_default()->id,
            'status' => 'review',
            'stage' => 'generate',
            'progress' => 100,
            'prompt' => $prompt,
            'language' => 'ro',
            'actualcost' => $cost,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_aicb_ailog', (object) [
            'userid' => $user->id,
            'jobid' => $jobid,
            'step' => 'sections',
            'connector' => 'deepseek',
            'model' => 'deepseek-flash',
            'tokensin' => 1500000,
            'tokensout' => 250000,
            'tokenscached' => 900000,
            'cost' => $cost,
            'durationms' => 1200,
            'status' => 'success',
            'timecreated' => $now,
        ]);
        $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $user->id,
            'period' => \local_aicoursebuilder\ai\budget_guard::current_period(),
            'spentusd' => $cost,
            'reservedusd' => 0,
            'alertsent' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
