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

namespace local_aicoursebuilder\blueprint;

/**
 * Tests of reading, saving and approving the blueprint versions of a job.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\blueprint\blueprint_service
 */
final class blueprint_service_test extends \advanced_testcase {
    /** @var \stdClass Owner of the job. */
    private \stdClass $user;

    /** @var int Job id. */
    private int $jobid;

    /** @var blueprint_service The service under test. */
    private blueprint_service $service;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'status' => 'review',
            'prompt' => 'Test',
            'timecreated' => time(),
            'timemodified' => time() - 100,
        ]);
        $this->service = new blueprint_service();
        $this->insert_version(1, $this->golden());
    }

    /**
     * Returns the golden blueprint, which is valid.
     *
     * @return array
     */
    private function golden(): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/blueprint_golden.json'), true);
    }

    /**
     * Inserts a draft version of the job.
     *
     * @param int $version Version number.
     * @param array $blueprint The blueprint.
     */
    private function insert_version(int $version, array $blueprint): void {
        global $DB;
        $content = json_encode($blueprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $this->jobid,
            'version' => $version,
            'content' => $content,
            'contenthash' => hash('sha256', $content),
            'status' => 'draft',
            'usermodified' => $this->user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Loads the job.
     *
     * @return \stdClass
     */
    private function job(): \stdClass {
        global $DB;
        return $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
    }

    /**
     * Asserts that a call is refused with an error of the plugin.
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
        }
    }

    /**
     * The latest version is the default, and an older one can be asked for.
     */
    public function test_get_returns_the_latest_or_a_given_version(): void {
        $edited = $this->golden();
        $edited['sections'][0]['title'] = 'Changed';
        $this->insert_version(2, $edited);

        $this->assertEquals(2, $this->service->get($this->jobid)->version);
        $this->assertEquals(2, $this->service->get($this->jobid, 2)->version);
        $this->assertEquals(1, $this->service->get($this->jobid, 1)->version);
    }

    /**
     * A version that does not exist, or a job with none, is refused.
     */
    public function test_get_refuses_a_missing_version(): void {
        $this->assert_refused('blueprintnotfound', fn() => $this->service->get($this->jobid, 9));
        $this->assert_refused('blueprintnotfound', fn() => $this->service->get($this->jobid + 100));
    }

    /**
     * An edit is saved as the next version, the earlier one is left as it was, and the job is touched.
     */
    public function test_save_creates_the_next_version(): void {
        global $DB;
        $edited = $this->golden();
        $edited['sections'][0]['title'] = 'A new title';
        $original = $this->service->get($this->jobid, 1)->content;

        $saved = $this->service->save($this->job(), (int) $this->user->id, $edited, 1);

        $this->assertSame([], $saved['errors']);
        $this->assertEquals(2, $saved['row']->version);
        $this->assertSame('draft', $saved['row']->status);
        $this->assertEquals($this->user->id, $saved['row']->usermodified);
        $this->assertSame(hash('sha256', $saved['row']->content), $saved['row']->contenthash);
        $this->assertSame('A new title', json_decode($saved['row']->content, true)['sections'][0]['title']);
        $this->assertSame($original, $this->service->get($this->jobid, 1)->content);
        $this->assertGreaterThan(time() - 50, (int) $DB->get_field('local_aicb_job', 'timemodified', ['id' => $this->jobid]));
    }

    /**
     * Saving what has not changed makes no new version.
     */
    public function test_save_of_an_unchanged_blueprint_adds_nothing(): void {
        global $DB;

        $saved = $this->service->save($this->job(), (int) $this->user->id, $this->golden(), 1);

        $this->assertEquals(1, $saved['row']->version);
        $this->assertSame(1, $DB->count_records('local_aicb_blueprint', ['jobid' => $this->jobid]));
    }

    /**
     * A blueprint with errors is saved all the same, and the errors are reported.
     */
    public function test_save_keeps_a_blueprint_with_errors(): void {
        $broken = $this->golden();
        $broken['sections'] = [];

        $saved = $this->service->save($this->job(), (int) $this->user->id, $broken, 1);

        $this->assertEquals(2, $saved['row']->version);
        $this->assertNotEmpty($saved['errors']);
        $this->assertInstanceOf(validation_error::class, $saved['errors'][0]);
    }

    /**
     * An edit that started from an older version than the latest is refused, so it cannot overwrite a newer one.
     */
    public function test_save_refuses_an_edit_of_an_old_version(): void {
        $edited = $this->golden();
        $edited['sections'][0]['title'] = 'Newer';
        $this->service->save($this->job(), (int) $this->user->id, $edited, 1);

        $other = $this->golden();
        $other['sections'][0]['title'] = 'Another';
        $this->assert_refused(
            'blueprintconflict',
            fn() => $this->service->save($this->job(), (int) $this->user->id, $other, 1)
        );
    }

    /**
     * Only a job in review can be edited.
     *
     * @dataProvider other_statuses
     * @param string $status The job status.
     */
    public function test_save_and_approve_need_a_job_in_review(string $status): void {
        global $DB;
        $DB->set_field('local_aicb_job', 'status', $status, ['id' => $this->jobid]);
        $hash = $this->service->get($this->jobid)->contenthash;

        $this->assert_refused(
            'blueprintnotreviewable',
            fn() => $this->service->save($this->job(), (int) $this->user->id, $this->golden(), 1)
        );
        $this->assert_refused(
            'blueprintnotreviewable',
            fn() => $this->service->approve($this->job(), (int) $this->user->id, 1, $hash)
        );
    }

    /**
     * Statuses in which the blueprint is not edited.
     *
     * @return array
     */
    public static function other_statuses(): array {
        return [
            'generating' => ['generating'],
            'approved' => ['approved'],
            'building' => ['building'],
            'failed' => ['failed'],
        ];
    }

    /**
     * Approving locks the version and moves the job on.
     */
    public function test_approve_locks_the_version(): void {
        global $DB;
        $hash = $this->service->get($this->jobid)->contenthash;

        $approved = $this->service->approve($this->job(), (int) $this->user->id, 1, $hash);

        $this->assertSame('approved', $approved['row']->status);
        $this->assertEquals($this->user->id, $approved['row']->approvedby);
        $this->assertNotNull($approved['row']->timeapproved);
        $this->assertSame(class_exists('\\local_aicoursebuilder\\task\\build_course'), $approved['queued']);

        $job = $this->job();
        $this->assertSame('approved', $job->status);
        $this->assertEquals($approved['row']->id, $job->blueprintid);
        $this->assertSame(get_string('blueprintapproved', 'local_aicoursebuilder'), $job->statusmessage);

        // An approved version is never changed: the job is no longer in review, so a save is refused.
        $content = $DB->get_field('local_aicb_blueprint', 'content', ['id' => $approved['row']->id]);
        $this->assert_refused(
            'blueprintnotreviewable',
            fn() => $this->service->save($this->job(), (int) $this->user->id, ['version' => '1.0'], 1)
        );
        $this->assertSame($content, $DB->get_field('local_aicb_blueprint', 'content', ['id' => $approved['row']->id]));
    }

    /**
     * What the teacher reviewed is what is approved: a different hash is refused.
     */
    public function test_approve_refuses_a_version_that_changed(): void {
        $this->assert_refused(
            'blueprinthashmismatch',
            fn() => $this->service->approve($this->job(), (int) $this->user->id, 1, str_repeat('a', 64))
        );
        $this->assertSame('review', $this->job()->status);
    }

    /**
     * Only the latest version is approved.
     */
    public function test_approve_refuses_a_version_that_is_not_the_latest(): void {
        $hash = $this->service->get($this->jobid)->contenthash;
        $edited = $this->golden();
        $edited['sections'][0]['title'] = 'Newer';
        $this->service->save($this->job(), (int) $this->user->id, $edited, 1);

        $this->assert_refused(
            'blueprintconflict',
            fn() => $this->service->approve($this->job(), (int) $this->user->id, 1, $hash)
        );
    }

    /**
     * A blueprint with errors is not approved.
     */
    public function test_approve_refuses_a_blueprint_with_errors(): void {
        $broken = $this->golden();
        $broken['sections'] = [];
        $saved = $this->service->save($this->job(), (int) $this->user->id, $broken, 1);

        $this->assert_refused(
            'blueprintnotvalid',
            fn() => $this->service->approve($this->job(), (int) $this->user->id, 2, $saved['row']->contenthash)
        );
        $this->assertSame('review', $this->job()->status);
    }
}
