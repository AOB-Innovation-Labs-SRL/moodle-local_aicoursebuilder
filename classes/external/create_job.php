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
 * Web service local_aicoursebuilder_create_job.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_job extends job_api {
    /** @var string Generate a new course. */
    public const MODE_NEWCOURSE = 'newcourse';

    /** @var string Add generated activities to an existing course. */
    public const MODE_EXISTINGCOURSE = 'existingcourse';

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'mode' => new external_value(PARAM_ALPHA, 'newcourse or existingcourse'),
            'categoryid' => new external_value(PARAM_INT, 'Category of the new course (newcourse mode)', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Target course (existingcourse mode)', VALUE_DEFAULT, 0),
            'sectionnum' => new external_value(PARAM_INT, 'Target section (existingcourse mode)', VALUE_DEFAULT, 0),
            'prompt' => new external_value(PARAM_TEXT, 'Teacher prompt'),
            'language' => new external_value(PARAM_ALPHANUMEXT, 'Course language', VALUE_DEFAULT, 'ro'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area with the source files', VALUE_DEFAULT, 0),
            'brief' => new external_value(PARAM_RAW, 'Confirmed brief as a JSON object, empty to generate it', VALUE_DEFAULT, ''),
            'offpeak' => new external_value(PARAM_BOOL, 'Schedule the AI work off-peak', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Validates the request; the job creation is implemented in a later task.
     *
     * @param string $mode newcourse or existingcourse.
     * @param int $categoryid Category of the new course.
     * @param int $courseid Target course.
     * @param int $sectionnum Target section.
     * @param string $prompt Teacher prompt.
     * @param string $language Course language.
     * @param int $draftitemid Draft area with the source files.
     * @param string $brief Brief JSON.
     * @param bool $offpeak Schedule off-peak.
     * @return array
     */
    public static function execute(
        string $mode,
        int $categoryid,
        int $courseid,
        int $sectionnum,
        string $prompt,
        string $language,
        int $draftitemid,
        string $brief,
        bool $offpeak
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'mode' => $mode,
            'categoryid' => $categoryid,
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'prompt' => $prompt,
            'language' => $language,
            'draftitemid' => $draftitemid,
            'brief' => $brief,
            'offpeak' => $offpeak,
        ]);

        if (trim($params['prompt']) === '') {
            throw new invalid_parameter_exception('prompt cannot be empty');
        }
        if ($params['brief'] !== '' && !is_array(json_decode($params['brief'], true))) {
            throw new invalid_parameter_exception('brief must be a JSON object');
        }

        if ($params['mode'] === self::MODE_NEWCOURSE) {
            if ($params['categoryid'] <= 0) {
                throw new invalid_parameter_exception('categoryid is required for a new course');
            }
            $context = \context_coursecat::instance($params['categoryid']);
            self::validate_context($context);
            require_capability('local/aicoursebuilder:use', $context);
            require_capability('moodle/course:create', $context);
        } else if ($params['mode'] === self::MODE_EXISTINGCOURSE) {
            if ($params['courseid'] <= 0) {
                throw new invalid_parameter_exception('courseid is required for an existing course');
            }
            $context = \context_course::instance($params['courseid']);
            self::validate_context($context);
            require_capability('local/aicoursebuilder:use', $context);
            require_capability('local/aicoursebuilder:generateincourse', $context);
            require_capability('moodle/course:manageactivities', $context);
        } else {
            throw new invalid_parameter_exception('mode must be newcourse or existingcourse');
        }

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
            'status' => new external_value(PARAM_ALPHA, 'Job status'),
        ]);
    }
}
