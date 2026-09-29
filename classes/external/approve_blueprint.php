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

namespace local_aicoursebuilder\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Web service local_aicoursebuilder_approve_blueprint.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approve_blueprint extends job_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'version' => new external_value(PARAM_INT, 'Blueprint version to approve'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'sha256 the teacher reviewed, to detect concurrent edits'),
        ]);
    }

    /**
     * Validates the request; approval is implemented in a later task.
     *
     * @param int $jobid Job id.
     * @param int $version Blueprint version.
     * @param string $contenthash Reviewed content hash.
     * @return array
     */
    public static function execute(int $jobid, int $version, string $contenthash): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'jobid' => $jobid,
            'version' => $version,
            'contenthash' => $contenthash,
        ]);
        self::validate_job($params['jobid']);
        self::not_implemented();
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'version' => new external_value(PARAM_INT, 'Approved (locked) version'),
            'status' => new external_value(PARAM_ALPHA, 'Job status after approval'),
            'queued' => new external_value(PARAM_BOOL, 'Whether the build task was queued'),
        ]);
    }
}
