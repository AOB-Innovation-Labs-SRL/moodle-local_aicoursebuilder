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

/**
 * Tells the owner of a job that it finished or failed, with the message providers of the plugin.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_notifier {
    /**
     * Sends a message to the owner of the job.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param string $provider Message provider: jobfinished or jobfailed.
     */
    public static function notify(\stdClass $job, string $provider): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $job->userid]);
        if (!$user) {
            return;
        }
        // A finished job leads to the editor, a failed one to the page that says what happened.
        $page = $provider === 'jobfinished' ? 'review' : 'job';
        $url = new \moodle_url('/local/aicoursebuilder/' . $page . '.php', ['id' => $job->id]);
        $a = (object) ['url' => $url->out(false), 'error' => (string) $job->error];
        $subject = get_string('message:' . $provider . '_subject', 'local_aicoursebuilder');
        $body = get_string('message:' . $provider . '_body', 'local_aicoursebuilder', $a);

        $message = new \core\message\message();
        $message->component = 'local_aicoursebuilder';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url;
        $message->contexturlname = get_string('pluginname', 'local_aicoursebuilder');
        message_send($message);
    }
}
