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

namespace local_aicoursebuilder\output;

use local_aicoursebuilder\ai\budget_guard;

/**
 * Tests for the usage dashboard: what it exports and what its template shows.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\output\usage_dashboard
 */
final class usage_dashboard_test extends \advanced_testcase {
    /** @var \stdClass A user who spent. */
    private \stdClass $user;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('alertpercent', '80', 'local_aicoursebuilder');
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');
        $this->user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Popescu']);
        $jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id, 'mode' => 'newcourse', 'status' => 'review', 'language' => 'ro',
            'prompt' => 'Un curs despre energia solară', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_aicb_ailog', (object) [
            'userid' => $this->user->id, 'jobid' => $jobid, 'step' => 'sections', 'connector' => 'deepseek',
            'model' => 'deepseek-flash', 'tokensin' => 1500, 'tokensout' => 300, 'tokenscached' => 900, 'cost' => 8.5,
            'durationms' => 10, 'status' => 'success', 'timecreated' => time(),
        ]);
        $DB->insert_record('local_aicb_budget', (object) [
            'userid' => $this->user->id, 'period' => budget_guard::current_period(), 'spentusd' => 8.5,
            'reservedusd' => 0, 'alertsent' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Renders the dashboard of a period.
     *
     * @param string $period Month, YYYY-MM, or an empty string for all time.
     * @return string
     */
    private function render(string $period): string {
        global $PAGE;
        $PAGE->set_url('/local/aicoursebuilder/usage.php');
        return $PAGE->get_renderer('core')->render(new usage_dashboard($period));
    }

    /**
     * The dashboard lists the month's spending per job, user, model and month, and warns about the limit that is near.
     */
    public function test_it_shows_the_spending_and_the_alert(): void {
        $html = $this->render(budget_guard::current_period());

        $this->assertStringContainsString('8.5000', $html);
        $this->assertStringContainsString('Un curs despre energia solară', $html);
        $this->assertStringContainsString('Ana Popescu', $html);
        $this->assertStringContainsString('deepseek-flash', $html);
        $this->assertStringContainsString('data-region="aicb-usage-alert"', $html);
        $this->assertStringContainsString('85%', $html);
        $this->assertStringContainsString('data-region="aicb-usage-months"', $html);
        $this->assertStringContainsString('/local/aicoursebuilder/job.php?id=', $html);
    }

    /**
     * The period selector offers the months and all time, and marks the one shown.
     */
    public function test_the_period_selector(): void {
        $data = (new usage_dashboard(''))->export_for_template($this->getMockBuilder(\renderer_base::class)
            ->disableOriginalConstructor()->getMock());

        $this->assertTrue($data->allperiods);
        $this->assertCount(13, $data->periods);
        $this->assertSame('all', end($data->periods)['value']);
        $this->assertTrue(end($data->periods)['selected']);
        $this->assertFalse($data->periods[0]['selected']);
        $this->assertSame(budget_guard::current_period(), $data->periods[0]['value']);
    }

    /**
     * A month without calls has no tables to show, only a note, and no alert.
     */
    public function test_a_month_without_spending(): void {
        $html = $this->render('2020-01');

        $this->assertStringNotContainsString('data-region="aicb-usage-jobs"', $html);
        $this->assertStringNotContainsString('data-region="aicb-usage-alert"', $html);
        $this->assertStringContainsString(get_string('usage:nodata', 'local_aicoursebuilder'), $html);
    }

    /**
     * A limit that is used up is shown as such.
     */
    public function test_a_limit_that_is_used_up(): void {
        global $DB;
        $DB->set_field('local_aicb_budget', 'spentusd', 10, ['userid' => $this->user->id]);

        $html = $this->render(budget_guard::current_period());

        $this->assertStringContainsString(get_string('usage:exceeded', 'local_aicoursebuilder'), $html);
        $this->assertStringContainsString('data-region="aicb-usage-alert"', $html);
    }
}
