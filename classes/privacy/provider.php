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

namespace local_aicoursebuilder\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API provider of local_aicoursebuilder.
 *
 * All personal data of a user (jobs with their sources, chunks, blueprints and steps, the AI call log
 * and the monthly budget rows) is kept in the context of that user. Jobs are changed by their owner
 * only, so the owner is the only user whose data a job holds.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] AI providers that receive prompts and source text. */
    private const EXTERNAL_PROVIDERS = ['deepseek', 'anthropic', 'gemini', 'openaicompat'];

    /**
     * Describes the personal data stored by the plugin and the data sent to AI providers.
     *
     * @param collection $collection The collection to add the metadata to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aicb_job', [
            'userid' => 'privacy:metadata:local_aicb_job:userid',
            'courseid' => 'privacy:metadata:local_aicb_job:courseid',
            'prompt' => 'privacy:metadata:local_aicb_job:prompt',
            'brief' => 'privacy:metadata:local_aicb_job:brief',
            'timecreated' => 'privacy:metadata:local_aicb_job:timecreated',
        ], 'privacy:metadata:local_aicb_job');

        $collection->add_database_table('local_aicb_source', [
            'filename' => 'privacy:metadata:local_aicb_source:filename',
            'mimetype' => 'privacy:metadata:local_aicb_source:mimetype',
            'filesize' => 'privacy:metadata:local_aicb_source:filesize',
            'digest' => 'privacy:metadata:local_aicb_source:digest',
        ], 'privacy:metadata:local_aicb_source');

        $collection->add_database_table('local_aicb_chunk', [
            'title' => 'privacy:metadata:local_aicb_chunk:title',
            'content' => 'privacy:metadata:local_aicb_chunk:content',
        ], 'privacy:metadata:local_aicb_chunk');

        $collection->add_database_table('local_aicb_blueprint', [
            'content' => 'privacy:metadata:local_aicb_blueprint:content',
            'usermodified' => 'privacy:metadata:local_aicb_blueprint:usermodified',
            'approvedby' => 'privacy:metadata:local_aicb_blueprint:approvedby',
            'timeapproved' => 'privacy:metadata:local_aicb_blueprint:timeapproved',
        ], 'privacy:metadata:local_aicb_blueprint');

        $collection->add_database_table('local_aicb_step', [
            'step' => 'privacy:metadata:local_aicb_step:step',
            'output' => 'privacy:metadata:local_aicb_step:output',
            'cost' => 'privacy:metadata:local_aicb_step:cost',
        ], 'privacy:metadata:local_aicb_step');

        $collection->add_database_table('local_aicb_ailog', [
            'userid' => 'privacy:metadata:local_aicb_ailog:userid',
            'connector' => 'privacy:metadata:local_aicb_ailog:connector',
            'model' => 'privacy:metadata:local_aicb_ailog:model',
            'tokensin' => 'privacy:metadata:local_aicb_ailog:tokens',
            'cost' => 'privacy:metadata:local_aicb_ailog:cost',
            'timecreated' => 'privacy:metadata:local_aicb_ailog:timecreated',
        ], 'privacy:metadata:local_aicb_ailog');

        $collection->add_database_table('local_aicb_budget', [
            'userid' => 'privacy:metadata:local_aicb_budget:userid',
            'period' => 'privacy:metadata:local_aicb_budget:period',
            'spentusd' => 'privacy:metadata:local_aicb_budget:spentusd',
            'limitusd' => 'privacy:metadata:local_aicb_budget:limitusd',
        ], 'privacy:metadata:local_aicb_budget');

        foreach (self::EXTERNAL_PROVIDERS as $name) {
            $collection->add_external_location_link($name, [
                'prompt' => 'privacy:metadata:aiprovider:prompt',
                'sourcecontent' => 'privacy:metadata:aiprovider:sourcecontent',
                'language' => 'privacy:metadata:aiprovider:language',
            ], 'privacy:metadata:aiprovider:' . $name);
        }

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->add_subsystem_link('core_ai', [], 'privacy:metadata:core_ai');

        return $collection;
    }

    /**
     * Returns the contexts that hold data of a user: the user context, when the user has any data.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :level
                   AND ctx.instanceid = :userid
                   AND (EXISTS (SELECT 1 FROM {local_aicb_job} j WHERE j.userid = :jobuser)
                        OR EXISTS (SELECT 1 FROM {local_aicb_ailog} l WHERE l.userid = :loguser)
                        OR EXISTS (SELECT 1 FROM {local_aicb_budget} b WHERE b.userid = :budgetuser))";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_USER,
            'userid' => $userid,
            'jobuser' => $userid,
            'loguser' => $userid,
            'budgetuser' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Returns the users that have data in a context.
     *
     * @param userlist $userlist The userlist to fill.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_USER) {
            return;
        }
        $sql = "SELECT userid FROM {local_aicb_job} WHERE userid = :jobuser
                UNION
                SELECT userid FROM {local_aicb_ailog} WHERE userid = :loguser
                UNION
                SELECT userid FROM {local_aicb_budget} WHERE userid = :budgetuser";
        $userlist->add_from_sql('userid', $sql, [
            'jobuser' => $context->instanceid,
            'loguser' => $context->instanceid,
            'budgetuser' => $context->instanceid,
        ]);
    }

    /**
     * Exports the data of a user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        $context = \context_user::instance($user->id);
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $root = get_string('pluginname', 'local_aicoursebuilder');

        $jobs = $DB->get_records('local_aicb_job', ['userid' => $user->id], 'id');
        foreach ($jobs as $job) {
            $data = (object) [
                'mode' => $job->mode,
                'courseid' => $job->courseid,
                'status' => $job->status,
                'prompt' => $job->prompt,
                'brief' => $job->brief,
                'language' => $job->language,
                'estimatedcost' => $job->estimatedcost,
                'actualcost' => $job->actualcost,
                'timecreated' => transform::datetime($job->timecreated),
                'timemodified' => transform::datetime($job->timemodified),
                'sources' => self::get_rows(
                    'local_aicb_source',
                    $job->id,
                    'id',
                    'id, filename, mimetype, filesize, status, language, pagecount, tokencount, digest'
                ),
                'chunks' => self::get_rows(
                    'local_aicb_chunk',
                    $job->id,
                    'sourceid, chunkindex',
                    'id, sourceid, chunkindex, title, pagefrom, pageto, content'
                ),
                'blueprints' => self::get_rows(
                    'local_aicb_blueprint',
                    $job->id,
                    'version',
                    'id, version, status, content, usermodified, approvedby, timecreated, timeapproved'
                ),
                'steps' => self::get_rows(
                    'local_aicb_step',
                    $job->id,
                    'id',
                    'id, step, nodekey, status, connector, model, output, tokensin, tokensout, cost'
                ),
            ];
            writer::with_context($context)->export_data(
                [$root, get_string('privacy:path:jobs', 'local_aicoursebuilder'), 'job_' . $job->id],
                $data
            );
        }

        $logs = $DB->get_records('local_aicb_ailog', ['userid' => $user->id], 'id');
        if ($logs) {
            $entries = [];
            foreach ($logs as $log) {
                $entries[] = (object) [
                    'jobid' => $log->jobid,
                    'step' => $log->step,
                    'connector' => $log->connector,
                    'model' => $log->model,
                    'tokensin' => $log->tokensin,
                    'tokensout' => $log->tokensout,
                    'cost' => $log->cost,
                    'status' => $log->status,
                    'timecreated' => transform::datetime($log->timecreated),
                ];
            }
            writer::with_context($context)->export_data(
                [$root, get_string('privacy:path:ailog', 'local_aicoursebuilder')],
                (object) ['calls' => $entries]
            );
        }

        $budgets = $DB->get_records('local_aicb_budget', ['userid' => $user->id], 'period', 'id, period, limitusd, spentusd');
        if ($budgets) {
            writer::with_context($context)->export_data(
                [$root, get_string('privacy:path:budget', 'local_aicoursebuilder')],
                (object) ['months' => array_values($budgets)]
            );
        }
    }

    /**
     * Returns the rows of a table that belong to a job, as a list for export.
     *
     * @param string $table Table with a jobid column.
     * @param int $jobid The job id.
     * @param string $sort Sort order.
     * @param string $fields Comma-separated fields.
     * @return \stdClass[]
     */
    private static function get_rows(string $table, int $jobid, string $sort, string $fields): array {
        global $DB;
        return array_values($DB->get_records($table, ['jobid' => $jobid], $sort, $fields));
    }

    /**
     * Deletes the data of all users in a context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel === CONTEXT_USER) {
            self::delete_user_data($context->instanceid);
        }
    }

    /**
     * Deletes the data of a user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        if (in_array(\context_user::instance($userid)->id, $contextlist->get_contextids())) {
            self::delete_user_data($userid);
        }
    }

    /**
     * Deletes the data of several users in a context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_USER) {
            return;
        }
        if (in_array($context->instanceid, $userlist->get_userids())) {
            self::delete_user_data($context->instanceid);
        }
    }

    /**
     * Deletes the jobs of a user with everything that depends on them, the AI call log and the budget rows.
     *
     * @param int $userid The user id.
     */
    private static function delete_user_data(int $userid): void {
        global $DB;

        $jobids = $DB->get_fieldset_select('local_aicb_job', 'id', 'userid = ?', [$userid]);
        if ($jobids) {
            [$insql, $params] = $DB->get_in_or_equal($jobids);
            $tables = ['local_aicb_chunk', 'local_aicb_source', 'local_aicb_step', 'local_aicb_blueprint', 'local_aicb_ailog'];
            foreach ($tables as $table) {
                $DB->delete_records_select($table, "jobid $insql", $params);
            }
            $DB->delete_records('local_aicb_job', ['userid' => $userid]);
        }
        $DB->delete_records('local_aicb_ailog', ['userid' => $userid]);
        $DB->delete_records('local_aicb_budget', ['userid' => $userid]);
    }
}
