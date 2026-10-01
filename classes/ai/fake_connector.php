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
 * Deterministic connector without network access, for tests, Behat and development.
 *
 * Answers with the fixture file tests/fixtures/ai/{step}.json for the step of the request, which is
 * what it does when nothing is programmed into it.
 *
 * A test that needs more than one answer for a step programmes a queue with push(): the queued
 * answers are used in order, then the fixture takes over again. A queued answer can be raw text, so
 * a test can hand the pipeline a broken JSON document, or an exception, so it can fail one sub-call
 * of a parallel batch and check that the others still run. Calls are counted per step, which is how
 * a test tells a resumed step from one that paid for a call.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_connector implements connector {
    use token_estimator;

    /** @var string Connector name. */
    public const NAME = 'fake';

    /** @var string Model name reported in results. */
    public const MODEL = 'fake';

    /** @var string Directory holding the fixture files. */
    protected string $fixturedir;

    /** @var array<string, list<string|\Throwable>> Queued answers per queue key. */
    protected array $queued = [];

    /** @var array<string, int> Calls made per step. */
    protected array $calls = [];

    /** @var request[] Every request the connector was given, in order. */
    protected array $requests = [];

    /** @var float Cost reported for each call, so a test can exercise the budget. */
    protected float $costpercall = 0.0;

    /**
     * Creates the connector.
     *
     * @param string|null $fixturedir Directory with {step}.json files, null for tests/fixtures/ai.
     */
    public function __construct(?string $fixturedir = null) {
        $this->fixturedir = $fixturedir ?? dirname(__DIR__, 2) . '/tests/fixtures/ai';
    }

    /**
     * Queues an answer for a step, used before the step falls back to its fixture.
     *
     * @param string $step Pipeline step the answer belongs to.
     * @param string|array|\Throwable $answer Raw text, a value to encode as JSON, or an exception
     *                                        to throw instead of answering.
     * @param string $nodekey Sub-call the answer belongs to, empty for any sub-call of the step.
     * @return self
     */
    public function push(string $step, string|array|\Throwable $answer, string $nodekey = ''): self {
        if (is_array($answer)) {
            $answer = (string) json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $this->queued[$this->queue_key($step, $nodekey)][] = $answer;
        return $this;
    }

    /**
     * Sets the cost every call reports, so a test can drive the budget guard.
     *
     * @param float $cost Cost in USD.
     * @return self
     */
    public function set_cost_per_call(float $cost): self {
        $this->costpercall = $cost;
        return $this;
    }

    /**
     * Returns how many calls a step has taken, or the total across every step.
     *
     * @param string|null $step Pipeline step, null for the total.
     * @return int
     */
    public function call_count(?string $step = null): int {
        if ($step === null) {
            return array_sum($this->calls);
        }
        return $this->calls[$step] ?? 0;
    }

    /**
     * Returns every request the connector was given, in order.
     *
     * @param string|null $step Only the requests of this step, null for all of them.
     * @return request[]
     */
    public function requests(?string $step = null): array {
        if ($step === null) {
            return $this->requests;
        }
        return array_values(array_filter($this->requests, fn(request $r) => $r->step === $step));
    }

    /**
     * Forgets the calls counted so far, keeping whatever is still queued.
     */
    public function reset_counts(): void {
        $this->calls = [];
        $this->requests = [];
    }

    /**
     * Returns the queued answer for a request, or the fixture when nothing is queued.
     *
     * @param request $request The completion request.
     * @return result
     * @throws \Throwable When the queued answer is an exception.
     */
    public function complete(request $request): result {
        $this->calls[$request->step] = ($this->calls[$request->step] ?? 0) + 1;
        $this->requests[] = $request;

        $content = $this->next_answer($request);
        $json = json_decode($content, true);

        return new result(
            content: $content,
            json: is_array($json) ? $json : null,
            tokensin: $this->count_tokens($request->get_input_text()),
            tokensout: $this->count_tokens($content),
            tokenscached: 0,
            cost: $this->costpercall,
            model: self::MODEL,
            durationms: 0,
            finishreason: 'stop',
            connector: self::NAME,
        );
    }

    /**
     * Only structured JSON output is supported.
     *
     * @param string $capability One of the connector::CAP_* constants.
     * @return bool
     */
    public function supports(string $capability): bool {
        return $capability === connector::CAP_JSON_SCHEMA;
    }

    /**
     * The fake connector is free, unless a test gave it a cost to exercise the budget.
     *
     * @param request $request The completion request.
     * @return float
     */
    public function estimate_cost(request $request): float {
        return $this->costpercall;
    }

    /**
     * Returns the answer of a request: the next queued one, or the step's fixture.
     *
     * @param request $request The completion request.
     * @return string The raw answer.
     * @throws \Throwable When the queued answer is an exception.
     * @throws \coding_exception When nothing is queued and the step has no fixture.
     */
    protected function next_answer(request $request): string {
        foreach ([$this->queue_key($request->step, $this->nodekey($request)), $request->step] as $key) {
            if (!empty($this->queued[$key])) {
                $answer = array_shift($this->queued[$key]);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                return $answer;
            }
        }

        // A Behat run cannot queue answers, so a sub-call takes the fixture of its own node when there is one:
        // fixtures/{step}/{nodekey}.json. Otherwise every section would get the same answer and duplicate ids.
        $nodekey = $this->nodekey($request);
        $nodepath = $this->fixturedir . '/' . $request->step . '/' . $nodekey . '.json';
        if (defined('BEHAT_SITE_RUNNING') && $nodekey !== '' && is_readable($nodepath)) {
            return trim(file_get_contents($nodepath));
        }

        $path = $this->fixturedir . '/' . $request->step . '.json';
        if (!is_readable($path)) {
            throw new \coding_exception("No AI fixture for step '{$request->step}': {$path}");
        }
        return trim(file_get_contents($path));
    }

    /**
     * Returns the key a queued answer is stored under.
     *
     * @param string $step Pipeline step.
     * @param string $nodekey Sub-call key, empty for any sub-call of the step.
     * @return string
     */
    protected function queue_key(string $step, string $nodekey): string {
        return $nodekey === '' ? $step : $step . ':' . $nodekey;
    }

    /**
     * Returns the sub-call a request belongs to.
     *
     * The connector interface carries no sub-call key, so the section id is recovered from the
     * SECTION block the sections prompt draws around the section it is writing. Only that block is
     * read, so text anywhere else in the prompt, source material included, cannot be mistaken for
     * a section id.
     *
     * @param request $request The completion request.
     * @return string The sub-call key, empty when the request is not a sub-call.
     */
    protected function nodekey(request $request): string {
        if (preg_match('/<<<TARGET_ID\R([^\r\n]+)\RTARGET_ID/', $request->system, $target)) {
            return $target[1];
        }
        if (!preg_match('/<<<SECTION\R(.*?)\RSECTION\s*$/ms', $request->system, $block)) {
            return '';
        }
        if (preg_match('/"id"\s*:\s*"(s[0-9]+(?:-[0-9]+)?)"/', $block[1], $matches)) {
            return $matches[1];
        }
        return '';
    }
}
