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
 * Tests of the book builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\book_builder
 */
final class book_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * The book of the golden blueprint has its three chapters in order, one of them a subchapter.
     */
    public function test_builds_the_book_with_its_chapters(): void {
        global $DB;

        $result = (new book_builder())->build($this->node('s1.book1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame('book', $this->get_cm($result)->modname);
        $chapters = array_values($DB->get_records('book_chapters', ['bookid' => $result->instanceid], 'pagenum'));
        $this->assertCount(3, $chapters);
        $this->assertSame(['Energia solară', 'Celule fotovoltaice', 'Energia eoliană'], array_column($chapters, 'title'));
        $this->assertEquals([1, 2, 3], array_column($chapters, 'pagenum'));
        $this->assertEquals([0, 1, 0], array_column($chapters, 'subchapter'));
        $this->assertEquals([0, 0, 0], array_column($chapters, 'hidden'));
        $this->assertStringContainsString('Panourile fotovoltaice', $chapters[0]->content);
        $this->assertEquals(FORMAT_HTML, $chapters[0]->contentformat);
    }

    /**
     * Adding the chapters raises the revision of the book, which is how what is cached is renewed.
     */
    public function test_the_revision_is_raised(): void {
        global $DB;

        $result = (new book_builder())->build($this->node('s1.book1'), $this->make_context());

        $this->assertEquals(2, $DB->get_field('book', 'revision', ['id' => $result->instanceid]));
    }

    /**
     * Each chapter fires the event Moodle fires when a chapter is created.
     */
    public function test_every_chapter_fires_its_event(): void {
        $sink = $this->redirectEvents();

        $result = (new book_builder())->build($this->node('s1.book1'), $this->make_context());

        $created = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_book\event\chapter_created
        ));
        $this->assertCount(3, $created);
        $this->assertEquals($result->instanceid, $created[0]->other['bookid']);
    }

    /**
     * The first chapter cannot be a subchapter, whatever the blueprint says.
     */
    public function test_the_first_chapter_is_never_a_subchapter(): void {
        global $DB;
        $node = $this->node('s1.book1');
        $node['content']['chapters'][0]['subchapter'] = true;

        $result = (new book_builder())->build($node, $this->make_context());

        $this->assertEquals(0, $DB->get_field('book_chapters', 'subchapter', ['bookid' => $result->instanceid, 'pagenum' => 1]));
    }

    /**
     * The text of a chapter is cleaned.
     */
    public function test_the_chapters_are_cleaned(): void {
        global $DB;
        $node = $this->node('s1.book1');
        $node['content']['chapters'][0]['content'] = '<p>Safe</p><script>x()</script>';
        $node['content']['chapters'][0]['title'] = '<i>Titlu</i>';

        $result = (new book_builder())->build($node, $this->make_context());

        $chapter = $DB->get_record('book_chapters', ['bookid' => $result->instanceid, 'pagenum' => 1], '*', MUST_EXIST);
        $this->assertSame('Titlu', $chapter->title);
        $this->assertStringNotContainsString('script', $chapter->content);
    }

    /**
     * A book whose chapters cannot be saved is deleted, so that no empty book is left behind.
     */
    public function test_a_book_without_its_chapters_is_deleted(): void {
        global $DB;
        $builder = new class extends book_builder {
            #[\Override]
            protected function add_chapters(int $bookid, int $cmid, array $chapters): void {
                throw new \RuntimeException('no chapters');
            }
        };

        try {
            $builder->build($this->node('s1.book1'), $this->make_context());
            $this->fail('Expected the failure to be passed on');
        } catch (\RuntimeException $e) {
            $this->assertSame('no chapters', $e->getMessage());
        }

        $this->assertSame(0, $DB->count_records('book'));
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'book']);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $this->course->id, 'module' => $moduleid]));
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new book_builder();
        $this->record($context, $builder->build($this->node('s1.book1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s1.book1'), $context)->status);
        $this->assertSame(1, $DB->count_records('book'));
    }
}
