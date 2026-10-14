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

use local_aicoursebuilder\ai\anthropic_connector;
use local_aicoursebuilder\ai\deepseek_connector;
use local_aicoursebuilder\ai\pricing;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\ingest\digest_builder;

/**
 * Estimates what a job will cost before any AI call is made (spec 3.8).
 *
 * The estimate is built from what is known when the teacher is about to start: the size of the sources and the
 * length of the course asked for. It follows the shape of the pipeline: one digest call per window of every
 * source, then the brief, the outline, one call of each of the sections, activities and questions steps for every
 * section and subsection, and the review of the whole blueprint. Each step is priced with the connector and model of
 * its own route, so changing a route in the settings changes the estimate.
 *
 * It is a forecast, not an upper bound: the calls of the content steps share a prefix that the provider serves from its
 * cache after the first one, and the work is priced at the off-peak rates when the job asks to run off-peak. Before the
 * sources are read their size is guessed from their files, and that guess is only as good as the file is a good
 * measure of its text; the estimate is made again with the real count once the sources have been read.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cost_estimator {
    /** @var int Bytes of a plain text file that one token stands for. */
    public const BYTES_PER_TOKEN = 5;

    /** @var int[] Bytes of a file that one token stands for, by kind of file, while its text has not been extracted yet. */
    public const BYTES_PER_TOKEN_BY_KIND = ['pdf' => 70, 'docx' => 90, 'pptx' => 90, 'xlsx' => 3];

    /** @var int Tokens of instructions and schema that every call carries besides its material. */
    public const PROMPT_OVERHEAD = 1500;

    /** @var int Tokens a digest call writes. */
    public const DIGEST_OUTPUT = 3500;

    /** @var int Tokens the brief writes. */
    public const BRIEF_OUTPUT = 800;

    /** @var int Tokens the outline writes. */
    public const OUTLINE_OUTPUT = 2500;

    /** @var int Tokens the content of one section writes. */
    public const SECTION_OUTPUT = 5000;

    /** @var int Tokens the interactive activities of one section write. */
    public const ACTIVITIES_OUTPUT = 2400;

    /** @var int Tokens the quiz of one section writes. */
    public const QUESTIONS_OUTPUT = 8500;

    /**
     * @var int[] Tokens a call of each of the sections, activities and questions steps carries after the shared prefix,
     *            and that the provider's cache does not serve: its own section, what was written for it before (the
     *            activities step reads the section, the questions step the chunks of its sources), and the part of the
     *            prefix that does not fill a whole cache block.
     */
    public const NODE_INPUT = [
        request::STEP_SECTIONS => 5000,
        request::STEP_ACTIVITIES => 9000,
        request::STEP_QUESTIONS => 20000,
    ];

    /** @var float Tokens the review reads for each token the sections, activities and quizzes wrote: the blueprint. */
    public const REVIEW_INPUT_FACTOR = 2.2;

    /** @var int Tokens the review writes. */
    public const REVIEW_OUTPUT = 3000;

    /** @var int Course length in minutes assumed when the brief does not give one. */
    public const DEFAULT_DURATION = 240;

    /** @var int Minutes of course that one section stands for. */
    public const MINUTES_PER_SECTION = 45;

    /** @var int Fewest sections an outline is assumed to have. */
    public const MIN_SECTIONS = 3;

    /** @var int Most sections an outline is assumed to have. */
    public const MAX_SECTIONS = 10;

    /** @var float Margin added for repair calls. */
    public const REPAIR_MARGIN = 1.05;

    /** @var string[] Connectors that serve the prefix every call of a job shares from their cache. */
    public const PREFIX_CACHE_CONNECTORS = [deepseek_connector::NAME, anthropic_connector::NAME];

    /** @var router Router giving the connector and model of each step. */
    private router $router;

    /**
     * Creates the estimator.
     *
     * @param router|null $router Router, null for the one of the plugin settings.
     */
    public function __construct(?router $router = null) {
        $this->router = $router ?? new router();
    }

    /**
     * Estimates the cost of a job.
     *
     * The estimate follows the pipeline as it runs. The sources are read once by the digest; after that every
     * call of the sections, activities and questions steps opens with the same prefix (the brief, the outline and
     * all the sources), so the first call of a job pays for it in full and the others find it in the provider's
     * cache, and what each of them pays for besides is its own node. The review reads the whole blueprint, and
     * nothing of it is cached. A job that asks to run off-peak is priced at the off-peak rates.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param int|null $timestamp The moment the work is priced at, null for now (or, for a job that runs off-peak,
     *                            for the next off-peak moment).
     * @return array ['cost' => float USD, 'tokensin' => int, 'tokensout' => int]
     */
    public function estimate(\stdClass $job, ?int $timestamp = null): array {
        $sources = $this->source_tokens($job);
        $total = array_sum($sources);
        $calls = [];

        $window = (int) get_config('local_aicoursebuilder', 'digest_maxinputtokens');
        $window = $window > 0 ? $window : digest_builder::DEFAULT_MAX_INPUT_TOKENS;
        $digests = 0;
        foreach ($sources as $tokens) {
            $windows = max(1, (int) ceil($tokens / $window));
            for ($i = 0; $i < $windows; $i++) {
                $calls[] = [request::STEP_DIGEST, min($tokens, $window) + self::PROMPT_OVERHEAD, 0, self::DIGEST_OUTPUT];
                $digests += self::DIGEST_OUTPUT;
            }
        }

        $calls[] = [request::STEP_BRIEF, $digests + self::PROMPT_OVERHEAD, 0, self::BRIEF_OUTPUT];
        $calls[] = [request::STEP_OUTLINE, $digests + self::BRIEF_OUTPUT + self::PROMPT_OVERHEAD, 0, self::OUTLINE_OUTPUT];

        $prefix = $total + self::PROMPT_OVERHEAD + self::BRIEF_OUTPUT + self::OUTLINE_OUTPUT;
        $nodes = $this->count_section_calls($job);
        $written = 0;
        $first = true;
        $outputs = [
            request::STEP_SECTIONS => self::SECTION_OUTPUT,
            request::STEP_ACTIVITIES => self::ACTIVITIES_OUTPUT,
            request::STEP_QUESTIONS => self::QUESTIONS_OUTPUT,
        ];
        foreach ($outputs as $step => $output) {
            $cached = $this->caches_prefix($step);
            for ($i = 0; $i < $nodes; $i++) {
                if ($cached && !$first) {
                    $calls[] = [$step, self::NODE_INPUT[$step], $prefix, $output];
                } else {
                    $calls[] = [$step, $prefix + self::NODE_INPUT[$step], 0, $output];
                }
                $first = false;
                $written += $output;
            }
        }
        $calls[] = [request::STEP_REVIEW, (int) round($written * self::REVIEW_INPUT_FACTOR), 0, self::REVIEW_OUTPUT];

        $timestamp ??= $this->pricing_timestamp($job);
        $cost = 0.0;
        $tokensin = 0;
        $tokensout = 0;
        foreach ($calls as [$step, $miss, $hit, $out]) {
            $route = $this->router->get_route($step);
            $cost += pricing::cost_for($route['connector'], $route['model'], $miss, $out, $hit, $timestamp);
            $tokensin += $miss + $hit;
            $tokensout += $out;
        }

        return [
            'cost' => round($cost * self::REPAIR_MARGIN, 6),
            'tokensin' => (int) round($tokensin * self::REPAIR_MARGIN),
            'tokensout' => (int) round($tokensout * self::REPAIR_MARGIN),
        ];
    }

    /**
     * Tells whether the calls of a step find the shared prefix in the cache of the provider its route uses.
     *
     * @param string $step Pipeline step.
     * @return bool
     */
    private function caches_prefix(string $step): bool {
        return in_array($this->router->get_route($step)['connector'], self::PREFIX_CACHE_CONNECTORS, true);
    }

    /**
     * Returns the moment the work of a job is priced at.
     *
     * A job that runs off-peak is priced at the next moment that is off-peak for the connector of its sections, which
     * is now when it is already. Any other job runs now.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return int
     */
    private function pricing_timestamp(\stdClass $job): int {
        $now = time();
        if (empty($job->offpeak)) {
            return $now;
        }
        $connector = $this->router->get_route(request::STEP_SECTIONS)['connector'];
        for ($hour = 0; $hour < 7 * 24; $hour++) {
            if (pricing::is_offpeak($connector, $now + $hour * HOURSECS)) {
                return $now + $hour * HOURSECS;
            }
        }
        return $now;
    }

    /**
     * Returns the size of each source of the job, in tokens.
     *
     * A source whose text was already chunked has its real count; one that was not is sized from its file, by the
     * kind of file: the size of a pdf or an office file says little about its text, so the figure is a first guess
     * that the real count replaces once the sources have been read.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return int[] Tokens per source id.
     */
    private function source_tokens(\stdClass $job): array {
        global $DB;

        $tokens = [];
        $sources = $DB->get_records(
            'local_aicb_source',
            ['jobid' => $job->id],
            'id',
            'id, filesize, tokencount, status, mimetype, filename',
        );
        foreach ($sources as $source) {
            if ($source->status === 'failed') {
                continue;
            }
            $tokens[$source->id] = $source->tokencount !== null
                ? (int) $source->tokencount
                : max(1, (int) ceil((int) $source->filesize / $this->bytes_per_token($source)));
        }
        return $tokens;
    }

    /**
     * Returns the bytes of a file that one token stands for, by its kind.
     *
     * @param \stdClass $source The source row, with its mimetype and file name.
     * @return int
     */
    private function bytes_per_token(\stdClass $source): int {
        $type = strtolower($source->mimetype . ' ' . $source->filename);
        $kinds = [
            'pdf' => 'pdf',
            'wordprocessingml' => 'docx',
            '.docx' => 'docx',
            'presentationml' => 'pptx',
            '.pptx' => 'pptx',
            'spreadsheetml' => 'xlsx',
            '.xlsx' => 'xlsx',
        ];
        foreach ($kinds as $needle => $kind) {
            if (str_contains($type, $needle)) {
                return self::BYTES_PER_TOKEN_BY_KIND[$kind];
            }
        }
        return self::BYTES_PER_TOKEN;
    }

    /**
     * Returns how many sections and subsections the outline is expected to have, one call of each step per node.
     *
     * The sections follow the length of the course, and half of them are assumed to have a subsection.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return int
     */
    private function count_section_calls(\stdClass $job): int {
        $duration = $this->duration_minutes($job);
        $duration = $duration > 0 ? $duration : self::DEFAULT_DURATION;

        $sections = (int) round($duration / self::MINUTES_PER_SECTION);
        $sections = max(self::MIN_SECTIONS, min(self::MAX_SECTIONS, $sections));
        return $sections + intdiv($sections, 2);
    }

    /**
     * Returns the length of the course the teacher asked for, in minutes, or 0 when none was asked for.
     *
     * The confirmed brief has it when the teacher filled the duration in. Before the brief exists the request is read
     * for it: a figure followed by minutes or hours ("Durata: 120 de minute", "2 hours").
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return int
     */
    private function duration_minutes(\stdClass $job): int {
        $brief = json_decode((string) $job->brief, true);
        $minutes = is_array($brief) ? (int) ($brief['duration_minutes'] ?? 0) : 0;
        if ($minutes > 0) {
            return $minutes;
        }
        $prompt = (string) ($job->prompt ?? '');
        if (preg_match('/(\d+)\s*(?:de\s+)?(?:minute|minutes|min)\b/iu', $prompt, $found)) {
            return (int) $found[1];
        }
        if (preg_match('/(\d+)\s*(?:de\s+)?(?:ore|ora|hours?)\b/iu', $prompt, $found)) {
            return (int) $found[1] * 60;
        }
        return 0;
    }
}
