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

namespace local_aicoursebuilder\external;

use core_external\external_api;

/**
 * Shared access checks of the job web services.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class job_api extends external_api {
    /**
     * Loads a job and checks that the current user may use it.
     *
     * The owner needs local/aicoursebuilder:use in the job context. Another user may only read the
     * job, and needs local/aicoursebuilder:manage in the system context; changes are for the owner only.
     *
     * @param int $jobid Job id.
     * @param bool $write True when the call changes the job (save, approve, regenerate).
     * @return \stdClass The local_aicb_job record.
     */
    protected static function validate_job(int $jobid, bool $write = false): \stdClass {
        global $DB, $USER;

        $job = $DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST);
        $context = self::get_job_context($job);
        self::validate_context($context);
        require_capability('local/aicoursebuilder:use', $context);
        if ((int) $job->userid !== (int) $USER->id) {
            if ($write) {
                throw new \moodle_exception('notjobowner', 'local_aicoursebuilder');
            }
            require_capability('local/aicoursebuilder:manage', \context_system::instance());
        }
        return $job;
    }

    /**
     * Returns the context of a job: its course, else its category, else the system.
     *
     * @param \stdClass $job The local_aicb_job record.
     * @return \context
     */
    public static function get_job_context(\stdClass $job): \context {
        if (!empty($job->courseid)) {
            return \context_course::instance($job->courseid);
        }
        if (!empty($job->categoryid)) {
            return \context_coursecat::instance($job->categoryid);
        }
        return \context_system::instance();
    }

    /**
     * Stops a function whose implementation belongs to a later task.
     *
     * @return never
     */
    protected static function not_implemented(): never {
        throw new \moodle_exception('notimplemented', 'local_aicoursebuilder');
    }
}
