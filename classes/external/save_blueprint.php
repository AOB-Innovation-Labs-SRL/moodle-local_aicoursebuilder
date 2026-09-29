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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;

/**
 * Web service local_aicoursebuilder_save_blueprint.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_blueprint extends job_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'blueprint' => new external_value(PARAM_RAW, 'Edited blueprint JSON'),
            'baseversion' => new external_value(PARAM_INT, 'Version the edit started from, for optimistic locking'),
        ]);
    }

    /**
     * Validates the request; saving is implemented in a later task.
     *
     * @param int $jobid Job id.
     * @param string $blueprint Blueprint JSON.
     * @param int $baseversion Version the edit started from.
     * @return array
     */
    public static function execute(int $jobid, string $blueprint, int $baseversion): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'jobid' => $jobid,
            'blueprint' => $blueprint,
            'baseversion' => $baseversion,
        ]);
        if (!is_array(json_decode($params['blueprint'], true))) {
            throw new invalid_parameter_exception('blueprint must be a JSON object');
        }
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
            'version' => new external_value(PARAM_INT, 'New blueprint version'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'sha256 of the saved JSON'),
            'valid' => new external_value(PARAM_BOOL, 'Whether the blueprint passed the validator'),
            'errors' => new external_multiple_structure(
                new external_single_structure([
                    'path' => new external_value(PARAM_TEXT, 'JSON pointer of the invalid node'),
                    'message' => new external_value(PARAM_TEXT, 'Validation error'),
                ]),
                'Validation errors'
            ),
        ]);
    }
}
