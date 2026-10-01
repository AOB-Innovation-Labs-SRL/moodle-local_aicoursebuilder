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

use local_aicoursebuilder\ai\pricing;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\ingest\digest_builder;

/**
 * Estimates what a job will cost before any AI call is made (spec 3.8).
 *
 * The estimate is built from what is known when the teacher is about to start: the size of the sources and the
 * length of the course asked for. It follows the shape of the pipeline: one digest call per window of every
 * source, then the brief, the outline and one call per section and subsection. Each step is priced with the
 * connector and model of its own route, so changing a route in the settings changes the estimate.
 *
 * It is deliberately an upper bound rather than a forecast: every section call is assumed to carry all of the
 * source text, and a margin is added for the repair calls. A job that fits its limits on this estimate will not
 * be stopped by them halfway.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cost_estimator {
    /** @var int Bytes of a source file that one token stands for, when its text has not been extracted yet. */
    public const BYTES_PER_TOKEN = 5;

    /** @var int Tokens of instructions and schema that every call carries besides its material. */
    public const PROMPT_OVERHEAD = 1500;

    /** @var int Tokens a digest call writes. */
    public const DIGEST_OUTPUT = 2500;

    /** @var int Tokens the brief writes. */
    public const BRIEF_OUTPUT = 800;

    /** @var int Tokens the outline writes. */
    public const OUTLINE_OUTPUT = 3000;

    /** @var int Tokens the content of one section writes. */
    public const SECTION_OUTPUT = 3500;

    /** @var int Course length in minutes assumed when the brief does not give one. */
    public const DEFAULT_DURATION = 240;

    /** @var int Minutes of course that one section stands for. */
    public const MINUTES_PER_SECTION = 45;

    /** @var int Fewest sections an outline is assumed to have. */
    public const MIN_SECTIONS = 3;

    /** @var int Most sections an outline is assumed to have. */
    public const MAX_SECTIONS = 10;

    /** @var float Margin added for repair calls. */
    public const REPAIR_MARGIN = 1.1;

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
     * @param \stdClass $job The local_aicb_job row.
     * @return array ['cost' => float USD, 'tokensin' => int, 'tokensout' => int]
     */
    public function estimate(\stdClass $job): array {
        $sources = $this->source_tokens($job);
        $total = array_sum($sources);
        $calls = [];

        $window = (int) get_config('local_aicoursebuilder', 'digest_maxinputtokens');
        $window = $window > 0 ? $window : digest_builder::DEFAULT_MAX_INPUT_TOKENS;
        foreach ($sources as $tokens) {
            $windows = max(1, (int) ceil($tokens / $window));
            for ($i = 0; $i < $windows; $i++) {
                $calls[] = [request::STEP_DIGEST, min($tokens, $window) + self::PROMPT_OVERHEAD, self::DIGEST_OUTPUT];
            }
        }

        $digests = count($sources) * self::DIGEST_OUTPUT;
        $calls[] = [request::STEP_BRIEF, $digests + self::PROMPT_OVERHEAD, self::BRIEF_OUTPUT];
        $calls[] = [request::STEP_OUTLINE, $digests + self::BRIEF_OUTPUT + self::PROMPT_OVERHEAD, self::OUTLINE_OUTPUT];
        for ($i = $this->count_section_calls($job); $i > 0; $i--) {
            $calls[] = [
                request::STEP_SECTIONS,
                $total + self::OUTLINE_OUTPUT + self::PROMPT_OVERHEAD,
                self::SECTION_OUTPUT,
            ];
        }

        $cost = 0.0;
        $tokensin = 0;
        $tokensout = 0;
        foreach ($calls as [$step, $in, $out]) {
            $route = $this->router->get_route($step);
            $cost += pricing::cost_for($route['connector'], $route['model'], $in, $out);
            $tokensin += $in;
            $tokensout += $out;
        }

        return [
            'cost' => round($cost * self::REPAIR_MARGIN, 6),
            'tokensin' => (int) round($tokensin * self::REPAIR_MARGIN),
            'tokensout' => (int) round($tokensout * self::REPAIR_MARGIN),
        ];
    }

    /**
     * Returns the size of each source of the job, in tokens.
     *
     * A source whose text was already chunked has its real count; one that was not is sized from its file.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return int[] Tokens per source id.
     */
    private function source_tokens(\stdClass $job): array {
        global $DB;

        $tokens = [];
        $sources = $DB->get_records('local_aicb_source', ['jobid' => $job->id], 'id', 'id, filesize, tokencount, status');
        foreach ($sources as $source) {
            if ($source->status === 'failed') {
                continue;
            }
            $tokens[$source->id] = $source->tokencount !== null
                ? (int) $source->tokencount
                : max(1, (int) ceil((int) $source->filesize / self::BYTES_PER_TOKEN));
        }
        return $tokens;
    }

    /**
     * Returns how many section calls the outline is expected to need.
     *
     * One per section and one per subsection; the sections follow the length of the course in the brief, and
     * half of them are assumed to have a subsection.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return int
     */
    private function count_section_calls(\stdClass $job): int {
        $brief = json_decode((string) $job->brief, true);
        $duration = is_array($brief) ? (int) ($brief['duration_minutes'] ?? 0) : 0;
        $duration = $duration > 0 ? $duration : self::DEFAULT_DURATION;

        $sections = (int) round($duration / self::MINUTES_PER_SECTION);
        $sections = max(self::MIN_SECTIONS, min(self::MAX_SECTIONS, $sections));
        return $sections + intdiv($sections, 2);
    }
}
