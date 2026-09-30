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
 * Tests for the budget guard: reservation, settlement, release and the cost limits.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\budget_guard
 * @covers     \local_aicoursebuilder\ai\budget_exceeded_exception
 * @covers     \local_aicoursebuilder\ai\budget_lock_exception
 * @covers     \local_aicoursebuilder\ai\fake_lock_factory
 */
final class budget_guard_test extends \advanced_testcase {
    /** @var int Test user id. */
    private int $userid;

    /** @var int Test job id. */
    private int $jobid;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $DB;
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->jobid = (int) $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->userid,
            'mode' => 'newcourse',
            'status' => 'generating',
            'prompt' => 'x',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');
        set_config('alertpercent', '80', 'local_aicoursebuilder');
    }

    /**
     * Returns the budget row of a user (or SITE_USERID) for the current period.
     *
     * @param int $userid User id, or budget_guard::SITE_USERID.
     * @return \stdClass
     */
    private function row(int $userid): \stdClass {
        global $DB;
        return $DB->get_record(
            'local_aicb_budget',
            ['userid' => $userid, 'period' => budget_guard::current_period()],
            '*',
            MUST_EXIST
        );
    }

    /**
     * A reservation under every limit succeeds and increments reservedusd for the user and the site.
     */
    public function test_reserve_under_limit(): void {
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('sitelimitusd', '100', 'local_aicoursebuilder');

        $reservation = (new budget_guard())->reserve($this->jobid, $this->userid, 2.5);

        $this->assertSame(2.5, (float) $this->row($this->userid)->reservedusd);
        $this->assertSame(2.5, (float) $this->row(budget_guard::SITE_USERID)->reservedusd);
        $this->assertSame(['jobid' => $this->jobid, 'userid' => $this->userid, 'period' => budget_guard::current_period(),
            'estimatedusd' => 2.5], $reservation);
    }

    /**
     * A reservation over the per-user limit throws before any row is left with a partial reservation.
     */
    public function test_reserve_over_user_limit_throws_and_leaves_no_trace(): void {
        set_config('userlimitusd', '5', 'local_aicoursebuilder');
        set_config('sitelimitusd', '100', 'local_aicoursebuilder');

        try {
            (new budget_guard())->reserve($this->jobid, $this->userid, 5.01);
            $this->fail('Reservation over the user limit accepted');
        } catch (budget_exceeded_exception $e) {
            $this->assertSame(budget_exceeded_exception::SCOPE_USER, $e->scope);
            $this->assertSame(5.0, $e->limitusd);
        }
        $this->assertSame(0.0, (float) $this->row($this->userid)->reservedusd);
    }

    /**
     * A reservation over the site limit releases the user reservation it had already made.
     */
    public function test_reserve_over_site_limit_releases_user_reservation(): void {
        set_config('userlimitusd', '100', 'local_aicoursebuilder');
        set_config('sitelimitusd', '5', 'local_aicoursebuilder');

        try {
            (new budget_guard())->reserve($this->jobid, $this->userid, 5.01);
            $this->fail('Reservation over the site limit accepted');
        } catch (budget_exceeded_exception $e) {
            $this->assertSame(budget_exceeded_exception::SCOPE_SITE, $e->scope);
        }
        $this->assertSame(0.0, (float) $this->row($this->userid)->reservedusd);
        $this->assertSame(0.0, (float) $this->row(budget_guard::SITE_USERID)->reservedusd);
    }

    /**
     * A job whose actual cost plus the new estimate exceeds the job limit throws before any reservation.
     */
    public function test_reserve_over_job_limit_throws_before_any_reservation(): void {
        global $DB;
        set_config('joblimitusd', '1', 'local_aicoursebuilder');
        $DB->set_field('local_aicb_job', 'actualcost', 0.9, ['id' => $this->jobid]);

        try {
            (new budget_guard())->reserve($this->jobid, $this->userid, 0.2);
            $this->fail('Reservation over the job limit accepted');
        } catch (budget_exceeded_exception $e) {
            $this->assertSame(budget_exceeded_exception::SCOPE_JOB, $e->scope);
            $this->assertSame(1.0, $e->limitusd);
        }
        global $DB;
        $this->assertFalse($DB->record_exists(
            'local_aicb_budget',
            ['userid' => $this->userid, 'period' => budget_guard::current_period()]
        ));
    }

    /**
     * settle() moves the amount from reservedusd to spentusd, at the actual cost.
     */
    public function test_settle_moves_reserved_to_spent(): void {
        $guard = new budget_guard();
        $reservation = $guard->reserve($this->jobid, $this->userid, 2.0);
        $guard->settle($reservation, 1.5);

        $userrow = $this->row($this->userid);
        $siterow = $this->row(budget_guard::SITE_USERID);
        $this->assertSame(0.0, (float) $userrow->reservedusd);
        $this->assertSame(1.5, (float) $userrow->spentusd);
        $this->assertSame(0.0, (float) $siterow->reservedusd);
        $this->assertSame(1.5, (float) $siterow->spentusd);
    }

    /**
     * release() clears the reservation without touching spentusd, after a failed call.
     */
    public function test_release_clears_reservation_without_spending(): void {
        $guard = new budget_guard();
        $reservation = $guard->reserve($this->jobid, $this->userid, 2.0);
        $guard->release($reservation);

        $userrow = $this->row($this->userid);
        $this->assertSame(0.0, (float) $userrow->reservedusd);
        $this->assertSame(0.0, (float) $userrow->spentusd);
    }

    /**
     * settle() marks alertsent once spending reaches the alert percentage of the limit.
     */
    public function test_settle_marks_alert(): void {
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('alertpercent', '80', 'local_aicoursebuilder');

        $guard = new budget_guard();
        $reservation = $guard->reserve($this->jobid, $this->userid, 5.0);
        $guard->settle($reservation, 5.0);
        $this->assertSame(0, (int) $this->row($this->userid)->alertsent);

        $reservation = $guard->reserve($this->jobid, $this->userid, 3.0);
        $guard->settle($reservation, 3.0);
        $this->assertSame(1, (int) $this->row($this->userid)->alertsent);
    }

    /**
     * Two reservations that together exceed the limit: exactly one succeeds, the other is refused,
     * and the row ends up with exactly the accepted amount reserved (nothing lost, nothing doubled).
     */
    public function test_concurrent_reservations_cannot_both_exceed_limit(): void {
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('sitelimitusd', '1000', 'local_aicoursebuilder');

        $guard = new budget_guard();
        $guard->reserve($this->jobid, $this->userid, 6.0);

        try {
            $guard->reserve($this->jobid, $this->userid, 6.0);
            $this->fail('A second reservation pushed the row past its limit');
        } catch (budget_exceeded_exception $e) {
            $this->assertSame(budget_exceeded_exception::SCOPE_USER, $e->scope);
        }
        $this->assertSame(6.0, (float) $this->row($this->userid)->reservedusd);
    }

    /**
     * Reserving against a row whose lock is already held by another process fails fast with
     * budget_lock_exception, instead of racing the read-check-write with whoever holds the lock.
     *
     * Real lock factories (Postgres advisory locks, and the like) are scoped to the DB session, so
     * a single PHPUnit run cannot simulate contention on the real factory: the test and the code
     * under test share the same connection, and the "second" acquisition would simply reenter. A
     * fake_lock_factory tracks held resources in PHP memory instead, so hold_externally() genuinely
     * blocks the guard's own get_lock() call, as a second process would.
     */
    public function test_reserve_fails_when_lock_is_already_held(): void {
        $period = budget_guard::current_period();
        $resource = "budget_{$this->userid}_{$period}";
        $lockfactory = new fake_lock_factory();
        $lockfactory->hold_externally($resource);

        try {
            (new budget_guard($lockfactory))->reserve($this->jobid, $this->userid, 1.0);
            $this->fail('Reservation succeeded while the row lock was held elsewhere');
        } catch (budget_lock_exception $e) {
            $this->assertSame($resource, $e->resource);
        }

        // Nothing was reserved: the row was never touched while the lock was unavailable.
        global $DB;
        $this->assertFalse($DB->record_exists('local_aicb_budget', ['userid' => $this->userid, 'period' => $period]));
    }

    /**
     * The site row's lock is independent from a user row's lock: holding one does not block the
     * other, but the user row's own reservation is rolled back when the site one then fails.
     */
    public function test_user_and_site_locks_are_independent(): void {
        $period = budget_guard::current_period();
        $lockfactory = new fake_lock_factory();
        $lockfactory->hold_externally("budget_0_{$period}");

        try {
            (new budget_guard($lockfactory))->reserve($this->jobid, $this->userid, 1.0);
            $this->fail('Reservation succeeded while the site row lock was held elsewhere');
        } catch (budget_lock_exception $e) {
            $this->assertSame("budget_0_{$period}", $e->resource);
        }

        // The user row was reserved (its own lock was free) and then rolled back when the site
        // reservation failed: it must not be left with a stray reservation.
        $this->assertSame(0.0, (float) $this->row($this->userid)->reservedusd);
    }

    /**
     * The lock is released after a successful reservation: a second reservation against the same
     * row, using the same fake lock factory, is not blocked by the first one's now-released lock.
     */
    public function test_lock_is_released_after_a_successful_reservation(): void {
        $lockfactory = new fake_lock_factory();
        $guard = new budget_guard($lockfactory);

        $guard->reserve($this->jobid, $this->userid, 1.0);
        $guard->reserve($this->jobid, $this->userid, 1.0);

        $this->assertSame(2.0, (float) $this->row($this->userid)->reservedusd);
    }

    /**
     * The lock is released even when the reservation is refused for exceeding the limit.
     */
    public function test_lock_is_released_after_a_refused_reservation(): void {
        set_config('userlimitusd', '1', 'local_aicoursebuilder');
        $lockfactory = new fake_lock_factory();
        $guard = new budget_guard($lockfactory);

        try {
            $guard->reserve($this->jobid, $this->userid, 2.0);
            $this->fail('Reservation over the limit accepted');
        } catch (budget_exceeded_exception $e) {
            $this->assertSame(budget_exceeded_exception::SCOPE_USER, $e->scope);
        }

        // A second attempt (small enough to fit) must not be blocked by a leftover lock.
        $guard->reserve($this->jobid, $this->userid, 0.5);
        $this->assertSame(0.5, (float) $this->row($this->userid)->reservedusd);
    }

    /**
     * A limit of 0 means no limit: reservations of any size succeed.
     */
    public function test_zero_limit_means_unlimited(): void {
        $guard = new budget_guard();
        $reservation = $guard->reserve($this->jobid, $this->userid, 999.0);
        $this->assertSame(999.0, (float) $this->row($this->userid)->reservedusd);
        $guard->release($reservation);
    }
}
