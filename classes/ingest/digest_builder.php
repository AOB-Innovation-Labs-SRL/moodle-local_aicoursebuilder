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
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\blueprint\schema_store;
use local_aicoursebuilder\blueprint\validator;
use local_aicoursebuilder\pipeline\pipeline_context;
use local_aicoursebuilder\pipeline\step_digest;
use local_aicoursebuilder\pipeline\step_store;

/**
 * Makes the digest of a source: one AI call for the whole document, through the digest step.
 *
 * The digest lists the concepts, definitions, learning objectives and procedures of the document, each with the
 * chunks it comes from. The call is made by pipeline\step_digest, so it has what every step has: the connector
 * and model of the digest route (the fake connector in tests, the real one when the settings say so), a
 * prompt that treats the document as data, a check of the answer against schema/steps/digest.v1.json with up to
 * two repair calls, a reservation in the budget before the call and its settlement after it, and a checkpoint
 * in local_aicb_step, so that a repeated or resumed run does not call the provider, or pay, again.
 *
 * A document that is too long for one call (more than the "digest_maxinputtokens" setting) is sent in windows
 * of consecutive chunks, and the digests of the windows are merged. The answer of a step is then cleaned: the
 * references to chunks that were not sent are dropped, text is trimmed, and every list is cut to its limit.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class digest_builder {
    /** @var int Most input tokens of one call when the setting does not say. */
    public const DEFAULT_MAX_INPUT_TOKENS = 60000;

    /** @var int[] Most items of each list in the digest of one call, told to the model and enforced. */
    public const LIMITS = ['concepts' => 40, 'definitions' => 40, 'objectives' => 15, 'procedures' => 15];

    /** @var string Language code for a source whose language is not known, when the job gives none. */
    private const UNDETERMINED = 'und';

    /** @var router Chooses the connector of the digest step. */
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
     * @throws ingest_exception When the source has no chunks, the step fails or the digest is empty.
     * @throws \local_aicoursebuilder\ai\budget_exceeded_exception When a cost limit would be passed.
     */
    public function build(\stdClass $job, \stdClass $source, array $chunks): array {
        if (!$chunks) {
            throw new ingest_exception(ingest_exception::DIGEST_INVALID, null, 'the source has no chunks');
        }
        $context = $this->make_context($job, $source);
        $windows = $this->make_windows($chunks);
        $digests = [];
        foreach ($windows as $number => $window) {
            $nodekey = 's' . $source->id . (count($windows) > 1 ? 'w' . $number : '');
            $digests[] = $this->digest_window($context, $source, $window, $nodekey);
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
     * Makes the context of the digest step: the job, the language of the document and the collaborators.
     *
     * The digest is written in the language of the document; when that is not known, in the language of the job.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $source The source.
     * @return pipeline_context
     */
    private function make_context(\stdClass $job, \stdClass $source): pipeline_context {
        $language = strtolower(trim((string) ($source->language ?? '')));
        if (!preg_match('/^[a-z]{2}$/', $language) || $language === self::UNDETERMINED) {
            $language = strtolower(substr(trim((string) ($job->language ?? '')), 0, 2));
        }
        $schemas = new schema_store();
        return new pipeline_context(
            jobid: (int) $job->id,
            userid: (int) $job->userid,
            language: preg_match('/^[a-z]{2}$/', $language) ? $language : pipeline_context::DEFAULT_LANGUAGE,
            router: $this->router,
            budget: $this->guard,
            steps: new step_store(),
            validator: new validator($schemas),
            schemas: $schemas,
        );
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
     * Makes the digest of one window with the digest step, which finds it in its checkpoint when it was made before.
     *
     * @param pipeline_context $context The context of the step.
     * @param \stdClass $source The source.
     * @param chunk[] $window The chunks of the window.
     * @param string $nodekey Key of the call in the job.
     * @return array Cleaned digest of the window.
     * @throws ingest_exception When the step fails or the digest is empty.
     */
    private function digest_window(pipeline_context $context, \stdClass $source, array $window, string $nodekey): array {
        $indexes = array_map(fn($chunk) => $chunk->index, $window);
        $result = (new step_digest($context))->run([
            'document' => $this->render_window($source, $window),
            'indexes' => $indexes,
        ], $nodekey);

        if (!$result->is_success()) {
            throw new ingest_exception(ingest_exception::DIGEST_FAILED, $result->error);
        }
        return $this->clean($result->output, $indexes);
    }

    /**
     * Writes the chunks of a window as the text that goes into the prompt.
     *
     * @param \stdClass $source The source.
     * @param chunk[] $window The chunks of the window.
     * @return string
     */
    private function render_window(\stdClass $source, array $window): string {
        $text = 'Document: ' . $source->filename . "\n";
        foreach ($window as $chunk) {
            $pages = '';
            if ($chunk->pagefrom !== null) {
                $pages = ' | pages ' . ($chunk->pagefrom === $chunk->pageto
                    ? $chunk->pagefrom : $chunk->pagefrom . '-' . $chunk->pageto);
            }
            $title = $chunk->title === null ? '' : ' | title: ' . $chunk->title;
            $text .= "\n<<< chunk {$chunk->index}{$pages}{$title} >>>\n" . $chunk->content . "\n";
        }
        return $text;
    }

    /**
     * Cleans the digest of a call: text is trimmed, every list is cut to its limit and the chunk references
     * that are not in the window are removed.
     *
     * @param array $data The digest the step validated.
     * @param int[] $indexes Indexes of the chunks that were sent.
     * @return array Digest with the keys title, language, concepts, definitions, objectives and procedures.
     * @throws ingest_exception When nothing is left in any list.
     */
    private function clean(array $data, array $indexes): array {
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
     * @param array[] $digests Cleaned digests, in window order.
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
