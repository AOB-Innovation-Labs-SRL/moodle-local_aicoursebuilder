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

/**
 * Web service functions for local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_aicoursebuilder_create_job' => [
        'classname' => \local_aicoursebuilder\external\create_job::class,
        'description' => 'Creates a course generation job (new course or existing course) as a draft, with its sources.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use, local/aicoursebuilder:generateincourse',
    ],
    'local_aicoursebuilder_start_job' => [
        'classname' => \local_aicoursebuilder\external\start_job::class,
        'description' => 'Starts a draft job after its estimated cost was shown, and queues its ingestion.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
    'local_aicoursebuilder_get_job_status' => [
        'classname' => \local_aicoursebuilder\external\get_job_status::class,
        'description' => 'Returns the state and progress of a job, for polling.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
    'local_aicoursebuilder_get_blueprint' => [
        'classname' => \local_aicoursebuilder\external\get_blueprint::class,
        'description' => 'Returns a blueprint version of a job.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
    'local_aicoursebuilder_save_blueprint' => [
        'classname' => \local_aicoursebuilder\external\save_blueprint::class,
        'description' => 'Validates and saves an edited blueprint as a new version.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
    'local_aicoursebuilder_approve_blueprint' => [
        'classname' => \local_aicoursebuilder\external\approve_blueprint::class,
        'description' => 'Approves and locks a blueprint version, then queues the course build.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
    'local_aicoursebuilder_estimate_cost' => [
        'classname' => \local_aicoursebuilder\external\estimate_cost::class,
        'description' => 'Estimates the AI cost of a job and checks it against the budget limits.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
    'local_aicoursebuilder_regenerate_node' => [
        'classname' => \local_aicoursebuilder\external\regenerate_node::class,
        'description' => 'Queues the regeneration of one blueprint node.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
    'local_aicoursebuilder_resume_job' => [
        'classname' => \local_aicoursebuilder\external\resume_job::class,
        'description' => 'Resumes a job that a cost limit paused, from the steps it had finished.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicoursebuilder:use',
    ],
];
