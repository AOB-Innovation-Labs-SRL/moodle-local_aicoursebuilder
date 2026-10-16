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

namespace local_aicoursebuilder;

use local_aicoursebuilder\task\build_course;
use local_aicoursebuilder\task\generate_blueprint;

/**
 * Tests of retrying a failed job and of rolling it back, in a new course and in an existing one.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\job_recovery
 */
final class job_recovery_test extends \advanced_testcase {
    /** @var \stdClass Owner of the jobs. */
    private \stdClass $user;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
    }

    /**
     * Inserts a failed job.
     *
     * @param array $fields Fields that differ from a failed new-course job at the build.
     * @return \stdClass The row.
     */
    private function failed_job(array $fields = []): \stdClass {
        global $DB;
        $id = $DB->insert_record('local_aicb_job', (object) ($fields + [
            'userid' => $this->user->id,
            'mode' => 'newcourse',
            'status' => 'failed',
            'stage' => build_course::STAGE,
            'prompt' => 'Test',
            'error' => 'It broke.',
            'timecreated' => time(),
            'timemodified' => time(),
        ]));
        return $DB->get_record('local_aicb_job', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Returns a build map entry for a module.
     *
     * @param \stdClass $module The module created by the generator.
     * @return array
     */
    private function entry(\stdClass $module): array {
        return ['cmid' => (int) $module->cmid, 'instanceid' => (int) $module->id, 'sectionnum' => 1, 'status' => 'created'];
    }

    /**
     * Rolling back a new-course job deletes its course and cancels the job.
     */
    public function test_rollback_deletes_the_course_of_a_new_course_job(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $job = $this->failed_job(['courseid' => $course->id, 'buildmap' => '{"course":{"cmid":null}}']);

        (new job_recovery())->rollback($job);

        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]));
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $row = $DB->get_record('local_aicb_job', ['id' => $job->id]);
        $this->assertSame('cancelled', $row->status);
        $this->assertNull($row->courseid);
        $this->assertNull($row->buildmap);
    }

    /**
     * Rolling back in an existing course deletes exactly the cmids of the build map and nothing else.
     */
    public function test_rollback_in_an_existing_course_deletes_only_the_build_map(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $built1 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $built2 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $untouched = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $foreign = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $map = [
            's1.p1' => $this->entry($built1),
            's1.p2' => $this->entry($built2),
            // A cmid of another course in the map is never deleted.
            's9.p9' => $this->entry($foreign),
            's1' => ['cmid' => null, 'instanceid' => null, 'sectionnum' => 1, 'status' => 'created'],
        ];
        $job = $this->failed_job([
            'mode' => 'existingcourse',
            'courseid' => $course->id,
            'buildmap' => json_encode($map),
        ]);

        (new job_recovery())->rollback($job);

        $this->assertFalse($DB->record_exists('course_modules', ['id' => $built1->cmid, 'deletioninprogress' => 0]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $built2->cmid, 'deletioninprogress' => 0]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $untouched->cmid, 'deletioninprogress' => 0]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $foreign->cmid, 'deletioninprogress' => 0]));
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $row = $DB->get_record('local_aicb_job', ['id' => $job->id]);
        $this->assertSame('cancelled', $row->status);
        $this->assertEquals($course->id, $row->courseid);
    }

    /**
     * A job that has not failed is neither rolled back nor retried, so its course is safe.
     */
    public function test_only_a_failed_job_is_recovered(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $job = $this->failed_job(['courseid' => $course->id, 'status' => 'building']);

        foreach (['rollback', 'retry'] as $method) {
            try {
                (new job_recovery())->{$method}($job);
                $this->fail("{$method} accepted a job that has not failed");
            } catch (\moodle_exception $e) {
                $this->assertSame('jobnotfailed', $e->errorcode);
            }
        }
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertSame('building', $DB->get_field('local_aicb_job', 'status', ['id' => $job->id]));
    }

    /**
     * A job that failed in the build is built again from its build map, which needs no AI.
     */
    public function test_retry_of_a_build_queues_the_build(): void {
        global $DB;
        $job = $this->failed_job();
        $version = $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $job->id,
            'version' => 1,
            'content' => '{}',
            'contenthash' => hash('sha256', '{}'),
            'status' => 'approved',
            'usermodified' => $this->user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field('local_aicb_job', 'blueprintid', $version, ['id' => $job->id]);
        $job->blueprintid = $version;

        (new job_recovery())->retry($job);

        $row = $DB->get_record('local_aicb_job', ['id' => $job->id]);
        $this->assertSame('approved', $row->status);
        $this->assertNull($row->error);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(build_course::class));
    }

    /**
     * A job that failed before the blueprint queues the generation again, once the policy is accepted.
     */
    public function test_retry_of_the_generation_queues_the_generation(): void {
        global $DB;
        $job = $this->failed_job(['stage' => generate_blueprint::STAGE]);

        try {
            (new job_recovery())->retry($job);
            $this->fail('Expected the policy error');
        } catch (\moodle_exception $e) {
            $this->assertSame('aipolicynotaccepted', $e->errorcode);
        }
        $this->assertSame('failed', $DB->get_field('local_aicb_job', 'status', ['id' => $job->id]));

        \core_ai\manager::user_policy_accepted((int) $this->user->id, \context_system::instance()->id);
        (new job_recovery())->retry($job);

        $this->assertSame('queued', $DB->get_field('local_aicb_job', 'status', ['id' => $job->id]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }
}
