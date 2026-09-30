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
 * Reserves and settles AI spend against the per-job, per-user and site monthly limits (spec 3.8).
 *
 * A reservation is made per sub-call, before it is sent, against local_aicb_budget.reservedusd of
 * the user row and the site row (userid 0), for the current calendar month. Read-check-write is not
 * atomic by itself, so reserve_row() takes a Moodle lock (\core\lock\lock_config) on the row's own
 * resource key (budget_{userid}_{period}) around the whole read, limit check and write: two runners
 * reserving against the same row serialise on that lock, and the second one re-reads the row the
 * first one already committed. The conditional UPDATE (WHERE spentusd + reservedusd + ? <= limit) is
 * kept as a second safety net in case a lock is ever bypassed or misconfigured, but the lock is what
 * actually guarantees correctness under concurrency; a lock that cannot be obtained within its
 * timeout raises budget_lock_exception rather than silently racing.
 * The job's own limit has no persistent row: it is checked against local_aicb_job.estimatedcost and
 * actualcost, which the orchestrator maintains, so it is checked here but not reserved here.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget_guard {
    /** @var string userid used for the whole-site row. */
    public const SITE_USERID = 0;

    /** @var string Lock type namespace passed to lock_config::get_lock_factory(). */
    public const LOCK_TYPE = 'local_aicoursebuilder';

    /** @var int Seconds to wait for a row's lock before giving up. */
    public const LOCK_TIMEOUT = 5;

    /** @var \core\lock\lock_factory Lock factory used to serialise reservations per row. */
    protected \core\lock\lock_factory $lockfactory;

    /**
     * Creates the guard.
     *
     * @param \core\lock\lock_factory|null $lockfactory Lock factory, null for the site's configured
     *                                                   one (\core\lock\lock_config::get_lock_factory()).
     */
    public function __construct(?\core\lock\lock_factory $lockfactory = null) {
        $this->lockfactory = $lockfactory ?? \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
    }

    /**
     * Reserves the estimated cost of a sub-call against the job, the user and the site limits.
     *
     * @param int $jobid Job the call belongs to.
     * @param int $userid User the call runs for.
     * @param float $estimatedusd Estimated cost of the call, in USD.
     * @return array{jobid: int, userid: int, period: string, estimatedusd: float} Reservation token
     *         for settle() or release().
     * @throws budget_exceeded_exception When a limit would be exceeded.
     */
    public function reserve(int $jobid, int $userid, float $estimatedusd): array {
        $period = self::current_period();

        $this->check_job_limit($jobid, $estimatedusd);
        $this->reserve_row($userid, $period, $estimatedusd, budget_exceeded_exception::SCOPE_USER);
        try {
            $this->reserve_row(self::SITE_USERID, $period, $estimatedusd, budget_exceeded_exception::SCOPE_SITE);
        } catch (budget_exceeded_exception | budget_lock_exception $e) {
            $this->release_row($userid, $period, $estimatedusd);
            throw $e;
        }

        return ['jobid' => $jobid, 'userid' => $userid, 'period' => $period, 'estimatedusd' => $estimatedusd];
    }

    /**
     * Moves a reservation from reservedusd to spentusd, at its actual cost.
     *
     * @param array $reservation Token returned by reserve().
     * @param float $actualusd Actual cost of the call, in USD.
     */
    public function settle(array $reservation, float $actualusd): void {
        $this->release_row($reservation['userid'], $reservation['period'], $reservation['estimatedusd']);
        $this->release_row(self::SITE_USERID, $reservation['period'], $reservation['estimatedusd']);
        $this->add_spent($reservation['userid'], $reservation['period'], $actualusd);
        $this->add_spent(self::SITE_USERID, $reservation['period'], $actualusd);
        $this->maybe_mark_alert($reservation['userid'], $reservation['period']);
        $this->maybe_mark_alert(self::SITE_USERID, $reservation['period']);
    }

    /**
     * Releases a reservation without settling any spend, after a failed call.
     *
     * @param array $reservation Token returned by reserve().
     */
    public function release(array $reservation): void {
        $this->release_row($reservation['userid'], $reservation['period'], $reservation['estimatedusd']);
        $this->release_row(self::SITE_USERID, $reservation['period'], $reservation['estimatedusd']);
    }

    /**
     * Checks the job's own cost limit; the job has no reservation row of its own.
     *
     * @param int $jobid Job id.
     * @param float $estimatedusd Estimated cost of the call about to be reserved, in USD.
     * @throws budget_exceeded_exception When the job's limit would be exceeded.
     */
    protected function check_job_limit(int $jobid, float $estimatedusd): void {
        global $DB;
        $limit = (float) get_config('local_aicoursebuilder', 'joblimitusd');
        if ($limit <= 0) {
            return;
        }
        $job = $DB->get_record('local_aicb_job', ['id' => $jobid], 'estimatedcost, actualcost', MUST_EXIST);
        if ((float) $job->actualcost + $estimatedusd > $limit) {
            throw new budget_exceeded_exception(budget_exceeded_exception::SCOPE_JOB, $limit);
        }
    }

    /**
     * Reserves an amount against a budget row under its lock, creating the row if needed.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @param string $period Month, YYYY-MM.
     * @param float $amountusd Amount to reserve, in USD.
     * @param string $scope One of the budget_exceeded_exception::SCOPE_* constants, for the error.
     * @throws budget_exceeded_exception When the reservation would exceed the row's limit.
     * @throws budget_lock_exception When the row's lock cannot be obtained in time.
     */
    protected function reserve_row(int $userid, string $period, float $amountusd, string $scope): void {
        $this->with_lock($userid, $period, function () use ($userid, $period, $amountusd, $scope): void {
            $this->reserve_row_locked($userid, $period, $amountusd, $scope);
        });
    }

    /**
     * Reserves an amount against a budget row; only ever called with the row's lock already held.
     *
     * The conditional UPDATE (its WHERE clause re-checking spentusd + reservedusd against the limit)
     * is kept as a second safety net alongside the lock, see the class docblock.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @param string $period Month, YYYY-MM.
     * @param float $amountusd Amount to reserve, in USD.
     * @param string $scope One of the budget_exceeded_exception::SCOPE_* constants, for the error.
     * @throws budget_exceeded_exception When the reservation would exceed the row's limit.
     */
    protected function reserve_row_locked(int $userid, string $period, float $amountusd, string $scope): void {
        global $DB;
        $row = $this->get_or_create_row($userid, $period);
        $limit = $row->limitusd !== null ? (float) $row->limitusd : $this->default_limit($userid);

        if ($limit > 0 && (float) $row->spentusd + (float) $row->reservedusd + $amountusd > $limit) {
            throw new budget_exceeded_exception($scope, $limit);
        }

        if ($limit > 0) {
            $sql = "UPDATE {local_aicb_budget}
                       SET reservedusd = reservedusd + :amount1, timemodified = :now
                     WHERE id = :id AND spentusd + reservedusd + :amount2 <= :limit";
            $DB->execute($sql, [
                'amount1' => $amountusd,
                'amount2' => $amountusd,
                'now' => time(),
                'id' => $row->id,
                'limit' => $limit,
            ]);
            $after = $DB->get_field('local_aicb_budget', 'reservedusd', ['id' => $row->id], MUST_EXIST);
            if (round((float) $after - (float) $row->reservedusd, 6) < round($amountusd, 6)) {
                throw new budget_exceeded_exception($scope, $limit);
            }
            return;
        }

        $DB->execute(
            'UPDATE {local_aicb_budget} SET reservedusd = reservedusd + :amount, timemodified = :now WHERE id = :id',
            ['amount' => $amountusd, 'now' => time(), 'id' => $row->id]
        );
    }

    /**
     * Runs a callback with the lock of a budget row held, released afterwards even on exception.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @param string $period Month, YYYY-MM.
     * @param callable $callback function(): void, run with the lock held.
     * @throws budget_lock_exception When the lock cannot be obtained within LOCK_TIMEOUT.
     */
    protected function with_lock(int $userid, string $period, callable $callback): void {
        $resource = "budget_{$userid}_{$period}";
        $lock = $this->lockfactory->get_lock($resource, self::LOCK_TIMEOUT);
        if ($lock === false) {
            throw new budget_lock_exception($resource);
        }
        try {
            $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Releases a previously reserved amount from a budget row.
     *
     * Never races with another release or reserve of the same row in a way that matters: reserve()
     * is the only operation that must refuse to exceed a limit, and it is atomic on its own (see
     * reserve_row()); a release only ever gives reservedusd back down, so a plain read-then-write is
     * enough here, clamped at zero in case of a rounding drift between reserve and release amounts.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @param string $period Month, YYYY-MM.
     * @param float $amountusd Amount to release, in USD.
     */
    protected function release_row(int $userid, string $period, float $amountusd): void {
        global $DB;
        $row = $DB->get_record('local_aicb_budget', ['userid' => $userid, 'period' => $period], '*', MUST_EXIST);
        $DB->set_field('local_aicb_budget', 'reservedusd', max(0.0, round((float) $row->reservedusd - $amountusd, 6)), [
            'id' => $row->id,
        ]);
        $DB->set_field('local_aicb_budget', 'timemodified', time(), ['id' => $row->id]);
    }

    /**
     * Adds an actual cost to a budget row's spentusd.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @param string $period Month, YYYY-MM.
     * @param float $amountusd Amount spent, in USD.
     */
    protected function add_spent(int $userid, string $period, float $amountusd): void {
        global $DB;
        $DB->execute(
            'UPDATE {local_aicb_budget} SET spentusd = spentusd + :amount, timemodified = :now
              WHERE userid = :userid AND period = :period',
            ['amount' => $amountusd, 'now' => time(), 'userid' => $userid, 'period' => $period]
        );
    }

    /**
     * Marks alertsent when a row has reached the alert threshold; never sends anything.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @param string $period Month, YYYY-MM.
     */
    protected function maybe_mark_alert(int $userid, string $period): void {
        global $DB;
        $row = $DB->get_record('local_aicb_budget', ['userid' => $userid, 'period' => $period], '*', MUST_EXIST);
        if ($row->alertsent) {
            return;
        }
        $limit = $row->limitusd !== null ? (float) $row->limitusd : $this->default_limit($userid);
        if ($limit <= 0) {
            return;
        }
        $percent = (float) get_config('local_aicoursebuilder', 'alertpercent');
        if (((float) $row->spentusd / $limit) * 100 >= $percent) {
            $DB->set_field('local_aicb_budget', 'alertsent', 1, ['id' => $row->id]);
        }
    }

    /**
     * Returns the default monthly limit of a scope, from the settings.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @return float
     */
    protected function default_limit(int $userid): float {
        $key = $userid === self::SITE_USERID ? 'sitelimitusd' : 'userlimitusd';
        return (float) get_config('local_aicoursebuilder', $key);
    }

    /**
     * Returns the budget row of a user and period, creating it with zero spend if it does not exist.
     *
     * @param int $userid User id, or SITE_USERID for the site row.
     * @param string $period Month, YYYY-MM.
     * @return \stdClass
     */
    protected function get_or_create_row(int $userid, string $period): \stdClass {
        global $DB;
        $row = $DB->get_record('local_aicb_budget', ['userid' => $userid, 'period' => $period]);
        if ($row) {
            return $row;
        }
        $now = time();
        try {
            $id = $DB->insert_record('local_aicb_budget', (object) [
                'userid' => $userid,
                'period' => $period,
                'limitusd' => null,
                'spentusd' => 0,
                'reservedusd' => 0,
                'alertsent' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } catch (\dml_exception $e) {
            // Another runner inserted the row for this user and period first.
            return $DB->get_record('local_aicb_budget', ['userid' => $userid, 'period' => $period], '*', MUST_EXIST);
        }
        return $DB->get_record('local_aicb_budget', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Returns the current calendar month, YYYY-MM, in server time.
     *
     * @return string
     */
    public static function current_period(): string {
        return date('Y-m');
    }
}
