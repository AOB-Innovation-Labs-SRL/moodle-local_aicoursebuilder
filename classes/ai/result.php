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
 * Immutable result of a completion call.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result {
    /**
     * Creates the result.
     *
     * @param string $content Raw text returned by the model.
     * @param array|null $json Decoded JSON content, or null when the content is not JSON.
     * @param int $tokensin Input tokens billed (cache misses).
     * @param int $tokensout Output tokens.
     * @param int $tokenscached Input tokens served from the prompt cache.
     * @param float $cost Real cost of the call in USD.
     * @param string $model Model that answered.
     * @param int $durationms Call duration in milliseconds.
     * @param string $finishreason Why the model stopped (stop, length and so on).
     * @param string $connector Name of the connector that ran the call.
     */
    public function __construct(
        /** @var string Raw text returned by the model. */
        public readonly string $content,
        /** @var array|null Decoded JSON content. */
        public readonly ?array $json,
        /** @var int Input tokens. */
        public readonly int $tokensin,
        /** @var int Output tokens. */
        public readonly int $tokensout,
        /** @var int Cached input tokens. */
        public readonly int $tokenscached,
        /** @var float Cost in USD. */
        public readonly float $cost,
        /** @var string Model. */
        public readonly string $model,
        /** @var int Duration in milliseconds. */
        public readonly int $durationms,
        /** @var string Finish reason. */
        public readonly string $finishreason = 'stop',
        /** @var string Connector name. */
        public readonly string $connector = '',
    ) {
    }

    /**
     * Returns the token usage.
     *
     * @return array ['tokensin' => int, 'tokensout' => int, 'tokenscached' => int]
     */
    public function get_usage(): array {
        return [
            'tokensin' => $this->tokensin,
            'tokensout' => $this->tokensout,
            'tokenscached' => $this->tokenscached,
        ];
    }
}
