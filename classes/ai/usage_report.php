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

/**
 * Reads the AI spending back: tokens and cost per job, user, month and model, and how the cost limits stand.
 *
 * The figures come from local_aicb_ailog, which has one row per call to a provider, so the report shows what the
 * providers were really paid, retries included, and not what the pipeline estimated. The limits come from
 * local_aicb_budget, the rows budget_guard reserves against. A period is a month, YYYY-MM, or an empty string for
 * all the time the log goes back.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_report {
    /** @var string State of a limit that is not near. */
    public const STATE_OK = 'ok';

    /** @var string State of a limit whose alert percentage was reached. */
    public const STATE_ALERT = 'alert';

    /** @var string State of a limit that is used up. */
    public const STATE_EXCEEDED = 'exceeded';

    /** @var int How many months the month table goes back, this one included. */
    public const MONTHS = 12;

    /** @var int The most jobs the job table lists, the costliest first. */
    public const MAXJOBS = 100;

    /**
     * Tells whether a string names a period the report can be run for.
     *
     * @param string $period Month, YYYY-MM, or an empty string for all time.
     * @return bool
     */
    public static function is_period(string $period): bool {
        return $period === '' || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    /**
     * Returns the sums over a period.
     *
     * @param string $period Month, YYYY-MM, or an empty string for all time.
     * @return \stdClass cost, tokensin, tokensout, tokenscached, calls and errors.
     */
    public function totals(string $period): \stdClass {
        [$where, $params] = $this->period_sql($period);
        $rows = $this->aggregate("SELECT %s FROM {local_aicb_ailog} l $where", $params);
        return $rows[0] ?? $this->empty_row();
    }

    /**
     * Returns the sums per job, the costliest first.
     *
     * @param string $period Month, YYYY-MM, or an empty string for all time.
     * @return \stdClass[] jobid (0 for calls that belong to no job), userid, the sums, and the job's prompt and status.
     */
    public function by_job(string $period): array {
        global $DB;

        [$where, $params] = $this->period_sql($period);
        $rows = $this->aggregate(
            "SELECT COALESCE(l.jobid, 0) AS jobid, l.userid, %s FROM {local_aicb_ailog} l $where
              GROUP BY COALESCE(l.jobid, 0), l.userid ORDER BY SUM(l.cost) DESC",
            $params,
            self::MAXJOBS
        );
        $jobs = $rows ? $DB->get_records_list('local_aicb_job', 'id', array_column($rows, 'jobid'), '', 'id, prompt, status') : [];
        foreach ($rows as $row) {
            $row->prompt = isset($jobs[$row->jobid]) ? shorten_text(format_string($jobs[$row->jobid]->prompt), 80) : '';
            $row->status = $jobs[$row->jobid]->status ?? '';
        }
        return $rows;
    }

    /**
     * Returns the sums per user, the costliest first.
     *
     * @param string $period Month, YYYY-MM, or an empty string for all time.
     * @return \stdClass[] userid, the sums and the full name of the user.
     */
    public function by_user(string $period): array {
        [$where, $params] = $this->period_sql($period);
        $rows = $this->aggregate(
            "SELECT l.userid, %s FROM {local_aicb_ailog} l $where GROUP BY l.userid ORDER BY SUM(l.cost) DESC",
            $params
        );
        $names = $this->names(array_column($rows, 'userid'));
        foreach ($rows as $row) {
            $row->name = $names[$row->userid] ?? '';
        }
        return $rows;
    }

    /**
     * Returns the sums per model, the costliest first.
     *
     * @param string $period Month, YYYY-MM, or an empty string for all time.
     * @return \stdClass[] connector, model and the sums.
     */
    public function by_model(string $period): array {
        [$where, $params] = $this->period_sql($period);
        return $this->aggregate(
            "SELECT l.connector, l.model, %s FROM {local_aicb_ailog} l $where
              GROUP BY l.connector, l.model ORDER BY SUM(l.cost) DESC",
            $params
        );
    }

    /**
     * Returns the sums of each of the last months, the latest first.
     *
     * @param int|null $now The time the months are counted back from, null for now.
     * @return \stdClass[] period (YYYY-MM) and the sums; a month without calls is there with zeros.
     */
    public function by_month(?int $now = null): array {
        $now ??= time();
        $months = [];
        for ($back = 0; $back < self::MONTHS; $back++) {
            $period = date('Y-m', mktime(0, 0, 0, (int) date('n', $now) - $back, 1, (int) date('Y', $now)));
            $row = $this->totals($period);
            $row->period = $period;
            $months[] = $row;
        }
        return $months;
    }

    /**
     * Returns the months a period can be chosen from, the latest first.
     *
     * @param int|null $now The time the months are counted back from, null for now.
     * @return string[] Months, YYYY-MM.
     */
    public function periods(?int $now = null): array {
        $now ??= time();
        $periods = [];
        for ($back = 0; $back < self::MONTHS; $back++) {
            $periods[] = date('Y-m', mktime(0, 0, 0, (int) date('n', $now) - $back, 1, (int) date('Y', $now)));
        }
        return $periods;
    }

    /**
     * Returns how the monthly limits of a month stand: the site's, and those of the users that spent in it.
     *
     * The limit is the one in the row, or the default of the settings when the row has none; a limit of 0 is no limit.
     * Spending counts what is reserved for running jobs as well, which is what budget_guard holds against the limit.
     *
     * @param string $period Month, YYYY-MM.
     * @return \stdClass[] userid (0 for the site), name, limit, spent, reserved, percent (0 without a limit) and
     *                     state, the site first and then the most used.
     */
    public function limits(string $period): array {
        global $DB;

        $alertpercent = (float) get_config('local_aicoursebuilder', 'alertpercent');
        $rows = [];
        foreach ($DB->get_records('local_aicb_budget', ['period' => $period], 'userid') as $row) {
            $issite = (int) $row->userid === budget_guard::SITE_USERID;
            $limit = $row->limitusd !== null
                ? (float) $row->limitusd
                : (float) get_config('local_aicoursebuilder', $issite ? 'sitelimitusd' : 'userlimitusd');
            $used = (float) $row->spentusd + (float) $row->reservedusd;
            $percent = $limit > 0 ? $used / $limit * 100 : 0.0;
            $rows[] = (object) [
                'userid' => (int) $row->userid,
                'limit' => $limit,
                'spent' => (float) $row->spentusd,
                'reserved' => (float) $row->reservedusd,
                'percent' => $percent,
                'state' => match (true) {
                    $limit <= 0 => self::STATE_OK,
                    $used >= $limit => self::STATE_EXCEEDED,
                    $alertpercent > 0 && $percent >= $alertpercent => self::STATE_ALERT,
                    default => self::STATE_OK,
                },
            ];
        }
        $names = $this->names(array_column($rows, 'userid'));
        foreach ($rows as $row) {
            $row->name = $row->userid === budget_guard::SITE_USERID ? '' : ($names[$row->userid] ?? '');
        }
        usort($rows, fn($a, $b) => [$a->userid !== budget_guard::SITE_USERID, -$a->percent]
            <=> [$b->userid !== budget_guard::SITE_USERID, -$b->percent]);
        return $rows;
    }

    /**
     * Returns the WHERE clause of a period.
     *
     * @param string $period Month, YYYY-MM, or an empty string for all time.
     * @return array [sql, params]
     * @throws \coding_exception When the period is not one.
     */
    private function period_sql(string $period): array {
        if (!self::is_period($period)) {
            throw new \coding_exception('Not a period: ' . $period);
        }
        if ($period === '') {
            return ['', []];
        }
        $from = (int) strtotime($period . '-01 00:00:00');
        return ['WHERE l.timecreated >= :periodfrom AND l.timecreated < :periodto', [
            'periodfrom' => $from,
            'periodto' => (int) strtotime('+1 month', $from),
        ]];
    }

    /**
     * Runs a query that sums the log, and returns its rows with the sums as numbers.
     *
     * The query holds %s where the sums go: the cost, the tokens, the number of calls and how many of them failed.
     *
     * @param string $sql The query, with %s for the sums.
     * @param array $params Its parameters.
     * @param int $limit The most rows to return, 0 for all.
     * @return \stdClass[] Rows, numbered from 0.
     */
    private function aggregate(string $sql, array $params, int $limit = 0): array {
        global $DB;

        $sums = 'SUM(l.cost) AS sumcost, SUM(l.tokensin) AS sumin, SUM(l.tokensout) AS sumout, '
            . 'SUM(l.tokenscached) AS sumcached, COUNT(1) AS calls, '
            . 'SUM(CASE WHEN l.status = :errorstatus THEN 1 ELSE 0 END) AS errors';
        $params['errorstatus'] = 'error';

        $rows = [];
        $recordset = $DB->get_recordset_sql(sprintf($sql, $sums), $params, 0, $limit);
        foreach ($recordset as $record) {
            $row = (object) array_diff_key((array) $record, array_flip(['sumcost', 'sumin', 'sumout', 'sumcached']));
            $row->cost = (float) $record->sumcost;
            $row->tokensin = (int) $record->sumin;
            $row->tokensout = (int) $record->sumout;
            $row->tokenscached = (int) $record->sumcached;
            $row->calls = (int) $record->calls;
            $row->errors = (int) $record->errors;
            foreach (['jobid', 'userid'] as $key) {
                if (isset($row->{$key})) {
                    $row->{$key} = (int) $row->{$key};
                }
            }
            $rows[] = $row;
        }
        $recordset->close();
        return $rows;
    }

    /**
     * Returns a row of zeros, for a period with no calls.
     *
     * @return \stdClass
     */
    private function empty_row(): \stdClass {
        return (object) ['cost' => 0.0, 'tokensin' => 0, 'tokensout' => 0, 'tokenscached' => 0, 'calls' => 0, 'errors' => 0];
    }

    /**
     * Returns the full names of some users.
     *
     * @param int[] $userids User ids.
     * @return string[] Full name per user id, for the users that exist.
     */
    private function names(array $userids): array {
        global $DB;

        $userids = array_filter(array_unique($userids));
        if (!$userids) {
            return [];
        }
        $names = [];
        foreach ($DB->get_records_list('user', 'id', $userids) as $user) {
            $names[(int) $user->id] = fullname($user);
        }
        return $names;
    }
}
