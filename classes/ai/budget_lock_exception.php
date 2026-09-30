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
 * Raised by budget_guard when the lock on a budget row cannot be obtained within its timeout.
 *
 * Distinct from budget_exceeded_exception: this is a contention failure (another runner is
 * reserving against the same row right now), not a limit being reached.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget_lock_exception extends \moodle_exception {
    /**
     * Creates the exception.
     *
     * @param string $resource The lock resource key that could not be obtained.
     */
    public function __construct(
        /** @var string The lock resource key that could not be obtained. */
        public readonly string $resource,
    ) {
        parent::__construct('budgetlocktimeout', 'local_aicoursebuilder', '', $resource);
    }
}
