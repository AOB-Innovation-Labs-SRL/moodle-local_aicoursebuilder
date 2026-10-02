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

namespace local_aicoursebuilder\task;

use local_aicoursebuilder\builder\build_result;
use local_aicoursebuilder\builder\builder_registry;

/**
 * Tests of build_course with the builders of the plugin, on the golden blueprint: the course, its sections and the
 * activities that have a builder are created, and the rest are left for the teacher.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\task\build_course
 * @covers     \local_aicoursebuilder\builder\builder_registry
 */
final class build_course_builders_test extends \advanced_testcase {
    /** @var \stdClass Owner of the job. */
    private \stdClass $user;

    /** @var int Job id. */
    private int $jobid;

    /** @var string[] Source ids the golden blueprint refers to, by the id in the file. */
    private array $sources = [];

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $category = $this->getDataGenerator()->create_category();
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        $this->getDataGenerator()->role_assign('manager', $this->user->id, \context_coursecat::instance($category->id)->id);
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'mode' => 'newcourse',
            'categoryid' => $category->id,
            'status' => 'approved',
            'prompt' => 'Test',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        foreach (['src1' => 'manual.pdf', 'src2' => 'anexa.docx'] as $key => $filename) {
            $this->sources[$key] = $this->add_source($filename, 'content of ' . $filename);
        }
    }

    /**
     * Stores a source file of the job.
     *
     * @param string $filename File name.
     * @param string $content Content.
     * @return string Source id as the blueprint writes it.
     */
    private function add_source(string $filename, string $content): string {
        global $DB;
        $id = $DB->insert_record('local_aicb_source', (object) [
            'jobid' => $this->jobid,
            'filename' => $filename,
            'mimetype' => 'application/octet-stream',
            'filesize' => strlen($content),
            'contenthash' => sha1($content),
            'status' => 'digested',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'source',
            'itemid' => $id,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return 'src' . $id;
    }

    /**
     * Approves the golden blueprint for the job, with its source ids pointing at the sources of the job.
     */
    private function approve_golden_blueprint(): void {
        global $DB;
        $content = file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json');
        $content = strtr($content, [
            '"src1"' => '"' . $this->sources['src1'] . '"',
            '"src2"' => '"' . $this->sources['src2'] . '"',
        ]);
        $blueprintid = $DB->insert_record('local_aicb_blueprint', (object) [
            'jobid' => $this->jobid,
            'version' => 1,
            'schemaversion' => '1.0',
            'content' => $content,
            'contenthash' => hash('sha256', $content),
            'status' => 'approved',
            'usermodified' => $this->user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field('local_aicb_job', 'blueprintid', $blueprintid, ['id' => $this->jobid]);
    }

    /**
     * Runs the build of the job as cron does.
     */
    private function run_build(): void {
        \core\task\manager::queue_adhoc_task(build_course::instance($this->jobid, (int) $this->user->id));
        // The task reports what it does with mtrace(), which the test does not need to see.
        ob_start();
        try {
            $this->runAdhocTasks(build_course::class);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The registry a build uses has a builder for the course, the sections and the activities that are built.
     */
    public function test_the_default_registry(): void {
        $registry = builder_registry::with_defaults();

        foreach (['course', 'section', 'subsection', 'page', 'label', 'url', 'resource', 'folder', 'book', 'quiz'] as $type) {
            $this->assertTrue($registry->has($type), $type);
        }
        foreach (['lesson', 'assign', 'glossary', 'forum', 'wiki', 'choice', 'feedback'] as $type) {
            $this->assertFalse($registry->has($type), $type);
        }
    }

    /**
     * The golden blueprint builds the course with its sections and the activities that have a builder.
     */
    public function test_builds_the_golden_blueprint(): void {
        global $DB;
        $this->approve_golden_blueprint();

        $this->run_build();

        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
        $this->assertSame('finished', $job->status, (string) $job->error);
        $this->assertNotEmpty($job->courseid);

        $course = get_course($job->courseid);
        $this->assertSame('Introducere în energia regenerabilă', $course->fullname);
        $modinfo = get_fast_modinfo($course);

        // The sections: the general one, the three of the blueprint and the delegated one of the subsection.
        $names = array_map(fn(\section_info $section) => $section->name, $modinfo->get_section_info_all());
        $this->assertContains('Noțiuni de bază', $names);
        $this->assertContains('Surse de energie în practică', $names);
        $this->assertContains('Evaluare și opinii', $names);

        // The modules of the types that have a builder.
        $built = [];
        foreach ($modinfo->get_cms() as $cm) {
            $built[$cm->modname][] = $cm->name;
        }
        $this->assertCount(1, $built['page']);
        $this->assertCount(1, $built['book']);
        $this->assertCount(1, $built['url']);
        $this->assertCount(1, $built['resource']);
        $this->assertCount(1, $built['folder']);
        $this->assertCount(1, $built['subsection']);
        $this->assertCount(1, $built['label']);

        // The other activities were left for the teacher, each one recorded as manual.
        $map = json_decode($job->buildmap, true);
        $manual = ['s1.glossary1', 's2.lesson1', 's2.assign1', 's2.forum1', 's2.wiki1', 's3.choice1', 's3.feedback1'];
        foreach ($manual as $id) {
            $this->assertSame(build_result::STATUS_MANUAL, $map[$id]['status'], $id);
        }
        foreach (['s1.quiz1', 's1.page1', 's1.book1', 's1.url1', 's1.resource1', 's1.label1', 's1-1.folder1'] as $id) {
            $this->assertSame(build_result::STATUS_CREATED, $map[$id]['status'], $id);
            $this->assertNotNull($map[$id]['cmid'], $id);
        }
    }

    /**
     * Every activity is in the section it belongs to, the one of the subsection in the delegated section.
     */
    public function test_activities_are_in_their_sections(): void {
        global $DB;
        $this->approve_golden_blueprint();

        $this->run_build();

        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
        $map = json_decode($job->buildmap, true);
        $modinfo = get_fast_modinfo($job->courseid);

        $section = fn(string $nodeid) => $modinfo->get_cm($map[$nodeid]['cmid'])->get_section_info();
        $this->assertSame('Noțiuni de bază', $section('s1.page1')->name);
        $this->assertSame('Noțiuni de bază', $section('s1-1')->name, 'The subsection is a module of its section');
        $folder = $section('s1-1.folder1');
        $this->assertSame('mod_subsection', $folder->component, 'The folder is in the section of the subsection');
        $this->assertSame('Resurse suplimentare', $folder->name);
    }

    /**
     * The owner of the job is a teacher of the new course, and the module files are the sources.
     */
    public function test_the_owner_teaches_the_course_and_the_files_are_published(): void {
        global $DB;
        $this->approve_golden_blueprint();

        $this->run_build();

        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
        $this->assertTrue(is_enrolled(\context_course::instance($job->courseid), $this->user->id));
        $map = json_decode($job->buildmap, true);
        $files = get_file_storage()->get_area_files(
            \context_module::instance($map['s1.resource1']['cmid'])->id,
            'mod_resource',
            'content',
            0,
            'filename',
            false
        );
        $this->assertSame(['manual.pdf'], array_values(array_map(fn($file) => $file->get_filename(), $files)));
    }

    /**
     * Running the build again builds nothing twice.
     */
    public function test_a_second_run_changes_nothing(): void {
        global $DB;
        $this->approve_golden_blueprint();
        $this->run_build();
        $modules = $DB->count_records('course_modules');
        $sections = $DB->count_records('course_sections');
        $courses = $DB->count_records('course');

        $DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $this->jobid]);
        $this->run_build();

        $this->assertSame($modules, $DB->count_records('course_modules'));
        $this->assertSame($sections, $DB->count_records('course_sections'));
        $this->assertSame($courses, $DB->count_records('course'));
    }

    /**
     * A node that fails does not stop the others: a file whose source is gone is failed and the rest is built.
     */
    public function test_a_failed_node_does_not_stop_the_build(): void {
        global $DB;
        $this->approve_golden_blueprint();
        $DB->delete_records('local_aicb_source', ['id' => (int) substr($this->sources['src1'], 3)]);

        $this->run_build();

        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
        $this->assertSame('finished', $job->status);
        $map = json_decode($job->buildmap, true);
        $this->assertSame(build_result::STATUS_FAILED, $map['s1.resource1']['status']);
        $this->assertSame(build_result::STATUS_CREATED, $map['s1.book1']['status']);
        $this->assertSame(build_result::STATUS_CREATED, $map['s2']['status']);
    }
}
