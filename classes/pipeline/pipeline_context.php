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

namespace local_aicoursebuilder\pipeline;

use local_aicoursebuilder\ai\budget_guard;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\blueprint\schema_store;
use local_aicoursebuilder\blueprint\validator;

/**
 * Everything a pipeline step needs that is the same for every step of a job.
 *
 * Built once by the orchestrator and handed to each step, so the collaborators can be swapped in a
 * test without any step knowing.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pipeline_context {
    /** @var string Language a course is written in when the job does not say otherwise. */
    public const DEFAULT_LANGUAGE = 'ro';

    /** @var array<string, string> English names of the languages the pilot writes courses in. */
    protected const LANGUAGE_NAMES = [
        'ro' => 'Romanian',
        'en' => 'English',
        'fr' => 'French',
        'de' => 'German',
        'es' => 'Spanish',
        'it' => 'Italian',
    ];

    /**
     * Creates the context.
     *
     * @param int $jobid Job the pipeline runs for.
     * @param int $userid User the job belongs to, for the AI policy and the budget.
     * @param string $language Language of the course, such as ro.
     * @param router $router Router choosing the connector and model of each step.
     * @param budget_guard $budget Guard reserving and settling the spend of each call.
     * @param step_store $steps Store persisting each step and handing back finished ones.
     * @param validator $validator Validator of the blueprint and of the step fragments.
     * @param schema_store $schemas Store of the JSON schema documents.
     * @param string[] $sourcetexts Text of each source document, keyed by source id (src1, src2...).
     * @param array $digests Digest of each source document, keyed by source id.
     * @param int|null $contextid Context the calls run in, null for the system context.
     * @param string $promptversion Version of the prompts, part of every step hash.
     * @param string|null $promptdir Directory holding the prompts, null for the plugin's prompts/.
     */
    public function __construct(
        /** @var int Job the pipeline runs for. */
        public readonly int $jobid,
        /** @var int User the job belongs to. */
        public readonly int $userid,
        /** @var string Language of the course. */
        public readonly string $language,
        /** @var router Router of connectors and models. */
        public readonly router $router,
        /** @var budget_guard Guard of the spend. */
        public readonly budget_guard $budget,
        /** @var step_store Store of the steps. */
        public readonly step_store $steps,
        /** @var validator Validator of the output. */
        public readonly validator $validator,
        /** @var schema_store Store of the schemas. */
        public readonly schema_store $schemas,
        /** @var string[] Text of each source document, keyed by source id. */
        public readonly array $sourcetexts = [],
        /** @var array Digest of each source document, keyed by source id. */
        public readonly array $digests = [],
        /** @var int|null Context the calls run in. */
        public readonly ?int $contextid = null,
        /** @var string Version of the prompts, which is part of every step hash. */
        public readonly string $promptversion = prompt::VERSION,
        /** @var string|null Directory holding the prompts. */
        public readonly ?string $promptdir = null,
    ) {
    }

    /**
     * Returns the schema version the blueprint documents describe, which is part of the step hash.
     *
     * @return string
     */
    public function get_schemaversion(): string {
        return schema_store::SCHEMA_VERSION;
    }

    /**
     * Returns the English name of the course language, for the prompts.
     *
     * The prompts are written in English and name the target language in English, so a model does
     * not have to know what a tag like ro means.
     *
     * @return string
     */
    public function language_name(): string {
        $code = strtolower(substr($this->language, 0, 2));
        return self::LANGUAGE_NAMES[$code] ?? $this->language;
    }

    /**
     * Returns the ids of the sources available to this job.
     *
     * @return string[]
     */
    public function source_ids(): array {
        return array_keys($this->sourcetexts ?: $this->digests);
    }

    /**
     * Returns the source ids as the list that goes into a prompt.
     *
     * @return string
     */
    public function source_ids_text(): string {
        $ids = $this->source_ids();
        return $ids === [] ? 'None: there are no source documents.' : implode(', ', $ids);
    }

    /**
     * Returns the user message of a step.
     *
     * These are instructions to the model, not text a teacher ever sees, so they stay in English
     * and out of the language packs, like the validator messages they travel with.
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @return string
     */
    public function step_instruction(string $step): string {
        return match ($step) {
            request::STEP_DIGEST => 'Write the digest of this document as a json object.',
            request::STEP_BRIEF => 'Write the brief of this course as a json object.',
            request::STEP_OUTLINE => 'Write the outline of this course as a json object.',
            request::STEP_SECTIONS => 'Write the activities of this section as a json object.',
            default => 'Answer with a json object.',
        };
    }

    /**
     * Returns the user message of a repair call.
     *
     * @return string
     */
    public function repair_instruction(): string {
        return 'Return the corrected json document.';
    }

    /**
     * Adds what one call cost to the job's running total.
     *
     * The per-job cost limit is checked against local_aicb_job.actualcost, which nothing else
     * updates, so a job would never reach its own limit if the calls did not report back. This runs
     * per call rather than per step because a node that repairs itself makes several, and measuring
     * them all against the total as it stood before the node began would overshoot the limit.
     *
     * A negative amount takes one off again, which is how the orchestrator holds the estimates of a
     * batch against the job while it is in flight and releases them as the answers are settled.
     *
     * @param float $costusd What to add, in USD; negative to take an amount off again.
     */
    public function add_job_cost(float $costusd): void {
        global $DB;

        if ($costusd === 0.0) {
            return;
        }
        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid], 'id, actualcost', MUST_EXIST);
        $total = max(0.0, (float) $job->actualcost + $costusd);
        $DB->set_field('local_aicb_job', 'actualcost', $total, ['id' => $this->jobid]);
    }

    /**
     * Writes what every finished step of the job has spent onto the job itself.
     *
     * Called when a step finishes, so the running total that add_job_cost() keeps is replaced by
     * the sum of what was actually persisted, and a call that was made but never stored cannot
     * drift the two apart.
     */
    public function record_job_cost(): void {
        global $DB;

        $DB->set_field('local_aicb_job', 'actualcost', $this->steps->totals($this->jobid)['cost'], [
            'id' => $this->jobid,
        ]);
    }
}
