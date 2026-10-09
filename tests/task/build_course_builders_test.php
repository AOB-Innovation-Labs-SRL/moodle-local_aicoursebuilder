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

use local_aicoursebuilder\ai\fake_lock_factory;
use local_aicoursebuilder\builder\assign_builder;
use local_aicoursebuilder\builder\build_result;
use local_aicoursebuilder\builder\builder_registry;
use local_aicoursebuilder\builder\failing_builder;
use local_aicoursebuilder\builder\half_built_wiki_builder;
use local_aicoursebuilder\builder\subsection_builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/failing_builder.php');
require_once(__DIR__ . '/../fixtures/half_built_wiki_builder.php');

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

        $built = [
            'course', 'section', 'subsection', 'page', 'label', 'url', 'resource', 'folder', 'book', 'quiz',
            'glossary', 'forum', 'lesson', 'wiki', 'choice', 'feedback', 'assign',
        ];
        foreach ($built as $type) {
            $this->assertTrue($registry->has($type), $type);
        }
        foreach (['h5pactivity', 'scorm'] as $type) {
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
        foreach (['glossary', 'lesson', 'wiki', 'choice', 'feedback', 'assign', 'quiz'] as $modname) {
            $this->assertCount(1, $built[$modname], $modname);
        }
        // A new course has its news forum already.
        $this->assertContains('Forum de discuții', $built['forum']);

        $map = json_decode($job->buildmap, true);
        $created = [
            's1.quiz1', 's1.page1', 's1.book1', 's1.url1', 's1.resource1', 's1.label1', 's1-1.folder1',
            's1.glossary1', 's2.forum1', 's2.lesson1', 's2.wiki1', 's2.assign1', 's3.choice1', 's3.feedback1',
        ];
        foreach ($created as $id) {
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
     * Runs the build of the job with a registry of builders, as cron does but with the builders of the test.
     *
     * @param builder_registry $registry The builders.
     */
    private function run_build_with(builder_registry $registry): void {
        $task = new build_course($registry, new fake_lock_factory());
        $task->set_custom_data(['jobid' => $this->jobid]);
        $task->set_userid((int) $this->user->id);
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Returns the job, with its build map decoded.
     *
     * @return array [\stdClass $job, array $map]
     */
    private function get_job_and_map(): array {
        global $DB;
        $job = $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST);
        return [$job, json_decode((string) $job->buildmap, true) ?: []];
    }

    /**
     * Counts the modules of a course by type.
     *
     * @param int $courseid Course id.
     * @return int[] Module name => count.
     */
    private function count_modules(int $courseid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT m.name, COUNT(cm.id) AS total
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0
           GROUP BY m.name',
            ['courseid' => $courseid]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->name] = (int) $row->total;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Returns what the golden blueprint has to build, by module type: every activity, the module of each subsection and
     * the news forum that Moodle gives a new course.
     *
     * @return int[] Module name => count.
     */
    private function expected_modules(): array {
        $blueprint = json_decode(file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json'), true);
        $counts = ['forum' => 1];
        foreach ($blueprint['sections'] as $section) {
            foreach ($section['activities'] ?? [] as $activity) {
                $counts[$activity['type']] = ($counts[$activity['type']] ?? 0) + 1;
            }
            foreach ($section['subsections'] ?? [] as $subsection) {
                $counts['subsection'] = ($counts['subsection'] ?? 0) + 1;
                foreach ($subsection['activities'] ?? [] as $activity) {
                    $counts[$activity['type']] = ($counts[$activity['type']] ?? 0) + 1;
                }
            }
        }
        // The types without a builder are left to the teacher.
        unset($counts['h5pactivity'], $counts['scorm']);
        // A quiz needs the question bank of the course, which is a module of its own in Moodle 5.
        if (!empty($counts['quiz'])) {
            $counts['qbank'] = 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * A build that is stopped on some nodes by an error, then resumed, gives the same course as a build with no error:
     * every module once, none twice.
     */
    public function test_resume_after_an_injected_error_builds_no_module_twice(): void {
        global $DB;
        $this->approve_golden_blueprint();

        // An error on an activity (the assignment), and on a subsection, whose folder then has nowhere to go.
        $registry = builder_registry::with_defaults();
        $assign = new failing_builder(new assign_builder(), ['s2.assign1']);
        $subsection = new failing_builder(new subsection_builder(), ['s1-1']);
        $registry->register('assign', $assign)->register('subsection', $subsection);
        $this->run_build_with($registry);

        [$job, $map] = $this->get_job_and_map();
        $this->assertNotEmpty($job->courseid, 'The course was made');
        $this->assertSame(build_result::STATUS_FAILED, $map['s2.assign1']['status']);
        $this->assertSame(build_result::STATUS_FAILED, $map['s1-1']['status']);
        $this->assertSame(build_result::STATUS_FAILED, $map['s1-1.folder1']['status'], 'Child of the failed subsection');
        $this->assertSame(build_result::STATUS_CREATED, $map['s1.page1']['status'], 'The independent nodes are built');
        $partial = $this->count_modules((int) $job->courseid);
        $this->assertArrayNotHasKey('assign', $partial);
        $this->assertArrayNotHasKey('folder', $partial);
        $cmids = array_column(array_filter($map, fn($entry) => !empty($entry['cmid'])), 'cmid');

        // The build is resumed with the error gone: the job is reopened the way a retry reopens it.
        $assign->armed = false;
        $subsection->armed = false;
        $DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $this->jobid]);
        $this->run_build_with($registry);

        [$job] = $this->get_job_and_map();
        $this->assertSame('finished', $job->status, (string) $job->error);
        $this->assertSame($this->expected_modules(), $this->count_modules((int) $job->courseid));
        // What was built before the error is the same module after the resume: nothing was built again.
        foreach ($cmids as $cmid) {
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]), "Module {$cmid} is still there");
        }
        $this->assertSame(1, $DB->count_records('course', ['id' => $job->courseid]));
    }

    /**
     * An error in the middle of a real builder, when the module and its wiki exist already, leaves nothing half built:
     * the resumed build makes the wiki once, with its pages.
     */
    public function test_an_error_inside_a_builder_leaves_no_half_built_module(): void {
        global $DB;
        $this->approve_golden_blueprint();
        $wiki = new half_built_wiki_builder();
        $registry = builder_registry::with_defaults()->register('wiki', $wiki);

        $this->run_build_with($registry);

        [$job, $map] = $this->get_job_and_map();
        $this->assertSame(build_result::STATUS_FAILED, $map['s2.wiki1']['status']);
        $wikimodule = $DB->get_field('modules', 'id', ['name' => 'wiki'], MUST_EXIST);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $job->courseid, 'module' => $wikimodule]));

        $wiki->armed = false;
        $DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $this->jobid]);
        $this->run_build_with($registry);

        [$job] = $this->get_job_and_map();
        $this->assertSame('finished', $job->status, (string) $job->error);
        $this->assertSame(1, $DB->count_records('course_modules', ['course' => $job->courseid, 'module' => $wikimodule]));
        $wikirow = $DB->get_record('wiki', ['course' => $job->courseid], '*', MUST_EXIST);
        $subwiki = $DB->get_record('wiki_subwikis', ['wikiid' => $wikirow->id], '*', MUST_EXIST);
        $this->assertSame(2, $DB->count_records('wiki_pages', ['subwikiid' => $subwiki->id]), 'The pages of the blueprint');
        $this->assertSame($this->expected_modules(), $this->count_modules((int) $job->courseid));
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
