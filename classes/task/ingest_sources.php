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

use local_aicoursebuilder\ai\budget_exceeded_exception;
use local_aicoursebuilder\ingest\chunk;
use local_aicoursebuilder\ingest\chunker;
use local_aicoursebuilder\ingest\digest_builder;
use local_aicoursebuilder\ingest\extractor_factory;
use local_aicoursebuilder\ingest\ingest_exception;
use local_aicoursebuilder\ingest\normalizer;
use local_aicoursebuilder\ingest\source_manager;
use local_aicoursebuilder\job_manager;

/**
 * Ingests the sources of a job: extracts their text, cuts it into chunks and makes their digest (spec 3.5, 3.8).
 *
 * Every source goes through three phases, in order:
 *  1. extraction: the text of the file becomes Markdown in the "extracted" file area (status pending to extracted);
 *  2. chunks: the text is normalised and cut into chunks, saved in local_aicb_chunk, and the language and the
 *     size of the source are saved;
 *  3. digest: one AI call per document makes the digest, saved in local_aicb_source (status digested).
 *
 * None of it runs in a web request. The task is idempotent and can be resumed: a phase is skipped when its
 * result is already there (the status of the source, the chunk rows, and for the digest the checkpoint in
 * local_aicb_step), so a repeated or resumed run does not repeat work, and does not pay for an AI call twice.
 * A source that fails is marked failed with its error and is not tried again by this task; it does not stop
 * the others. The job fails only when none of its sources is digested; otherwise the task queues generate_blueprint.
 * A cost limit that stops a digest pauses the job instead, leaving the source as it was, so that resuming it goes on
 * from there.
 * The job progress is the share of the phases that were done, within the ingest stage.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ingest_sources extends \core\task\adhoc_task {
    /** @var string Pipeline stage written in the job. */
    public const STAGE = 'ingest';

    /** @var string Job status while the sources are ingested. */
    public const STATUS_INGESTING = 'ingesting';

    /** @var string Source status: chunks and digest are done. */
    public const STATUS_DIGESTED = 'digested';

    /** @var int Phases of a source: extraction, chunks, digest. */
    private const PHASES = 3;

    /** @var string[] Job statuses in which the task does nothing. */
    private const DONE_STATUSES = ['cancelled', 'failed', 'finished', 'paused'];

    /** @var \stdClass[] The source rows of the job being ingested, by id, kept up to date while the task runs. */
    private array $sources = [];

    /** @var budget_exceeded_exception|null The cost limit that stopped the digest of a source, if one did. */
    private ?budget_exceeded_exception $stoppedbybudget = null;

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
     * Ingests the sources of the job, then updates the job.
     */
    #[\Override]
    public function execute(): void {
        global $DB;

        $jobid = (int) ($this->get_custom_data()->jobid ?? 0);
        $job = $DB->get_record('local_aicb_job', ['id' => $jobid]);
        if (!$job || in_array($job->status, self::DONE_STATUSES, true)) {
            return;
        }

        $this->sources = $DB->get_records('local_aicb_source', ['jobid' => $jobid], 'id');
        $this->update_job($job, [
            'status' => self::STATUS_INGESTING,
            'stage' => self::STAGE,
            'timestarted' => $job->timestarted ?: time(),
            'error' => null,
        ]);
        $this->report($job);

        $this->stoppedbybudget = null;
        foreach ($this->sources as $source) {
            $this->ingest_source($job, $source);
            gc_collect_cycles();
            if ($this->stoppedbybudget) {
                break;
            }
        }

        if ($this->stoppedbybudget) {
            // The source that was being digested is left as it was, so that resuming the job picks it up again.
            (new job_manager())->pause($job, $this->stoppedbybudget->getMessage());
            return;
        }
        if (!$this->fail_job_without_digest($job)) {
            // At least one source has a digest: the blueprint is generated from what there is.
            \core\task\manager::queue_adhoc_task(generate_blueprint::instance((int) $job->id, (int) $job->userid), true);
        }
    }

    /**
     * Takes one source through the phases that it has not been through yet.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param \stdClass $source The local_aicb_source row, updated in place.
     */
    private function ingest_source(\stdClass $job, \stdClass $source): void {
        if ($source->status === source_manager::STATUS_PENDING && !$this->run_phase($source, fn() => $this->extract($source))) {
            $this->report($job);
            return;
        }
        $this->report($job);

        if ($source->status === source_manager::STATUS_EXTRACTED) {
            if (!$this->has_chunks($source) && !$this->run_phase($source, fn() => $this->make_chunks($source))) {
                $this->report($job);
                return;
            }
            $this->report($job);
            $this->run_phase($source, fn() => $this->make_digest($job, $source));
            $this->report($job);
        }
    }

    /**
     * Runs a phase of a source; a failure marks the source failed with the error and stops its phases.
     *
     * A cost limit is not a failure of the source: the source keeps its status, and the whole task stops.
     *
     * @param \stdClass $source The local_aicb_source row, updated in place.
     * @param callable $phase The phase.
     * @return bool False when the phase failed or a cost limit stopped it.
     */
    private function run_phase(\stdClass $source, callable $phase): bool {
        global $DB;

        try {
            $phase();
            return true;
        } catch (budget_exceeded_exception $e) {
            $this->stoppedbybudget = $e;
            return false;
        } catch (\Throwable $e) {
            $source->status = source_manager::STATUS_FAILED;
            $source->error = $e->getMessage();
            $source->timemodified = time();
            $DB->update_record('local_aicb_source', $source);
            mtrace("  Source {$source->id} failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Phase 1: extracts the text of a source into the "extracted" file area and records the extractor.
     *
     * @param \stdClass $source The local_aicb_source row, updated in place.
     * @throws ingest_exception When the text cannot be extracted.
     */
    private function extract(\stdClass $source): void {
        global $DB;

        $manager = new source_manager();
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
        $source->timemodified = time();
        $DB->update_record('local_aicb_source', $source);
        if ($result->warnings) {
            mtrace("  Source {$source->id}: " . implode(', ', $result->warnings));
        }
    }

    /**
     * Phase 2: normalises the extracted text, cuts it into chunks and saves them with the language and size.
     *
     * The chunks of an earlier, interrupted run are replaced, in one transaction.
     *
     * @param \stdClass $source The local_aicb_source row, updated in place.
     * @throws ingest_exception When the extracted text is missing or has no text left after the normalisation.
     */
    private function make_chunks(\stdClass $source): void {
        global $DB;

        $file = (new source_manager())->get_extracted_file($source->id);
        if (!$file) {
            throw new ingest_exception(ingest_exception::FILE_MISSING);
        }
        $normalized = (new normalizer())->normalize($file->get_content());
        $chunks = (new chunker())->chunk($normalized->markdown);
        if (!$chunks) {
            throw new ingest_exception(ingest_exception::NO_TEXT);
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_aicb_chunk', ['sourceid' => $source->id]);
        $rows = [];
        foreach ($chunks as $chunk) {
            $rows[] = (object) [
                'jobid' => $source->jobid,
                'sourceid' => $source->id,
                'chunkindex' => $chunk->index,
                'title' => $chunk->title,
                'pagefrom' => $chunk->pagefrom,
                'pageto' => $chunk->pageto,
                'content' => $chunk->content,
                'tokencount' => $chunk->tokencount,
                'contenthash' => sha1($chunk->content),
                'timecreated' => $now,
            ];
        }
        $DB->insert_records('local_aicb_chunk', $rows);

        $source->language = $normalized->language;
        $source->tokencount = array_sum(array_map(fn($chunk) => $chunk->tokencount, $chunks));
        $source->timemodified = $now;
        $DB->update_record('local_aicb_source', $source);
        $transaction->allow_commit();
    }

    /**
     * Phase 3: makes the digest of the source from its chunks and saves it.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param \stdClass $source The local_aicb_source row, updated in place.
     */
    private function make_digest(\stdClass $job, \stdClass $source): void {
        global $DB;

        $chunks = array_map(
            fn($row) => new chunk(
                (int) $row->chunkindex,
                $row->title,
                $row->pagefrom === null ? null : (int) $row->pagefrom,
                $row->pageto === null ? null : (int) $row->pageto,
                $row->content,
                (int) $row->tokencount
            ),
            array_values($DB->get_records('local_aicb_chunk', ['sourceid' => $source->id], 'chunkindex'))
        );
        $digest = (new digest_builder())->build($job, $source, $chunks);

        $source->digest = json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $source->status = self::STATUS_DIGESTED;
        $source->error = null;
        $source->timemodified = time();
        $DB->update_record('local_aicb_source', $source);
    }

    /**
     * Tells whether the chunks of a source are saved.
     *
     * @param \stdClass $source The local_aicb_source row.
     * @return bool
     */
    private function has_chunks(\stdClass $source): bool {
        global $DB;
        return $DB->record_exists('local_aicb_chunk', ['sourceid' => $source->id]);
    }

    /**
     * Fails the job when none of its sources is digested, with the reason.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return bool True when the job was failed.
     */
    private function fail_job_without_digest(\stdClass $job): bool {
        global $DB;

        if ($DB->record_exists('local_aicb_source', ['jobid' => $job->id, 'status' => self::STATUS_DIGESTED])) {
            return false;
        }
        // Some text was extracted: the sources failed later, in the chunks or the digest.
        $extracted = $DB->record_exists_select(
            'local_aicb_source',
            'jobid = :jobid AND extractor IS NOT NULL',
            ['jobid' => $job->id]
        );
        $message = $this->sources ? ($extracted ? 'ingestnodigest' : 'ingestallfailed') : 'ingestnosources';
        $this->update_job($job, [
            'status' => 'failed',
            'error' => get_string($message, 'local_aicoursebuilder'),
            'statusmessage' => null,
            'timefinished' => time(),
        ]);
        return true;
    }

    /**
     * Writes the progress of the job: the share of the phases that are done, and how many sources are finished.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     */
    private function report(\stdClass $job): void {
        $total = count($this->sources);
        $done = 0;
        $finished = 0;
        foreach ($this->sources as $source) {
            $phases = $this->phases_done($source);
            $done += $phases;
            $finished += $phases === self::PHASES ? 1 : 0;
        }
        $this->update_job($job, [
            'progress' => $total ? intdiv($done * 100, self::PHASES * $total) : 0,
            'statusmessage' => get_string('ingestprogress', 'local_aicoursebuilder', ['done' => $finished, 'total' => $total]),
        ]);
    }

    /**
     * Returns how many phases of a source are done; a failed source counts as done, so that the job can finish.
     *
     * @param \stdClass $source The local_aicb_source row.
     * @return int 0 to PHASES.
     */
    private function phases_done(\stdClass $source): int {
        return match ($source->status) {
            source_manager::STATUS_PENDING => 0,
            source_manager::STATUS_FAILED, self::STATUS_DIGESTED => self::PHASES,
            default => $this->has_chunks($source) ? 2 : 1,
        };
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
