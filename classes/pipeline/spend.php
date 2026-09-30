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

use local_aicoursebuilder\ai\result;

/**
 * Running total of the tokens, cost and calls one node has used.
 *
 * A node can take several calls before it is done: the first one, and up to two repairs. They are
 * all charged to the same step row, so the stored cost is what the node really cost, not what its
 * last attempt cost.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class spend {
    /** @var int Input tokens spent, cache misses only. */
    public int $tokensin = 0;

    /** @var int Output tokens spent. */
    public int $tokensout = 0;

    /** @var int Input tokens served from the prompt cache. */
    public int $tokenscached = 0;

    /** @var float Cost in USD. */
    public float $cost = 0.0;

    /** @var int Calls made. */
    public int $calls = 0;

    /**
     * Adds what one call used.
     *
     * @param result $result The result of the call.
     */
    public function add(result $result): void {
        $this->tokensin += $result->tokensin;
        $this->tokensout += $result->tokensout;
        $this->tokenscached += $result->tokenscached;
        $this->cost += $result->cost;
        $this->calls++;
    }
}
