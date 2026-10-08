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
 * Tests of the lesson builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\lesson_builder
 */
final class lesson_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
    }

    /**
     * Returns the pages of a lesson in the order the student sees them.
     *
     * @param int $lessonid Lesson id.
     * @return \stdClass[]
     */
    private function get_pages(int $lessonid): array {
        global $DB;
        $lesson = new \lesson($DB->get_record('lesson', ['id' => $lessonid], '*', MUST_EXIST));
        return array_values($lesson->load_all_pages());
    }

    /**
     * Returns the answers of a page.
     *
     * @param int $pageid Page id.
     * @return \stdClass[]
     */
    private function get_answers(int $pageid): array {
        global $DB;
        return array_values($DB->get_records('lesson_answers', ['pageid' => $pageid], 'id'));
    }

    /**
     * The lesson of the golden blueprint has its four pages, in order and linked.
     */
    public function test_builds_the_pages_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new lesson_builder())->build($this->node('s2.lesson1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame([], $result->warnings);
        $this->assertSame('lesson', $this->get_cm($result)->modname);

        $pages = $DB->get_records('lesson_pages', ['lessonid' => $result->instanceid], 'id');
        $this->assertSame(
            ['Introducere', 'Verificare', 'Alegere', 'Termen'],
            array_values(array_column($pages, 'title'))
        );
        $this->assertSame(
            [lesson_builder::QTYPE_BRANCHTABLE, lesson_builder::QTYPE_TRUEFALSE, lesson_builder::QTYPE_MULTICHOICE,
                lesson_builder::QTYPE_SHORTANSWER],
            array_map('intval', array_values(array_column($pages, 'qtype')))
        );

        // The pages are a chain: the first has no page before it and the last none after it.
        $ids = array_keys($pages);
        foreach ($ids as $position => $id) {
            $this->assertEquals($ids[$position - 1] ?? 0, $pages[$id]->prevpageid);
            $this->assertEquals($ids[$position + 1] ?? 0, $pages[$id]->nextpageid);
        }
        $this->assertSame('<p>Alegerea sursei depinde de climă și de relief.</p>', $pages[$ids[0]]->contents);
        $this->assertEquals(FORMAT_HTML, $pages[$ids[0]]->contentsformat);
    }

    /**
     * The answers keep their text, feedback and score, and the jumps are the constants of the lesson or a page.
     */
    public function test_answers_and_jumps(): void {
        $result = (new lesson_builder())->build($this->node('s2.lesson1'), $this->make_context());
        $pages = $this->get_pages($result->instanceid);

        // A page of content: one button that goes on.
        $answers = $this->get_answers($pages[0]->id);
        $this->assertCount(1, $answers);
        $this->assertSame('Continuă', $answers[0]->answer);
        $this->assertEquals(LESSON_NEXTPAGE, $answers[0]->jumpto);

        // True or false: the right answer goes on, the wrong one back to the first page (a jump to a page).
        $answers = $this->get_answers($pages[1]->id);
        $this->assertCount(2, $answers);
        $this->assertSame('<p>Adevărat</p>', trim($answers[0]->answer));
        $this->assertSame('Corect.', trim(strip_tags($answers[0]->response)));
        $this->assertEquals(1, $answers[0]->score);
        $this->assertEquals(LESSON_NEXTPAGE, $answers[0]->jumpto);
        $this->assertEquals(0, $answers[1]->score);
        $this->assertEquals($pages[0]->id, $answers[1]->jumpto);

        // Multiple choice: the wrong answer stays on the page.
        $answers = $this->get_answers($pages[2]->id);
        $this->assertCount(2, $answers);
        $this->assertEquals(LESSON_NEXTPAGE, $answers[0]->jumpto);
        $this->assertEquals(LESSON_THISPAGE, $answers[1]->jumpto);

        // Short answer: the answer is the text the student types, and it ends the lesson.
        $answers = $this->get_answers($pages[3]->id);
        $this->assertCount(1, $answers);
        $this->assertSame('geotermală', $answers[0]->answer);
        $this->assertEquals(LESSON_EOL, $answers[0]->jumpto);
    }

    /**
     * A jump can name a page that comes later in the lesson.
     */
    public function test_a_jump_forward(): void {
        $node = $this->node('s2.lesson1');
        $node['content']['pages'][0]['answers'] = [['text' => 'Sari', 'jumpto' => 'p4']];

        $result = (new lesson_builder())->build($node, $this->make_context());

        $pages = $this->get_pages($result->instanceid);
        $this->assertSame([], $result->warnings);
        $this->assertEquals($pages[3]->id, $this->get_answers($pages[0]->id)[0]->jumpto);
    }

    /**
     * A jump to a page the lesson does not have goes to the next page, with a warning.
     */
    public function test_a_jump_to_a_missing_page_is_a_warning(): void {
        $node = $this->node('s2.lesson1');
        $node['content']['pages'][0]['answers'] = [['text' => 'Sari', 'jumpto' => 'p9']];

        $result = (new lesson_builder())->build($node, $this->make_context());

        $pages = $this->get_pages($result->instanceid);
        $this->assertCount(1, $result->warnings);
        $this->assertEquals(LESSON_NEXTPAGE, $this->get_answers($pages[0]->id)[0]->jumpto);
    }

    /**
     * The lesson scores its answers, so a question is right by its score, and the lesson has room for every answer.
     */
    public function test_the_lesson_settings(): void {
        global $DB;
        $node = $this->node('s2.lesson1');
        $answers = [];
        for ($i = 1; $i <= 7; $i++) {
            $answers[] = ['text' => "Varianta {$i}", 'jumpto' => 'next', 'score' => $i === 1 ? 1 : 0];
        }
        $node['content']['pages'][2]['answers'] = $answers;

        $result = (new lesson_builder())->build($node, $this->make_context());

        $lesson = $DB->get_record('lesson', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(1, $lesson->custom);
        $this->assertGreaterThanOrEqual(7, (int) $lesson->maxanswers);
        $pages = $this->get_pages($result->instanceid);
        $this->assertCount(7, $this->get_answers($pages[2]->id), 'No answer is lost for the limit of the lesson');
    }

    /**
     * An answer with no text is left out and the jumps of the others stay with their answers.
     */
    public function test_an_empty_answer_is_left_out(): void {
        $node = $this->node('s2.lesson1');
        $node['content']['pages'][2]['answers'] = [
            ['text' => '<p></p>', 'jumpto' => 'end', 'score' => 0],
            ['text' => 'Solară', 'jumpto' => 'next', 'score' => 1],
        ];

        $result = (new lesson_builder())->build($node, $this->make_context());

        $pages = $this->get_pages($result->instanceid);
        $answers = $this->get_answers($pages[2]->id);
        $this->assertCount(1, $answers);
        $this->assertEquals(LESSON_NEXTPAGE, $answers[0]->jumpto);
    }

    /**
     * The text of the pages and of the answers is cleaned.
     */
    public function test_the_text_is_cleaned(): void {
        $node = $this->node('s2.lesson1');
        $node['content']['pages'][0]['contents'] = '<p>Ok</p><script>x()</script>';
        $node['content']['pages'][1]['answers'][0]['response'] = '<p>Bine</p><script>y()</script>';

        $result = (new lesson_builder())->build($node, $this->make_context());

        $pages = $this->get_pages($result->instanceid);
        $this->assertStringNotContainsString('script', $pages[0]->contents);
        $this->assertStringNotContainsString('script', $this->get_answers($pages[1]->id)[0]->response);
    }

    /**
     * A student can go through the lesson: the first page is the one it opens on, and its button leads on.
     */
    public function test_the_lesson_can_be_followed_as_a_student(): void {
        global $DB;
        $result = (new lesson_builder())->build($this->node('s2.lesson1'), $this->make_context());
        $lesson = new \lesson($DB->get_record('lesson', ['id' => $result->instanceid], '*', MUST_EXIST));
        $pages = $lesson->load_all_pages();

        $first = $lesson->load_page(reset($pages)->id);
        $this->assertSame('Introducere', $first->title);
        $next = $lesson->load_page($first->nextpageid);
        $this->assertSame('Verificare', $next->title);

        // The page of content leads to the next page when its button is pressed.
        $answers = $first->get_answers();
        $this->assertSame($next->id, $first->nextpageid);
        $this->assertEquals(LESSON_NEXTPAGE, reset($answers)->jumpto);
        $this->assertTrue($next->is_question());
    }

    /**
     * The manual completion of the golden blueprint is not needed here: the completion of a node is still set.
     */
    public function test_completion_is_set(): void {
        $node = $this->node('s2.lesson1');
        $node['completion'] = ['mode' => 'manual'];

        $result = (new lesson_builder())->build($node, $this->make_context());

        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->get_cm($result)->completion);
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new lesson_builder();
        $this->record($context, $builder->build($this->node('s2.lesson1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s2.lesson1'), $context)->status);
        $this->assertSame(1, $DB->count_records('lesson'));
    }
}
