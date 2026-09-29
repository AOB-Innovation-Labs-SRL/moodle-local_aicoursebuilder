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
use invalid_parameter_exception;

/**
 * Web service local_aicoursebuilder_regenerate_node.
 *
 * Regeneration runs in an ad-hoc task; the editor polls get_job_status and get_blueprint.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regenerate_node extends job_api {
    /** @var string Blueprint node id: section, subsection, activity or question. */
    public const NODEID_PATTERN = '/^(s[0-9]+(-[0-9]+)?(\.[a-z][a-z0-9]*[0-9])?|q[0-9]+)$/';

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'nodeid' => new external_value(PARAM_TEXT, 'Blueprint node id (s1, s1-1, s1.quiz1, q3)'),
            'instructions' => new external_value(PARAM_TEXT, 'Extra instructions from the teacher', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Validates the request; regeneration is implemented in a later task.
     *
     * @param int $jobid Job id.
     * @param string $nodeid Blueprint node id.
     * @param string $instructions Extra instructions.
     * @return array
     */
    public static function execute(int $jobid, string $nodeid, string $instructions = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'jobid' => $jobid,
            'nodeid' => $nodeid,
            'instructions' => $instructions,
        ]);
        if (!preg_match(self::NODEID_PATTERN, $params['nodeid'])) {
            throw new invalid_parameter_exception('nodeid is not a blueprint node id');
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
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'nodeid' => new external_value(PARAM_TEXT, 'Blueprint node id'),
            'queued' => new external_value(PARAM_BOOL, 'Whether the regeneration task was queued'),
        ]);
    }
}
