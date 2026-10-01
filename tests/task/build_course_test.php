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

namespace local_aicoursebuilder\task;

use local_aicoursebuilder\ai\fake_lock_factory;
use local_aicoursebuilder\builder\build_plan;
use local_aicoursebuilder\builder\build_result;
use local_aicoursebuilder\builder\builder_registry;
use local_aicoursebuilder\builder\recording_builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/recording_builder.php');

/**
 * Tests for the build_course task: order, checkpoints, idempotence, isolation of failures and the lock.
 *
 * The builders are fakes registered by the test, because no real builder exists yet: what is tested here is
 * the orchestration, not what any single builder writes into Moodle.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\task\build_course
 * @covers     \local_aicoursebuilder\builder\build_plan
 * @covers     \local_aicoursebuilder\builder\build_step
 * @covers     \local_aicoursebuilder\builder\builder_registry
 * @covers     \local_aicoursebuilder\builder\availability_target
 */
final class build_course_test extends \advanced_testcase {
    /** @var \stdClass Owner of the job. */
    private \stdClass $user;

    /** @var int Job id. */
    private int $jobid;

    /** @var recording_builder The builder registered for every node type. */
    private recording_builder $builder;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'mode' => 'newcourse',
            'status' => 'approved',
            'prompt' => 'Test',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->builder = new recording_builder();
    }

    /**
     * Returns the blueprint the tests build: two sections, one with a subsection, with availability.
     *
     * @return array
     */
    private function blueprint(): array {
        return [
            'version' => '1.0',
            'course' => [
                'fullname' => 'Test course',
                'shortname' => 'TC1',
                'summary' => 'Summary',
                'format' => 'topics',
                'enablecompletion' => true,
                'completion_activities' => ['s1.quiz1', 's2.page1'],
            ],
            'sections' => [
                [
                    'id' => 's1',
                    'title' => 'First',
                    'activities' => [
                        ['id' => 's1.page1', 'type' => 'page', 'name' => 'Page one'],
                        ['id' => 's1.quiz1', 'type' => 'quiz', 'name' => 'Quiz one'],
                    ],
                    'subsections' => [
                        [
                            'id' => 's1-1',
                            'title' => 'Sub',
                            'activities' => [
                                ['id' => 's1-1.label1', 'type' => 'label', 'name' => 'Label one'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 's2',
                    'title' => 'Second',
                    'availability' => ['require_completion_of' => ['s1.quiz1']],
                    'activities' => [
                        [
                            'id' => 's2.page1',
                            'type' => 'page',
                            'name' => 'Page two',
                            'availability' => ['min_grade' => ['activity' => 's1.quiz1', 'min' => 50]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Saves a blueprint version of the job and points the job at it.
     *
     * @param array|null $blueprint The blueprint, null for the default one.
     * @param string $status approved or draft.
     * @param int|null $jobid Owner job, null for the job of the test.
     * @return int Blueprint version id.
     */
    private function add_blueprint(?array $blueprint = null, string $status = 'approved', ?int $jobid = null): int {
        global $DB;

        $content = json_encode($blueprint ?? $this->blueprint(), JSON_THROW_ON_ERROR);
        $blueprintid = $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $jobid ?? $this->jobid,
            'version' => 1,
            'schemaversion' => '1.0',
            'content' => $content,
            'contenthash' => hash('sha256', $content),
            'status' => $status,
            'usermodified' => $this->user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field('local_aicb_job', 'blueprintid', $blueprintid, ['id' => $this->jobid]);
        return $blueprintid;
    }

    /**
     * Registers the recording builder for every node type of the blueprint.
     *
     * @param string[] $skip Types to leave without a builder.
     * @return builder_registry
     */
    private function registry(array $skip = []): builder_registry {
        $registry = new builder_registry();
        foreach (builder_registry::all_types() as $type) {
            if (!in_array($type, $skip, true)) {
                $registry->register($type, $this->builder);
            }
        }
        return $registry;
    }

    /**
     * Runs the task over the job of the test.
     *
     * @param builder_registry|null $registry Builders, null for one holding the recording builder.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory, null for a fake one.
     */
    private function run_task(?builder_registry $registry = null, ?\core\lock\lock_factory $lockfactory = null): void {
        $task = new build_course($registry ?? $this->registry(), $lockfactory ?? new fake_lock_factory());
        $task->set_custom_data(['jobid' => $this->jobid]);
        $task->set_userid($this->user->id);
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Returns the job row as it is now.
     *
     * @return \stdClass
     */
    private function job(): \stdClass {
        global $DB;
        return $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
    }

    /**
     * Returns the build map of the job.
     *
     * @return array
     */
    private function buildmap(): array {
        return json_decode($this->job()->buildmap ?? '{}', true) ?? [];
    }

    /**
     * The nodes are built in topological order: course, then each section with its modules and subsections.
     */
    public function test_topological_order(): void {
        $this->add_blueprint();
        $this->run_task();

        $this->assertSame([
            build_plan::COURSE_NODEID,
            's1',
            's1.page1',
            's1.quiz1',
            's1-1',
            's1-1.label1',
            's2',
            's2.page1',
        ], $this->builder->calls, 'Course first, then every section before the modules it holds');

        $job = $this->job();
        $this->assertSame(build_course::STATUS_FINISHED, $job->status);
        $this->assertSame(build_course::STAGE, $job->stage);
        $this->assertSame(100, (int) $job->progress);
    }

    /**
     * The owner is enrolled as editing teacher right after the course node, before any other builder.
     */
    public function test_creator_is_enrolled_before_the_other_builders(): void {
        global $DB;
        $this->add_blueprint();
        $this->builder->oncall = function (string $nodeid): void {
            global $DB;
            // Record whether the owner was already enrolled when each node was built.
            $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid]);
            $this->builder->notes[$nodeid] = $job->courseid
                && is_enrolled(\context_course::instance((int) $job->courseid), $this->user->id);
        };
        $this->run_task();

        $courseid = (int) $this->job()->courseid;
        $this->assertGreaterThan(0, $courseid);
        $context = \context_course::instance($courseid);
        $this->assertTrue(is_enrolled($context, $this->user->id), 'The owner is enrolled in the new course');

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->assertTrue(user_has_role_assignment($this->user->id, $roleid, $context->id));

        // The course node itself ran before the enrolment, every later node after it.
        $this->assertFalse($this->builder->notes[build_plan::COURSE_NODEID]);
        foreach (['s1', 's1.page1', 's1.quiz1', 's1-1', 's1-1.label1', 's2', 's2.page1'] as $nodeid) {
            $this->assertTrue($this->builder->notes[$nodeid], "{$nodeid} was built after the enrolment");
        }
    }

    /**
     * A run interrupted half way resumes: the nodes already built are skipped and their builders not called.
     */
    public function test_resume_skips_built_nodes(): void {
        global $DB;
        $this->add_blueprint();
        $this->builder->throwon = 's1-1';
        $this->run_task();

        // The build went on past the exception, so everything except the failed node and its child is built.
        $this->assertSame(
            [build_plan::COURSE_NODEID, 's1', 's1.page1', 's1.quiz1', 's1-1', 's2', 's2.page1'],
            $this->builder->calls
        );
        $map = $this->buildmap();
        $this->assertSame(build_result::STATUS_FAILED, $map['s1-1']['status']);
        $this->assertSame(build_result::STATUS_FAILED, $map['s1-1.label1']['status'], 'Child of a failed section');

        // Second run: the builder works now, so only the two nodes that failed are built. The job is
        // reopened first, the way the retry of a finished build with warnings does.
        $DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $this->jobid]);
        $resumed = new recording_builder();
        $this->builder = $resumed;
        $this->run_task();

        $this->assertSame(['s1-1', 's1-1.label1'], $resumed->calls, 'Built nodes are not built again');
        $map = $this->buildmap();
        foreach (['s1', 's1.page1', 's1.quiz1', 's1-1', 's1-1.label1', 's2', 's2.page1'] as $nodeid) {
            $this->assertSame(build_result::STATUS_CREATED, $map[$nodeid]['status'], $nodeid);
        }
        $this->assertSame(build_course::STATUS_FINISHED, $this->job()->status);
    }

    /**
     * A second run of a finished build calls no builder at all.
     */
    public function test_rerun_builds_nothing(): void {
        global $DB;
        $this->add_blueprint();
        $this->run_task();
        $before = $this->buildmap();

        // The job is finished, so it is reopened the way a retry would, to prove the checkpoint does the work.
        $DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $this->jobid]);
        $second = new recording_builder();
        $this->builder = $second;
        $this->run_task();

        $this->assertSame([], $second->calls, 'Nothing is built twice');
        $this->assertSame($before, $this->buildmap(), 'The build map does not change');
    }

    /**
     * A blueprint version that is not approved is refused and nothing is built.
     */
    public function test_unapproved_version_is_refused(): void {
        $this->add_blueprint(status: 'draft');
        $this->run_task();

        $this->assertSame([], $this->builder->calls);
        $job = $this->job();
        $this->assertSame(build_course::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('not approved', $job->error);
        $this->assertEmpty($job->courseid);
    }

    /**
     * A job with no blueprint at all, and a version belonging to another job, are both refused.
     */
    public function test_missing_and_foreign_blueprints_are_refused(): void {
        global $DB;
        $this->run_task();
        $this->assertSame(build_course::STATUS_FAILED, $this->job()->status);
        $this->assertSame([], $this->builder->calls);

        // A version of another job would build the wrong course.
        $otherjob = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'prompt' => 'Other',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $this->jobid]);
        $this->add_blueprint(jobid: $otherjob);
        $this->run_task();

        $this->assertSame([], $this->builder->calls);
        $this->assertSame(build_course::STATUS_FAILED, $this->job()->status);
    }

    /**
     * A node type with no registered builder is marked manual, with a warning, and the build goes on.
     */
    public function test_type_without_builder_is_manual(): void {
        $this->add_blueprint();
        $this->run_task($this->registry(skip: ['quiz']));

        $this->assertNotContains('s1.quiz1', $this->builder->calls, 'No builder was called for the quiz');
        $map = $this->buildmap();
        $this->assertSame(build_result::STATUS_MANUAL, $map['s1.quiz1']['status']);
        $this->assertNull($map['s1.quiz1']['cmid']);

        // Everything else was still built, and the job finished with warnings.
        $this->assertSame(build_result::STATUS_CREATED, $map['s2.page1']['status']);
        $job = $this->job();
        $this->assertSame(build_course::STATUS_FINISHED, $job->status);
        $this->assertStringContainsString('to check', $job->statusmessage);
    }

    /**
     * One failed activity is recorded with its error; the independent nodes are built anyway.
     */
    public function test_failed_node_is_isolated(): void {
        $this->add_blueprint();
        $this->builder->throwon = 's1.page1';
        $this->run_task();

        $map = $this->buildmap();
        $this->assertSame(build_result::STATUS_FAILED, $map['s1.page1']['status']);
        foreach ([build_plan::COURSE_NODEID, 's1', 's1.quiz1', 's1-1', 's1-1.label1', 's2', 's2.page1'] as $nodeid) {
            $this->assertSame(build_result::STATUS_CREATED, $map[$nodeid]['status'], $nodeid);
        }
        // The course exists, so the job finished rather than failed.
        $this->assertSame(build_course::STATUS_FINISHED, $this->job()->status);
    }

    /**
     * The course node failing ends the job: there is nowhere to build the rest.
     */
    public function test_failed_course_fails_the_job(): void {
        $this->add_blueprint();
        $this->builder->throwon = build_plan::COURSE_NODEID;
        $this->run_task();

        $this->assertSame([build_plan::COURSE_NODEID], $this->builder->calls, 'Nothing is built after the course');
        $job = $this->job();
        $this->assertSame(build_course::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('boom', $job->error);
        $this->assertEmpty($job->courseid);
    }

    /**
     * An availability rule over a node that failed is skipped with a warning, not applied half way.
     */
    public function test_availability_over_a_failed_node_is_skipped(): void {
        $this->add_blueprint();
        // Both rules of the blueprint depend on s1.quiz1.
        $this->builder->throwon = 's1.quiz1';
        $this->run_task();

        $job = $this->job();
        $this->assertSame(build_course::STATUS_FINISHED, $job->status, 'A skipped rule is a warning, not a failure');
        $this->assertStringContainsString('to check', $job->statusmessage);
        $map = $this->buildmap();
        $this->assertSame(build_result::STATUS_FAILED, $map['s1.quiz1']['status']);
        // The node the rule restricts was built: only the restriction was left off.
        $this->assertSame(build_result::STATUS_CREATED, $map['s2']['status']);
        $this->assertSame(build_result::STATUS_CREATED, $map['s2.page1']['status']);
    }

    /**
     * In an existing course only the activities are built: no course and no sections.
     */
    public function test_existing_course_builds_only_activities(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $DB->update_record('local_aicb_job', (object) [
            'id' => $this->jobid,
            'mode' => 'existingcourse',
            'courseid' => $course->id,
            'sectionnum' => 1,
        ]);
        $this->add_blueprint();
        $this->run_task();

        $this->assertSame(
            ['s1.page1', 's1.quiz1', 's1-1.label1', 's2.page1'],
            $this->builder->calls,
            'No course node and no section nodes'
        );
        $this->assertSame((int) $course->id, (int) $this->job()->courseid, 'The chosen course is not replaced');
        $this->assertSame(build_course::STATUS_FINISHED, $this->job()->status);
        $this->assertArrayNotHasKey(build_plan::COURSE_NODEID, $this->buildmap());
    }

    /**
     * A job whose course is being built by another runner is left alone.
     */
    public function test_lock_held_by_another_runner(): void {
        $this->add_blueprint();
        $lockfactory = new fake_lock_factory();
        $lockfactory->hold_externally('job_' . $this->jobid);

        $this->run_task(lockfactory: $lockfactory);

        $this->assertSame([], $this->builder->calls, 'The second runner builds nothing');
        $job = $this->job();
        $this->assertSame('approved', $job->status, 'The job is left exactly as the other runner has it');
        $this->assertEmpty($job->buildmap);

        // Once the other runner is done, the lock is free and the build runs normally.
        $lockfactory->release_externally('job_' . $this->jobid);
        $this->run_task(lockfactory: $lockfactory);
        $this->assertNotEmpty($this->builder->calls);
        $this->assertSame(build_course::STATUS_FINISHED, $this->job()->status);
    }

    /**
     * queue() queues the task for the owner of the job, and refuses a job that is already over.
     */
    public function test_queue(): void {
        global $DB;
        $this->add_blueprint();

        $this->assertTrue(build_course::queue($this->jobid));
        $queued = $DB->get_records('task_adhoc', ['classname' => '\\' . build_course::class]);
        $this->assertCount(1, $queued);
        $task = reset($queued);
        $this->assertSame((int) $this->user->id, (int) $task->userid, 'The task runs as the owner of the job');
        $this->assertSame($this->jobid, (int) json_decode($task->customdata)->jobid);

        $this->assertFalse(build_course::queue($this->jobid + 1000), 'An unknown job is not queued');
        $DB->set_field('local_aicb_job', 'status', 'cancelled', ['id' => $this->jobid]);
        $this->assertFalse(build_course::queue($this->jobid), 'A cancelled job is not queued');
    }

    /**
     * A new course job whose course node builds nothing fails before the other builders run.
     */
    public function test_course_builder_without_a_course_fails_the_job(): void {
        $this->add_blueprint();
        // A course builder that reports success but no course id: there is nothing to build into.
        $registry = $this->registry(skip: ['course']);
        $registry->register('course', new class implements \local_aicoursebuilder\builder\builder_interface {
            /**
             * Reports a course that does not exist.
             *
             * @param array|\stdClass $node The blueprint node.
             * @param \local_aicoursebuilder\builder\build_context $context The build context.
             * @return build_result
             */
            #[\Override]
            public function build(
                array|\stdClass $node,
                \local_aicoursebuilder\builder\build_context $context,
            ): build_result {
                return new build_result(build_plan::COURSE_NODEID, build_result::STATUS_CREATED);
            }
        });
        $this->run_task($registry);

        $this->assertSame([], $this->builder->calls, 'No other builder ran');
        $this->assertSame(build_course::STATUS_FAILED, $this->job()->status);
    }

    /**
     * A cancelled job is not built, even if the task was already queued.
     */
    public function test_cancelled_job_is_not_built(): void {
        global $DB;
        $this->add_blueprint();
        $DB->set_field('local_aicb_job', 'status', 'cancelled', ['id' => $this->jobid]);

        $this->run_task();

        $this->assertSame([], $this->builder->calls);
        $this->assertSame('cancelled', $this->job()->status);
    }
}
