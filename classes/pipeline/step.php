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
use local_aicoursebuilder\ai\connector_exception;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\result;
use local_aicoursebuilder\blueprint\validation_error;

/**
 * What every pipeline step shares: one AI call, checked, repaired and paid for.
 *
 * A step builds its prompt, sends it through the router's connector for that step, and insists on
 * an answer that validates. When the answer does not, the local repair runs first and a repair call
 * is spent only if that was not enough, at most twice (spec 3.6). A node that still does not
 * validate is handed back marked for a human rather than thrown away, so one bad node never costs
 * the whole job.
 *
 * Money is reserved before each call and settled at its real cost afterwards, so a job cannot spend
 * past its limit and a failed call does not leave a reservation behind (spec 3.8).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class step {
    /** @var int Repair calls allowed per node, after the local repair has been tried. */
    public const MAX_REPAIR_CALLS = 2;

    /** @var pipeline_context The job this step runs for. */
    protected pipeline_context $context;

    /**
     * Creates the step.
     *
     * @param pipeline_context $context The job this step runs for.
     */
    public function __construct(pipeline_context $context) {
        $this->context = $context;
    }

    /**
     * Returns the pipeline step this class runs, one of the request::STEP_* constants.
     *
     * @return string
     */
    abstract public function get_step(): string;

    /**
     * Returns the prompt template name of this step.
     *
     * @return string
     */
    abstract public function get_prompt_name(): string;

    /**
     * Returns a node's output when repair never produced a valid one.
     *
     * The placeholder has to validate, because the pipeline goes on with it; it just holds no
     * content, and its review flag tells the teacher where to look.
     *
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @param array $input The input the step was given.
     * @return array|null Placeholder output, or null when the step has no usable placeholder.
     */
    protected function placeholder(string $nodekey, array $input): ?array {
        return null;
    }

    /**
     * Runs the step, or returns what it produced last time.
     *
     * @param array $input Everything the step needs, which also decides its hash.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @return step_result
     * @throws budget_exceeded_exception When a limit stops the call before it is sent.
     */
    public function run(array $input, string $nodekey = ''): step_result {
        $step = $this->get_step();
        $route = $this->context->router->get_route($step);
        $hashcontext = [
            'promptversion' => $this->context->promptversion,
            'connector' => $route['connector'],
            'model' => $route['model'],
            'schemaversion' => $this->context->get_schemaversion(),
        ];
        $hash = step_store::hash($this->context->jobid, $step, $nodekey, $input, $hashcontext);

        $done = $this->context->steps->find_output($this->context->jobid, $hash);
        if ($done !== null) {
            return step_result::resumed($done);
        }

        $id = $this->context->steps->start($this->context->jobid, $step, $nodekey, $hash, $hashcontext);
        try {
            $result = $this->generate($input, $nodekey);
        } catch (budget_exceeded_exception $e) {
            // The job stops here, but the row must not stay marked running, or a resume would
            // treat this step as one that was never attempted and lose its attempt count.
            $this->context->steps->finish($id, step_result::failed($e->getMessage()));
            $this->context->record_job_cost();
            throw $e;
        } catch (connector_exception $e) {
            $result = step_result::failed($e->getMessage());
        }
        $this->context->steps->finish($id, $result);
        $this->context->record_job_cost();
        return $result;
    }

    /**
     * Sends the step's prompt and insists on an answer that validates.
     *
     * @param array $input Everything the step needs.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @return step_result
     * @throws budget_exceeded_exception When a limit stops a call before it is sent.
     * @throws connector_exception When the first call fails outright.
     */
    protected function generate(array $input, string $nodekey): step_result {
        $system = $this->render_prompt($input, $nodekey);
        $request = $this->build_request($system, $this->user_message($input, $nodekey));

        $spend = new spend();
        $result = $this->call($request, $spend);
        $output = json_repair::decode($result->content);
        $errors = $this->validate($output, $input, $nodekey);

        for ($attempt = 0; $errors !== [] && $attempt < self::MAX_REPAIR_CALLS; $attempt++) {
            $repaired = $this->repair($output, $result->content, $errors, $spend);
            if ($repaired === null) {
                break;
            }
            $output = $repaired;
            $errors = $this->validate($output, $input, $nodekey);
        }

        if ($errors === []) {
            return new step_result(
                output: $output,
                tokensin: $spend->tokensin,
                tokensout: $spend->tokensout,
                tokenscached: $spend->tokenscached,
                cost: $spend->cost,
                calls: $spend->calls,
            );
        }

        $placeholder = $this->placeholder($nodekey, $input);
        if ($placeholder === null) {
            return step_result::failed(
                'The output of step ' . $this->get_step() . ' never validated: '
                    . validation_error::list_to_json(array_slice($errors, 0, 5)),
                calls: $spend->calls,
                cost: $spend->cost,
            );
        }
        return step_result::needs_manual(
            output: $placeholder,
            errors: $errors,
            tokensin: $spend->tokensin,
            tokensout: $spend->tokensout,
            tokenscached: $spend->tokenscached,
            cost: $spend->cost,
            calls: $spend->calls,
        );
    }

    /**
     * Asks the model to fix an answer the validator rejected.
     *
     * @param array|null $output The decoded answer, or null when it was not JSON.
     * @param string $raw The raw answer, sent when there is no decoded one to send.
     * @param validation_error[] $errors What the validator found.
     * @param spend $spend Running total of what this node has cost.
     * @return array|null The repaired answer, or null when the repair call itself failed.
     * @throws budget_exceeded_exception When a limit stops the repair call before it is sent.
     */
    protected function repair(?array $output, string $raw, array $errors, spend $spend): ?array {
        $document = $output === null
            ? $raw
            : json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $system = (new prompt('repair', $this->context->promptversion, $this->context->promptdir))->render([
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'errors' => validation_error::list_to_json($errors),
            'document' => $document,
        ]);

        $request = new request(
            step: request::STEP_REPAIR,
            system: $system,
            messages: [['role' => 'user', 'content' => $this->context->repair_instruction()]],
            schema: null,
            files: [],
            maxtokens: 0,
            temperature: null,
            timeout: 0,
            jobid: $this->context->jobid,
            userid: $this->context->userid,
            contextid: $this->context->contextid,
            json: true,
        );

        try {
            $result = $this->call($request, $spend, request::STEP_REPAIR);
        } catch (connector_exception $e) {
            return null;
        }
        return json_repair::decode($result->content);
    }

    /**
     * Sends one request, with its cost reserved beforehand and settled afterwards.
     *
     * @param request $request The request.
     * @param spend $spend Running total of what this node has cost.
     * @param string|null $step Step whose route to use, null for this step's own.
     * @return result
     * @throws budget_exceeded_exception When a limit stops the call before it is sent.
     * @throws connector_exception When the call fails.
     */
    protected function call(request $request, spend $spend, ?string $step = null): result {
        $connector = $this->context->router->for_step($step ?? $this->get_step());
        $reservation = $this->context->budget->reserve(
            $this->context->jobid,
            $this->context->userid,
            $connector->estimate_cost($request),
        );
        try {
            $result = $connector->complete($request);
        } catch (\Throwable $e) {
            $this->context->budget->release($reservation);
            throw $e;
        }
        $this->context->budget->settle($reservation, $result->cost);
        $spend->add($result);
        return $result;
    }

    /**
     * Returns the request of this step's own call.
     *
     * @param string $system The rendered prompt.
     * @param string $message The user message.
     * @return request
     */
    protected function build_request(string $system, string $message): request {
        return new request(
            step: $this->get_step(),
            system: $system,
            messages: [['role' => 'user', 'content' => $message]],
            schema: $this->context->schemas->step_schema_array($this->get_step()),
            files: [],
            maxtokens: 0,
            temperature: null,
            timeout: 0,
            jobid: $this->context->jobid,
            userid: $this->context->userid,
            contextid: $this->context->contextid,
            json: true,
        );
    }

    /**
     * Returns the prompt of this step, filled in.
     *
     * @param array $input Everything the step needs.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @return string
     */
    protected function render_prompt(array $input, string $nodekey): string {
        $prompt = new prompt($this->get_prompt_name(), $this->context->promptversion, $this->context->promptdir);
        return $prompt->render($this->prompt_values($input, $nodekey));
    }

    /**
     * Returns the placeholder values of this step's prompt.
     *
     * @param array $input Everything the step needs.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @return array Placeholder name => value.
     */
    abstract protected function prompt_values(array $input, string $nodekey): array;

    /**
     * Returns the user message that goes with this step's prompt.
     *
     * @param array $input Everything the step needs.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @return string
     */
    protected function user_message(array $input, string $nodekey): string {
        return $this->context->step_instruction($this->get_step());
    }

    /**
     * Checks what the model returned.
     *
     * @param array|null $output The decoded answer, or null when it was not JSON.
     * @param array $input Everything the step needs.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @return validation_error[]
     */
    protected function validate(?array $output, array $input, string $nodekey): array {
        return $this->context->validator->validate_step(
            $this->get_step(),
            $output,
            $this->context->sourcetexts,
        );
    }
}
