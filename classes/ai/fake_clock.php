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
 * Clock for tests: time is fixed unless advanced, and sleep never really waits.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_clock implements clock {
    /** @var int Current time. */
    protected int $time;

    /** @var int[] Milliseconds passed to every sleep() call, in order. */
    protected array $sleeps = [];

    /**
     * Creates the clock.
     *
     * @param int|null $time Starting time, null for the real current time.
     */
    public function __construct(?int $time = null) {
        $this->time = $time ?? time();
    }

    #[\Override]
    public function time(): int {
        return $this->time;
    }

    #[\Override]
    public function sleep(int $milliseconds): void {
        $this->sleeps[] = $milliseconds;
        $this->time += (int) ceil($milliseconds / 1000);
    }

    /**
     * Returns the milliseconds passed to every sleep() call, in order.
     *
     * @return int[]
     */
    public function get_sleeps(): array {
        return $this->sleeps;
    }
}
