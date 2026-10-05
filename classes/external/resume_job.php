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
use local_aicoursebuilder\job_manager;

/**
 * Web service local_aicoursebuilder_resume_job.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resume_job extends job_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_INT, 'Job id'),
        ]);
    }

    /**
     * Resumes a job that a cost limit paused, once the limit has room again.
     *
     * The steps the job had finished are not paid for again. Only the owner of the job may resume it.
     *
     * @param int $jobid Job id.
     * @return array The job id and its status.
     */
    public static function execute(int $jobid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['jobid' => $jobid]);
        $job = self::validate_job($params['jobid'], true);

        (new job_manager())->resume($job);
        return ['jobid' => (int) $job->id, 'status' => job_manager::STATUS_QUEUED];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'jobid' => new external_value(PARAM_INT, 'Job id'),
            'status' => new external_value(PARAM_ALPHA, 'Job status'),
        ]);
    }
}
