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
 * Output token limit (max_tokens) sent with the calls of each pipeline step.
 *
 * Without an explicit limit DeepSeek answers with at most 8K tokens in non-thinking mode, which
 * cuts a quiz or a long section off in the middle of its JSON. The defaults are sized for
 * deepseek-flash, whose documented maximum output is 384K (393216) tokens: about four times
 * what a v2 answer of the step needs, so a normal answer never reaches it, while a runaway one
 * still stops and the budget reservation (output priced at this limit) stays bounded.
 * Each limit can be changed in the plugin settings (maxtokens_{step}).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class output_limits {
    /** @var int Largest output deepseek-flash accepts, from the DeepSeek API documentation (384K). */
    public const MAX = 393216;

    /** @var int Limit for a step that has no default of its own. */
    public const FALLBACK = 16384;

    /** @var array<string, int> Default limit per step. */
    public const DEFAULTS = [
        request::STEP_DIGEST => 8192,
        request::STEP_BRIEF => 4096,
        request::STEP_OUTLINE => 16384,
        request::STEP_SECTIONS => 32768,
        request::STEP_ACTIVITIES => 16384,
        request::STEP_QUESTIONS => 32768,
        request::STEP_REVIEW => 16384,
        // A repair writes the whole document again, so it needs as much room as the largest step.
        request::STEP_REPAIR => 32768,
    ];

    /**
     * Returns the default limit of a step.
     *
     * @param string $step Pipeline step.
     * @return int
     */
    public static function default_for(string $step): int {
        return self::DEFAULTS[$step] ?? self::FALLBACK;
    }

    /**
     * Returns the limit to send with a call of this step: the setting when it is a positive number,
     * the default otherwise, and never more than the provider maximum.
     *
     * @param string $step Pipeline step.
     * @return int
     */
    public static function for_step(string $step): int {
        $configured = (int) get_config('local_aicoursebuilder', "maxtokens_{$step}");
        $limit = $configured > 0 ? $configured : self::default_for($step);
        return min(self::MAX, $limit);
    }
}
