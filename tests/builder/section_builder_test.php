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
 * Tests of the section builder and of the subsection builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\section_builder
 * @covers     \local_aicoursebuilder\builder\subsection_builder
 * @covers     \local_aicoursebuilder\builder\build_context
 */
final class section_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job('newcourse');
    }

    /**
     * Returns the sections of the course, read again.
     *
     * @return \section_info[] Section number => section.
     */
    private function sections(): array {
        rebuild_course_cache($this->course->id, true);
        return get_fast_modinfo($this->course->id)->get_section_info_all();
    }

    /**
     * A section is added at the end of the course, with the title and the summary of the blueprint.
     */
    public function test_builds_a_section_at_the_end(): void {
        $before = count($this->sections());

        $result = (new section_builder())->build($this->node('s1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame('s1', $result->nodeid);
        $this->assertNull($result->cmid);
        $this->assertSame($before, $result->sectionnum);
        $sections = $this->sections();
        $this->assertCount($before + 1, $sections);
        $this->assertSame('Noțiuni de bază', $sections[$result->sectionnum]->name);
        $this->assertSame('<p>Ce este energia regenerabilă și de ce contează.</p>', $sections[$result->sectionnum]->summary);
        $this->assertEquals(FORMAT_HTML, $sections[$result->sectionnum]->summaryformat);
    }

    /**
     * Sections come one after another, in the order they are built.
     */
    public function test_sections_follow_one_another(): void {
        $context = $this->make_context();
        $builder = new section_builder();

        $first = $this->record($context, $builder->build($this->node('s1'), $context));
        $second = $this->record($context, $builder->build($this->node('s2'), $context));

        $this->assertSame($first->sectionnum + 1, $second->sectionnum);
        $this->assertSame($second->sectionnum, $context->get_sectionnum('s2'));
    }

    /**
     * The summary is cleaned.
     */
    public function test_the_summary_is_cleaned(): void {
        $node = $this->node('s1');
        $node['summary'] = '<p>Ok</p><script>x()</script>';

        $result = (new section_builder())->build($node, $this->make_context());

        $summary = $this->sections()[$result->sectionnum]->summary;
        $this->assertStringContainsString('<p>Ok</p>', $summary);
        $this->assertStringNotContainsString('script', $summary);
    }

    /**
     * A section already built is not built again.
     */
    public function test_a_built_section_is_skipped(): void {
        $context = $this->make_context();
        $builder = new section_builder();
        $this->record($context, $builder->build($this->node('s1'), $context));
        $count = count($this->sections());

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s1'), $context)->status);
        $this->assertCount($count, $this->sections());
    }

    /**
     * The activities of a section are built in it.
     */
    public function test_activities_are_built_in_their_section(): void {
        $context = $this->make_context();
        $this->record($context, (new section_builder())->build($this->node('s1'), $context));
        $this->record($context, (new section_builder())->build($this->node('s2'), $context));

        $node = $this->node('s1.label1');
        $node['id'] = 's2.label1';
        $one = (new label_builder())->build($this->node('s1.label1'), $context);
        $two = (new label_builder())->build($node, $context);

        $this->assertSame($context->get_sectionnum('s1'), $one->sectionnum);
        $this->assertSame($context->get_sectionnum('s2'), $two->sectionnum);
        $this->assertEquals($context->get_sectionnum('s2'), $this->get_cm($two)->sectionnum);
    }

    /**
     * A subsection is a module of its section that holds a section of its own.
     */
    public function test_builds_a_subsection(): void {
        $context = $this->make_context();
        $section = $this->record($context, (new section_builder())->build($this->node('s1'), $context));

        $result = (new subsection_builder())->build($this->node('s1-1'), $context);

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertNotNull($result->cmid);
        $cm = $this->get_cm($result);
        $this->assertSame('subsection', $cm->modname);
        $this->assertSame('Resurse suplimentare', $cm->name);
        $this->assertEquals($section->sectionnum, $cm->sectionnum, 'The module is in its section');
        $delegated = $this->sections()[$result->sectionnum];
        $this->assertSame('mod_subsection', $delegated->component);
        $this->assertEquals($result->instanceid, $delegated->itemid);
        $this->assertSame('<p>Materiale opționale.</p>', $delegated->summary);
    }

    /**
     * The activities of a subsection are built in its own section.
     */
    public function test_activities_are_built_in_their_subsection(): void {
        $context = $this->make_context();
        $this->record($context, (new section_builder())->build($this->node('s1'), $context));
        $subsection = $this->record($context, (new subsection_builder())->build($this->node('s1-1'), $context));

        $node = $this->node('s1.label1');
        $node['id'] = 's1-1.label1';
        $result = (new label_builder())->build($node, $context);

        $this->assertSame($subsection->sectionnum, $result->sectionnum);
        $this->assertEquals($subsection->sectionnum, $this->get_cm($result)->sectionnum);
    }

    /**
     * A section built after a subsection pushes its delegated section down, and the context follows it.
     */
    public function test_the_section_of_a_subsection_is_found_after_it_moved(): void {
        $context = $this->make_context();
        $this->record($context, (new section_builder())->build($this->node('s1'), $context));
        $subsection = $this->record($context, (new subsection_builder())->build($this->node('s1-1'), $context));
        $recorded = $subsection->sectionnum;

        $this->record($context, (new section_builder())->build($this->node('s2'), $context));

        $this->assertSame($recorded + 1, $context->locate_section('s1-1'), 'The delegated section moved down');
        $this->assertSame($recorded, $context->get_sectionnum('s1-1'), 'The recorded number is the old one');
        $node = $this->node('s1.label1');
        $node['id'] = 's1-1.label2';
        $result = (new label_builder())->build($node, $context);
        $this->assertEquals($context->locate_section('s1-1'), $this->get_cm($result)->sectionnum);
    }

    /**
     * A node that was never built has no section.
     */
    public function test_an_unbuilt_node_has_no_section(): void {
        $this->assertNull($this->make_context()->locate_section('s9'));
    }
}
