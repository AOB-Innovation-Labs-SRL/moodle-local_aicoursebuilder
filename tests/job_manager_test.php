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

use local_aicoursebuilder\ai\budget_guard;
use local_aicoursebuilder\ingest\ingest_exception;
use local_aicoursebuilder\task\generate_blueprint;
use local_aicoursebuilder\task\ingest_sources;

/**
 * Tests of creating, estimating and starting jobs.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\job_manager
 */
final class job_manager_test extends \advanced_testcase {
    /** @var \stdClass Owner of the jobs. */
    private \stdClass $user;

    /** @var job_manager The manager under test. */
    private job_manager $manager;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        $this->manager = new job_manager();
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
    }

    /**
     * Returns the data of a job for a new course.
     *
     * @param array $override Fields to change.
     * @return array
     */
    private function data(array $override = []): array {
        return $override + [
            'mode' => job_manager::MODE_NEWCOURSE,
            'categoryid' => 1,
            'courseid' => 0,
            'sectionnum' => 0,
            'prompt' => 'Un curs despre energia regenerabilă.',
            'language' => 'ro',
            'brief' => '',
            'offpeak' => true,
        ];
    }

    /**
     * Puts files in a draft area of the current user.
     *
     * @param string[] $files Content of the files, indexed by file name.
     * @return int The draft item id.
     */
    private function draft(array $files): int {
        $draftitemid = file_get_unused_draft_itemid();
        $context = \context_user::instance($this->user->id);
        foreach ($files as $filename => $content) {
            get_file_storage()->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $content);
        }
        return $draftitemid;
    }

    /**
     * Creates a draft job and loads it.
     *
     * @param array $override Fields of the job to change.
     * @param int $draftitemid Draft area of the sources.
     * @return \stdClass The job row.
     */
    private function create(array $override = [], int $draftitemid = 0): \stdClass {
        global $DB;
        $id = $this->manager->create_draft((int) $this->user->id, $this->data($override), $draftitemid);
        return $DB->get_record('local_aicb_job', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Makes the owner accept the Moodle AI policy.
     */
    private function accept_policy(): void {
        \core_ai\manager::user_policy_accepted((int) $this->user->id, \context_system::instance()->id);
    }

    /**
     * A new course job is created as a draft with its category, and nothing is queued.
     */
    public function test_create_draft_for_a_new_course(): void {
        $job = $this->create(['brief' => '{"level": "începător"}', 'offpeak' => false]);

        $this->assertSame(job_manager::STATUS_DRAFT, $job->status);
        $this->assertEquals($this->user->id, $job->userid);
        $this->assertSame('newcourse', $job->mode);
        $this->assertEquals(1, $job->categoryid);
        $this->assertNull($job->courseid);
        $this->assertSame('ro', $job->language);
        $this->assertSame('{"level": "începător"}', $job->brief);
        $this->assertEquals(0, $job->offpeak);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(ingest_sources::class));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A job for an existing course keeps the course and the section, and no category.
     */
    public function test_create_draft_for_an_existing_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $job = $this->create(['mode' => 'existingcourse', 'courseid' => $course->id, 'sectionnum' => 2]);

        $this->assertSame('existingcourse', $job->mode);
        $this->assertEquals($course->id, $job->courseid);
        $this->assertEquals(2, $job->sectionnum);
        $this->assertNull($job->categoryid);
        $this->assertSame(\context_course::instance($course->id)->id, job_manager::get_context($job)->id);
    }

    /**
     * The files of the draft area become the sources of the job.
     */
    public function test_create_draft_saves_the_sources(): void {
        global $DB;

        $job = $this->create([], $this->draft(['a.txt' => 'Primul document.', 'b.md' => '# Al doilea']));

        $sources = $DB->get_records('local_aicb_source', ['jobid' => $job->id], 'filename');
        $this->assertCount(2, $sources);
        $this->assertSame(['a.txt', 'b.md'], array_column(array_values($sources), 'filename'));
        foreach ($sources as $source) {
            $this->assertSame('pending', $source->status);
            $this->assertNotNull((new ingest\source_manager())->get_source_file((int) $source->id));
        }
    }

    /**
     * An empty draft area, as an untouched filemanager leaves, is a job without sources.
     */
    public function test_create_draft_with_an_empty_draft_area(): void {
        global $DB;

        $job = $this->create([], file_get_unused_draft_itemid());

        $this->assertSame(job_manager::STATUS_DRAFT, $job->status);
        $this->assertSame(0, $DB->count_records('local_aicb_source'));
    }

    /**
     * A file that is refused leaves no job behind.
     */
    public function test_refused_source_leaves_no_job(): void {
        global $DB;

        try {
            $this->create([], $this->draft(['malware.exe' => 'x']));
            $this->fail('A file of a type that is not allowed was accepted');
        } catch (ingest_exception $e) {
            $this->assertSame(0, $DB->count_records('local_aicb_job'));
            $this->assertSame(0, $DB->count_records('local_aicb_source'));
        }
    }

    /**
     * The estimate is saved in the job and fits the limits when there are none.
     */
    public function test_estimate_is_saved_and_within_budget(): void {
        global $DB;
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');
        $job = $this->create();

        $estimate = $this->manager->estimate($job);

        $this->assertGreaterThan(0, $estimate['estimatedcost']);
        $this->assertTrue($estimate['withinbudget']);
        $this->assertEquals(0, $estimate['joblimit']);
        $this->assertEquals(-1, $estimate['userremaining']);
        $this->assertEquals($estimate['estimatedcost'], $DB->get_field('local_aicb_job', 'estimatedcost', ['id' => $job->id]));
    }

    /**
     * An estimate above the limit of the job is not within the budget.
     */
    public function test_estimate_over_the_job_limit(): void {
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        set_config('joblimitusd', '0.0001', 'local_aicoursebuilder');

        $estimate = $this->manager->estimate($this->create());

        $this->assertFalse($estimate['withinbudget']);
        $this->assertEquals(0.0001, $estimate['joblimit']);
    }

    /**
     * An estimate above what is left of the monthly budget of the user is not within the budget.
     */
    public function test_estimate_over_the_user_budget(): void {
        global $DB;
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '1', 'local_aicoursebuilder');
        $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $this->user->id,
            'period' => budget_guard::current_period(),
            'spentusd' => 0.9999,
            'reservedusd' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $estimate = $this->manager->estimate($this->create());

        $this->assertFalse($estimate['withinbudget']);
        $this->assertEqualsWithDelta(0.0001, $estimate['userremaining'], 0.000001);
    }

    /**
     * What is left of a budget: the limit less what is spent and reserved, never below zero, -1 without a limit.
     */
    public function test_remaining(): void {
        global $DB;
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');

        $this->assertEquals(10.0, $this->manager->remaining((int) $this->user->id));
        $this->assertEquals(-1.0, $this->manager->remaining(budget_guard::SITE_USERID));

        $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $this->user->id,
            'period' => budget_guard::current_period(),
            'limitusd' => 4,
            'spentusd' => 3,
            'reservedusd' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->assertEquals(0.0, $this->manager->remaining((int) $this->user->id), 'a limit override beats the setting');
    }

    /**
     * Starting a job with sources queues its ingestion, which queues the generation by itself.
     */
    public function test_start_with_sources_queues_the_ingestion(): void {
        global $DB;
        $this->accept_policy();
        $job = $this->create([], $this->draft(['a.txt' => 'Un document.']));

        $this->manager->start($job);

        $job = $DB->get_record('local_aicb_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_QUEUED, $job->status);
        $this->assertSame(ingest_sources::STAGE, $job->stage);
        $tasks = \core\task\manager::get_adhoc_tasks(ingest_sources::class);
        $this->assertCount(1, $tasks);
        $this->assertEquals($job->id, reset($tasks)->get_custom_data()->jobid);
        $this->assertEquals($this->user->id, reset($tasks)->get_userid());
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A job without sources has nothing to ingest, so it goes straight to the generation.
     */
    public function test_start_without_sources_queues_the_generation(): void {
        $this->accept_policy();

        $this->manager->start($this->create());

        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(ingest_sources::class));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A job cannot be started without the Moodle AI policy.
     */
    public function test_start_requires_the_ai_policy(): void {
        global $DB;
        $job = $this->create();

        try {
            $this->manager->start($job);
            $this->fail('A job was started without the AI policy');
        } catch (\moodle_exception $e) {
            $this->assertSame('aipolicynotaccepted', $e->errorcode);
        }
        $this->assertSame(job_manager::STATUS_DRAFT, $DB->get_field('local_aicb_job', 'status', ['id' => $job->id]));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A job whose estimate is over the limits is not started.
     */
    public function test_start_refuses_a_job_over_budget(): void {
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        set_config('joblimitusd', '0.0001', 'local_aicoursebuilder');
        $this->accept_policy();

        try {
            $this->manager->start($this->create());
            $this->fail('A job over budget was started');
        } catch (\moodle_exception $e) {
            $this->assertSame('startoverbudget', $e->errorcode);
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A job is started once.
     */
    public function test_start_twice_is_refused(): void {
        $this->accept_policy();
        $job = $this->create();
        $this->manager->start($job);
        $job->status = job_manager::STATUS_QUEUED;

        try {
            $this->manager->start($job);
            $this->fail('A job was started twice');
        } catch (\moodle_exception $e) {
            $this->assertSame('jobnotdraft', $e->errorcode);
        }
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * Makes a paused job, as a cost limit leaves it.
     *
     * @param string $stage The stage it stopped in.
     * @param float $actualcost What it had cost when it stopped.
     * @return \stdClass The job row.
     */
    private function paused_job(string $stage = generate_blueprint::STAGE, float $actualcost = 2.0): \stdClass {
        global $DB;
        $job = $this->create();
        $DB->update_record('local_aicb_job', (object) [
            'id' => $job->id,
            'status' => job_manager::STATUS_PAUSED,
            'stage' => $stage,
            'actualcost' => $actualcost,
            'error' => 'The cost limit was reached.',
        ]);
        return $DB->get_record('local_aicb_job', ['id' => $job->id], '*', MUST_EXIST);
    }

    /**
     * Pausing a job keeps its stage, writes the reason and the cost it had run up, and tells its owner.
     */
    public function test_pause_keeps_the_stage_and_tells_the_owner(): void {
        global $DB;
        $job = $this->create();
        $DB->update_record('local_aicb_job', (object) ['id' => $job->id, 'status' => 'generating', 'stage' => 'generate']);
        $job = $DB->get_record('local_aicb_job', ['id' => $job->id], '*', MUST_EXIST);
        $now = time();
        $DB->insert_record('local_aicb_step', (object) [
            'jobid' => $job->id, 'step' => 'brief', 'inputhash' => 'a', 'status' => 'done', 'cost' => 0.75,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $sink = $this->redirectMessages();

        $this->manager->pause($job, 'The limit of the job was reached.');

        $paused = $DB->get_record('local_aicb_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_PAUSED, $paused->status);
        $this->assertSame('generate', $paused->stage);
        $this->assertSame('The limit of the job was reached.', $paused->error);
        $this->assertEquals(0.75, $paused->actualcost);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('budgetexceeded', $messages[0]->eventtype);
        $this->assertEquals($this->user->id, $messages[0]->useridto);
    }

    /**
     * Only a paused job is resumed.
     */
    public function test_resume_refuses_a_job_that_is_not_paused(): void {
        $this->accept_policy();

        try {
            $this->manager->resume($this->create());
            $this->fail('A draft job was resumed');
        } catch (\moodle_exception $e) {
            $this->assertSame('jobnotpaused', $e->errorcode);
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A job is resumed only by an owner who has accepted the AI policy.
     */
    public function test_resume_requires_the_ai_policy(): void {
        try {
            $this->manager->resume($this->paused_job());
            $this->fail('A job was resumed without the AI policy');
        } catch (\moodle_exception $e) {
            $this->assertSame('aipolicynotaccepted', $e->errorcode);
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A job stopped in the generation is resumed in the generation, once, and is no longer paused.
     */
    public function test_resume_queues_the_generation(): void {
        global $DB;
        $this->accept_policy();
        $job = $this->paused_job(generate_blueprint::STAGE);

        $this->manager->resume($job);

        $resumed = $DB->get_record('local_aicb_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_QUEUED, $resumed->status);
        $this->assertSame(generate_blueprint::STAGE, $resumed->stage);
        $this->assertNull($resumed->error);
        $tasks = \core\task\manager::get_adhoc_tasks(generate_blueprint::class);
        $this->assertCount(1, $tasks);
        $this->assertEquals($job->id, reset($tasks)->get_custom_data()->jobid);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(ingest_sources::class));
    }

    /**
     * A job stopped in the ingestion is resumed in the ingestion.
     */
    public function test_resume_queues_the_ingestion(): void {
        $this->accept_policy();

        $this->manager->resume($this->paused_job(ingest_sources::STAGE));

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(ingest_sources::class));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * A limit that is used up keeps the job paused; one that has room, or none, lets it go.
     */
    public function test_has_room_to_resume(): void {
        global $DB;
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');
        $job = $this->paused_job(generate_blueprint::STAGE, 2.0);
        $this->assertTrue($this->manager->has_room_to_resume($job));

        set_config('joblimitusd', '2', 'local_aicoursebuilder');
        $this->assertFalse($this->manager->has_room_to_resume($job), 'the job limit is used up');
        set_config('joblimitusd', '2.5', 'local_aicoursebuilder');
        $this->assertTrue($this->manager->has_room_to_resume($job), 'the job limit has room');
        set_config('joblimitusd', '0', 'local_aicoursebuilder');

        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        $row = $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $this->user->id, 'period' => budget_guard::current_period(), 'spentusd' => 10,
            'reservedusd' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertFalse($this->manager->has_room_to_resume($job), 'the monthly limit of the user is used up');
        set_config('userlimitusd', '20', 'local_aicoursebuilder');
        $this->assertTrue($this->manager->has_room_to_resume($job), 'the monthly limit of the user was raised');
        $DB->set_field('local_aicb_budget', 'limitusd', 10, ['id' => $row]);
        $this->assertFalse($this->manager->has_room_to_resume($job), 'a limit of his own beats the setting');
        $DB->set_field('local_aicb_budget', 'limitusd', 15, ['id' => $row]);
        $this->assertTrue($this->manager->has_room_to_resume($job), 'a raised limit of his own');

        set_config('sitelimitusd', '5', 'local_aicoursebuilder');
        $DB->insert_record('local_aicb_budget', (object) [
            'userid' => budget_guard::SITE_USERID, 'period' => budget_guard::current_period(), 'spentusd' => 5,
            'reservedusd' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertFalse($this->manager->has_room_to_resume($job), 'the limit of the site is used up');
    }

    /**
     * A user who has used the alert percentage of the monthly limit is flagged by the estimate.
     */
    public function test_estimate_flags_a_user_at_the_alert_level(): void {
        global $DB;
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('alertpercent', '80', 'local_aicoursebuilder');
        $job = $this->create();
        $this->assertFalse($this->manager->estimate($job)['useralert'], 'nothing spent');

        $row = $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $this->user->id, 'period' => budget_guard::current_period(), 'spentusd' => 7,
            'reservedusd' => 0.5, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertFalse($this->manager->estimate($job)['useralert'], '75% used');

        $DB->set_field('local_aicb_budget', 'reservedusd', 1, ['id' => $row]);
        $this->assertTrue($this->manager->estimate($job)['useralert'], '80% used, the reserved part counts');

        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        $this->assertFalse($this->manager->estimate($job)['useralert'], 'no limit');
    }

    /**
     * Whoever holds the capability in the system, in a category or in a course may use the plugin; the others may not.
     */
    public function test_can_use(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $category = $generator->create_category();

        $manager = $generator->create_user();
        $generator->role_assign('manager', $manager->id, \context_system::instance()->id);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $categoryteacher = $generator->create_user();
        $generator->role_assign('editingteacher', $categoryteacher->id, \context_coursecat::instance($category->id)->id);
        $nobody = $generator->create_user();

        $this->assertTrue($this->manager->can_use((int) $manager->id), 'in the system');
        $this->assertTrue($this->manager->can_use((int) $teacher->id), 'in a course');
        $this->assertTrue($this->manager->can_use((int) $categoryteacher->id), 'in a category');
        $this->assertFalse($this->manager->can_use((int) $student->id), 'a student');
        $this->assertFalse($this->manager->can_use((int) $nobody->id), 'enrolled nowhere');
    }

    /**
     * The answer is kept for a while, so that it is not worked out again on every page.
     */
    public function test_can_use_is_remembered(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse($this->manager->can_use((int) $user->id));

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->assertFalse($this->manager->can_use((int) $user->id), 'the earlier answer is kept');

        \cache::make('local_aicoursebuilder', 'canuse')->purge();
        $this->assertTrue($this->manager->can_use((int) $user->id), 'a fresh answer');
    }
}
