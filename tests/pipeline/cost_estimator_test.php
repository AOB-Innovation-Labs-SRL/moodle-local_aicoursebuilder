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

namespace local_aicoursebuilder\pipeline;

/**
 * Tests of the estimate of a job's cost.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\pipeline\cost_estimator
 */
final class cost_estimator_test extends \advanced_testcase {
    /** @var int Id of the job under test. */
    private int $jobid;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        $this->jobid = $this->add_job();
    }

    /**
     * Inserts a job.
     *
     * @param string|null $brief Brief JSON.
     * @return int Job id.
     */
    private function add_job(?string $brief = null): int {
        global $DB;
        return $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->getDataGenerator()->create_user()->id,
            'status' => 'draft',
            'prompt' => 'Test',
            'brief' => $brief,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts a source of a job.
     *
     * @param int $jobid Job id.
     * @param int $filesize Size of the file in bytes.
     * @param int|null $tokencount Tokens, null while the text has not been extracted.
     * @param string $status Source status.
     */
    private function add_source(int $jobid, int $filesize, ?int $tokencount = null, string $status = 'pending'): void {
        global $DB;
        $DB->insert_record('local_aicb_source', (object) [
            'jobid' => $jobid,
            'filename' => 'file.pdf',
            'mimetype' => 'application/pdf',
            'filesize' => $filesize,
            'contenthash' => sha1((string) $filesize),
            'status' => $status,
            'tokencount' => $tokencount,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the estimate of a job.
     *
     * @param int $jobid Job id.
     * @return array
     */
    private function estimate(int $jobid): array {
        global $DB;
        return (new cost_estimator())->estimate($DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST));
    }

    /**
     * A job without sources still has a cost: the brief, the outline and the sections are written anyway.
     */
    public function test_job_without_sources_has_a_cost(): void {
        $estimate = $this->estimate($this->jobid);

        $this->assertGreaterThan(0, $estimate['cost']);
        $this->assertGreaterThan(0, $estimate['tokensin']);
        $this->assertGreaterThan(0, $estimate['tokensout']);
    }

    /**
     * More source material costs more.
     */
    public function test_cost_grows_with_the_sources(): void {
        $empty = $this->estimate($this->jobid)['cost'];
        $this->add_source($this->jobid, 500_000);
        $one = $this->estimate($this->jobid)['cost'];
        $this->add_source($this->jobid, 500_000);
        $two = $this->estimate($this->jobid)['cost'];

        $this->assertGreaterThan($empty, $one);
        $this->assertGreaterThan($one, $two);
    }

    /**
     * The real token count of a source is used once it is known, and a failed source costs nothing.
     */
    public function test_known_tokens_replace_the_size_of_the_file(): void {
        $big = $this->add_job();
        $this->add_source($big, 5_000_000);
        $counted = $this->add_job();
        $this->add_source($counted, 5_000_000, 1000);
        $failed = $this->add_job();
        $this->add_source($failed, 5_000_000, null, 'failed');

        $this->assertGreaterThan($this->estimate($counted)['cost'], $this->estimate($big)['cost']);
        $this->assertEquals($this->estimate($this->jobid)['cost'], $this->estimate($failed)['cost']);
    }

    /**
     * A longer course has more sections, so it costs more.
     */
    public function test_cost_grows_with_the_duration_of_the_course(): void {
        $short = $this->add_job('{"duration_minutes": 60}');
        $long = $this->add_job('{"duration_minutes": 600}');

        $this->assertGreaterThan($this->estimate($short)['cost'], $this->estimate($long)['cost']);
    }

    /**
     * A duration that makes no sense falls back to the default instead of breaking the estimate.
     */
    public function test_invalid_brief_is_ignored(): void {
        $broken = $this->add_job('not json');
        $negative = $this->add_job('{"duration_minutes": -5}');

        $this->assertEquals($this->estimate($this->jobid)['cost'], $this->estimate($broken)['cost']);
        $this->assertEquals($this->estimate($this->jobid)['cost'], $this->estimate($negative)['cost']);
    }

    /**
     * The estimate follows the route of each step, so a free connector costs nothing.
     */
    public function test_route_decides_the_price(): void {
        $paid = $this->estimate($this->jobid)['cost'];
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
        $free = $this->estimate($this->jobid)['cost'];

        $this->assertGreaterThan(0, $paid);
        $this->assertEquals(0, $free);
    }

    /**
     * A long document is read in several windows, so it adds more digest calls.
     */
    public function test_long_document_adds_digest_calls(): void {
        set_config('digest_maxinputtokens', '1000', 'local_aicoursebuilder');
        $short = $this->add_job();
        $this->add_source($short, 1, 900);
        $long = $this->add_job();
        $this->add_source($long, 1, 3500);

        $shortestimate = $this->estimate($short);
        $longestimate = $this->estimate($long);
        $this->assertGreaterThan($shortestimate['tokensout'], $longestimate['tokensout']);
    }
}
