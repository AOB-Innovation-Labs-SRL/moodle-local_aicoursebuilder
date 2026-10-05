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

use core_external\external_api;
use invalid_parameter_exception;
use local_aicoursebuilder\blueprint\validator;
use local_aicoursebuilder\blueprint\version_store;
use local_aicoursebuilder\task\regenerate_node as regenerate_task;

/**
 * Tests for the web service contracts (parameters, returns and access checks).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\external\job_api
 * @covers     \local_aicoursebuilder\external\create_job
 * @covers     \local_aicoursebuilder\external\start_job
 * @covers     \local_aicoursebuilder\external\get_job_status
 * @covers     \local_aicoursebuilder\external\get_blueprint
 * @covers     \local_aicoursebuilder\external\save_blueprint
 * @covers     \local_aicoursebuilder\external\approve_blueprint
 * @covers     \local_aicoursebuilder\external\estimate_cost
 * @covers     \local_aicoursebuilder\external\regenerate_node
 * @covers     \local_aicoursebuilder\external\resume_job
 */
final class external_functions_test extends \core_external\tests\externallib_testcase {
    /** @var string A sha256 hash for the examples. */
    private const HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Example parameters and return values of every function.
     *
     * @return array Class name => ['params' => array, 'returns' => array].
     */
    private function examples(): array {
        $blueprint = file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json');
        return [
            create_job::class => [
                'params' => ['mode' => 'newcourse', 'categoryid' => 1, 'courseid' => 0, 'sectionnum' => 0,
                    'prompt' => 'Curs despre energie regenerabilă', 'language' => 'ro', 'draftitemid' => 123,
                    'brief' => '{"level": "începător"}', 'offpeak' => true],
                'returns' => ['jobid' => 7, 'status' => 'queued'],
            ],
            start_job::class => [
                'params' => ['jobid' => 7],
                'returns' => ['jobid' => 7, 'status' => 'queued'],
            ],
            get_job_status::class => [
                'params' => ['jobid' => 7],
                'returns' => ['jobid' => 7, 'status' => 'generating', 'stage' => 'sections', 'progress' => 40,
                    'message' => 'Secțiunea 2 din 3', 'courseid' => 0, 'blueprintversion' => 0,
                    'estimatedcost' => 0.17, 'actualcost' => 0.05, 'error' => '', 'timemodified' => 1790000000,
                    'steps' => [
                        ['step' => 'outline', 'nodekey' => '', 'status' => 'done', 'reason' => ''],
                        ['step' => 'sections', 'nodekey' => 's2', 'status' => 'running', 'reason' => ''],
                    ]],
            ],
            get_blueprint::class => [
                'params' => ['jobid' => 7, 'version' => 0],
                'returns' => ['jobid' => 7, 'version' => 2, 'status' => 'draft', 'schemaversion' => '1.0',
                    'blueprint' => $blueprint, 'contenthash' => self::HASH, 'timemodified' => 1790000000],
            ],
            save_blueprint::class => [
                'params' => ['jobid' => 7, 'blueprint' => $blueprint, 'baseversion' => 2],
                'returns' => ['version' => 3, 'contenthash' => self::HASH, 'valid' => false,
                    'errors' => [['path' => '/sections/0/activities/1', 'message' => 'content lipsește']]],
            ],
            approve_blueprint::class => [
                'params' => ['jobid' => 7, 'version' => 3, 'contenthash' => self::HASH],
                'returns' => ['jobid' => 7, 'version' => 3, 'status' => 'approved', 'queued' => true],
            ],
            estimate_cost::class => [
                'params' => ['jobid' => 7],
                'returns' => ['estimatedcost' => 0.17, 'currency' => 'USD', 'tokensin' => 550000,
                    'tokensout' => 140000, 'withinbudget' => true, 'joblimit' => 1.5, 'userremaining' => 9.83,
                    'useralert' => false],
            ],
            resume_job::class => [
                'params' => ['jobid' => 7],
                'returns' => ['jobid' => 7, 'status' => 'queued'],
            ],
            regenerate_node::class => [
                'params' => ['jobid' => 7, 'nodeid' => 's1.quiz1', 'instructions' => 'Mai multe întrebări grele'],
                'returns' => ['jobid' => 7, 'nodeid' => 's1.quiz1', 'queued' => true],
            ],
        ];
    }

    /**
     * Every function describes valid parameters and returns.
     */
    public function test_parameters_and_returns(): void {
        $this->resetAfterTest();
        foreach ($this->examples() as $class => $example) {
            $params = external_api::validate_parameters($class::execute_parameters(), $example['params']);
            $this->assertSame($example['params'], $params, $class);
            $cleaned = external_api::clean_returnvalue($class::execute_returns(), $example['returns']);
            $this->assertEquals($example['returns'], $cleaned, $class);
        }
    }

    /**
     * Every function is registered, allowed from AJAX, and points to its class.
     */
    public function test_functions_are_registered(): void {
        foreach (array_keys($this->examples()) as $class) {
            $name = 'local_aicoursebuilder_' . substr($class, strrpos($class, '\\') + 1);
            $info = external_api::external_function_info($name);
            $this->assertSame(ltrim($class, '\\'), ltrim($info->classname, '\\'), $name);
            $this->assertTrue($info->allowed_from_ajax, $name);
            $this->assertNotEmpty($info->capabilities, $name);
        }
    }

    /**
     * Inserts a job owned by a user.
     *
     * @param int $userid Owner.
     * @param int $courseid Target course.
     * @return int Job id.
     */
    private function create_job_record(int $userid, int $courseid): int {
        global $DB;
        return $DB->insert_record('local_aicb_job', (object) [
            'userid' => $userid,
            'mode' => create_job::MODE_EXISTINGCOURSE,
            'courseid' => $courseid,
            'prompt' => 'Test',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Asserts that a call is refused with a given error of the plugin.
     *
     * @param string $errorcode The error code.
     * @param callable $call The call.
     */
    private function assert_refused(string $errorcode, callable $call): void {
        try {
            $call();
            $this->fail("Expected {$errorcode}");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertSame('local_aicoursebuilder', $e->module);
        }
    }

    /**
     * The job owner passes the access checks of every job function.
     */
    public function test_job_functions_check_access_then_stop(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($teacher->id, $course->id);
        $this->setUser($teacher);

        $hash = self::HASH;
        $this->assertSame($jobid, get_job_status::execute($jobid)['jobid']);
        $this->assertArrayHasKey('estimatedcost', estimate_cost::execute($jobid));
        // The access checks pass; what stops the calls is that the job has no blueprint to read or to review yet.
        $this->assert_refused('blueprintnotfound', fn() => get_blueprint::execute($jobid, 0));
        $this->assert_refused('blueprintnotreviewable', fn() => save_blueprint::execute($jobid, '{"version": "1.0"}', 1));
        $this->assert_refused('blueprintnotreviewable', fn() => approve_blueprint::execute($jobid, 1, $hash));
        try {
            regenerate_node::execute($jobid, 's1.quiz1', '');
            $this->fail('Expected the missing blueprint error');
        } catch (\moodle_exception $e) {
            $this->assertSame('regenerationnoblueprint', $e->errorcode);
        }
        $created = create_job::execute('existingcourse', 0, $course->id, 1, 'Test', 'ro', 0, '', true);
        $this->assertSame('draft', $created['status']);
    }

    /**
     * The owner queues a dedicated task pinned to the current blueprint version.
     */
    public function test_regenerate_node_queues_its_own_task(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($owner->id, $course->id);
        $blueprint = json_decode(
            file_get_contents(dirname(__DIR__) . '/fixtures/blueprint_golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $source = (new version_store())->save($jobid, (int) $owner->id, $blueprint, new validator());
        $this->setUser($owner);

        $response = regenerate_node::execute($jobid, 's1.quiz1', 'Clarifică feedback-ul');

        $this->assertSame(['jobid' => $jobid, 'nodeid' => 's1.quiz1', 'queued' => true], $response);
        $task = $DB->get_record('task_adhoc', ['classname' => '\\' . regenerate_task::class], '*', MUST_EXIST);
        $data = json_decode($task->customdata, true);
        $this->assertSame((int) $source->id, $data['blueprintid']);
        $this->assertSame('s1.quiz1', $data['nodeid']);
        $this->assertSame('Clarifică feedback-ul', $data['instructions']);
        $this->assertSame((int) $owner->id, (int) $task->userid);
    }

    /**
     * A user who is neither the owner nor a manager is refused.
     */
    public function test_job_functions_refuse_other_users(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($owner->id, $course->id);
        $this->setUser($other);

        $this->expectException(\required_capability_exception::class);
        get_job_status::execute($jobid);
    }

    /**
     * A manager can read another user's job but cannot change it.
     */
    public function test_manager_reads_but_cannot_change_other_users_job(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);
        $jobid = $this->create_job_record($owner->id, $course->id);
        $this->setUser($manager);

        // Reading passes the access checks.
        $this->assertSame($jobid, get_job_status::execute($jobid)['jobid']);
        $this->assertArrayHasKey('estimatedcost', estimate_cost::execute($jobid));
        $this->assert_refused('blueprintnotfound', fn() => get_blueprint::execute($jobid, 0));

        // Changing is for the owner only.
        $hash = self::HASH;
        $writes = [
            'approve_blueprint' => fn() => approve_blueprint::execute($jobid, 1, $hash),
            'save_blueprint' => fn() => save_blueprint::execute($jobid, '{"version": "1.0"}', 1),
            'regenerate_node' => fn() => regenerate_node::execute($jobid, 's1.quiz1', ''),
            'start_job' => fn() => start_job::execute($jobid),
            'resume_job' => fn() => resume_job::execute($jobid),
        ];
        foreach ($writes as $name => $call) {
            try {
                $call();
                $this->fail("{$name} accepted for a manager who is not the owner");
            } catch (\moodle_exception $e) {
                $this->assertSame('notjobowner', $e->errorcode, $name);
                $this->assertSame('local_aicoursebuilder', $e->module, $name);
            }
        }
    }

    /**
     * Students cannot create jobs.
     */
    public function test_create_job_requires_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        create_job::execute('existingcourse', 0, $course->id, 0, 'Test', 'ro', 0, '', true);
    }

    /**
     * Invalid parameters are rejected before any access check.
     */
    public function test_invalid_parameters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [
            'mode' => fn() => create_job::execute('other', 1, 0, 0, 'Test', 'ro', 0, '', true),
            'category' => fn() => create_job::execute('newcourse', 0, 0, 0, 'Test', 'ro', 0, '', true),
            'prompt' => fn() => create_job::execute('newcourse', 1, 0, 0, ' ', 'ro', 0, '', true),
            'brief' => fn() => create_job::execute('newcourse', 1, 0, 0, 'Test', 'ro', 0, 'not json', true),
            'blueprint' => fn() => save_blueprint::execute(1, 'not json', 1),
            'nodeid' => fn() => regenerate_node::execute(1, 'section one', ''),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("Invalid {$name} accepted");
            } catch (invalid_parameter_exception $e) {
                $this->assertInstanceOf(invalid_parameter_exception::class, $e, $name);
            }
        }
        $this->assertGreaterThan(0, create_job::execute('newcourse', 1, 0, 0, 'Test', 'ro', 0, '', true)['jobid']);
    }

    /**
     * Creating a job saves a draft with what the wizard sent, and queues nothing.
     */
    public function test_create_job_saves_a_draft(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $created = create_job::execute('newcourse', 1, 0, 0, '  Curs despre energie  ', 'ro', 0, '{"level": "avansat"}', false);

        $this->assertSame('draft', $created['status']);
        $job = $DB->get_record('local_aicb_job', ['id' => $created['jobid']], '*', MUST_EXIST);
        $this->assertSame('Curs despre energie', $job->prompt);
        $this->assertSame('{"level": "avansat"}', $job->brief);
        $this->assertEquals(0, $job->offpeak);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_aicoursebuilder\task\ingest_sources::class));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_aicoursebuilder\task\generate_blueprint::class));
    }

    /**
     * The status of a job is what the job holds, with the steps that ran and the latest blueprint version.
     */
    public function test_get_job_status_reports_the_job(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($teacher->id, $course->id);
        $DB->update_record('local_aicb_job', (object) [
            'id' => $jobid,
            'status' => 'generating',
            'stage' => 'generate',
            'progress' => 40,
            'statusmessage' => 'In lucru',
            'actualcost' => 0.05,
            'error' => 'oops',
        ]);
        $reason = '{"type":"repair_exhausted","httpcode":0,"errors":[{"path":"/activities/0","code":"schema"}],'
            . '"errorcount":1,"repairs":2,"attempts":3}';
        $rows = [
            ['outline', '', 'done', null],
            ['sections', 's2', 'running', null],
            ['sections', 's3', 'manual', $reason],
            ['brief', '', 'error', 'Budget reached'],
        ];
        foreach ($rows as [$step, $nodekey, $state, $error]) {
            $DB->insert_record('local_aicb_step', (object) [
                'jobid' => $jobid,
                'step' => $step,
                'nodekey' => $nodekey === '' ? null : $nodekey,
                'inputhash' => hash('sha256', $step . $nodekey),
                'status' => $state,
                'error' => $error,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $jobid,
            'version' => 2,
            'content' => '{}',
            'contenthash' => self::HASH,
            'usermodified' => $teacher->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->setUser($teacher);

        $status = external_api::clean_returnvalue(get_job_status::execute_returns(), get_job_status::execute($jobid));

        $this->assertSame('generating', $status['status']);
        $this->assertSame('generate', $status['stage']);
        $this->assertSame(40, $status['progress']);
        $this->assertSame('In lucru', $status['message']);
        $this->assertSame((int) $course->id, $status['courseid']);
        $this->assertSame(2, $status['blueprintversion']);
        $this->assertEqualsWithDelta(0.05, $status['actualcost'], 0.000001);
        $this->assertSame('oops', $status['error']);
        $this->assertSame([
            ['step' => 'outline', 'nodekey' => '', 'status' => 'done', 'reason' => ''],
            ['step' => 'sections', 'nodekey' => 's2', 'status' => 'running', 'reason' => ''],
            ['step' => 'sections', 'nodekey' => 's3', 'status' => 'manual', 'reason' => $reason],
            ['step' => 'brief', 'nodekey' => '', 'status' => 'error', 'reason' => ''],
        ], $status['steps'], 'only a structured reason is exposed, a plain message is not');
    }

    /**
     * The estimate answers with the shape the wizard needs.
     */
    public function test_estimate_cost_returns_an_estimate(): void {
        $this->resetAfterTest();
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        set_config('joblimitusd', '5', 'local_aicoursebuilder');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($teacher->id, $course->id);
        $this->setUser($teacher);

        $estimate = external_api::clean_returnvalue(estimate_cost::execute_returns(), estimate_cost::execute($jobid));

        $this->assertGreaterThan(0, $estimate['estimatedcost']);
        $this->assertSame('USD', $estimate['currency']);
        $this->assertGreaterThan(0, $estimate['tokensin']);
        $this->assertEquals(5, $estimate['joblimit']);
        $this->assertTrue($estimate['withinbudget']);
    }

    /**
     * Starting a job queues its task, once the owner has accepted the AI policy.
     */
    public function test_start_job_queues_the_task(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($teacher->id, $course->id);
        $DB->set_field('local_aicb_job', 'status', 'draft', ['id' => $jobid]);
        $this->setUser($teacher);

        try {
            start_job::execute($jobid);
            $this->fail('A job was started without the AI policy');
        } catch (\moodle_exception $e) {
            $this->assertSame('aipolicynotaccepted', $e->errorcode);
        }

        \core_ai\manager::user_policy_accepted((int) $teacher->id, \context_system::instance()->id);
        $started = start_job::execute($jobid);

        $this->assertSame(['jobid' => $jobid, 'status' => 'queued'], $started);
        $this->assertSame('queued', $DB->get_field('local_aicb_job', 'status', ['id' => $jobid]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_aicoursebuilder\task\generate_blueprint::class));
    }

    /**
     * The owner resumes a job that a cost limit paused, and its task is queued; a job that is not paused is refused.
     */
    public function test_resume_job_queues_the_task_of_a_paused_job(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($teacher->id, $course->id);
        $this->setUser($teacher);
        $this->assert_refused('jobnotpaused', fn() => resume_job::execute($jobid));

        $DB->update_record('local_aicb_job', (object) [
            'id' => $jobid, 'status' => 'paused', 'stage' => 'generate', 'error' => 'The cost limit was reached.',
        ]);
        $this->assert_refused('aipolicynotaccepted', fn() => resume_job::execute($jobid));

        \core_ai\manager::user_policy_accepted((int) $teacher->id, \context_system::instance()->id);
        $resumed = resume_job::execute($jobid);

        $this->assertSame(['jobid' => $jobid, 'status' => 'queued'], $resumed);
        $this->assertSame('queued', $DB->get_field('local_aicb_job', 'status', ['id' => $jobid]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_aicoursebuilder\task\generate_blueprint::class));
    }

    /**
     * A job whose cost limit is still used up stays paused.
     */
    public function test_resume_job_refuses_while_a_limit_is_used_up(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
        set_config('joblimitusd', '2', 'local_aicoursebuilder');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($teacher->id, $course->id);
        $DB->update_record('local_aicb_job', (object) [
            'id' => $jobid, 'status' => 'paused', 'stage' => 'generate', 'actualcost' => 2.0,
        ]);
        \core_ai\manager::user_policy_accepted((int) $teacher->id, \context_system::instance()->id);
        $this->setUser($teacher);

        $this->assert_refused('resumestillover', fn() => resume_job::execute($jobid));

        $this->assertSame('paused', $DB->get_field('local_aicb_job', 'status', ['id' => $jobid]));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_aicoursebuilder\task\generate_blueprint::class));
    }

    /**
     * The teacher reads the blueprint, edits it, saves it and approves it through the web services.
     */
    public function test_blueprint_round_trip_through_the_web_services(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $jobid = $this->create_job_record($teacher->id, $course->id);
        $DB->set_field('local_aicb_job', 'status', 'review', ['id' => $jobid]);
        $golden = file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json');
        $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $jobid,
            'version' => 1,
            'content' => $golden,
            'contenthash' => hash('sha256', $golden),
            'usermodified' => $teacher->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->setUser($teacher);

        $read = external_api::clean_returnvalue(get_blueprint::execute_returns(), get_blueprint::execute($jobid, 0));
        $this->assertSame(1, $read['version']);
        $this->assertSame('draft', $read['status']);
        $this->assertSame(hash('sha256', $golden), $read['contenthash']);

        $edited = json_decode($read['blueprint'], true);
        $edited['sections'][0]['title'] = 'Titlu nou';
        $saved = external_api::clean_returnvalue(
            save_blueprint::execute_returns(),
            save_blueprint::execute($jobid, json_encode($edited), 1)
        );
        $this->assertSame(2, $saved['version']);
        $this->assertTrue($saved['valid']);
        $this->assertSame([], $saved['errors']);

        $edited['sections'] = [];
        $broken = external_api::clean_returnvalue(
            save_blueprint::execute_returns(),
            save_blueprint::execute($jobid, json_encode($edited), 2)
        );
        $this->assertSame(3, $broken['version']);
        $this->assertFalse($broken['valid']);
        $this->assertNotEmpty($broken['errors'][0]['message']);

        $this->assert_refused(
            'blueprintnotvalid',
            fn() => approve_blueprint::execute($jobid, 3, $broken['contenthash'])
        );

        $fixed = json_decode($read['blueprint'], true);
        $fixed['sections'][0]['title'] = 'Titlu final';
        $final = save_blueprint::execute($jobid, json_encode($fixed), 3);
        $approved = external_api::clean_returnvalue(
            approve_blueprint::execute_returns(),
            approve_blueprint::execute($jobid, $final['version'], $final['contenthash'])
        );
        $this->assertSame(4, $approved['version']);
        $this->assertSame('approved', $approved['status']);
        $this->assertSame('approved', get_blueprint::execute($jobid, 4)['status']);
        $this->assertSame('approved', $DB->get_field('local_aicb_job', 'status', ['id' => $jobid]));
    }
}
