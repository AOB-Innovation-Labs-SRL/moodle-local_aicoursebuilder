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
use local_aicoursebuilder\pipeline\failure_reason;
use local_aicoursebuilder\pipeline\step_store;

/**
 * Web service local_aicoursebuilder_get_job_status.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_job_status extends job_api {
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
     * Returns the state and progress of a job, with the pipeline steps that have run.
     *
     * @param int $jobid Job id.
     * @return array
     */
    public static function execute(int $jobid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['jobid' => $jobid]);
        $job = self::validate_job($params['jobid']);

        $version = $DB->get_field_sql('SELECT MAX(version) FROM {local_aicb_blueprint} WHERE jobid = ?', [$job->id]);
        $steps = [];
        foreach ((new step_store())->all_for_job((int) $job->id) as $step) {
            // Only a structured reason goes out: it never holds model output or source text.
            $reason = failure_reason::decode($step->error);
            $steps[] = [
                'step' => $step->step,
                'nodekey' => (string) $step->nodekey,
                'status' => $step->status,
                'reason' => $reason === null ? '' : (string) json_encode($reason, JSON_UNESCAPED_SLASHES),
            ];
        }

        return [
            'jobid' => (int) $job->id,
            'status' => $job->status,
            'stage' => (string) $job->stage,
            'progress' => (int) $job->progress,
            'message' => (string) $job->statusmessage,
            'courseid' => (int) $job->courseid,
            'blueprintversion' => (int) $version,
            'estimatedcost' => (float) $job->estimatedcost,
            'actualcost' => (float) $job->actualcost,
            'error' => (string) $job->error,
            'timemodified' => (int) $job->timemodified,
            'steps' => $steps,
        ];
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
            'stage' => new external_value(PARAM_ALPHANUMEXT, 'Current pipeline step, empty when idle'),
            'progress' => new external_value(PARAM_INT, 'Progress 0-100'),
            'message' => new external_value(PARAM_TEXT, 'Short progress message'),
            'courseid' => new external_value(PARAM_INT, 'Target or created course, 0 when none'),
            'blueprintversion' => new external_value(PARAM_INT, 'Latest blueprint version, 0 when none'),
            'estimatedcost' => new external_value(PARAM_FLOAT, 'Estimated cost, USD'),
            'actualcost' => new external_value(PARAM_FLOAT, 'Actual cost so far, USD'),
            'error' => new external_value(PARAM_TEXT, 'Last error, empty when none'),
            'timemodified' => new external_value(PARAM_INT, 'Last change'),
            'steps' => new external_multiple_structure(
                new external_single_structure([
                    'step' => new external_value(PARAM_ALPHA, 'Pipeline step'),
                    'nodekey' => new external_value(PARAM_TEXT, 'Sub-call key, empty for the whole step'),
                    'status' => new external_value(PARAM_ALPHA, 'Step status'),
                    'reason' => new external_value(
                        PARAM_RAW,
                        'Why the node failed or was left manual, as JSON (type, httpcode, errors, attempts); empty when none'
                    ),
                ]),
                'Pipeline steps'
            ),
        ]);
    }
}
