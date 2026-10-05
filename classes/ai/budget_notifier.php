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

namespace local_aicoursebuilder\ai;

/**
 * Tells people about the cost limits: when spending reaches the alert threshold, and when a limit stops a job.
 *
 * The user whose spending it is hears about it, and so do the managers (the users who hold
 * local/aicoursebuilder:manage), who are the ones that can raise a limit. The threshold is told once per row and
 * month by budget_guard; a job stopped by a limit is told every time it stops.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget_notifier {
    /** @var string Message provider: spending reached the alert threshold. */
    public const PROVIDER_ALERT = 'budgetalert';

    /** @var string Message provider: a cost limit stopped a job. */
    public const PROVIDER_EXCEEDED = 'budgetexceeded';

    /**
     * Tells that the spending of a user, or of the site, reached the alert threshold.
     *
     * @param int $userid The user, or budget_guard::SITE_USERID for the whole site.
     * @param string $period Month, YYYY-MM.
     * @param float $spent What was spent, in USD.
     * @param float $limit The limit, in USD.
     */
    public function alert(int $userid, string $period, float $spent, float $limit): void {
        global $DB;

        $owner = $userid === budget_guard::SITE_USERID ? null : $DB->get_record('user', ['id' => $userid]);
        $a = (object) [
            'who' => $owner ? fullname($owner) : get_string('usage:site', 'local_aicoursebuilder'),
            'spent' => format_float($spent, 2),
            'limit' => format_float($limit, 2),
            'percent' => (int) floor($limit > 0 ? $spent / $limit * 100 : 0),
            'period' => $period,
        ];
        $url = new \moodle_url('/local/aicoursebuilder/usage.php');
        foreach ($this->recipients($owner ?: null) as $user) {
            // The report is for those who may view it; the others are told, without a link to a page they cannot open.
            $canview = has_capability('local/aicoursebuilder:viewusage', \context_system::instance(), $user);
            $this->send(self::PROVIDER_ALERT, $user, $a, $canview ? $url : null);
        }
    }

    /**
     * Tells that a cost limit stopped a job.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param string $reason What stopped it, as budget_exceeded_exception words it.
     */
    public function exceeded(\stdClass $job, string $reason): void {
        global $DB;

        $owner = $DB->get_record('user', ['id' => $job->userid]);
        $a = (object) ['job' => shorten_text(format_string($job->prompt), 80), 'reason' => $reason];
        $url = new \moodle_url('/local/aicoursebuilder/job.php', ['id' => $job->id]);
        foreach ($this->recipients($owner ?: null) as $user) {
            $this->send(self::PROVIDER_EXCEEDED, $user, $a, $url);
        }
    }

    /**
     * Returns who is told: the user the spending belongs to, if any, and the managers.
     *
     * @param \stdClass|null $owner The user the spending belongs to, null for the whole site.
     * @return \stdClass[] User records, once each.
     */
    protected function recipients(?\stdClass $owner): array {
        $recipients = [];
        if ($owner && !$owner->deleted && !$owner->suspended) {
            $recipients[$owner->id] = $owner;
        }
        $managers = get_users_by_capability(\context_system::instance(), 'local/aicoursebuilder:manage', 'u.*');
        foreach ($managers as $manager) {
            if (!$manager->deleted && !$manager->suspended) {
                $recipients[$manager->id] = $manager;
            }
        }
        return $recipients;
    }

    /**
     * Sends one notification.
     *
     * @param string $provider One of the PROVIDER_* constants, which names the message strings too.
     * @param \stdClass $user Who is told.
     * @param \stdClass $a Values of the message strings.
     * @param \moodle_url|null $url Where the message leads, null for nowhere.
     */
    protected function send(string $provider, \stdClass $user, \stdClass $a, ?\moodle_url $url): void {
        $subject = get_string('message:' . $provider . '_subject', 'local_aicoursebuilder', $a);

        $message = new \core\message\message();
        $message->component = 'local_aicoursebuilder';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = get_string('message:' . $provider . '_body', 'local_aicoursebuilder', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        if ($url) {
            $message->contexturl = $url;
            $message->contexturlname = get_string('pluginname', 'local_aicoursebuilder');
        }
        message_send($message);
    }
}
