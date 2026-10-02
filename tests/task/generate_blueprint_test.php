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

use local_aicoursebuilder\ai\connector_exception;
use local_aicoursebuilder\ai\fake_connector;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\blueprint\validation_error;
use local_aicoursebuilder\blueprint\validator;
use local_aicoursebuilder\ingest\source_manager;
use local_aicoursebuilder\job_manager;
use local_aicoursebuilder\pipeline\pipeline_outcome;

/**
 * Tests of the generate_blueprint task, on the fake connector.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\task\generate_blueprint
 */
final class generate_blueprint_test extends \advanced_testcase {
    /** @var \stdClass Owner of the job. */
    private \stdClass $user;

    /** @var int Job id. */
    private int $jobid;

    /** @var fake_connector The connector every route answers with. */
    private fake_connector $connector;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'mode' => 'newcourse',
            'categoryid' => 1,
            'status' => 'queued',
            'prompt' => 'Un curs introductiv despre energia regenerabilă.',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        set_config('defaultconnector', fake_connector::NAME, 'local_aicoursebuilder');
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');

        $this->connector = new fake_connector();
        router::set_test_connector($this->connector);
        $this->queue_sections();
    }

    #[\Override]
    protected function tearDown(): void {
        router::set_test_connector(null);
        parent::tearDown();
    }

    /**
     * Queues the per-section fixtures, which the single sections.json cannot give.
     *
     * @param string[] $renames Source ids of the fixtures and the ids they stand for in this test.
     */
    private function queue_sections(array $renames = []): void {
        foreach (['s1', 's1-1', 's2', 's3'] as $id) {
            $path = dirname(__DIR__) . '/fixtures/ai/sections/' . $id . '.json';
            $this->connector->push(request::STEP_SECTIONS, strtr(file_get_contents($path), $renames), $id);
            $path = dirname(__DIR__) . '/fixtures/ai/activities/' . $id . '.json';
            $this->connector->push(request::STEP_ACTIVITIES, strtr(file_get_contents($path), $renames), $id);
        }
        foreach (['s1', 's2', 's3'] as $id) {
            $path = dirname(__DIR__) . '/fixtures/ai/questions/' . $id . '.json';
            $this->connector->push(request::STEP_QUESTIONS, strtr(file_get_contents($path), $renames), $id);
        }
    }

    /**
     * Saves files as the sources of the job, with their extracted text and digest, as the ingestion leaves them.
     *
     * @param string[] $files Content of the files, indexed by file name.
     */
    private function add_digested_sources(array $files): void {
        global $DB;

        $manager = new source_manager();
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($this->user->id);
        foreach ($files as $filename => $content) {
            get_file_storage()->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $content);
        }
        foreach ($manager->save_from_draft($this->jobid, $draftitemid, \context_system::instance()) as $source) {
            $manager->save_extracted($source, $manager->get_source_file((int) $source->id), $files[$source->filename]);
            $source->status = ingest_sources::STATUS_DIGESTED;
            $source->digest = json_encode(['source' => 'src' . $source->id, 'title' => $source->filename, 'concepts' => []]);
            $DB->update_record('local_aicb_source', $source);
        }
    }

    /**
     * Saves the two sources the AI fixtures refer to as src1 and src2.
     *
     * The sources get their ids in the order of their file names, so the one with the URL the fixtures cite is first.
     */
    private function add_energy_sources(): void {
        $this->add_digested_sources([
            '1-energie.txt' => 'Energia regenerabilă provine din surse care se refac natural. '
                . 'Vezi https://ro.wikipedia.org/wiki/Energie_regenerabil%C4%83 pentru detalii.',
            '2-aplicatii.txt' => 'Aplicații practice ale surselor regenerabile.',
        ]);

        // The fixtures cite src1 and src2; the sources of this test got whatever ids the database gave them.
        global $DB;
        $ids = array_keys($DB->get_records('local_aicb_source', ['jobid' => $this->jobid], 'id'));
        $renames = ['"src1"' => '"src' . $ids[0] . '"', '"src2"' => '"src' . $ids[1] . '"'];
        $this->connector = new fake_connector();
        router::set_test_connector($this->connector);
        $this->queue_sections($renames);
        $this->connector->push(
            request::STEP_OUTLINE,
            strtr(file_get_contents(dirname(__DIR__) . '/fixtures/ai/outline.json'), $renames)
        );
    }

    /**
     * Runs the task of the job as cron does.
     */
    private function run_task(): void {
        \core\task\manager::queue_adhoc_task(generate_blueprint::instance($this->jobid, (int) $this->user->id));
        $this->runAdhocTasks(generate_blueprint::class);
    }

    /**
     * Loads the job again.
     *
     * @return \stdClass
     */
    private function job(): \stdClass {
        global $DB;
        return $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
    }

    /**
     * The task writes the blueprint of a job and leaves it in review.
     */
    public function test_generates_the_blueprint_and_puts_the_job_in_review(): void {
        global $DB;
        $this->add_energy_sources();
        $sink = $this->redirectMessages();

        $this->run_task();

        $job = $this->job();
        $this->assertSame(job_manager::STATUS_REVIEW, $job->status, (string) $job->error);
        $this->assertEquals(100, $job->progress);
        $this->assertNull($job->error);
        $this->assertNotNull($job->timefinished);

        $blueprints = $DB->get_records('local_aicb_blueprint', ['jobid' => $this->jobid]);
        $this->assertCount(1, $blueprints);
        $blueprint = reset($blueprints);
        $this->assertEquals(1, $blueprint->version);
        $this->assertSame('draft', $blueprint->status);
        $this->assertSame(hash('sha256', $blueprint->content), $blueprint->contenthash);
        $this->assertEquals($this->user->id, $blueprint->usermodified);

        $decoded = json_decode($blueprint->content, true);
        $this->assertSame('ro', $decoded['language']);
        $this->assertCount(3, $decoded['sections']);

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('jobfinished', $messages[0]->eventtype);
        $this->assertEquals($this->user->id, $messages[0]->useridto);
        $this->assertStringContainsString('review.php?id=' . $this->jobid, $messages[0]->fullmessage);
    }

    /**
     * The blueprint that comes out of the sources of the job passes the validator.
     */
    public function test_the_blueprint_is_valid(): void {
        global $DB;
        $this->add_energy_sources();
        $texts = [];
        foreach ($DB->get_records('local_aicb_source', ['jobid' => $this->jobid], 'id') as $source) {
            $texts['src' . $source->id] = (new source_manager())->get_extracted_file((int) $source->id)->get_content();
        }

        $this->run_task();

        $blueprint = json_decode($DB->get_field('local_aicb_blueprint', 'content', ['jobid' => $this->jobid]), true);
        $this->assertSame([], (new validator())->validate($blueprint, $texts));
    }

    /**
     * A source that was not digested is left out.
     */
    public function test_sources_without_a_digest_are_left_out(): void {
        global $DB;
        $this->add_digested_sources(['energie.txt' => 'Energia regenerabilă.']);
        $DB->set_field('local_aicb_source', 'status', 'failed', ['jobid' => $this->jobid]);

        $this->run_task();

        $request = $this->connector->requests(request::STEP_BRIEF)[0];
        $this->assertStringContainsString('There are no source documents', $request->system);
    }

    /**
     * What the teacher asked for in the wizard goes to the model with the request.
     */
    public function test_the_wizard_brief_goes_into_the_request(): void {
        global $DB;
        $DB->set_field('local_aicb_job', 'brief', json_encode([
            'audience' => 'Operatori de teren',
            'level' => 'avansat',
            'duration_minutes' => 120,
            'tone' => 'formal',
        ]), ['id' => $this->jobid]);

        $this->run_task();

        $system = $this->connector->requests(request::STEP_BRIEF)[0]->system;
        $this->assertStringContainsString('Un curs introductiv despre energia regenerabilă.', $system);
        $this->assertStringContainsString('Audience: Operatori de teren', $system);
        $this->assertStringContainsString('Level: avansat', $system);
        $this->assertStringContainsString('Duration in minutes: 120', $system);
        $this->assertStringContainsString('Tone: formal', $system);
    }

    /**
     * A brief that is not an object, or has empty fields, adds nothing to the request.
     */
    public function test_an_unusable_brief_adds_nothing(): void {
        global $DB;
        $DB->set_field('local_aicb_job', 'brief', '{"audience": " ", "level": ["x"]}', ['id' => $this->jobid]);

        $this->run_task();

        $system = $this->connector->requests(request::STEP_BRIEF)[0]->system;
        $this->assertStringNotContainsString('The teacher also asked for', $system);
    }

    /**
     * A job whose brief cannot be written fails with the reason, and the teacher is told.
     */
    public function test_a_failed_pipeline_fails_the_job(): void {
        $this->connector->push(request::STEP_BRIEF, new connector_exception(connector_exception::NETWORK_ERROR));
        $sink = $this->redirectMessages();

        $this->run_task();

        $job = $this->job();
        $this->assertSame(job_manager::STATUS_FAILED, $job->status);
        $this->assertNotEmpty($job->error);
        $this->assertNotNull($job->timefinished);
        $this->assertDebuggingNotCalled();

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('jobfailed', $messages[0]->eventtype);
    }

    /**
     * A cost limit stops the job, and what it finished is kept so that starting it again resumes from there.
     */
    public function test_a_cost_limit_fails_the_job_and_a_new_run_resumes(): void {
        global $DB;
        $this->add_energy_sources();
        $this->connector->set_cost_per_call(1.0);
        set_config('joblimitusd', '2.5', 'local_aicoursebuilder');

        $this->run_task();

        $job = $this->job();
        $this->assertSame(job_manager::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('2.5', $job->error);
        $this->assertSame(0, $DB->count_records('local_aicb_blueprint', ['jobid' => $this->jobid]));
        $paid = $this->connector->call_count(request::STEP_BRIEF) + $this->connector->call_count(request::STEP_OUTLINE);

        // The limit is raised and the job started again: the brief and the outline are not paid for twice.
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        $DB->set_field('local_aicb_job', 'status', 'queued', ['id' => $this->jobid]);
        $this->connector->reset_counts();
        $this->run_task();

        $this->assertSame(job_manager::STATUS_REVIEW, $this->job()->status);
        $again = $this->connector->call_count(request::STEP_BRIEF) + $this->connector->call_count(request::STEP_OUTLINE);
        $this->assertLessThan($paid, $again);
    }

    /**
     * A job that is no longer waiting for the task is left alone.
     *
     * @dataProvider finished_statuses
     * @param string $status The status of the job.
     */
    public function test_it_ignores_a_job_that_is_not_waiting(string $status): void {
        global $DB;
        $DB->set_field('local_aicb_job', 'status', $status, ['id' => $this->jobid]);

        $this->run_task();

        $this->assertSame($status, $this->job()->status);
        $this->assertSame(0, $this->connector->call_count());
        $this->assertSame(0, $DB->count_records('local_aicb_blueprint'));
    }

    /**
     * Statuses in which the task does nothing.
     *
     * @return array
     */
    public static function finished_statuses(): array {
        return [
            'draft' => ['draft'],
            'review' => ['review'],
            'failed' => ['failed'],
            'cancelled' => ['cancelled'],
            'finished' => ['finished'],
        ];
    }

    /**
     * A task for a job that is gone does nothing.
     */
    public function test_it_ignores_a_job_that_does_not_exist(): void {
        \core\task\manager::queue_adhoc_task(generate_blueprint::instance(999999, (int) $this->user->id));
        $this->runAdhocTasks(generate_blueprint::class);

        $this->assertSame(0, $this->connector->call_count());
    }

    /**
     * A job that stopped halfway, left generating, is taken up again.
     */
    public function test_it_resumes_a_job_left_generating(): void {
        global $DB;
        $this->add_energy_sources();
        $DB->set_field('local_aicb_job', 'status', generate_blueprint::STATUS_GENERATING, ['id' => $this->jobid]);

        $this->run_task();

        $this->assertSame(job_manager::STATUS_REVIEW, $this->job()->status);
    }

    /**
     * A blueprint the validator still finds fault with is kept as a draft and the job goes to review, flagged.
     *
     * The orchestrator saves only blueprints that validate, so the task keeps the others itself.
     */
    public function test_a_blueprint_that_does_not_validate_is_kept_for_review(): void {
        global $DB;
        $blueprint = ['version' => '1.0', 'language' => 'ro', 'course' => [], 'sections' => []];
        $outcome = pipeline_outcome::completed(
            blueprint: $blueprint,
            brief: [],
            manualnodes: [],
            errors: [new validation_error('/course', validation_error::CODE_SCHEMA, 'course is incomplete')],
            totals: ['cost' => 0.25],
        );
        $task = generate_blueprint::instance($this->jobid, (int) $this->user->id);
        $sink = $this->redirectMessages();

        (new \ReflectionMethod($task, 'finish'))->invoke($task, $this->job(), $outcome);

        $job = $this->job();
        $this->assertSame(job_manager::STATUS_REVIEW, $job->status);
        $this->assertSame(get_string('generatereviewattention', 'local_aicoursebuilder'), $job->statusmessage);
        $this->assertEqualsWithDelta(0.25, (float) $job->actualcost, 0.000001);
        $rows = $DB->get_records('local_aicb_blueprint', ['jobid' => $this->jobid]);
        $this->assertCount(1, $rows);
        $this->assertEquals(1, reset($rows)->version);
        $this->assertSame('draft', reset($rows)->status);
        $this->assertSame($blueprint, json_decode(reset($rows)->content, true));
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * The cost of the job is the cost of its steps.
     */
    public function test_the_actual_cost_is_recorded(): void {
        $this->add_energy_sources();
        $this->connector->set_cost_per_call(0.01);

        $this->run_task();

        $job = $this->job();
        $this->assertSame(job_manager::STATUS_REVIEW, $job->status);
        $this->assertGreaterThan(0, (float) $job->actualcost);
        $this->assertEqualsWithDelta(0.01 * $this->connector->call_count(), (float) $job->actualcost, 0.000001);
    }
}
