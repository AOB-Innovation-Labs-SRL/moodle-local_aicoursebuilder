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
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests of the Privacy API provider of local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * The metadata describes every table with personal data and the external AI providers.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_aicoursebuilder'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());

        $tables = [
            'local_aicb_job',
            'local_aicb_source',
            'local_aicb_chunk',
            'local_aicb_blueprint',
            'local_aicb_step',
            'local_aicb_ailog',
            'local_aicb_budget',
        ];
        foreach ($tables as $table) {
            $this->assertContains($table, $names);
        }
        foreach (['deepseek', 'anthropic', 'gemini', 'openaicompat'] as $provider) {
            $this->assertContains($provider, $names);
        }
    }

    /**
     * Only users with data have a context.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        [$user, $other] = $this->create_users_with_data();
        $nodata = $this->getDataGenerator()->create_user();

        $contextids = provider::get_contexts_for_userid($user->id)->get_contextids();
        $this->assertEquals([\context_user::instance($user->id)->id], array_map('intval', $contextids));
        $this->assertEmpty(provider::get_contexts_for_userid($nodata->id)->get_contextids());
        $this->assertCount(1, provider::get_contexts_for_userid($other->id)->get_contextids());
    }

    /**
     * The user context lists its user, and other contexts list nobody.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        [$user] = $this->create_users_with_data();

        $userlist = new userlist(\context_user::instance($user->id), 'local_aicoursebuilder');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$user->id], $userlist->get_userids());

        $systemlist = new userlist(\context_system::instance(), 'local_aicoursebuilder');
        provider::get_users_in_context($systemlist);
        $this->assertEmpty($systemlist->get_userids());
    }

    /**
     * The export holds the jobs with their children, the AI call log and the budget.
     */
    public function test_export_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        [$user] = $this->create_users_with_data();
        $context = \context_user::instance($user->id);
        $jobid = $DB->get_field('local_aicb_job', 'id', ['userid' => $user->id], MUST_EXIST);

        provider::export_user_data(new approved_contextlist($user, 'local_aicoursebuilder', [$context->id]));

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $root = get_string('pluginname', 'local_aicoursebuilder');
        $job = $writer->get_data([$root, get_string('privacy:path:jobs', 'local_aicoursebuilder'), 'job_' . $jobid]);
        $this->assertSame('Explain photosynthesis', $job->prompt);
        $this->assertCount(1, $job->sources);
        $this->assertCount(1, $job->chunks);
        $this->assertCount(1, $job->blueprints);
        $this->assertCount(1, $job->steps);

        $this->assertNotEmpty($writer->get_data([$root, get_string('privacy:path:ailog', 'local_aicoursebuilder')]));
        $this->assertNotEmpty($writer->get_data([$root, get_string('privacy:path:budget', 'local_aicoursebuilder')]));
    }

    /**
     * Deleting a context removes the data of its user only.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $other] = $this->create_users_with_data();

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertEquals(2, $DB->count_records('local_aicb_job'));

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));
        $this->assert_no_data($user->id);
        $this->assert_has_data($other->id);
    }

    /**
     * Deleting a user removes the jobs, their children, the call log and the budget rows.
     */
    public function test_delete_data_for_user(): void {
        $this->resetAfterTest();
        [$user, $other] = $this->create_users_with_data();
        $context = \context_user::instance($user->id);

        provider::delete_data_for_user(new approved_contextlist($user, 'local_aicoursebuilder', [$context->id]));

        $this->assert_no_data($user->id);
        $this->assert_has_data($other->id);
    }

    /**
     * Deleting several users of a context removes the data of the listed users only.
     */
    public function test_delete_data_for_users(): void {
        $this->resetAfterTest();
        [$user, $other] = $this->create_users_with_data();
        $context = \context_user::instance($user->id);

        provider::delete_data_for_users(new approved_userlist($context, 'local_aicoursebuilder', [$other->id]));
        $this->assert_has_data($user->id);
        $this->assert_has_data($other->id);

        provider::delete_data_for_users(new approved_userlist($context, 'local_aicoursebuilder', [$user->id]));
        $this->assert_no_data($user->id);
        $this->assert_has_data($other->id);
    }

    /**
     * Creates two users, each with a job, its children, an AI call and a budget row.
     *
     * @return \stdClass[] The two users.
     */
    private function create_users_with_data(): array {
        $users = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        foreach ($users as $user) {
            $this->create_data($user->id);
        }
        return $users;
    }

    /**
     * Creates a job of a user with a source, a chunk, a blueprint, a step, an AI call and a budget row.
     *
     * @param int $userid The user id.
     */
    private function create_data(int $userid): void {
        global $DB;
        $now = time();

        $jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $userid,
            'prompt' => 'Explain photosynthesis',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $sourceid = $DB->insert_record('local_aicb_source', (object) [
            'jobid' => $jobid,
            'filename' => 'biology.pdf',
            'mimetype' => 'application/pdf',
            'contenthash' => sha1('biology'),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_aicb_chunk', (object) [
            'jobid' => $jobid,
            'sourceid' => $sourceid,
            'chunkindex' => 0,
            'content' => 'Plants convert light into chemical energy.',
            'contenthash' => sha1('chunk'),
            'timecreated' => $now,
        ]);
        $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $jobid,
            'version' => 1,
            'content' => '{}',
            'contenthash' => hash('sha256', '{}'),
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_aicb_step', (object) [
            'jobid' => $jobid,
            'step' => 'brief',
            'inputhash' => hash('sha256', 'brief' . $jobid),
            'output' => '{}',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_aicb_ailog', (object) [
            'userid' => $userid,
            'jobid' => $jobid,
            'step' => 'brief',
            'connector' => 'fake',
            'model' => 'fake-model',
            'timecreated' => $now,
        ]);
        $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $userid,
            'period' => gmdate('Y-m', $now),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Asserts that every table has data of a user.
     *
     * @param int $userid The user id.
     */
    private function assert_has_data(int $userid): void {
        foreach ($this->count_user_rows($userid) as $table => $count) {
            $this->assertEquals(1, $count, $table);
        }
    }

    /**
     * Asserts that no table has data of a user.
     *
     * @param int $userid The user id.
     */
    private function assert_no_data(int $userid): void {
        foreach ($this->count_user_rows($userid) as $table => $count) {
            $this->assertEquals(0, $count, $table);
        }
    }

    /**
     * Counts the rows of a user in every table, including the tables that hang off the jobs.
     *
     * @param int $userid The user id.
     * @return int[] Row count by table.
     */
    private function count_user_rows(int $userid): array {
        global $DB;

        $jobids = $DB->get_fieldset_select('local_aicb_job', 'id', 'userid = ?', [$userid]);
        $counts = [
            'local_aicb_job' => count($jobids),
            'local_aicb_ailog' => $DB->count_records('local_aicb_ailog', ['userid' => $userid]),
            'local_aicb_budget' => $DB->count_records('local_aicb_budget', ['userid' => $userid]),
        ];
        // These tables have no userid: they belong to the jobs of the user. A row left behind by a
        // deleted job is counted as an orphan and fails the test.
        $children = ['local_aicb_source', 'local_aicb_chunk', 'local_aicb_blueprint', 'local_aicb_step'];
        foreach ($children as $table) {
            $counts[$table] = 0;
            if ($jobids) {
                [$insql, $params] = $DB->get_in_or_equal($jobids);
                $counts[$table] = $DB->count_records_select($table, "jobid $insql", $params);
            }
            $sql = "SELECT COUNT(1) FROM {" . $table . "} t
                     WHERE NOT EXISTS (SELECT 1 FROM {local_aicb_job} j WHERE j.id = t.jobid)";
            $this->assertEquals(0, $DB->count_records_sql($sql), $table . ' orphans');
        }
        return $counts;
    }
}
