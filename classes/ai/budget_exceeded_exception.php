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
 * Raised by budget_guard when a reservation would push a scope over its cost limit.
 *
 * Thrown before any HTTP call, so the orchestrator can stop a job in a controlled way, with a
 * checkpoint, instead of paying for a call that could not be afforded (spec 3.8).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget_exceeded_exception extends \moodle_exception {
    /** @var string The job's own cost limit was reached. */
    public const SCOPE_JOB = 'job';

    /** @var string The user's monthly limit was reached. */
    public const SCOPE_USER = 'user';

    /** @var string The site's monthly limit was reached. */
    public const SCOPE_SITE = 'site';

    /**
     * Creates the exception.
     *
     * @param string $scope One of the SCOPE_* constants.
     * @param float $limitusd The limit that was reached, in USD.
     */
    public function __construct(
        /** @var string One of the SCOPE_* constants. */
        public readonly string $scope,
        /** @var float The limit that was reached, in USD. */
        public readonly float $limitusd,
    ) {
        parent::__construct('budgetexceeded', 'local_aicoursebuilder', '', (object) [
            'scope' => $scope,
            'limit' => $limitusd,
        ]);
    }
}
