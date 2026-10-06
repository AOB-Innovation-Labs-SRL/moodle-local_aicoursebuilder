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

/**
 * Tests for the list of generation jobs: whose jobs it lists, what it shows of them and where it leads.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\output\job_list
 */
final class job_list_test extends \advanced_testcase {
    /** @var \stdClass A teacher. */
    private \stdClass $ana;

    /** @var \stdClass Another teacher. */
    private \stdClass $dan;

    /** @var \stdClass A manager. */
    private \stdClass $manager;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->ana = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Popescu']);
        $this->dan = $this->getDataGenerator()->create_user(['firstname' => 'Dan', 'lastname' => 'Ionescu']);
        $this->manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $this->manager->id, \context_system::instance()->id);
    }

    /**
     * Inserts a job.
     *
     * @param \stdClass $user The owner.
     * @param string $prompt What was asked for.
     * @param array $override Fields to change.
     * @return int The job id.
     */
    private function create_job(\stdClass $user, string $prompt, array $override = []): int {
        global $DB;
        return (int) $DB->insert_record('local_aicb_job', (object) ($override + [
            'userid' => $user->id,
            'mode' => 'newcourse',
            'status' => 'review',
            'progress' => 100,
            'prompt' => $prompt,
            'language' => 'ro',
            'actualcost' => 1.5,
            'timecreated' => time(),
            'timemodified' => time(),
        ]));
    }

    /**
     * Exports the list of a user.
     *
     * @param \stdClass $viewer Who looks at it.
     * @param bool $showall Whether the jobs of everybody are asked for.
     * @param int $page The page.
     * @return \stdClass
     */
    private function export(\stdClass $viewer, bool $showall = false, int $page = 0): \stdClass {
        global $PAGE;
        $PAGE->set_url('/local/aicoursebuilder/index.php');
        return (new job_list((int) $viewer->id, $showall, $page))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * A user is listed only their own jobs, the newest first, with what is known of each.
     */
    public function test_a_user_sees_their_own_jobs_newest_first(): void {
        $now = time();
        $this->create_job($this->ana, 'Un curs vechi', ['timecreated' => $now - 7200, 'status' => 'ingesting', 'progress' => 20]);
        $this->create_job($this->ana, 'Un curs nou', ['timecreated' => $now - 60, 'actualcost' => 0.25]);
        $this->create_job($this->dan, 'Cursul altcuiva');

        $data = $this->export($this->ana);

        $this->assertTrue($data->hasjobs);
        $this->assertCount(2, $data->jobs);
        $this->assertSame('Un curs nou', $data->jobs[0]['title']);
        $this->assertSame('0.2500', $data->jobs[0]['cost']);
        $this->assertSame(get_string('wizard:stage_review', 'local_aicoursebuilder'), $data->jobs[0]['status']);
        $this->assertSame(100, $data->jobs[0]['progress']);
        $this->assertSame('Un curs vechi', $data->jobs[1]['title']);
        $this->assertSame(20, $data->jobs[1]['progress']);
        $this->assertSame(get_string('wizard:stage_ingesting', 'local_aicoursebuilder'), $data->jobs[1]['status']);
        $this->assertFalse($data->showall);
        $this->assertFalse($data->canshowall);
        $this->assertStringContainsString('/local/aicoursebuilder/wizard.php', $data->newcourseurl);
    }

    /**
     * A user who has no job gets an empty list, and still the way to a new course.
     */
    public function test_an_empty_list(): void {
        $data = $this->export($this->ana);

        $this->assertFalse($data->hasjobs);
        $this->assertSame([], $data->jobs);
        $this->assertStringContainsString('/local/aicoursebuilder/wizard.php', $data->newcourseurl);
    }

    /**
     * A manager can read the jobs of everybody, with their owners; anybody else who asks is kept to their own.
     */
    public function test_a_manager_can_list_everybodys_jobs(): void {
        $this->create_job($this->manager, 'Cursul managerului');
        $this->create_job($this->ana, 'Cursul Anei');
        $this->create_job($this->dan, 'Cursul lui Dan');

        $own = $this->export($this->manager);
        $this->assertTrue($own->canshowall);
        $this->assertFalse($own->showall);
        $this->assertCount(1, $own->jobs);
        $this->assertStringContainsString('all=1', $own->toggleurl);

        $all = $this->export($this->manager, true);
        $this->assertTrue($all->showall);
        $this->assertCount(3, $all->jobs);
        $owners = array_column($all->jobs, 'owner', 'title');
        $this->assertSame('Ana Popescu', $owners['Cursul Anei']);
        $this->assertSame('Dan Ionescu', $owners['Cursul lui Dan']);
        $this->assertStringNotContainsString('all=1', $all->toggleurl);

        $refused = $this->export($this->ana, true);
        $this->assertFalse($refused->showall);
        $this->assertCount(1, $refused->jobs);
        $this->assertSame('Cursul Anei', $refused->jobs[0]['title']);
    }

    /**
     * The blueprint is offered once there is one to open, and the course once it is built.
     */
    public function test_the_links_follow_the_status(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->create_job($this->ana, 'In curs', ['status' => 'generating', 'progress' => 60]);
        $this->create_job($this->ana, 'Gata', ['status' => 'review']);
        $this->create_job($this->ana, 'Construit', ['status' => 'finished', 'courseid' => $course->id]);
        $this->create_job($this->ana, 'Oprit', ['status' => 'paused']);

        $bytitle = array_column($this->export($this->ana)->jobs, null, 'title');

        $this->assertFalse($bytitle['In curs']['hasreview']);
        $this->assertFalse($bytitle['In curs']['hascourse']);
        $this->assertFalse($bytitle['Oprit']['hasreview']);
        $this->assertTrue($bytitle['Gata']['hasreview']);
        $this->assertFalse($bytitle['Gata']['hascourse']);
        $this->assertTrue($bytitle['Construit']['hasreview']);
        $this->assertTrue($bytitle['Construit']['hascourse']);
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $bytitle['Construit']['courseurl']);
        $this->assertStringContainsString('/local/aicoursebuilder/job.php?id=', $bytitle['Gata']['url']);
        $this->assertStringContainsString('/local/aicoursebuilder/review.php?id=', $bytitle['Gata']['reviewurl']);
    }

    /**
     * The list is paged.
     */
    public function test_the_list_is_paged(): void {
        for ($i = 1; $i <= job_list::PERPAGE + 2; $i++) {
            $this->create_job($this->ana, 'Curs ' . $i, ['timecreated' => 1000000 + $i]);
        }

        $first = $this->export($this->ana);
        $second = $this->export($this->ana, false, 1);

        $this->assertCount(job_list::PERPAGE, $first->jobs);
        $this->assertSame('Curs ' . (job_list::PERPAGE + 2), $first->jobs[0]['title']);
        $this->assertNotSame('', $first->pagingbar);
        $this->assertCount(2, $second->jobs);
        $this->assertSame('Curs 2', $second->jobs[0]['title']);
        $this->assertSame('Curs 1', $second->jobs[1]['title']);
    }

    /**
     * A page before the first is the first.
     */
    public function test_a_negative_page_is_the_first(): void {
        $this->create_job($this->ana, 'Un curs');

        $this->assertCount(1, $this->export($this->ana, false, -3)->jobs);
    }

    /**
     * The template draws the list, the button of a new course and, for a manager, the owners.
     */
    public function test_the_template(): void {
        global $PAGE;
        $this->create_job($this->ana, 'Un curs despre energia solară');
        $PAGE->set_url('/local/aicoursebuilder/index.php');
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('local_aicoursebuilder/job_list', $this->export($this->manager, true));

        $this->assertStringContainsString('Un curs despre energia solară', $html);
        $this->assertStringContainsString('Ana Popescu', $html);
        $this->assertStringContainsString('data-action="aicb-new-course"', $html);
        $this->assertStringContainsString('data-action="aicb-toggle-all"', $html);
        $this->assertStringContainsString(get_string('index:showmine', 'local_aicoursebuilder'), $html);

        $mine = $renderer->render_from_template('local_aicoursebuilder/job_list', $this->export($this->ana));
        $this->assertStringNotContainsString('data-action="aicb-toggle-all"', $mine);
        $this->assertStringNotContainsString(get_string('index:owner', 'local_aicoursebuilder'), $mine);

        $nobody = $renderer->render_from_template('local_aicoursebuilder/job_list', $this->export($this->dan));
        $this->assertStringContainsString('data-region="aicb-no-jobs"', $nobody);
    }
}
