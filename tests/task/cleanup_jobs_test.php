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

/**
 * Tests of the scheduled task that deletes the jobs past the retention.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\task\cleanup_jobs
 */
final class cleanup_jobs_test extends \advanced_testcase {
    /** @var \stdClass Owner of the jobs. */
    private \stdClass $user;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * Inserts a job with a source, a chunk, a step and a blueprint, changed a number of days ago.
     *
     * @param string $status Status of the job.
     * @param int $days Days since the job was last changed.
     * @param int|null $courseid Course the job built.
     * @return int Job id.
     */
    private function job_with_data(string $status, int $days, ?int $courseid = null): int {
        global $DB;
        $then = time() - $days * DAYSECS;
        $jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'mode' => 'newcourse',
            'courseid' => $courseid,
            'status' => $status,
            'prompt' => 'Test',
            'timecreated' => $then,
            'timemodified' => $then,
        ]);
        $sourceid = $DB->insert_record('local_aicb_source', (object) [
            'jobid' => $jobid, 'filename' => 'a.txt', 'mimetype' => 'text/plain', 'contenthash' => sha1('a'),
            'timecreated' => $then, 'timemodified' => $then,
        ]);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'source',
            'itemid' => $sourceid,
            'filepath' => '/',
            'filename' => 'a.txt',
        ], 'Some text');
        $DB->insert_record('local_aicb_chunk', (object) [
            'jobid' => $jobid, 'sourceid' => $sourceid, 'chunkindex' => 0, 'content' => 'Some text',
            'contenthash' => sha1('Some text'), 'tokencount' => 2,
            'timecreated' => $then,
        ]);
        $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $jobid, 'version' => 1, 'content' => '{}', 'contenthash' => hash('sha256', '{}'),
            'usermodified' => $this->user->id, 'timecreated' => $then, 'timemodified' => $then,
        ]);
        $DB->insert_record('local_aicb_ailog', (object) [
            'userid' => $this->user->id, 'jobid' => $jobid, 'step' => 'sections', 'connector' => 'fake', 'model' => 'x',
            'timecreated' => $then,
        ]);
        return $jobid;
    }

    /**
     * Counts the stored source files, without the folder entries.
     *
     * @return int
     */
    private function source_files(): int {
        global $DB;
        $select = "component = 'local_aicoursebuilder' AND filearea = 'source' AND filename <> '.'";
        return $DB->count_records_select('files', $select);
    }

    /**
     * Counts the rows that hang from a job.
     *
     * @param int $jobid The job.
     * @return int
     */
    private function rows_of(int $jobid): int {
        global $DB;
        $total = $DB->count_records('local_aicb_job', ['id' => $jobid]);
        foreach (['local_aicb_source', 'local_aicb_chunk', 'local_aicb_blueprint'] as $table) {
            $total += $DB->count_records($table, ['jobid' => $jobid]);
        }
        return $total;
    }

    /**
     * An ended job past the retention is deleted with its data and files; a recent one and a running one are kept.
     */
    public function test_ended_jobs_past_the_retention_are_deleted(): void {
        global $DB;
        set_config('jobretentiondays', 30, 'local_aicoursebuilder');
        $course = $this->getDataGenerator()->create_course();
        $old = $this->job_with_data('finished', 40, $course->id);
        $oldfailed = $this->job_with_data('failed', 31);
        $oldcancelled = $this->job_with_data('cancelled', 90);
        $recent = $this->job_with_data('finished', 5);
        $running = $this->job_with_data('generating', 400);
        $files = $this->source_files();
        $this->assertSame(5, $files);

        ob_start();
        (new cleanup_jobs())->execute();
        ob_end_clean();

        $this->assertSame(0, $this->rows_of($old));
        $this->assertSame(0, $this->rows_of($oldfailed));
        $this->assertSame(0, $this->rows_of($oldcancelled));
        $this->assertSame(4, $this->rows_of($recent));
        $this->assertSame(4, $this->rows_of($running));
        $this->assertSame(2, $this->source_files());
        // The course the job built is never deleted.
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        // The call log stays, without the job it pointed to.
        $this->assertSame(5, $DB->count_records('local_aicb_ailog'));
        $this->assertSame(3, $DB->count_records_select('local_aicb_ailog', 'jobid IS NULL'));
    }

    /**
     * With a retention of 0 nothing is deleted.
     */
    public function test_a_retention_of_zero_keeps_everything(): void {
        set_config('jobretentiondays', 0, 'local_aicoursebuilder');
        $old = $this->job_with_data('finished', 4000);

        ob_start();
        (new cleanup_jobs())->execute();
        ob_end_clean();

        $this->assertSame(4, $this->rows_of($old));
    }

    /**
     * A run deletes a bounded number of jobs, so a long backlog is cleaned over several runs.
     */
    public function test_a_run_deletes_a_bounded_batch(): void {
        global $DB;
        $before = time() - 10;
        for ($i = 0; $i < 3; $i++) {
            $DB->insert_record('local_aicb_job', (object) [
                'userid' => $this->user->id, 'mode' => 'newcourse', 'status' => 'failed', 'prompt' => 'Test',
                'timecreated' => 1, 'timemodified' => 1,
            ]);
        }

        $this->assertSame(3, cleanup_jobs::delete_older_than($before));
        $this->assertSame(0, cleanup_jobs::delete_older_than($before));
    }
}
