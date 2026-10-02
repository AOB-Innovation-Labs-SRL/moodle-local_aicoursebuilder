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

namespace local_aicoursebuilder\builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/builder_test_helpers.php');

/**
 * Tests of the page builder, and of what every module builder has in common (module_builder).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\page_builder
 * @covers     \local_aicoursebuilder\builder\module_builder
 */
final class page_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * The page of the golden blueprint is created in the section of the job, with its text and its intro.
     */
    public function test_builds_the_page_of_the_golden_blueprint(): void {
        global $DB;

        $context = $this->make_context();
        $result = (new page_builder())->build($this->node('s1.page1'), $context);

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame('s1.page1', $result->nodeid);
        $this->assertSame([], $result->warnings);
        $this->assertSame(1, $result->sectionnum);

        $cm = $this->get_cm($result);
        $this->assertSame('page', $cm->modname);
        $this->assertSame('Ce este energia regenerabilă', $cm->name);
        $this->assertEquals(1, $cm->sectionnum);

        $page = $DB->get_record('page', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertStringContainsString('Energia regenerabilă provine din surse', $page->content);
        $this->assertEquals(FORMAT_HTML, $page->contentformat);
        $this->assertSame('<p>Definiții și exemple.</p>', $page->intro);
        $this->assertEquals(1, $page->revision);
        $this->assertNotEmpty($page->displayoptions);
    }

    /**
     * The display options are the site defaults of the module.
     */
    public function test_display_options_come_from_the_site_defaults(): void {
        global $DB;
        set_config('printlastmodified', 0, 'page');
        set_config('printintro', 1, 'page');

        $result = (new page_builder())->build($this->node('s1.page1'), $this->make_context());

        $options = unserialize($DB->get_field('page', 'displayoptions', ['id' => $result->instanceid]));
        $this->assertEquals(0, $options['printlastmodified']);
        $this->assertEquals(1, $options['printintro']);
    }

    /**
     * What the model wrote is cleaned: a script does not reach the page, the text around it does.
     */
    public function test_the_text_is_cleaned(): void {
        global $DB;
        $node = $this->node('s1.page1');
        $node['content']['text'] = '<p>Safe</p><script>alert(1)</script><p onclick="x()">Click</p>';
        $node['intro'] = '<p>Intro</p><script>alert(2)</script>';

        $result = (new page_builder())->build($node, $this->make_context());

        $page = $DB->get_record('page', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertStringNotContainsString('script', $page->content);
        $this->assertStringNotContainsString('onclick', $page->content);
        $this->assertStringContainsString('<p>Safe</p>', $page->content);
        $this->assertStringNotContainsString('script', $page->intro);
    }

    /**
     * The name is plain text of at most 255 characters.
     */
    public function test_the_name_is_plain_and_short(): void {
        $node = $this->node('s1.page1');
        $node['name'] = "<b>Bold</b>   and\n spaced " . str_repeat('x', 300);

        $result = (new page_builder())->build($node, $this->make_context());

        $name = $this->get_cm($result)->name;
        $this->assertStringStartsWith('Bold and spaced xxx', $name);
        $this->assertSame(255, \core_text::strlen($name));
    }

    /**
     * A node already in the build map is not built again, and Moodle is not called.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new page_builder();
        $this->record($context, $builder->build($this->node('s1.page1'), $context));

        $again = $builder->build($this->node('s1.page1'), $context);

        $this->assertSame(build_result::STATUS_SKIPPED, $again->status);
        $this->assertSame(1, $DB->count_records('page'));
    }

    /**
     * Automatic completion with the view rule is set on the module, and a node with none keeps the course defaults.
     */
    public function test_completion_is_set_from_the_node(): void {
        $builder = new page_builder();

        $auto = $builder->build($this->node('s1.page1'), $this->make_context());
        $cm = $this->get_cm($auto);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $cm->completion);
        $this->assertEquals(1, $cm->completionview);

        $node = $this->node('s1.page1');
        $node['id'] = 's1.page2';
        $node['completion'] = ['mode' => 'manual'];
        $manual = $builder->build($node, $this->make_context());
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->get_cm($manual)->completion);

        $node['id'] = 's1.page3';
        $node['completion'] = ['mode' => 'none'];
        $none = $builder->build($node, $this->make_context());
        $this->assertEquals(COMPLETION_TRACKING_NONE, $this->get_cm($none)->completion);
    }

    /**
     * Only the fields of Moodle's completion are taken from the rules of a node.
     */
    public function test_a_completion_rule_cannot_set_any_field(): void {
        $node = $this->node('s1.page1');
        $node['completion'] = ['mode' => 'auto', 'view' => true, 'rules' => ['course' => 1, 'name' => 'x', 'completionview' => 0]];

        $result = (new page_builder())->build($node, $this->make_context());

        $cm = $this->get_cm($result);
        $this->assertSame($this->course->id, (string) $cm->course);
        $this->assertSame('Ce este energia regenerabilă', $cm->name);
    }

    /**
     * Without the capability to add a module, nothing is built and the reason is the one Moodle gives.
     */
    public function test_a_user_who_cannot_add_modules_is_refused(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        (new page_builder())->build($this->node('s1.page1'), $this->make_context());
    }

    /**
     * A module whose content cannot be saved is deleted again, so no empty module is left behind.
     */
    public function test_a_module_without_its_content_is_deleted(): void {
        global $DB;
        $builder = new class extends page_builder {
            #[\Override]
            protected function after_created(\stdClass $created, array $node, build_context $context): void {
                throw new \RuntimeException('no content');
            }
        };

        try {
            $builder->build($this->node('s1.page1'), $this->make_context());
            $this->fail('Expected the failure to be passed on');
        } catch (\RuntimeException $e) {
            $this->assertSame('no content', $e->getMessage());
        }

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'page']);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $this->course->id, 'module' => $moduleid]));
    }

    /**
     * Activities go into the section of the job in an existing course, or the first one when it has none.
     */
    public function test_the_section_falls_back_to_the_one_of_the_job(): void {
        global $DB;
        $DB->set_field('local_aicb_job', 'sectionnum', 3, ['id' => $this->jobid]);

        $result = (new page_builder())->build($this->node('s1.page1'), $this->make_context());

        $this->assertSame(3, $result->sectionnum);
        $this->assertEquals(3, $this->get_cm($result)->sectionnum);
    }
}
