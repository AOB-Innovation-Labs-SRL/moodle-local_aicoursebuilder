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

namespace local_aicoursebuilder\ingest;

use local_aicoursebuilder\ai\budget_guard;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\result;
use local_aicoursebuilder\ai\router;

/**
 * Makes the digest of a source: one call to the AI connector of the digest step for the whole document.
 *
 * The digest lists the concepts, definitions, learning objectives and procedures of the document, each with
 * the chunks it comes from. The connector comes from the router, so the step runs on the fake connector in
 * development and on the real one when the settings say so. A document that is too long for one call
 * (more than the "digest_maxinputtokens" setting) is sent in windows of consecutive chunks and the digests of
 * the windows are merged.
 *
 * Every call is a checkpoint in local_aicb_step, keyed by a hash of its input: a repeated or resumed run
 * finds the answer there and does not call the provider, or pay, again. Every call is reserved against the
 * budget before it is sent and settled with its real cost after it.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class digest_builder {
    /** @var string Version of the prompt, kept in the step checkpoint and part of its input hash. */
    public const PROMPT_VERSION = 'v1';

    /** @var int Most input tokens of one call when the setting does not say. */
    public const DEFAULT_MAX_INPUT_TOKENS = 60000;

    /** @var int Most output tokens of one call. */
    public const MAX_OUTPUT_TOKENS = 6000;

    /** @var int[] Most items of each list in the digest of one call, told to the model and enforced. */
    public const LIMITS = ['concepts' => 40, 'definitions' => 40, 'objectives' => 15, 'procedures' => 15];

    /** @var string[] Names of the languages that the prompt can name, by ISO code. */
    private const LANGUAGE_NAMES = [
        'ro' => 'Romanian', 'en' => 'English', 'fr' => 'French', 'de' => 'German',
        'es' => 'Spanish', 'it' => 'Italian', 'hu' => 'Hungarian',
    ];

    /** @var router Chooses the connector of the step. */
    private router $router;

    /** @var budget_guard Reserves and settles the cost of the calls. */
    private budget_guard $guard;

    /**
     * Creates the builder.
     *
     * @param router|null $router Router, null for the one of the plugin settings.
     * @param budget_guard|null $guard Budget guard, null for the default one.
     */
    public function __construct(?router $router = null, ?budget_guard $guard = null) {
        $this->router = $router ?? new router();
        $this->guard = $guard ?? new budget_guard();
    }

    /**
     * Makes the digest of a source.
     *
     * @param \stdClass $job The local_aicb_job row; its owner pays and its id is on every call.
     * @param \stdClass $source The local_aicb_source row.
     * @param chunk[] $chunks The chunks of the source, in order.
     * @return array The digest: source, title, language, concepts, definitions, objectives and procedures.
     * @throws ingest_exception When the source has no chunks or the answer is not a usable digest.
     * @throws \local_aicoursebuilder\ai\connector_exception When the provider call fails.
     * @throws \local_aicoursebuilder\ai\budget_exceeded_exception When a cost limit would be passed.
     */
    public function build(\stdClass $job, \stdClass $source, array $chunks): array {
        if (!$chunks) {
            throw new ingest_exception(ingest_exception::DIGEST_INVALID, null, 'the source has no chunks');
        }
        $windows = $this->make_windows($chunks);
        $digests = [];
        foreach ($windows as $number => $window) {
            $nodekey = 's' . $source->id . (count($windows) > 1 ? 'w' . $number : '');
            $digests[] = $this->digest_window($job, $source, $window, $nodekey);
        }
        $digest = count($digests) === 1 ? $digests[0] : $this->merge($digests);

        $language = strtolower(trim((string) ($digest['language'] ?? '')));
        return [
            'source' => 'src' . $source->id,
            'title' => $digest['title'] !== '' ? $digest['title'] : pathinfo($source->filename, PATHINFO_FILENAME),
            'language' => preg_match('/^[a-z]{2,3}$/', $language) ? $language : (string) ($source->language ?? ''),
            'concepts' => $digest['concepts'],
            'definitions' => $digest['definitions'],
            'objectives' => $digest['objectives'],
            'procedures' => $digest['procedures'],
        ];
    }

    /**
     * Groups the chunks into windows that each fit in one call.
     *
     * @param chunk[] $chunks The chunks of the source.
     * @return chunk[][] Windows of consecutive chunks; a chunk bigger than the limit is a window of its own.
     */
    private function make_windows(array $chunks): array {
        $limit = (int) get_config('local_aicoursebuilder', 'digest_maxinputtokens');
        $limit = $limit > 0 ? $limit : self::DEFAULT_MAX_INPUT_TOKENS;

        $windows = [];
        $window = [];
        $tokens = 0;
        foreach ($chunks as $chunk) {
            if ($window && $tokens + $chunk->tokencount > $limit) {
                $windows[] = $window;
                $window = [];
                $tokens = 0;
            }
            $window[] = $chunk;
            $tokens += $chunk->tokencount;
        }
        $windows[] = $window;
        return $windows;
    }

    /**
     * Makes the digest of one window: from its checkpoint when it has one, else with a call.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $source The source.
     * @param chunk[] $window The chunks of the window.
     * @param string $nodekey Key of the call in the job.
     * @return array Validated digest of the window.
     */
    private function digest_window(\stdClass $job, \stdClass $source, array $window, string $nodekey): array {
        global $DB;

        $route = $this->router->get_route(request::STEP_DIGEST);
        $request = $this->make_request($job, $source, $window);
        $inputhash = hash('sha256', json_encode([
            $job->id,
            request::STEP_DIGEST,
            $nodekey,
            self::PROMPT_VERSION,
            $route,
            array_map(fn($chunk) => [$chunk->index, sha1($chunk->content)], $window),
        ], JSON_THROW_ON_ERROR));
        $indexes = array_map(fn($chunk) => $chunk->index, $window);

        $step = $DB->get_record('local_aicb_step', ['jobid' => $job->id, 'inputhash' => $inputhash]);
        if ($step && $step->status === 'done') {
            $saved = json_decode((string) $step->output, true);
            if (is_array($saved)) {
                try {
                    return $this->validate($saved, $indexes);
                } catch (ingest_exception) {
                    // The saved answer is no longer usable: ask again.
                    $saved = null;
                }
            }
        }

        $step = $this->start_step($job, $step, $nodekey, $inputhash, $route);
        $reservation = null;
        try {
            $connector = $this->router->for_step(request::STEP_DIGEST);
            $reservation = $this->guard->reserve($job->id, $job->userid, $connector->estimate_cost($request));
            $result = $connector->complete($request);
            $this->guard->settle($reservation, $result->cost);
            $reservation = null;
        } catch (\Throwable $e) {
            if ($reservation !== null) {
                $this->guard->release($reservation);
            }
            $this->finish_step($step, 'failed', null, $e->getMessage());
            throw $e;
        }

        // The call was paid for, valid or not.
        $DB->execute(
            'UPDATE {local_aicb_job} SET actualcost = actualcost + :cost WHERE id = :id',
            ['cost' => $result->cost, 'id' => $job->id]
        );
        $data = $result->json ?? json_decode($result->content, true);
        try {
            if (!is_array($data)) {
                throw new ingest_exception(ingest_exception::DIGEST_INVALID, null, 'the answer is not a JSON object');
            }
            $digest = $this->validate($data, $indexes);
        } catch (ingest_exception $e) {
            $this->finish_step($step, 'failed', $result, $e->getMessage());
            throw $e;
        }
        $this->finish_step($step, 'done', $result, null);
        return $digest;
    }

    /**
     * Makes the request of one window.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $source The source.
     * @param chunk[] $window The chunks of the window.
     * @return request
     */
    private function make_request(\stdClass $job, \stdClass $source, array $window): request {
        $language = (string) ($source->language ?? '');
        $system = strtr((string) file_get_contents(dirname(__DIR__, 2) . '/prompts/digest.' . self::PROMPT_VERSION . '.txt'), [
            '{{language}}' => self::LANGUAGE_NAMES[$language] ?? 'the language of the document',
            '{{maxconcepts}}' => (string) self::LIMITS['concepts'],
            '{{maxdefinitions}}' => (string) self::LIMITS['definitions'],
            '{{maxobjectives}}' => (string) self::LIMITS['objectives'],
            '{{maxprocedures}}' => (string) self::LIMITS['procedures'],
        ]);

        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/digest.v1.json'), true);
        unset($schema['$schema']);

        $text = 'Document: ' . $source->filename . "\n";
        foreach ($window as $chunk) {
            $pages = $chunk->pagefrom === null ? '' : ' | pages ' . ($chunk->pagefrom === $chunk->pageto
                ? $chunk->pagefrom : $chunk->pagefrom . '-' . $chunk->pageto);
            $title = $chunk->title === null ? '' : ' | title: ' . $chunk->title;
            $text .= "\n<<< chunk {$chunk->index}{$pages}{$title} >>>\n" . $chunk->content . "\n";
        }

        return new request(
            step: request::STEP_DIGEST,
            system: $system,
            messages: [['role' => 'user', 'content' => $text]],
            schema: $schema,
            maxtokens: self::MAX_OUTPUT_TOKENS,
            jobid: (int) $job->id,
            userid: (int) $job->userid,
        );
    }

    /**
     * Creates the checkpoint row of a call, or marks the one of an earlier failed try as running again.
     *
     * @param \stdClass $job The job.
     * @param \stdClass|false $step The existing row of this input, false when there is none.
     * @param string $nodekey Key of the call in the job.
     * @param string $inputhash Hash of the input.
     * @param array $route The connector and model of the step.
     * @return \stdClass The row.
     */
    private function start_step(
        \stdClass $job,
        \stdClass|false $step,
        string $nodekey,
        string $inputhash,
        array $route
    ): \stdClass {
        global $DB;

        $now = time();
        if ($step) {
            $step->status = 'running';
            $step->attempts++;
            $step->error = null;
            $step->timemodified = $now;
            $DB->update_record('local_aicb_step', $step);
            return $step;
        }
        $step = (object) [
            'jobid' => $job->id,
            'step' => request::STEP_DIGEST,
            'nodekey' => $nodekey,
            'inputhash' => $inputhash,
            'status' => 'running',
            'connector' => $route['connector'],
            'model' => $route['model'],
            'promptversion' => self::PROMPT_VERSION,
            'attempts' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $step->id = $DB->insert_record('local_aicb_step', $step);
        return $step;
    }

    /**
     * Writes the outcome of a call in its checkpoint row.
     *
     * @param \stdClass $step The row.
     * @param string $status done or failed.
     * @param result|null $result The result of the call, null for a call that failed.
     * @param string|null $error Message of the failure, without any content of the document.
     */
    private function finish_step(\stdClass $step, string $status, ?result $result, ?string $error): void {
        global $DB;

        $step->status = $status;
        $step->error = $error;
        $step->timemodified = time();
        if ($result !== null) {
            $step->connector = $result->connector !== '' ? $result->connector : $step->connector;
            $step->model = $result->model;
            $step->output = $result->content;
            $step->tokensin = $result->tokensin;
            $step->tokensout = $result->tokensout;
            $step->tokenscached = $result->tokenscached;
            $step->cost = $result->cost;
        }
        $DB->update_record('local_aicb_step', $step);
    }

    /**
     * Checks the digest of a call and cleans it: text is trimmed, empty items are dropped, every list is cut to
     * its limit and the chunk references that are not in the window are removed.
     *
     * @param array $data The JSON of the answer.
     * @param int[] $indexes Indexes of the chunks that were sent.
     * @return array Digest with the keys title, language, concepts, definitions, objectives and procedures.
     * @throws ingest_exception When the answer has nothing in any list.
     */
    private function validate(array $data, array $indexes): array {
        $refs = fn($value) => array_values(array_unique(array_filter(
            array_map('intval', array_filter(
                is_array($value) ? $value : [],
                fn($ref) => is_int($ref) || (is_string($ref) && ctype_digit($ref))
            )),
            fn($index) => in_array($index, $indexes, true)
        )));
        $text = fn($value) => trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '') ?? '');
        $list = fn($value) => is_array($value) ? array_values(array_filter($value, 'is_array')) : [];

        $concepts = [];
        foreach ($list($data['concepts'] ?? null) as $item) {
            if ($text($item['name'] ?? '') !== '') {
                $concepts[] = ['name' => $text($item['name']), 'chunks' => $refs($item['chunks'] ?? [])];
            }
        }
        $definitions = [];
        foreach ($list($data['definitions'] ?? null) as $item) {
            if ($text($item['term'] ?? '') !== '' && $text($item['definition'] ?? '') !== '') {
                $definitions[] = [
                    'term' => $text($item['term']),
                    'definition' => $text($item['definition']),
                    'chunks' => $refs($item['chunks'] ?? []),
                ];
            }
        }
        $objectives = [];
        foreach (is_array($data['objectives'] ?? null) ? $data['objectives'] : [] as $objective) {
            if ($text($objective) !== '') {
                $objectives[] = $text($objective);
            }
        }
        $procedures = [];
        foreach ($list($data['procedures'] ?? null) as $item) {
            $steps = array_values(array_filter(array_map($text, is_array($item['steps'] ?? null) ? $item['steps'] : [])));
            if ($text($item['title'] ?? '') !== '' && $steps) {
                $procedures[] = ['title' => $text($item['title']), 'steps' => $steps, 'chunks' => $refs($item['chunks'] ?? [])];
            }
        }

        if (!$concepts && !$definitions && !$objectives && !$procedures) {
            throw new ingest_exception(ingest_exception::DIGEST_INVALID, null, 'the digest has no items');
        }
        return [
            'title' => $text($data['title'] ?? ''),
            'language' => $text($data['language'] ?? ''),
            'concepts' => array_slice($concepts, 0, self::LIMITS['concepts']),
            'definitions' => array_slice($definitions, 0, self::LIMITS['definitions']),
            'objectives' => array_slice($objectives, 0, self::LIMITS['objectives']),
            'procedures' => array_slice($procedures, 0, self::LIMITS['procedures']),
        ];
    }

    /**
     * Merges the digests of the windows of a long document; an item that comes back under the same name is kept once.
     *
     * @param array[] $digests Validated digests, in window order.
     * @return array Digest with the keys title, language, concepts, definitions, objectives and procedures.
     */
    private function merge(array $digests): array {
        $merged = [
            'title' => '',
            'language' => '',
            'concepts' => [],
            'definitions' => [],
            'objectives' => [],
            'procedures' => [],
        ];
        $keys = ['concepts' => 'name', 'definitions' => 'term', 'procedures' => 'title'];
        $seen = [];
        foreach ($digests as $digest) {
            $merged['title'] = $merged['title'] !== '' ? $merged['title'] : $digest['title'];
            $merged['language'] = $merged['language'] !== '' ? $merged['language'] : $digest['language'];
            foreach ($keys as $list => $key) {
                foreach ($digest[$list] as $item) {
                    $id = $list . '|' . \core_text::strtolower($item[$key]);
                    if (isset($seen[$id])) {
                        $existing = &$merged[$list][$seen[$id]];
                        $existing['chunks'] = array_values(array_unique(array_merge($existing['chunks'], $item['chunks'])));
                        unset($existing);
                        continue;
                    }
                    $seen[$id] = count($merged[$list]);
                    $merged[$list][] = $item;
                }
            }
            foreach ($digest['objectives'] as $objective) {
                $id = 'objectives|' . \core_text::strtolower($objective);
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $merged['objectives'][] = $objective;
                }
            }
        }
        return $merged;
    }
}
