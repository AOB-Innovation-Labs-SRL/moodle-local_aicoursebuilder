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
 * Moodle AI policy check run by every real connector before a call (spec 3.3, 3.9).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class policy {
    /**
     * Throws unless the request user has accepted the Moodle AI policy.
     *
     * @param request $request The completion request.
     * @return int The request user id.
     * @throws \coding_exception When the request has no user.
     * @throws connector_exception When the policy is not accepted.
     */
    public static function require_accepted(request $request): int {
        if ($request->userid === null) {
            throw new \coding_exception('AI requests sent to a real connector need a userid');
        }
        if (!\core_ai\manager::get_user_policy_status($request->userid)) {
            throw new connector_exception(connector_exception::POLICY_NOT_ACCEPTED);
        }
        return $request->userid;
    }
}
