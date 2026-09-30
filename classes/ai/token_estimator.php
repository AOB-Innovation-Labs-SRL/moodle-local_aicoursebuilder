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
 * Heuristic token estimate shared by the connectors (spec 3.3).
 *
 * The characters-per-token factor is a setting of its own per connector (setting key
 * token_estimator_charsperfactor_{connector NAME}), so each provider can be calibrated on its own
 * golden-set run (spec 3.3, 3.5); the class using this trait must define a public NAME constant.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait token_estimator {
    /** @var float Default characters per token, heuristic for Romanian (spec 3.3). */
    public const DEFAULT_CHARS_PER_TOKEN = 3.5;

    /**
     * Estimates tokens as characters / factor (heuristic for Romanian), rounded up.
     *
     * @param string $text The text.
     * @return int
     */
    public function count_tokens(string $text): int {
        return (int) ceil(\core_text::strlen($text) / $this->get_chars_per_token());
    }

    /**
     * Returns the characters-per-token factor of this connector, from its setting or the default.
     *
     * @return float
     */
    protected function get_chars_per_token(): float {
        $value = get_config('local_aicoursebuilder', 'token_estimator_charsperfactor_' . static::NAME);
        $factor = (float) $value;
        return $factor > 0 ? $factor : self::DEFAULT_CHARS_PER_TOKEN;
    }
}
