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

namespace local_aicoursebuilder\ai;

use GuzzleHttp\Psr7\Response;

/**
 * Tests for the usage report: the sums of the AI call log per period, job, user, model and month, and the limits.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\usage_report
 */
final class usage_report_test extends \advanced_testcase {
    /** @var usage_report The report under test. */
    private usage_report $report;

    /** @var \stdClass A user. */
    private \stdClass $ana;

    /** @var \stdClass Another user. */
    private \stdClass $dan;

    /** @var int A job of the first user. */
    private int $jobana;

    /** @var int A job of the second user. */
    private int $jobdan;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->report = new usage_report();
        $this->ana = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Popescu']);
        $this->dan = $this->getDataGenerator()->create_user(['firstname' => 'Dan', 'lastname' => 'Ionescu']);
        $this->jobana = $this->create_job((int) $this->ana->id, 'Un curs despre energia solară');
        $this->jobdan = $this->create_job((int) $this->dan->id, 'Un curs despre baze de date');
        set_config('alertpercent', '80', 'local_aicoursebuilder');
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('sitelimitusd', '100', 'local_aicoursebuilder');
    }

    /**
     * Inserts a job.
     *
     * @param int $userid Owner.
     * @param string $prompt What was asked for.
     * @return int Job id.
     */
    private function create_job(int $userid, string $prompt): int {
        global $DB;
        return (int) $DB->insert_record('local_aicb_job', (object) [
            'userid' => $userid,
            'mode' => 'newcourse',
            'status' => 'review',
            'prompt' => $prompt,
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts one row of the AI call log.
     *
     * @param \stdClass $user Who made the call.
     * @param int|null $jobid The job, null for none.
     * @param float $cost Cost in USD.
     * @param array $override Fields to change: connector, model, tokensin, tokensout, tokenscached, status, time.
     */
    private function log(\stdClass $user, ?int $jobid, float $cost, array $override = []): void {
        global $DB;
        $override += [
            'connector' => 'deepseek',
            'model' => 'deepseek-flash',
            'tokensin' => 1000,
            'tokensout' => 200,
            'tokenscached' => 400,
            'status' => 'success',
            'time' => time(),
        ];
        $DB->insert_record('local_aicb_ailog', (object) [
            'userid' => $user->id,
            'jobid' => $jobid,
            'step' => 'sections',
            'connector' => $override['connector'],
            'model' => $override['model'],
            'tokensin' => $override['tokensin'],
            'tokensout' => $override['tokensout'],
            'tokenscached' => $override['tokenscached'],
            'cost' => $cost,
            'durationms' => 100,
            'status' => $override['status'],
            'timecreated' => $override['time'],
        ]);
    }

    /**
     * Returns a time in the middle of a month.
     *
     * @param string $period Month, YYYY-MM.
     * @return int
     */
    private function in_month(string $period): int {
        return (int) strtotime($period . '-15 12:00:00');
    }

    /**
     * A period is a month or nothing, which stands for all time.
     */
    public function test_is_period(): void {
        $this->assertTrue(usage_report::is_period(''));
        $this->assertTrue(usage_report::is_period('2026-10'));
        $this->assertFalse(usage_report::is_period('2026-13'));
        $this->assertFalse(usage_report::is_period('2026-1'));
        $this->assertFalse(usage_report::is_period('all'));
        $this->assertFalse(usage_report::is_period("2026-10' OR 1=1"));
    }

    /**
     * A period that is not one is refused rather than put in a query.
     */
    public function test_a_bad_period_is_refused(): void {
        $this->expectException(\coding_exception::class);
        $this->report->totals("2026-10' OR 1=1");
    }

    /**
     * The totals of a month sum its calls, and leave out the calls of other months.
     */
    public function test_totals_of_a_month(): void {
        $this->log($this->ana, $this->jobana, 0.25);
        $this->log($this->ana, $this->jobana, 0.5, ['tokensin' => 3000, 'tokensout' => 800, 'tokenscached' => 0]);
        $this->log($this->dan, $this->jobdan, 0.0, ['status' => 'error']);
        $this->log($this->ana, $this->jobana, 9.0, ['time' => $this->in_month('2025-01')]);

        $totals = $this->report->totals(budget_guard::current_period());

        $this->assertEqualsWithDelta(0.75, $totals->cost, 1e-9);
        $this->assertSame(5000, $totals->tokensin);
        $this->assertSame(1200, $totals->tokensout);
        $this->assertSame(800, $totals->tokenscached);
        $this->assertSame(3, $totals->calls);
        $this->assertSame(1, $totals->errors);
    }

    /**
     * All time sums the whole log, and a month with no calls has zeros.
     */
    public function test_totals_of_all_time_and_of_an_empty_month(): void {
        $this->log($this->ana, $this->jobana, 0.5);
        $this->log($this->ana, $this->jobana, 9.0, ['time' => $this->in_month('2025-01')]);

        $this->assertEqualsWithDelta(9.5, $this->report->totals('')->cost, 1e-9);
        $this->assertSame(2, $this->report->totals('')->calls);
        $empty = $this->report->totals('2024-03');
        $this->assertSame(0.0, $empty->cost);
        $this->assertSame(0, $empty->calls);
        $this->assertSame(0, $empty->errors);
    }

    /**
     * The jobs come with their cost, costliest first; a call that belongs to no job is a row of its own.
     */
    public function test_by_job(): void {
        $this->log($this->ana, $this->jobana, 0.25);
        $this->log($this->ana, $this->jobana, 0.25);
        $this->log($this->dan, $this->jobdan, 2.0);
        $this->log($this->dan, null, 0.1);

        $rows = $this->report->by_job(budget_guard::current_period());

        $this->assertCount(3, $rows);
        $this->assertSame($this->jobdan, $rows[0]->jobid);
        $this->assertEqualsWithDelta(2.0, $rows[0]->cost, 1e-9);
        $this->assertSame('Un curs despre baze de date', $rows[0]->prompt);
        $this->assertSame('review', $rows[0]->status);
        $this->assertSame($this->jobana, $rows[1]->jobid);
        $this->assertEqualsWithDelta(0.5, $rows[1]->cost, 1e-9);
        $this->assertSame(2, $rows[1]->calls);
        $this->assertSame(0, $rows[2]->jobid);
        $this->assertSame('', $rows[2]->prompt);
    }

    /**
     * The users come with their name and cost, costliest first.
     */
    public function test_by_user(): void {
        $this->log($this->ana, $this->jobana, 0.5);
        $this->log($this->dan, $this->jobdan, 1.5);
        $this->log($this->dan, $this->jobdan, 0.5);

        $rows = $this->report->by_user('');

        $this->assertCount(2, $rows);
        $this->assertSame('Dan Ionescu', $rows[0]->name);
        $this->assertEqualsWithDelta(2.0, $rows[0]->cost, 1e-9);
        $this->assertSame('Ana Popescu', $rows[1]->name);
    }

    /**
     * The models come with their connector, costliest first.
     */
    public function test_by_model(): void {
        $this->log($this->ana, $this->jobana, 0.5);
        $this->log($this->ana, $this->jobana, 0.5);
        $this->log($this->ana, $this->jobana, 3.0, ['connector' => 'anthropic', 'model' => 'claude-sonnet']);

        $rows = $this->report->by_model('');

        $this->assertCount(2, $rows);
        $this->assertSame('anthropic', $rows[0]->connector);
        $this->assertSame('claude-sonnet', $rows[0]->model);
        $this->assertEqualsWithDelta(3.0, $rows[0]->cost, 1e-9);
        $this->assertSame('deepseek-flash', $rows[1]->model);
        $this->assertSame(2, $rows[1]->calls);
    }

    /**
     * The months are the last twelve, the latest first, and a month without calls is there with zeros.
     */
    public function test_by_month(): void {
        $now = $this->in_month('2026-10');
        $this->log($this->ana, $this->jobana, 1.0, ['time' => $this->in_month('2026-10')]);
        $this->log($this->ana, $this->jobana, 2.0, ['time' => $this->in_month('2026-08')]);
        $this->log($this->ana, $this->jobana, 4.0, ['time' => $this->in_month('2026-08')]);
        $this->log($this->ana, $this->jobana, 8.0, ['time' => $this->in_month('2025-09')]);
        $this->log($this->ana, $this->jobana, 16.0, ['time' => $this->in_month('2025-11')]);

        $months = $this->report->by_month($now);

        $this->assertCount(usage_report::MONTHS, $months);
        $this->assertSame('2026-10', $months[0]->period);
        $this->assertEqualsWithDelta(1.0, $months[0]->cost, 1e-9);
        $this->assertSame('2026-09', $months[1]->period);
        $this->assertSame(0.0, $months[1]->cost);
        $this->assertSame('2026-08', $months[2]->period);
        $this->assertEqualsWithDelta(6.0, $months[2]->cost, 1e-9);
        $this->assertSame(2, $months[2]->calls);
        // Twelve months back from October 2026 ends in November 2025, which is in; September 2025 is out.
        $this->assertSame('2025-11', $months[11]->period);
        $this->assertEqualsWithDelta(16.0, $months[11]->cost, 1e-9);
        $this->assertSame($this->report->periods($now), array_column($months, 'period'));
    }

    /**
     * The months of a year boundary are counted back correctly.
     */
    public function test_periods_cross_the_year(): void {
        $periods = $this->report->periods($this->in_month('2026-02'));

        $this->assertSame('2026-02', $periods[0]);
        $this->assertSame('2026-01', $periods[1]);
        $this->assertSame('2025-12', $periods[2]);
        $this->assertSame('2025-03', $periods[11]);
    }

    /**
     * What the report shows is what the logging connector wrote, which is what the provider was paid for.
     */
    public function test_it_reflects_the_cost_of_the_logged_calls(): void {
        set_config('deepseek_apikey', \core\encryption::encrypt('sk-test'), 'local_aicoursebuilder');
        \core_ai\manager::user_policy_accepted((int) $this->ana->id, \context_system::instance()->id);
        ['mock' => $mock] = $this->get_mocked_http_client();
        $body = json_encode([
            'model' => 'deepseek-flash',
            'choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 100000,
                'completion_tokens' => 5000,
                'prompt_cache_hit_tokens' => 60000,
                'prompt_cache_miss_tokens' => 40000,
            ],
        ]);
        $mock->append(new Response(200, [], $body), new Response(200, [], $body));
        $connector = new logging_connector(new deepseek_connector(), deepseek_connector::NAME);
        $request = new request(
            step: request::STEP_DIGEST,
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'text']],
            userid: (int) $this->ana->id,
            jobid: $this->jobana,
            json: true,
        );

        $paid = $connector->complete($request)->cost + $connector->complete($request)->cost;

        $totals = $this->report->totals(budget_guard::current_period());
        $this->assertGreaterThan(0, $paid);
        $this->assertEqualsWithDelta($paid, $totals->cost, 1e-9);
        // The connector counts as input the tokens that were not served from the cache: 40000 of each call.
        $this->assertSame(80000, $totals->tokensin);
        $this->assertSame(10000, $totals->tokensout);
        $this->assertSame(120000, $totals->tokenscached);
        $this->assertSame(2, $totals->calls);
        $this->assertEqualsWithDelta($paid, $this->report->by_job('')[0]->cost, 1e-9);
    }

    /**
     * The limits of a month: the site first, then the users by how much of their limit they used, with their state.
     */
    public function test_limits(): void {
        global $DB;
        $period = budget_guard::current_period();
        $now = time();
        $row = fn(int $userid, float $spent, float $reserved = 0.0, ?float $limit = null) => $DB->insert_record(
            'local_aicb_budget',
            (object) [
                'userid' => $userid, 'period' => $period, 'limitusd' => $limit, 'spentusd' => $spent,
                'reservedusd' => $reserved, 'alertsent' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]
        );
        $row((int) $this->ana->id, 8.5);
        $row((int) $this->dan->id, 3.0, 0.0, 50.0);
        $row(budget_guard::SITE_USERID, 100.0);
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Eva', 'lastname' => 'Marin']);
        $row((int) $other->id, 6.0, 4.0);

        $limits = $this->report->limits($period);

        $this->assertCount(4, $limits);
        $this->assertSame(budget_guard::SITE_USERID, $limits[0]->userid);
        $this->assertSame(usage_report::STATE_EXCEEDED, $limits[0]->state);
        $this->assertEquals(100.0, $limits[0]->limit);
        // Eva has spent and reserved the whole of the default limit of 10.
        $this->assertSame('Eva Marin', $limits[1]->name);
        $this->assertSame(usage_report::STATE_EXCEEDED, $limits[1]->state);
        $this->assertSame('Ana Popescu', $limits[2]->name);
        $this->assertEquals(85.0, $limits[2]->percent);
        $this->assertSame(usage_report::STATE_ALERT, $limits[2]->state);
        // Dan has a limit of his own, which is far from used.
        $this->assertSame('Dan Ionescu', $limits[3]->name);
        $this->assertEquals(50.0, $limits[3]->limit);
        $this->assertSame(usage_report::STATE_OK, $limits[3]->state);
    }

    /**
     * A limit of 0 is no limit: it is never near, and has no percentage.
     */
    public function test_a_limit_of_zero_is_no_limit(): void {
        global $DB;
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $this->ana->id, 'period' => budget_guard::current_period(), 'limitusd' => null,
            'spentusd' => 500, 'reservedusd' => 0, 'alertsent' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $limits = $this->report->limits(budget_guard::current_period());

        $this->assertCount(1, $limits);
        $this->assertSame(usage_report::STATE_OK, $limits[0]->state);
        $this->assertEquals(0, $limits[0]->percent);
    }

    /**
     * A month with no budget rows has no limits to show.
     */
    public function test_no_limits_without_spending(): void {
        $this->assertSame([], $this->report->limits('2020-01'));
    }
}
