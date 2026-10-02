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

use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\blueprint\validation_error;

/**
 * Step 2: turns the brief and the source digests into the course and its sections.
 *
 * The outline reads the digests rather than the documents: it decides how the course is divided,
 * which needs the shape of the material and not its wording, and the documents themselves would
 * not fit in one call (spec 3.6).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_outline extends step {
    /** @var int Fewest minutes of learner time one section should hold. */
    public const MIN_MINUTES_PER_SECTION = 30;

    /** @var int Most minutes of learner time one section should hold. */
    public const MAX_MINUTES_PER_SECTION = 80;

    /** @var int Most sections an outline may have. */
    public const MAX_SECTIONS = 12;

    /**
     * Returns how many top-level sections a course of this length may have.
     *
     * One section holds 30 to 80 minutes of learner time, so 240 minutes is 3 to 8 sections.
     *
     * @param int $minutes Total learner time from the brief.
     * @return int[] The fewest and the most sections, or [0, 0] when the brief has no duration.
     */
    public static function section_range(int $minutes): array {
        if ($minutes <= 0) {
            return [0, 0];
        }
        $minimum = min(self::MAX_SECTIONS, max(1, (int) ceil($minutes / self::MAX_MINUTES_PER_SECTION)));
        $maximum = min(self::MAX_SECTIONS, max($minimum, (int) floor($minutes / self::MIN_MINUTES_PER_SECTION)));
        return [$minimum, $maximum];
    }

    /**
     * Returns the pipeline step this class runs.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_OUTLINE;
    }

    /**
     * Returns the prompt template name of this step.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'outline';
    }

    /**
     * Returns the placeholder values of the outline prompt.
     *
     * @param array $input The brief, as ['brief' => array].
     * @param string $nodekey Unused: the outline is written in one call.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        // A brief with no duration keeps the old fixed range.
        [$minimum, $maximum] = self::section_range((int) ($input['brief']['duration_minutes'] ?? 0));
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'brief' => $input['brief'] ?? [],
            'sources' => $this->digests(),
            'source_ids' => $this->context->source_ids_text(),
            'sections_min' => $minimum ?: 3,
            'sections_max' => $maximum ?: self::MAX_SECTIONS,
        ];
    }

    /**
     * Checks the step schema, then that the number of sections fits the length of the course.
     *
     * @param array|null $output Decoded answer, null when it was not JSON.
     * @param array $input Step input.
     * @param string $nodekey Unused.
     * @return validation_error[]
     */
    protected function validate(?array $output, array $input, string $nodekey): array {
        $errors = parent::validate($output, $input, $nodekey);
        if ($output === null || !is_array($output['sections'] ?? null)) {
            return $errors;
        }
        $minutes = (int) ($input['brief']['duration_minutes'] ?? 0);
        [$minimum, $maximum] = self::section_range($minutes);
        $count = count($output['sections']);
        if ($maximum > 0 && ($count < $minimum || $count > $maximum)) {
            $errors[] = new validation_error(
                '/sections',
                validation_error::CODE_MOODLE_LIMIT,
                "A course of {$minutes} minutes needs between {$minimum} and {$maximum} sections "
                    . "(30 to 80 minutes each), found {$count}.",
            );
        }
        return $errors;
    }

    /**
     * Returns the digests the outline is built from.
     *
     * @return string
     */
    protected function digests(): string {
        if ($this->context->digests === []) {
            return 'There are no source documents: build the outline from the brief alone.';
        }
        return (string) json_encode(
            $this->context->digests,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
