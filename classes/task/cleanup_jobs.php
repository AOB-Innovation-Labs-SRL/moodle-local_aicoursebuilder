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

namespace local_aicoursebuilder\task;

use local_aicoursebuilder\ingest\source_manager;

/**
 * Deletes the jobs that ended long ago, with their sources, chunks, pipeline steps and blueprints.
 *
 * The retention is the setting jobretentiondays; 0 keeps everything. Only a finished, failed or cancelled job is
 * deleted, by the time it was last changed. The courses the jobs built are never touched, and neither are the AI call
 * log and the budget rows, which hold the spending of the site and carry no content.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_jobs extends \core\task\scheduled_task {
    /** @var string[] Statuses of a job that has ended and may be deleted. */
    public const ENDED_STATUSES = ['finished', 'failed', 'cancelled'];

    /** @var int Jobs deleted in one run, so that a site with a long backlog is cleaned over several nights. */
    public const BATCH = 200;

    /**
     * Returns the name of the task.
     *
     * @return string
     */
    #[\Override]
    public function get_name(): string {
        return get_string('task_cleanupjobs', 'local_aicoursebuilder');
    }

    /**
     * Deletes the jobs past the retention.
     */
    #[\Override]
    public function execute(): void {
        $days = (int) get_config('local_aicoursebuilder', 'jobretentiondays');
        if ($days <= 0) {
            return;
        }
        $deleted = self::delete_older_than(time() - $days * DAYSECS);
        mtrace("Deleted {$deleted} job(s) older than {$days} days.");
    }

    /**
     * Deletes the ended jobs last changed before a moment.
     *
     * @param int $before Unix timestamp.
     * @return int Jobs deleted.
     */
    public static function delete_older_than(int $before): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::ENDED_STATUSES, SQL_PARAMS_NAMED);
        $jobids = $DB->get_fieldset_select(
            'local_aicb_job',
            'id',
            "status $insql AND timemodified < :before ORDER BY timemodified",
            $params + ['before' => $before],
            0,
            self::BATCH
        );
        foreach ($jobids as $jobid) {
            self::delete_job((int) $jobid);
        }
        return count($jobids);
    }

    /**
     * Deletes one job with everything that depends on it, except its AI call log.
     *
     * @param int $jobid The job.
     */
    protected static function delete_job(int $jobid): void {
        global $DB;

        $fs = get_file_storage();
        $sourceids = $DB->get_fieldset_select('local_aicb_source', 'id', 'jobid = ?', [$jobid]);
        if ($sourceids) {
            [$sourcesql, $sourceparams] = $DB->get_in_or_equal($sourceids, SQL_PARAMS_NAMED);
            [$areasql, $areaparams] = $DB->get_in_or_equal(
                [source_manager::AREA_SOURCE, source_manager::AREA_EXTRACTED],
                SQL_PARAMS_NAMED
            );
            $fileids = $DB->get_fieldset_select(
                'files',
                'id',
                "component = :component AND filearea $areasql AND itemid $sourcesql",
                $sourceparams + $areaparams + ['component' => source_manager::COMPONENT]
            );
            foreach ($fileids as $fileid) {
                $fs->get_file_by_id($fileid)?->delete();
            }
        }
        $transaction = $DB->start_delegated_transaction();
        foreach (['local_aicb_chunk', 'local_aicb_source', 'local_aicb_step', 'local_aicb_blueprint'] as $table) {
            $DB->delete_records($table, ['jobid' => $jobid]);
        }
        // The job row goes last; the call log stays, but no longer points at a job that is gone.
        $DB->set_field('local_aicb_ailog', 'jobid', null, ['jobid' => $jobid]);
        $DB->delete_records('local_aicb_job', ['id' => $jobid]);
        $transaction->allow_commit();
    }
}
