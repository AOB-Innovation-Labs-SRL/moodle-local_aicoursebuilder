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
 * Tests for the cost notifications: the alert threshold, and the job that a cost limit stops.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\budget_notifier
 * @covers     \local_aicoursebuilder\ai\budget_guard
 */
final class budget_notifier_test extends \advanced_testcase {
    /** @var \stdClass The user who spends. */
    private \stdClass $user;

    /** @var \stdClass A manager, who may raise the limits. */
    private \stdClass $manager;

    /** @var int A job of the user. */
    private int $jobid;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        $this->manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/aicoursebuilder:manage', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $this->manager->id, \context_system::instance()->id);
        $this->jobid = (int) $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'mode' => 'newcourse',
            'status' => 'generating',
            'prompt' => 'Un curs despre energia solară',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '10', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');
        set_config('alertpercent', '80', 'local_aicoursebuilder');
    }

    /**
     * Reserves and settles a call, as the pipeline does.
     *
     * @param float $cost What the call cost, in USD.
     */
    private function spend(float $cost): void {
        $guard = new budget_guard(new fake_lock_factory());
        $guard->settle($guard->reserve($this->jobid, (int) $this->user->id, $cost), $cost);
    }

    /**
     * Reaching the alert percentage tells the user and the managers, once.
     */
    public function test_the_alert_is_sent_once_to_the_user_and_the_managers(): void {
        $sink = $this->redirectMessages();

        $this->spend(7.0);
        $this->assertSame([], $sink->get_messages());

        $this->spend(1.5);
        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        $recipients = array_map(fn($message) => (int) $message->useridto, $messages);
        $this->assertEqualsCanonicalizing([(int) $this->user->id, (int) $this->manager->id], $recipients);
        foreach ($messages as $message) {
            $this->assertSame('budgetalert', $message->eventtype);
            $this->assertStringContainsString('85%', $message->subject);
            $this->assertStringContainsString('8.50', $message->fullmessage);
        }

        // Once the threshold is passed, the next calls do not tell it again.
        $this->spend(0.5);
        $this->assertCount(2, $sink->get_messages());
    }

    /**
     * The alert is armed again when the spending is back under the threshold, which raising the limit does.
     */
    public function test_the_alert_is_armed_again_when_the_limit_is_raised(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $this->spend(9.0);
        $this->assertCount(2, $sink->get_messages());
        $sink->clear();

        set_config('userlimitusd', '100', 'local_aicoursebuilder');
        $this->spend(0.5);
        $this->assertSame([], $sink->get_messages());
        $this->assertEquals(0, $DB->get_field('local_aicb_budget', 'alertsent', [
            'userid' => $this->user->id,
            'period' => budget_guard::current_period(),
        ]));

        $this->spend(80.0);
        $this->assertCount(2, $sink->get_messages());
    }

    /**
     * The site's spending is told to the managers, and to nobody else.
     */
    public function test_the_site_alert_goes_to_the_managers(): void {
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        set_config('sitelimitusd', '10', 'local_aicoursebuilder');
        $sink = $this->redirectMessages();

        $this->spend(9.0);

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($this->manager->id, $messages[0]->useridto);
        $this->assertStringContainsString(get_string('usage:site', 'local_aicoursebuilder'), $messages[0]->fullmessage);
    }

    /**
     * No limit and no percentage mean no alert.
     */
    public function test_no_alert_without_a_limit_or_a_percentage(): void {
        $sink = $this->redirectMessages();
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        $this->spend(500.0);
        $this->assertSame([], $sink->get_messages());

        set_config('userlimitusd', '10000', 'local_aicoursebuilder');
        set_config('alertpercent', '0', 'local_aicoursebuilder');
        $this->spend(9000.0);
        $this->assertSame([], $sink->get_messages());
    }

    /**
     * A suspended user is not told.
     */
    public function test_a_suspended_user_is_not_told(): void {
        global $DB;
        $DB->set_field('user', 'suspended', 1, ['id' => $this->manager->id]);
        $sink = $this->redirectMessages();

        $this->spend(9.0);

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($this->user->id, $messages[0]->useridto);
    }

    /**
     * A job that a limit stopped is told to its owner and the managers, with the way to the job.
     */
    public function test_a_stopped_job_is_told_to_its_owner_and_the_managers(): void {
        global $DB;
        $sink = $this->redirectMessages();

        (new budget_notifier())->exceeded($DB->get_record('local_aicb_job', ['id' => $this->jobid]), 'Plafonul a fost atins.');

        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertSame('budgetexceeded', $message->eventtype);
            $this->assertStringContainsString('Un curs despre energia solară', $message->fullmessage);
            $this->assertStringContainsString('Plafonul a fost atins.', $message->fullmessage);
            $this->assertStringContainsString('/local/aicoursebuilder/job.php?id=' . $this->jobid, $message->contexturl);
        }
    }

    /**
     * Only those who may open the usage report get the link to it.
     */
    public function test_only_those_who_may_view_the_usage_get_its_link(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/aicoursebuilder:viewusage', CAP_ALLOW, $roleid, \context_system::instance()->id);
        assign_capability('local/aicoursebuilder:manage', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $viewer->id, \context_system::instance()->id);
        $sink = $this->redirectMessages();

        $this->spend(9.0);

        $links = [];
        foreach ($sink->get_messages() as $message) {
            $links[(int) $message->useridto] = (string) $message->contexturl;
        }
        $this->assertStringContainsString('/local/aicoursebuilder/usage.php', $links[(int) $viewer->id]);
        $this->assertSame('', $links[(int) $this->user->id]);
        $this->assertSame('', $links[(int) $this->manager->id]);
    }
}
