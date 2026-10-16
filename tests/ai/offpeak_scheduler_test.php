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

use local_aicoursebuilder\task\generate_blueprint;

/**
 * Tests of the off-peak scheduling: the next off-peak moment, and the delay of a task.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\offpeak_scheduler
 */
final class offpeak_scheduler_test extends \advanced_testcase {
    /**
     * Returns a UTC moment.
     *
     * @param string $datetime Date and time, UTC.
     * @return int
     */
    private function at(string $datetime): int {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * A moment that is off-peak already is the answer.
     */
    public function test_an_offpeak_moment_is_returned_as_it_is(): void {
        $this->resetAfterTest();
        // Wednesday 05:30 UTC is between the two peak windows of the default schedule (01-04 and 06-10).
        $now = $this->at('2026-10-14 05:30:00');
        $this->assertSame($now, offpeak_scheduler::next_offpeak('deepseek', $now));
        // So is the weekend.
        $saturday = $this->at('2026-10-17 07:15:00');
        $this->assertSame($saturday, offpeak_scheduler::next_offpeak('deepseek', $saturday));
    }

    /**
     * Inside a peak window the answer is the start of the first hour after it.
     */
    public function test_a_peak_moment_waits_for_the_end_of_the_window(): void {
        $this->resetAfterTest();
        $this->assertSame(
            $this->at('2026-10-14 04:00:00'),
            offpeak_scheduler::next_offpeak('deepseek', $this->at('2026-10-14 02:20:00'))
        );
        $this->assertSame(
            $this->at('2026-10-14 10:00:00'),
            offpeak_scheduler::next_offpeak('deepseek', $this->at('2026-10-14 09:59:59'))
        );
    }

    /**
     * The windows of the settings replace the default ones.
     */
    public function test_the_windows_are_configurable(): void {
        $this->resetAfterTest();
        // Peak all day on Wednesday (ISO weekday 3).
        set_config('peakwindows_deepseek', '{"3": [[0, 24]]}', 'local_aicoursebuilder');

        $this->assertSame(
            $this->at('2026-10-15 00:00:00'),
            offpeak_scheduler::next_offpeak('deepseek', $this->at('2026-10-14 12:00:00'))
        );
    }

    /**
     * A connector that is peak all the time cannot be waited for: the task is not delayed.
     */
    public function test_a_connector_that_is_never_offpeak_is_not_delayed(): void {
        $this->resetAfterTest();
        $windows = [];
        for ($day = 1; $day <= 7; $day++) {
            $windows[$day] = [[0, 24]];
        }
        set_config('peakwindows_deepseek', json_encode($windows), 'local_aicoursebuilder');
        $now = $this->at('2026-10-14 03:00:00');

        $this->assertSame($now, offpeak_scheduler::next_offpeak('deepseek', $now));
    }

    /**
     * A job that asks for it has its task delayed; the task keeps its time otherwise.
     */
    public function test_a_task_is_delayed_only_when_the_job_asks_for_it(): void {
        $this->resetAfterTest();
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        $now = $this->at('2026-10-14 02:20:00');
        $job = (object) ['id' => 7, 'userid' => 2, 'offpeak' => 1];

        $task = generate_blueprint::instance(7, 2);
        $at = offpeak_scheduler::schedule($task, $job, $now);
        $this->assertSame($this->at('2026-10-14 04:00:00'), $at);
        $this->assertSame($at, $task->get_next_run_time());

        $job->offpeak = 0;
        $immediate = generate_blueprint::instance(7, 2);
        $before = $immediate->get_next_run_time();
        $this->assertSame($now, offpeak_scheduler::schedule($immediate, $job, $now));
        $this->assertSame($before, $immediate->get_next_run_time());
    }

    /**
     * The fake connector costs nothing, so it is never delayed.
     */
    public function test_the_fake_connector_is_never_delayed(): void {
        $this->resetAfterTest();
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
        $now = $this->at('2026-10-14 02:20:00');
        $job = (object) ['id' => 7, 'userid' => 2, 'offpeak' => 1];

        $task = generate_blueprint::instance(7, 2);
        $this->assertSame($now, offpeak_scheduler::schedule($task, $job, $now));
    }
}
