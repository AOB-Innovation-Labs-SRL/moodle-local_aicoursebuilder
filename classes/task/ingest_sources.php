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

use local_aicoursebuilder\ingest\extractor_factory;
use local_aicoursebuilder\ingest\ingest_exception;
use local_aicoursebuilder\ingest\source_manager;

/**
 * Extracts the text of the pending sources of a job (spec 3.5, 3.8).
 *
 * Extraction never runs in a web request. The task is idempotent: it only looks at sources with
 * the status pending, so a repeated or resumed run skips the sources that are already extracted
 * (or failed). A source that fails does not stop the others; the job fails only when none of its
 * sources has text. The job progress is the share of sources that were handled, within the ingest stage.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ingest_sources extends \core\task\adhoc_task {
    /** @var string Pipeline stage written in the job. */
    public const STAGE = 'ingest';

    /** @var string Job status while the sources are extracted. */
    public const STATUS_INGESTING = 'ingesting';

    /** @var string[] Job statuses in which the task does nothing. */
    private const DONE_STATUSES = ['cancelled', 'failed', 'finished'];

    /**
     * Creates the task of a job, ready to be queued.
     *
     * @param int $jobid Job id.
     * @param int $userid Owner of the job, the user the task runs as.
     * @return self
     */
    public static function instance(int $jobid, int $userid): self {
        $task = new self();
        $task->set_custom_data(['jobid' => $jobid]);
        $task->set_userid($userid);
        return $task;
    }

    /**
     * Returns the name of the task.
     *
     * @return string
     */
    #[\Override]
    public function get_name(): string {
        return get_string('task_ingestsources', 'local_aicoursebuilder');
    }

    /**
     * Extracts the text of the pending sources of the job, then updates the job.
     */
    #[\Override]
    public function execute(): void {
        global $DB;

        $jobid = (int) ($this->get_custom_data()->jobid ?? 0);
        $job = $DB->get_record('local_aicb_job', ['id' => $jobid]);
        if (!$job || in_array($job->status, self::DONE_STATUSES, true)) {
            return;
        }

        $sources = $DB->get_records('local_aicb_source', ['jobid' => $jobid], 'id');
        $total = count($sources);
        $handled = 0;
        foreach ($sources as $source) {
            if ($source->status !== source_manager::STATUS_PENDING) {
                $handled++;
            }
        }

        $this->update_job($job, [
            'status' => self::STATUS_INGESTING,
            'stage' => self::STAGE,
            'progress' => $total ? intdiv($handled * 100, $total) : 0,
            'statusmessage' => $this->progress_message($handled, $total),
            'timestarted' => $job->timestarted ?: time(),
            'error' => null,
        ]);

        foreach ($sources as $source) {
            if ($source->status !== source_manager::STATUS_PENDING) {
                continue;
            }
            $this->ingest_source($source);
            $handled++;
            gc_collect_cycles();
            $this->update_job($job, [
                'progress' => intdiv($handled * 100, $total),
                'statusmessage' => $this->progress_message($handled, $total),
            ]);
        }

        $extracted = $DB->count_records_select(
            'local_aicb_source',
            'jobid = :jobid AND status <> :failed AND status <> :pending',
            ['jobid' => $jobid, 'failed' => source_manager::STATUS_FAILED, 'pending' => source_manager::STATUS_PENDING]
        );
        if ($extracted === 0) {
            $this->update_job($job, [
                'status' => 'failed',
                'error' => get_string($total ? 'ingestallfailed' : 'ingestnosources', 'local_aicoursebuilder'),
                'statusmessage' => null,
                'timefinished' => time(),
            ]);
        }
    }

    /**
     * Extracts one source and records the outcome in its row.
     *
     * @param \stdClass $source The local_aicb_source row.
     */
    private function ingest_source(\stdClass $source): void {
        global $DB;

        $manager = new source_manager();
        try {
            $file = $manager->get_source_file($source->id);
            if (!$file) {
                throw new ingest_exception(ingest_exception::FILE_MISSING);
            }
            $result = extractor_factory::for_file($file)->extract($file);
            $manager->save_extracted($source, $file, $result->markdown);

            $source->status = source_manager::STATUS_EXTRACTED;
            $source->extractor = $result->extractor;
            $source->pagecount = $result->pagecount;
            $source->error = null;
            if ($result->warnings) {
                mtrace("  Source {$source->id}: " . implode(', ', $result->warnings));
            }
        } catch (\Throwable $e) {
            $source->status = source_manager::STATUS_FAILED;
            $source->error = $e->getMessage();
            mtrace("  Source {$source->id} failed: " . $e->getMessage());
        }
        $source->timemodified = time();
        $DB->update_record('local_aicb_source', $source);
    }

    /**
     * Returns the short progress message of the job.
     *
     * @param int $handled Sources that are extracted or failed.
     * @param int $total Sources of the job.
     * @return string
     */
    private function progress_message(int $handled, int $total): string {
        return get_string('ingestprogress', 'local_aicoursebuilder', ['done' => $handled, 'total' => $total]);
    }

    /**
     * Updates fields of the job row.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param array $fields Field values.
     */
    private function update_job(\stdClass $job, array $fields): void {
        global $DB;

        foreach ($fields as $name => $value) {
            $job->{$name} = $value;
        }
        $job->timemodified = time();
        $DB->update_record('local_aicb_job', $job);
    }
}
