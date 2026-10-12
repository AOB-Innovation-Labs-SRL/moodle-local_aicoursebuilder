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
use local_aicoursebuilder\ai\usage_report;

/**
 * The AI usage dashboard: spending per job, user, month and model, and how the cost limits stand.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_dashboard implements \renderable, \templatable {
    /** @var string The period the tables are for: a month, YYYY-MM, or an empty string for all time. */
    private string $period;

    /** @var usage_report Where the figures come from. */
    private usage_report $report;

    /**
     * Creates the dashboard.
     *
     * @param string $period The period the tables are for: a month, YYYY-MM, or an empty string for all time.
     * @param usage_report|null $report Where the figures come from, null for the log of the site.
     */
    public function __construct(string $period, ?usage_report $report = null) {
        $this->period = $period;
        $this->report = $report ?? new usage_report();
    }

    /**
     * Exports the dashboard for its template.
     *
     * @param \renderer_base $output The renderer.
     * @return \stdClass
     */
    #[\Override]
    public function export_for_template(\renderer_base $output): \stdClass {
        $limitsperiod = $this->period === '' ? budget_guard::current_period() : $this->period;
        $limits = array_map([$this, 'export_limit'], $this->report->limits($limitsperiod));
        $jobs = array_map([$this, 'export_job'], $this->report->by_job($this->period));
        $users = array_map([$this, 'export_user'], $this->report->by_user($this->period));
        $models = array_map([$this, 'export_model'], $this->report->by_model($this->period));

        return (object) [
            'action' => (new \moodle_url('/local/aicoursebuilder/usage.php'))->out(false),
            'periods' => $this->export_periods(),
            'allperiods' => $this->period === '',
            'totals' => $this->export_sums($this->report->totals($this->period)),
            'limitsperiod' => $limitsperiod,
            'haslimits' => $limits !== [],
            'limits' => $limits,
            'hasalerts' => (bool) array_filter($limits, fn($limit) => $limit['alert'] || $limit['exceeded']),
            'hasjobs' => $jobs !== [],
            'jobs' => $jobs,
            'hasusers' => $users !== [],
            'users' => $users,
            'hasmodels' => $models !== [],
            'models' => $models,
            'months' => array_map([$this, 'export_month'], $this->report->by_month()),
        ];
    }

    /**
     * Returns the options of the period selector.
     *
     * @return array[] value, name and selected of each option, all time last.
     */
    private function export_periods(): array {
        $options = [];
        foreach ($this->report->periods() as $month) {
            $options[] = ['value' => $month, 'name' => $month, 'selected' => $month === $this->period];
        }
        $options[] = [
            'value' => 'all',
            'name' => get_string('usage:allperiods', 'local_aicoursebuilder'),
            'selected' => $this->period === '',
        ];
        return $options;
    }

    /**
     * Formats the sums of a row.
     *
     * @param \stdClass $row A row of the report.
     * @return array cost, tokensin, tokensout, tokenscached, calls and errors, formatted.
     */
    private function export_sums(\stdClass $row): array {
        return [
            'cost' => format_float($row->cost, 4),
            'tokensin' => format_float($row->tokensin, 0),
            'tokensout' => format_float($row->tokensout, 0),
            'tokenscached' => format_float($row->tokenscached, 0),
            'calls' => format_float($row->calls, 0),
            'errors' => format_float($row->errors, 0),
        ];
    }

    /**
     * Formats a row of the limits table.
     *
     * @param \stdClass $limit A row of usage_report::limits().
     * @return array
     */
    private function export_limit(\stdClass $limit): array {
        $who = $limit->userid === budget_guard::SITE_USERID
            ? get_string('usage:site', 'local_aicoursebuilder')
            : ($limit->name ?: get_string('usage:deleteduser', 'local_aicoursebuilder'));
        return [
            'who' => $who,
            'limit' => $limit->limit > 0 ? format_float($limit->limit, 2) : '',
            'unlimited' => $limit->limit <= 0,
            'spent' => format_float($limit->spent, 4),
            'reserved' => format_float($limit->reserved, 4),
            'percent' => (int) floor($limit->percent),
            'alert' => $limit->state === usage_report::STATE_ALERT,
            'exceeded' => $limit->state === usage_report::STATE_EXCEEDED,
        ];
    }

    /**
     * Formats a row of the jobs table.
     *
     * @param \stdClass $row A row of usage_report::by_job().
     * @return array
     */
    private function export_job(\stdClass $row): array {
        return [
            'hasjob' => $row->jobid > 0,
            'url' => (new \moodle_url('/local/aicoursebuilder/job.php', ['id' => $row->jobid]))->out(false),
            'title' => $row->prompt !== '' ? $row->prompt : get_string('usage:jobid', 'local_aicoursebuilder', $row->jobid),
            'status' => $row->status,
        ] + $this->export_sums($row);
    }

    /**
     * Formats a row of the users table.
     *
     * @param \stdClass $row A row of usage_report::by_user().
     * @return array
     */
    private function export_user(\stdClass $row): array {
        return ['name' => $row->name ?: get_string('usage:deleteduser', 'local_aicoursebuilder')] + $this->export_sums($row);
    }

    /**
     * Formats a row of the models table.
     *
     * @param \stdClass $row A row of usage_report::by_model().
     * @return array
     */
    private function export_model(\stdClass $row): array {
        return ['connector' => $row->connector, 'model' => $row->model] + $this->export_sums($row);
    }

    /**
     * Formats a row of the months table.
     *
     * @param \stdClass $row A row of usage_report::by_month().
     * @return array
     */
    private function export_month(\stdClass $row): array {
        return ['period' => $row->period] + $this->export_sums($row);
    }
}
