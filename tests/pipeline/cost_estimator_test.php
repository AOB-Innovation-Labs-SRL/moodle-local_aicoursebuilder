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

    /**
     * Adds a job with the given request and brief.
     *
     * @param string $prompt The teacher's request.
     * @param string|null $brief Brief JSON.
     * @param bool $offpeak Whether the job asks to run off-peak.
     * @return int Job id.
     */
    private function add_job_for(string $prompt, ?string $brief = null, bool $offpeak = false): int {
        global $DB;
        return $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->getDataGenerator()->create_user()->id,
            'status' => 'draft',
            'prompt' => $prompt,
            'brief' => $brief,
            'offpeak' => $offpeak ? 1 : 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the estimate of a job, priced at a given moment.
     *
     * @param int $jobid Job id.
     * @param int|null $timestamp The moment the work is priced at.
     * @return array
     */
    private function estimate_at(int $jobid, ?int $timestamp): array {
        global $DB;
        $job = $DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST);
        return (new cost_estimator())->estimate($job, $timestamp);
    }

    /** Wednesday 14 October 2026, 07:00 UTC: inside the peak window of the DeepSeek pilot schedule. */
    private const PEAK = 1791961200;

    /** Wednesday 14 October 2026, 12:06 UTC: off-peak. */
    private const OFFPEAK = 1791979560;

    /**
     * After the first call of the content steps the shared prefix is served from the provider's cache, so one more
     * section costs a fraction of what sending the prefix again would cost.
     */
    public function test_the_prefix_is_paid_once_and_then_found_in_the_cache(): void {
        $prefixtokens = 100_000;
        $few = $this->add_job_for('Curs. Durata: 135 de minute.');
        $this->add_source($few, 1, $prefixtokens);
        $many = $this->add_job_for('Curs. Durata: 450 de minute.');
        $this->add_source($many, 1, $prefixtokens);

        $fewestimate = $this->estimate_at($few, self::OFFPEAK);
        $manyestimate = $this->estimate_at($many, self::OFFPEAK);

        // 135 minutes are 3 sections and 1 subsection, 450 minutes are 10 sections and 5 subsections: 11 nodes more,
        // each with a call of the sections, activities and questions steps.
        $extranodes = 11;
        $extracost = $manyestimate['cost'] - $fewestimate['cost'];
        $uncachedprefix = $extranodes * 3 * ($prefixtokens / 1_000_000) * 0.15;
        $this->assertGreaterThan(0, $extracost);
        $this->assertLessThan(
            0.5 * $uncachedprefix + $extranodes * 0.04,
            $extracost,
            'a node costs its own tokens and the cached prefix, not the prefix again',
        );
        $this->assertGreaterThan($fewestimate['tokensin'], $manyestimate['tokensin'], 'the cached tokens are still input');
    }

    /**
     * A connector with no prefix cache pays for the prefix at every call.
     */
    public function test_a_connector_without_a_prefix_cache_pays_for_the_prefix_every_time(): void {
        $job = $this->add_job_for('Curs. Durata: 240 de minute.');
        $this->add_source($job, 1, 100_000);
        $cached = $this->estimate_at($job, self::OFFPEAK)['cost'];

        set_config('defaultconnector', 'openaicompat', 'local_aicoursebuilder');
        $prices = ['gpt-test' => ['input_miss' => 0.30, 'input_hit' => 0.006, 'output' => 1.2]];
        set_config('pricing_openaicompat', json_encode($prices), 'local_aicoursebuilder');
        set_config('openaicompat_model', 'gpt-test', 'local_aicoursebuilder');
        $uncached = $this->estimate_at($job, self::OFFPEAK)['cost'];

        $this->assertGreaterThan(3 * $cached, $uncached);
    }

    /**
     * Work priced off-peak costs about half of the same work at peak, and a job that asks to run off-peak is priced
     * off-peak.
     */
    public function test_off_peak_is_priced_at_the_off_peak_rates(): void {
        $job = $this->add_job_for('Curs. Durata: 240 de minute.');
        $this->add_source($job, 1, 100_000);

        $peak = $this->estimate_at($job, self::PEAK)['cost'];
        $offpeak = $this->estimate_at($job, self::OFFPEAK)['cost'];
        $this->assertEqualsWithDelta(0.5, $offpeak / $peak, 0.02);

        $asked = $this->add_job_for('Curs. Durata: 240 de minute.', null, true);
        $this->add_source($asked, 1, 100_000);
        $this->assertEqualsWithDelta($offpeak, $this->estimate_at($asked, null)['cost'], 0.000001);
    }

    /**
     * The length of the course is read from the request when the brief does not exist yet.
     */
    public function test_the_duration_is_read_from_the_request(): void {
        $inprompt = $this->add_job_for('Curs introductiv. Public: începători. Durata: 120 de minute. Limba: română.');
        $inbrief = $this->add_job_for('Un curs.', '{"duration_minutes": 120}');
        $hours = $this->add_job_for('A course of 2 hours for beginners.');
        $long = $this->add_job_for('Curs. Durata: 480 de minute.');
        $none = $this->add_job_for('Un curs despre energie.');

        $short = $this->estimate_at($inbrief, self::PEAK)['cost'];
        $this->assertEquals($short, $this->estimate_at($inprompt, self::PEAK)['cost']);
        $this->assertEquals($short, $this->estimate_at($hours, self::PEAK)['cost']);
        $this->assertGreaterThan($short, $this->estimate_at($long, self::PEAK)['cost']);
        $this->assertGreaterThan($short, $this->estimate_at($none, self::PEAK)['cost'], 'no length asked for: the default');
    }

    /**
     * Before the text is extracted the size of a pdf says far less about its tokens than the size of a text file.
     */
    public function test_the_size_of_a_file_is_read_by_its_kind(): void {
        global $DB;
        $pdf = $this->add_job();
        $this->add_source($pdf, 700_000);
        $text = $this->add_job();
        $this->add_source($text, 700_000);
        $DB->set_field('local_aicb_source', 'mimetype', 'text/plain', ['jobid' => $text]);
        $DB->set_field('local_aicb_source', 'filename', 'notes.txt', ['jobid' => $text]);

        $this->assertGreaterThan($this->estimate($pdf)['cost'], $this->estimate($text)['cost']);
    }

    /**
     * The estimate is within 25% of what pdf_text_01 really cost in the runs of 14 October 2026 (deepseek-flash, 240
     * minutes, 101,520 tokens of source): 0.3365 USD at peak on the local stack, 0.2205 USD off-peak on the test server.
     */
    public function test_the_estimate_is_close_to_what_a_real_run_cost(): void {
        $job = $this->add_job_for('Curs introductiv pentru profesori despre educația incluzivă. Durata: 240 de minute.');
        $this->add_source($job, 6_180_122, 101_520);

        foreach ([[self::PEAK, 0.3365], [self::OFFPEAK, 0.2205]] as [$timestamp, $real]) {
            $estimated = $this->estimate_at($job, $timestamp)['cost'];
            $this->assertLessThan(0.25, abs($estimated - $real) / $real, "estimated {$estimated}, real {$real}");
        }
    }

    /**
     * Provides requests that state the length of the course in the ways teachers write it, with the minutes they mean.
     *
     * @return array
     */
    public static function duration_in_the_request_provider(): array {
        return [
            'decimal comma, hours' => ['Un curs de 2,5 ore pentru începători.', 150],
            'decimal comma, 1,5 hours' => ['Durata: 1,5 ore.', 90],
            'decimal point, hours' => ['A course of 1.5 hours.', 90],
            'decimal point, 2.5 hours' => ['A course of 2.5 hours.', 150],
            'one hour in Romanian' => ['Un curs de 1 oră.', 60],
            'hours in Romanian' => ['Un curs de 2 ore.', 120],
            'five hours' => ['Un curs de 5 ore.', 300],
            'minutes' => ['Durata: 90 min.', 90],
            'minutes in Romanian' => ['Durata: 240 de minute.', 240],
        ];
    }

    /**
     * The length is read whole: a decimal figure is not cut at its point, and "oră" is an hour.
     *
     * @dataProvider duration_in_the_request_provider
     * @param string $prompt The request.
     * @param int $minutes What it asks for.
     */
    public function test_the_duration_in_the_request_is_read_whole(string $prompt, int $minutes): void {
        $inprompt = $this->add_job_for($prompt);
        $inbrief = $this->add_job_for('Un curs.', json_encode(['duration_minutes' => $minutes]));

        $this->assertEquals(
            $this->estimate_at($inbrief, self::PEAK)['cost'],
            $this->estimate_at($inprompt, self::PEAK)['cost'],
            "'{$prompt}' is {$minutes} minutes",
        );
    }

    /**
     * Sets the sections step to a connector with no prefix cache, priced like DeepSeek so that only the cache differs.
     *
     * @param string $step The step to move to that connector.
     */
    private function route_to_a_connector_without_a_cache(string $step): void {
        $prices = ['input_miss' => 0.30, 'input_hit' => 0.006, 'output' => 1.2];
        $table = ['gpt-test' => $prices + ['offpeak' => ['input_miss' => 0.15, 'input_hit' => 0.003, 'output' => 0.6]]];
        set_config('pricing_openaicompat', json_encode($table), 'local_aicoursebuilder');
        set_config('openaicompat_model', 'gpt-test', 'local_aicoursebuilder');
        set_config("route_{$step}_connector", 'openaicompat', 'local_aicoursebuilder');
    }

    /**
     * Provides the step that goes to the connector with no cache.
     *
     * @return array
     */
    public static function step_without_a_cache_provider(): array {
        return ['sections' => ['sections'], 'activities' => ['activities']];
    }

    /**
     * The cache of a provider holds what that provider was sent. When the first step that carries the prefix goes to a
     * connector with no cache, the first call that goes to DeepSeek still pays the prefix in full, and when a step in the
     * middle goes to such a connector, all its calls pay it, whatever the other steps did.
     *
     * @dataProvider step_without_a_cache_provider
     * @param string $step The step that goes to the connector with no cache.
     */
    public function test_the_prefix_is_paid_once_for_each_connector(string $step): void {
        $job = $this->add_job_for('Curs. Durata: 240 de minute.');
        $this->add_source($job, 1, 100_000);
        $together = $this->estimate_at($job, self::OFFPEAK)['cost'];

        $this->route_to_a_connector_without_a_cache($step);
        $apart = $this->estimate_at($job, self::OFFPEAK)['cost'];

        // 240 minutes are 5 sections and 2 subsections: 7 calls of the step. All 7 pay the prefix in full instead of
        // finding it in the cache, and for sections the first call of activities has to pay it as well; for activities
        // the sections have already paid it at DeepSeek, so the 7 calls are the whole difference.
        $prefix = 100_000 + 1500 + 800 + 2500;
        $dearer = ($prefix / 1_000_000) * (0.15 - 0.003);
        $expected = 7 * $dearer * 1.05;
        $this->assertEqualsWithDelta($expected, $apart - $together, 0.002);
    }
}
