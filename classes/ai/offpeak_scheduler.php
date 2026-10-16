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
 * Finds the next moment the AI work of a job is cheapest, and schedules ad-hoc tasks there (spec 8).
 *
 * The windows are the peak windows of the connector, in UTC, from the settings or the DeepSeek pilot default.
 * Only AI work is scheduled here: the build of the course makes no AI call, so it runs at the next cron.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class offpeak_scheduler {
    /** @var int Days looked ahead for an off-peak hour; a week covers any weekly schedule. */
    protected const HORIZON_DAYS = 7;

    /**
     * Returns the first moment at or after the given one that is off-peak for the connector.
     *
     * @param string $connector Connector name.
     * @param int $now Unix timestamp to start from.
     * @return int The same moment when it is already off-peak, else the start of the first off-peak hour; $now when
     *             the connector is never off-peak within a week.
     */
    public static function next_offpeak(string $connector, int $now): int {
        if (pricing::is_offpeak($connector, $now)) {
            return $now;
        }
        $hourstart = $now - ($now % HOURSECS);
        for ($hour = 1; $hour <= self::HORIZON_DAYS * 24; $hour++) {
            $moment = $hourstart + $hour * HOURSECS;
            if (pricing::is_offpeak($connector, $moment)) {
                return $moment;
            }
        }
        return $now;
    }

    /**
     * Delays a task of a job to the next off-peak moment, when the job asks for it.
     *
     * The fake connector costs nothing, so it is never delayed.
     *
     * @param \core\task\adhoc_task $task The task, not yet queued.
     * @param \stdClass $job The local_aicb_job row.
     * @param int|null $now Unix timestamp, null for now.
     * @return int The moment the task is scheduled at, $now when it is not delayed.
     */
    public static function schedule(\core\task\adhoc_task $task, \stdClass $job, ?int $now = null): int {
        $now ??= time();
        if (empty($job->offpeak)) {
            return $now;
        }
        $connector = (new router())->get_route(request::STEP_SECTIONS)['connector'];
        if ($connector === fake_connector::NAME) {
            return $now;
        }
        $next = self::next_offpeak($connector, $now);
        if ($next > $now) {
            $task->set_next_run_time($next);
        }
        return $next;
    }
}
