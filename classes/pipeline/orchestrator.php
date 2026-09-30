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

use local_aicoursebuilder\ai\budget_exceeded_exception;
use local_aicoursebuilder\ai\parallel_executor;
use local_aicoursebuilder\ai\result;

/**
 * Runs the generation pipeline: brief, then outline, then the sections (spec 3.6).
 *
 * Every step is persisted as it finishes, so a job that stops halfway resumes from where it got to
 * and pays only for what is left. Stopping happens in one of two ways, and both leave the work done
 * so far intact: a budget limit, which stops the pipeline between sub-calls, and a step that could
 * not be produced at all.
 *
 * The sections are independent of one another, so their first calls go out as one concurrent batch;
 * everything after the first answer, the checking, the repairs and the persistence, stays per node.
 *
 * A section that cannot be written does not stop anything. It is kept as a node marked for a human
 * to complete, and the rest of the course is built around it.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator {
    /** @var pipeline_context The job this pipeline runs for. */
    protected pipeline_context $context;

    /**
     * Creates the orchestrator.
     *
     * @param pipeline_context $context The job this pipeline runs for.
     */
    public function __construct(pipeline_context $context) {
        $this->context = $context;
    }

    /**
     * Runs the pipeline as far as it gets and returns what it built.
     *
     * @param string $prompt The teacher's request.
     * @param string $target newcourse or existingcourse.
     * @return pipeline_outcome What was built, and why it stopped if it did.
     */
    public function run(string $prompt, string $target = 'newcourse'): pipeline_outcome {
        try {
            return $this->generate($prompt, $target);
        } catch (budget_exceeded_exception $e) {
            // Everything finished so far is already persisted, so a later run resumes from here.
            return pipeline_outcome::stopped(
                pipeline_outcome::REASON_BUDGET,
                $e->getMessage(),
                $this->context->steps->totals($this->context->jobid),
            );
        }
    }

    /**
     * Runs the three steps and assembles their output into a blueprint.
     *
     * @param string $prompt The teacher's request.
     * @param string $target newcourse or existingcourse.
     * @return pipeline_outcome
     * @throws budget_exceeded_exception When a limit stops the pipeline.
     */
    protected function generate(string $prompt, string $target): pipeline_outcome {
        $brief = (new step_brief($this->context))->run(['prompt' => $prompt, 'target' => $target]);
        if (!$brief->is_success()) {
            return $this->failed(pipeline_outcome::REASON_BRIEF, $brief->error);
        }

        $outline = (new step_outline($this->context))->run(['brief' => $brief->output]);
        if (!$outline->is_success()) {
            return $this->failed(pipeline_outcome::REASON_OUTLINE, $outline->error);
        }

        $sections = $this->run_sections($brief->output, $outline->output);
        $blueprint = $this->assemble($outline->output, $sections);

        return pipeline_outcome::completed(
            blueprint: $blueprint,
            brief: $brief->output,
            manualnodes: $this->manual_nodes($sections),
            errors: $this->context->validator->validate($blueprint, $this->context->sourcetexts),
            totals: $this->context->steps->totals($this->context->jobid),
        );
    }

    /**
     * Runs one sub-call per section and subsection of the outline.
     *
     * The sub-calls are independent, so a failure is confined to its own section: the others are
     * written anyway, and the failed one comes back as a node for a human to complete.
     *
     * @param array $brief The confirmed brief.
     * @param array $outline The outline.
     * @return array<string, step_result> Result per section id.
     * @throws budget_exceeded_exception When a limit stops the pipeline between sub-calls.
     */
    protected function run_sections(array $brief, array $outline): array {
        $step = new step_sections($this->context);
        $inputs = [];
        foreach ($this->containers($outline) as $id => $container) {
            $inputs[$id] = ['brief' => $brief, 'outline' => $outline, 'section' => $container];
        }
        if ($inputs === []) {
            return [];
        }

        $answers = $this->first_answers($step, $inputs);

        $results = [];
        foreach ($inputs as $id => $input) {
            // A sub-call whose first answer never arrived is run again on its own, so it still gets
            // its repair attempts and its placeholder rather than being dropped for a network fault.
            $results[$id] = $step->run($input, $id, $answers[$id] ?? null);
        }
        return $results;
    }

    /**
     * Sends the first call of every section at once and returns the answers that arrived.
     *
     * The sections of a course are independent, so their first calls are sent concurrently rather
     * than one after another (spec 3.8). Only the first call of each is batched: what follows it,
     * the validation, the repairs and the persistence, is per node and stays in the step.
     *
     * A section already finished by an earlier run is left out of the batch entirely, so resuming a
     * job does not pay for answers it already has.
     *
     * @param step_sections $step The step the requests belong to.
     * @param array<string, array> $inputs Input per section id.
     * @return array<string, result> Answer per section id, for those that arrived.
     * @throws budget_exceeded_exception When a limit stops the batch before it is sent.
     */
    protected function first_answers(step_sections $step, array $inputs): array {
        $requests = [];
        $reservations = [];
        foreach ($inputs as $id => $input) {
            if ($step->has_finished($input, $id)) {
                continue;
            }
            $requests[$id] = $step->request_for($input, $id);
        }
        if ($requests === []) {
            return [];
        }

        $route = $this->context->router->get_route($step->get_step());
        $connector = $this->context->router->raw_connector($step->get_step());

        // Every sub-call of the batch is reserved before any of it is sent, so a batch that cannot
        // be afforded stops the job here rather than halfway through its own answers.
        //
        // The job's own limit has no reservation row of its own: budget_guard checks it against
        // what the job has already spent. That is enough when calls are made one at a time, but a
        // batch would reserve every sub-call against the same starting total and sail past the
        // limit, so what this batch has reserved so far is added to the job as it goes and taken
        // off again once each answer is settled at its real cost.
        try {
            foreach ($requests as $id => $request) {
                $estimate = $connector->estimate_cost($request);
                $reservations[$id] = $this->context->budget->reserve(
                    $this->context->jobid,
                    $this->context->userid,
                    $estimate,
                );
                $this->context->add_job_cost($estimate);
                $reservations[$id]['jobestimate'] = $estimate;
            }
        } catch (budget_exceeded_exception $e) {
            foreach ($reservations as $reservation) {
                $this->context->budget->release($reservation);
                $this->context->add_job_cost(-$reservation['jobestimate']);
            }
            throw $e;
        }

        $executor = new parallel_executor($connector, $route['connector']);
        $outcomes = $executor->run($requests, fn() => null);

        $answers = [];
        foreach ($outcomes as $id => $outcome) {
            // The estimate this sub-call was holding against the job comes off either way; a real
            // answer then puts its actual cost on in its place.
            $this->context->add_job_cost(-$reservations[$id]['jobestimate']);

            if ($outcome instanceof result) {
                // Settled and charged here, because this is where the call was actually made.
                $this->context->budget->settle($reservations[$id], $outcome->cost);
                $this->context->add_job_cost($outcome->cost);
                $answers[$id] = $outcome;
            } else {
                $this->context->budget->release($reservations[$id]);
            }
        }
        return $answers;
    }

    /**
     * Returns every section and subsection of an outline, keyed by id.
     *
     * @param array $outline The outline.
     * @return array<string, array>
     */
    protected function containers(array $outline): array {
        $containers = [];
        foreach ($outline['sections'] ?? [] as $section) {
            if (!isset($section['id'])) {
                continue;
            }
            $containers[$section['id']] = $section;
            foreach ($section['subsections'] ?? [] as $subsection) {
                if (isset($subsection['id'])) {
                    $containers[$subsection['id']] = $subsection;
                }
            }
        }
        return $containers;
    }

    /**
     * Puts the activities of each sub-call back into the outline they belong to.
     *
     * @param array $outline The outline.
     * @param array<string, step_result> $sections Result per section id.
     * @return array The blueprint.
     */
    protected function assemble(array $outline, array $sections): array {
        $activities = [];
        foreach ($sections as $id => $result) {
            $activities[$id] = $result->is_success() ? ($result->output['activities'] ?? []) : [];
        }

        $blueprint = [
            'version' => '1.0',
            'language' => $this->context->language,
            'course' => $outline['course'] ?? [],
            'sections' => [],
        ];

        foreach ($outline['sections'] ?? [] as $section) {
            $id = $section['id'] ?? '';
            $section['activities'] = $activities[$id] ?? [];
            if (isset($section['subsections'])) {
                foreach ($section['subsections'] as $index => $subsection) {
                    $subid = $subsection['id'] ?? '';
                    $section['subsections'][$index]['activities'] = $activities[$subid] ?? [];
                }
            }
            $blueprint['sections'][] = $section;
        }
        return $blueprint;
    }

    /**
     * Returns the ids of the sections a human has to complete.
     *
     * @param array<string, step_result> $sections Result per section id.
     * @return string[]
     */
    protected function manual_nodes(array $sections): array {
        $manual = [];
        foreach ($sections as $id => $result) {
            if ($result->manual || !$result->is_success()) {
                $manual[] = $id;
            }
        }
        return $manual;
    }

    /**
     * Returns the outcome of a step that could not be produced at all.
     *
     * @param string $reason One of the pipeline_outcome::REASON_* constants.
     * @param string $message What went wrong.
     * @return pipeline_outcome
     */
    protected function failed(string $reason, string $message): pipeline_outcome {
        return pipeline_outcome::stopped($reason, $message, $this->context->steps->totals($this->context->jobid));
    }
}
