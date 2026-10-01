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
 * @covers     \local_aicoursebuilder\external\get_job_status
 * @covers     \local_aicoursebuilder\external\get_blueprint
 * @covers     \local_aicoursebuilder\external\save_blueprint
 * @covers     \local_aicoursebuilder\external\approve_blueprint
 * @covers     \local_aicoursebuilder\external\estimate_cost
 * @covers     \local_aicoursebuilder\external\regenerate_node
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
            get_job_status::class => [
                'params' => ['jobid' => 7],
                'returns' => ['jobid' => 7, 'status' => 'generating', 'stage' => 'sections', 'progress' => 40,
                    'message' => 'Secțiunea 2 din 3', 'courseid' => 0, 'blueprintversion' => 0,
                    'estimatedcost' => 0.17, 'actualcost' => 0.05, 'error' => '', 'timemodified' => 1790000000,
                    'steps' => [
                        ['step' => 'outline', 'nodekey' => '', 'status' => 'done'],
                        ['step' => 'sections', 'nodekey' => 's2', 'status' => 'running'],
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
                    'tokensout' => 140000, 'withinbudget' => true, 'joblimit' => 1.5, 'userremaining' => 9.83],
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
     * Asserts that a call stops at the notimplemented exception, after the access checks.
     *
     * @param callable $call The call.
     */
    private function assert_not_implemented(callable $call): void {
        try {
            $call();
            $this->fail('Expected notimplemented');
        } catch (\moodle_exception $e) {
            $this->assertSame('notimplemented', $e->errorcode);
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
        $this->assert_not_implemented(fn() => get_job_status::execute($jobid));
        $this->assert_not_implemented(fn() => get_blueprint::execute($jobid, 0));
        $this->assert_not_implemented(fn() => save_blueprint::execute($jobid, '{"version": "1.0"}', 1));
        $this->assert_not_implemented(fn() => approve_blueprint::execute($jobid, 1, $hash));
        $this->assert_not_implemented(fn() => estimate_cost::execute($jobid));
        try {
            regenerate_node::execute($jobid, 's1.quiz1', '');
            $this->fail('Expected the missing blueprint error');
        } catch (\moodle_exception $e) {
            $this->assertSame('regenerationnoblueprint', $e->errorcode);
        }
        $this->assert_not_implemented(fn() => create_job::execute('existingcourse', 0, $course->id, 1, 'Test', 'ro', 0, '', true));
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
        $this->assert_not_implemented(fn() => get_job_status::execute($jobid));
        $this->assert_not_implemented(fn() => get_blueprint::execute($jobid, 0));
        $this->assert_not_implemented(fn() => estimate_cost::execute($jobid));

        // Changing is for the owner only.
        $hash = self::HASH;
        $writes = [
            'approve_blueprint' => fn() => approve_blueprint::execute($jobid, 1, $hash),
            'save_blueprint' => fn() => save_blueprint::execute($jobid, '{"version": "1.0"}', 1),
            'regenerate_node' => fn() => regenerate_node::execute($jobid, 's1.quiz1', ''),
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
        $this->assert_not_implemented(fn() => create_job::execute('newcourse', 1, 0, 0, 'Test', 'ro', 0, '', true));
    }
}
