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

use local_aicoursebuilder\ai\offpeak_scheduler;
use local_aicoursebuilder\builder\build_context;
use local_aicoursebuilder\task\build_course;
use local_aicoursebuilder\task\generate_blueprint;
use local_aicoursebuilder\task\ingest_sources;

/**
 * What the owner of a job that failed for good can do: try again, or give up and undo what was built.
 *
 * The course of a new-course job stays hidden until the build succeeds, so a failed job leaves nothing a student
 * can see. Giving up deletes that course. In an existing course only the activities the build made are deleted,
 * the ones whose cmids are in the build map of the job; whatever else the course holds is never touched.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_recovery {
    /** @var string Job status after a rollback. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Tries a failed job again, from the checkpoints it had reached.
     *
     * A job that failed while building queues the build again, which skips the nodes already built. Any other job
     * queues the stage it failed in, which skips the pipeline steps already paid for.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @throws \moodle_exception When the job has not failed, or the owner has not accepted the AI policy.
     */
    public function retry(\stdClass $job): void {
        global $DB;

        if ($job->status !== job_manager::STATUS_FAILED) {
            throw new \moodle_exception('jobnotfailed', 'local_aicoursebuilder');
        }
        $common = ['id' => $job->id, 'error' => null, 'timefinished' => null, 'timemodified' => time()];

        if ($job->stage === build_course::STAGE && !empty($job->blueprintid)) {
            // The blueprint was approved, so the build needs no AI: it runs at the next cron.
            $DB->update_record('local_aicb_job', (object) ($common + ['status' => 'approved', 'statusmessage' => null]));
            build_course::queue((int) $job->id);
            return;
        }

        if (!\core_ai\manager::get_user_policy_status((int) $job->userid)) {
            throw new \moodle_exception('aipolicynotaccepted', 'local_aicoursebuilder');
        }
        $ingesting = $job->stage === ingest_sources::STAGE;
        $DB->update_record('local_aicb_job', (object) ($common + [
            'status' => job_manager::STATUS_QUEUED,
            'stage' => $ingesting ? ingest_sources::STAGE : generate_blueprint::STAGE,
            'statusmessage' => null,
        ]));
        $task = $ingesting
            ? ingest_sources::instance((int) $job->id, (int) $job->userid)
            : generate_blueprint::instance((int) $job->id, (int) $job->userid);
        offpeak_scheduler::schedule($task, $job);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Gives a failed job up: deletes what the build made and cancels the job.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @throws \moodle_exception When the job has not failed.
     */
    public function rollback(\stdClass $job): void {
        global $DB;

        if ($job->status !== job_manager::STATUS_FAILED) {
            throw new \moodle_exception('jobnotfailed', 'local_aicoursebuilder');
        }
        $courseid = (int) ($job->courseid ?? 0);
        $course = $courseid > 0 ? $DB->get_record('course', ['id' => $courseid]) : false;
        $newcourse = ($job->mode ?? 'newcourse') !== 'existingcourse';

        if ($course) {
            if ($newcourse) {
                delete_course($course, false);
            } else {
                $this->delete_built_modules($job, $course);
            }
        }
        $DB->update_record('local_aicb_job', (object) [
            'id' => $job->id,
            'status' => self::STATUS_CANCELLED,
            'buildmap' => null,
            // The course of a new-course job is gone; the course of an existing-course job is where it was aimed.
            'courseid' => $newcourse ? null : $job->courseid,
            'statusmessage' => get_string('rolledback', 'local_aicoursebuilder'),
            'timemodified' => time(),
        ]);
    }

    /**
     * Deletes the activities the build recorded in an existing course, and only those.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param \stdClass $course The existing course.
     */
    protected function delete_built_modules(\stdClass $job, \stdClass $course): void {
        global $DB;

        $cmids = array_values(build_context::from_job($job, $course)->get_cmidmap());
        if ($cmids === []) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        // A cmid of another course, or one already gone, is left alone.
        $existing = $DB->get_fieldset_select(
            'course_modules',
            'id',
            "course = :course AND id $insql",
            $params + ['course' => $course->id]
        );
        // The global course_delete_module() is deprecated since 5.2 (MDL-86856) in favour of the format actions.
        $cmactions = new \core_courseformat\local\cmactions($course);
        foreach ($existing as $cmid) {
            $cmactions->delete((int) $cmid, false);
        }
    }
}
