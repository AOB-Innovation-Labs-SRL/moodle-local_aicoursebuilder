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
 * Tests of the wiki builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\wiki_builder
 */
final class wiki_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
        require_once($CFG->dirroot . '/mod/wiki/locallib.php');
    }

    /**
     * The wiki of the golden blueprint is collaborative, with its pages.
     */
    public function test_builds_the_wiki_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new wiki_builder())->build($this->node('s2.wiki1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame([], $result->warnings);
        $this->assertSame('wiki', $this->get_cm($result)->modname);
        $wiki = $DB->get_record('wiki', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('collaborative', $wiki->wikimode);
        $this->assertSame('html', $wiki->defaultformat);
        $this->assertEquals(1, $wiki->forceformat);
        $this->assertSame('Pagina principală', $wiki->firstpagetitle);

        $subwikis = $DB->get_records('wiki_subwikis', ['wikiid' => $wiki->id]);
        $this->assertCount(1, $subwikis);
        $subwiki = reset($subwikis);
        $this->assertEquals(0, $subwiki->groupid);
        $this->assertEquals(0, $subwiki->userid);

        $pages = array_values($DB->get_records('wiki_pages', ['subwikiid' => $subwiki->id], 'id'));
        $this->assertSame(['Pagina principală', 'Exemple solare'], array_column($pages, 'title'));
        $this->assertSame($pages[0]->id, wiki_get_first_page($subwiki->id)->id, 'The wiki opens on the first page');
    }

    /**
     * The content of a page is its current version, in HTML.
     */
    public function test_pages_have_their_content(): void {
        global $DB;

        $result = (new wiki_builder())->build($this->node('s2.wiki1'), $this->make_context());

        $subwiki = $DB->get_record('wiki_subwikis', ['wikiid' => $result->instanceid], '*', MUST_EXIST);
        $page = wiki_get_page_by_title($subwiki->id, 'Exemple solare');
        $version = wiki_get_current_version($page->id);
        $this->assertSame('<p>Listați proiecte solare cunoscute.</p>', $version->content);
        $this->assertSame('html', $version->contentformat);
        $this->assertEquals(1, $version->version, 'The empty first version, then the content');
        $this->assertEquals($this->teacher->id, $version->userid);
        $this->assertStringContainsString('proiecte solare', $page->cachedcontent);
    }

    /**
     * The content of a page is cleaned.
     */
    public function test_the_text_is_cleaned(): void {
        global $DB;
        $node = $this->node('s2.wiki1');
        $node['content']['pages'] = [['title' => '<b>Start</b>', 'content' => '<p>Ok</p><script>x()</script>']];

        $result = (new wiki_builder())->build($node, $this->make_context());

        $subwiki = $DB->get_record('wiki_subwikis', ['wikiid' => $result->instanceid], '*', MUST_EXIST);
        $page = wiki_get_page_by_title($subwiki->id, 'Start');
        $this->assertNotFalse($page);
        $this->assertStringNotContainsString('script', wiki_get_current_version($page->id)->content);
    }

    /**
     * A title that comes twice makes one page, not a second one that overwrites the first.
     */
    public function test_a_duplicate_title_is_left_out(): void {
        global $DB;
        $node = $this->node('s2.wiki1');
        $node['content']['pages'][] = ['title' => 'EXEMPLE SOLARE', 'content' => '<p>Altceva.</p>'];

        $result = (new wiki_builder())->build($node, $this->make_context());

        $subwiki = $DB->get_record('wiki_subwikis', ['wikiid' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame(2, $DB->count_records('wiki_pages', ['subwikiid' => $subwiki->id]));
        $this->assertCount(1, $result->warnings);
        $page = wiki_get_page_by_title($subwiki->id, 'Exemple solare');
        $this->assertSame('<p>Listați proiecte solare cunoscute.</p>', wiki_get_current_version($page->id)->content);
    }

    /**
     * The manual completion of the golden blueprint is set.
     */
    public function test_completion_is_set(): void {
        $result = (new wiki_builder())->build($this->node('s2.wiki1'), $this->make_context());

        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->get_cm($result)->completion);
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new wiki_builder();
        $this->record($context, $builder->build($this->node('s2.wiki1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s2.wiki1'), $context)->status);
        $this->assertSame(1, $DB->count_records('wiki'));
    }
}
