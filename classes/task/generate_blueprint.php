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

use local_aicoursebuilder\ai\budget_guard;
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\blueprint\schema_store;
use local_aicoursebuilder\blueprint\validator;
use local_aicoursebuilder\ingest\source_manager;
use local_aicoursebuilder\job_manager;
use local_aicoursebuilder\pipeline\orchestrator;
use local_aicoursebuilder\pipeline\pipeline_context;
use local_aicoursebuilder\pipeline\pipeline_outcome;
use local_aicoursebuilder\pipeline\step_store;

/**
 * Generates the blueprint of a job: brief, outline and sections, through the orchestrator (spec 3.6, 3.8).
 *
 * The task reads what the ingestion left (the extracted text and the digest of every source that made it), hands it
 * to the orchestrator and keeps what comes back as the first version of the blueprint, ready for the teacher to
 * review. It makes the AI calls so that no web request has to; the orchestrator persists every step as it
 * finishes, so a task that is stopped or run again resumes from where it got to and pays only for what is left.
 *
 * A job can stop in two ways, and both are written in the job. A run that reached the end leaves the job in review,
 * even when the validator still finds something in the blueprint or a section is marked for a human: that is
 * what the review is for. A run that could not produce the brief or the outline, or that hit a cost limit, fails
 * the job with the reason, and the teacher is told.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_blueprint extends \core\task\adhoc_task {
    /** @var string Pipeline stage written in the job. */
    public const STAGE = 'generate';

    /** @var string Job status while the pipeline runs. */
    public const STATUS_GENERATING = 'generating';

    /** @var string[] Job statuses in which the task may start or resume the pipeline. */
    private const RUNNABLE_STATUSES = ['queued', 'ingesting', self::STATUS_GENERATING];

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
        return get_string('task_generateblueprint', 'local_aicoursebuilder');
    }

    /**
     * Runs the pipeline for the job and saves the blueprint.
     */
    #[\Override]
    public function execute(): void {
        global $DB;

        $jobid = (int) ($this->get_custom_data()->jobid ?? 0);
        $job = $DB->get_record('local_aicb_job', ['id' => $jobid]);
        if (!$job || !in_array($job->status, self::RUNNABLE_STATUSES, true)) {
            return;
        }

        $this->update_job($job, [
            'status' => self::STATUS_GENERATING,
            'stage' => self::STAGE,
            'progress' => 0,
            'statusmessage' => get_string('generateprogress', 'local_aicoursebuilder'),
            'timestarted' => $job->timestarted ?: time(),
            'error' => null,
        ]);

        try {
            $outcome = (new orchestrator($this->make_context($job)))->run(
                $this->make_prompt($job),
                $job->mode === job_manager::MODE_EXISTINGCOURSE ? 'existingcourse' : 'newcourse'
            );
        } catch (\Throwable $e) {
            // A fault the pipeline did not expect ends the job rather than retrying it for ever; what the pipeline
            // finished before it is saved, so starting the job again resumes from there.
            $this->fail($job, $e->getMessage());
            return;
        }

        if (!$outcome->blueprint) {
            $this->fail($job, $outcome->message, $outcome->totals);
            return;
        }
        $this->finish($job, $outcome);
    }

    /**
     * Builds the context of the pipeline from what the ingestion left.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return pipeline_context
     */
    private function make_context(\stdClass $job): pipeline_context {
        global $DB;

        $manager = new source_manager();
        $sourcetexts = [];
        $digests = [];
        $sources = $DB->get_records('local_aicb_source', ['jobid' => $job->id, 'status' => ingest_sources::STATUS_DIGESTED], 'id');
        foreach ($sources as $source) {
            $key = 'src' . $source->id;
            $file = $manager->get_extracted_file((int) $source->id);
            $digest = json_decode((string) $source->digest, true);
            if (!$file || !is_array($digest)) {
                continue;
            }
            $sourcetexts[$key] = $file->get_content();
            $digests[$key] = $digest;
        }

        $schemas = new schema_store();
        return new pipeline_context(
            jobid: (int) $job->id,
            userid: (int) $job->userid,
            language: $job->language ?: pipeline_context::DEFAULT_LANGUAGE,
            router: new router(),
            budget: new budget_guard(),
            steps: new step_store(),
            validator: new validator($schemas),
            schemas: $schemas,
            sourcetexts: $sourcetexts,
            digests: $digests,
            contextid: job_manager::get_context($job)->id,
        );
    }

    /**
     * Returns the request the brief step is written from: the teacher's prompt and what the wizard asked for.
     *
     * The wizard's brief fields are decisions of the teacher, so they go to the model as part of the request and
     * the brief step turns them, with the digests, into the formal brief. They are instructions to the model and
     * stay in English like the rest of the prompts.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return string
     */
    private function make_prompt(\stdClass $job): string {
        $prompt = $job->prompt;
        $brief = json_decode((string) $job->brief, true);
        if (!is_array($brief)) {
            return $prompt;
        }
        $labels = [
            'audience' => 'Audience',
            'level' => 'Level',
            'duration_minutes' => 'Duration in minutes',
            'tone' => 'Tone',
        ];
        $lines = [];
        foreach ($labels as $key => $label) {
            if (isset($brief[$key]) && is_scalar($brief[$key]) && trim((string) $brief[$key]) !== '') {
                $lines[] = $label . ': ' . trim((string) $brief[$key]);
            }
        }
        return $lines ? $prompt . "\n\nThe teacher also asked for:\n" . implode("\n", $lines) : $prompt;
    }

    /**
     * Puts the job in review, with the blueprint the pipeline produced.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param pipeline_outcome $outcome What the pipeline produced.
     */
    private function finish(\stdClass $job, pipeline_outcome $outcome): void {
        // The orchestrator saves the blueprint itself when it validates. One it could not save, because the
        // validator still finds something in it, is kept here as a draft, so the teacher can fix it in review.
        if ($outcome->errors !== []) {
            $this->save_invalid_draft($job, $outcome->blueprint);
        }

        $needsattention = count($outcome->manualnodes) + ($outcome->errors ? 1 : 0);
        $this->update_job($job, [
            'status' => job_manager::STATUS_REVIEW,
            'progress' => 100,
            'actualcost' => $outcome->totals['cost'] ?? $job->actualcost,
            'statusmessage' => get_string($needsattention ? 'generatereviewattention' : 'generatereview', 'local_aicoursebuilder'),
            'timefinished' => time(),
        ]);
        $this->notify($job, 'jobfinished');
    }

    /**
     * Keeps a blueprint that does not validate as the next draft version of the job.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param array $blueprint The blueprint.
     */
    private function save_invalid_draft(\stdClass $job, array $blueprint): void {
        global $DB;

        $content = json_encode($blueprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $latest = $DB->get_field_sql('SELECT MAX(version) FROM {local_aicb_blueprint} WHERE jobid = ?', [$job->id]);
        $now = time();
        $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $job->id,
            'version' => 1 + (int) $latest,
            'schemaversion' => $blueprint['version'] ?? schema_store::SCHEMA_VERSION,
            'content' => $content,
            'contenthash' => hash('sha256', $content),
            'status' => 'draft',
            'usermodified' => $job->userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Ends the job with an error and tells the teacher.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param string $message What stopped the job.
     * @param array $totals What the job has spent so far, from step_store::totals().
     */
    private function fail(\stdClass $job, string $message, array $totals = []): void {
        $this->update_job($job, [
            'status' => job_manager::STATUS_FAILED,
            'error' => $message,
            'statusmessage' => null,
            'actualcost' => $totals['cost'] ?? $job->actualcost,
            'timefinished' => time(),
        ]);
        $this->notify($job, 'jobfailed');
    }

    /**
     * Tells the owner of the job that it finished or failed.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param string $provider Message provider: jobfinished or jobfailed.
     */
    private function notify(\stdClass $job, string $provider): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $job->userid]);
        if (!$user) {
            return;
        }
        $url = new \moodle_url('/local/aicoursebuilder/wizard.php', ['jobid' => $job->id]);
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
