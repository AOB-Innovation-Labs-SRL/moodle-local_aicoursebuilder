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

/**
 * Builds a lesson: the module and its pages, with the answers and the jumps between them.
 *
 * The pages are made the way the page editor of the lesson makes them, with lesson_page::create(), one after the
 * other, each after the page before it. A page of content is a branch table whose answers are the buttons the
 * student presses to go on; the questions (multiple choice, true or false, short answer) keep the answers of the
 * blueprint with their feedback and score. A jump that names another page of the lesson (p3) can point forward, so
 * it is set once every page exists; the other jumps (next, this, end) are constants of the lesson.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_builder extends module_builder {
    /** @var int Page type: short answer (LESSON_PAGE_SHORTANSWER). */
    public const QTYPE_SHORTANSWER = 1;

    /** @var int Page type: true or false (LESSON_PAGE_TRUEFALSE). */
    public const QTYPE_TRUEFALSE = 2;

    /** @var int Page type: multiple choice (LESSON_PAGE_MULTICHOICE). */
    public const QTYPE_MULTICHOICE = 3;

    /** @var int Page type: branch table (LESSON_PAGE_BRANCHTABLE), the page of content. */
    public const QTYPE_BRANCHTABLE = 20;

    /** @var int[] Blueprint page type => page type of the lesson. */
    public const QTYPES = [
        'content' => self::QTYPE_BRANCHTABLE,
        'multichoice' => self::QTYPE_MULTICHOICE,
        'truefalse' => self::QTYPE_TRUEFALSE,
        'shortanswer' => self::QTYPE_SHORTANSWER,
    ];

    /** @var int Most answers a page can have: the highest value of the maximum number of answers of the lesson. */
    public const MAX_ANSWERS = 20;

    /** @var int Fewest attempts a question gets: with one, a jump back to the same page would never repeat it. */
    public const MIN_ATTEMPTS = 3;

    /** @var int Grade of the lesson, in points. */
    public const GRADE = 100;

    /** @var string[] Settings of the lesson the site sets under the same name as the field of the lesson. */
    protected const SAME_NAME = [
        'practice', 'modattempts', 'ongoing', 'activitylink', 'mediaheight', 'mediawidth', 'mediaclose', 'slideshow',
        'displayleftif', 'progressbar',
    ];

    /** @var string[] Field of the lesson => name of the site setting, where they differ. */
    protected const RENAMED = [
        'maxattempts' => 'maximumnumberofattempts',
        'review' => 'displayreview',
        'nextpagedefault' => 'defaultnextpage',
        'feedback' => 'defaultfeedback',
        'minquestions' => 'minimumnumberofquestions',
        'maxpages' => 'numberofpagestoshow',
        'retake' => 'retakesallowed',
        'usemaxgrade' => 'handlingofretakes',
        'displayleft' => 'displayleftmenu',
    ];

    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'lesson';
    }

    /**
     * Adds the settings of the lesson.
     *
     * The lesson keeps the score of every answer (custom scoring), so a question is right when its answer has a
     * score; the other settings are the site defaults of the module.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        $config = get_config('mod_lesson');
        foreach (self::SAME_NAME as $field) {
            $info->{$field} = (int) ($config->{$field} ?? 0);
        }
        foreach (self::RENAMED as $field => $setting) {
            $info->{$field} = (int) ($config->{$setting} ?? 0);
        }

        // A value of 0 is unlimited; a smaller one than the minimum would end a question after its first wrong answer.
        if ($info->maxattempts !== 0) {
            $info->maxattempts = max($info->maxattempts, self::MIN_ATTEMPTS);
        }
        $info->usepassword = 0;
        $info->dependency = 0;
        $info->mediafile = 0;
        $info->custom = 1;
        $info->grade = self::GRADE;
        $info->width = 640;
        $info->height = 480;
        $info->bgcolor = '#FFFFFF';
        $info->available = 0;
        $info->deadline = 0;
        $info->timelimit = 0;
        $info->allowofflineattempts = 0;
        $info->maxanswers = min(
            self::MAX_ANSWERS,
            max((int) ($config->maxanswers ?? 4), $this->count_most_answers($node))
        );
    }

    /**
     * Makes the pages of the lesson, then sets the jumps that name a page.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');

        $lesson = new \lesson($DB->get_record('lesson', ['id' => $created->instance], '*', MUST_EXIST));
        $modulecontext = \context_module::instance((int) $created->coursemodule);
        $maxbytes = (int) get_course($created->course)->maxbytes;

        $pageids = [];
        $links = [];
        $previous = 0;
        foreach ($node['content']['pages'] ?? [] as $data) {
            $answers = $this->clean_answers($data);
            $page = \lesson_page::create(
                $this->page_properties($data, $answers, $previous),
                $lesson,
                $modulecontext,
                $maxbytes
            );
            $previous = (int) $page->id;
            $pageids[(string) ($data['id'] ?? '')] = $previous;

            // The answers come back in the order they were made, which is the order of the blueprint.
            $rows = array_values($DB->get_records('lesson_answers', ['pageid' => $previous], 'id', 'id'));
            foreach ($answers as $index => $answer) {
                if (isset($rows[$index])) {
                    $links[] = [(int) $rows[$index]->id, (string) ($answer['jumpto'] ?? 'next')];
                }
            }
        }

        foreach ($links as [$answerid, $jumpto]) {
            $DB->set_field('lesson_answers', 'jumpto', $this->resolve_jump($jumpto, $pageids), ['id' => $answerid]);
        }
    }

    /**
     * Returns the properties lesson_page::create() reads, the ones the page form would give.
     *
     * @param array $data The page of the blueprint.
     * @param array[] $answers The answers of the page, cleaned.
     * @param int $previous Id of the page before this one, 0 for the first.
     * @return \stdClass
     */
    protected function page_properties(array $data, array $answers, int $previous): \stdClass {
        $qtype = self::QTYPES[$data['type'] ?? 'content'] ?? self::QTYPE_BRANCHTABLE;
        // A branch table and a short answer keep their answers as plain text; the other questions as HTML.
        $plain = $qtype === self::QTYPE_BRANCHTABLE || $qtype === self::QTYPE_SHORTANSWER;

        $properties = new \stdClass();
        $properties->title = self::clean_name((string) ($data['title'] ?? ''));
        $properties->contents_editor = [
            'text' => self::clean_html((string) ($data['contents'] ?? '')),
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
        $properties->qtype = $qtype;
        $properties->pageid = $previous;
        // The buttons of a page of content go one under the other, as in the form.
        $properties->layout = 1;
        $properties->display = 1;

        $properties->answer_editor = [];
        $properties->response_editor = [];
        $properties->jumpto = [];
        $properties->score = [];
        foreach ($answers as $index => $answer) {
            $text = (string) $answer['text'];
            $properties->answer_editor[$index] = $plain
                ? self::clean_name($text)
                : ['text' => self::clean_html($text), 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()];
            $properties->response_editor[$index] = [
                'text' => self::clean_html((string) ($answer['response'] ?? '')),
                'format' => FORMAT_HTML,
                'itemid' => file_get_unused_draft_itemid(),
            ];
            // The jump is set after all the pages exist; the lesson needs a valid value to start with.
            $properties->jumpto[$index] = LESSON_NEXTPAGE;
            $properties->score[$index] = $qtype === self::QTYPE_BRANCHTABLE ? 0 : (int) ($answer['score'] ?? 0);
        }
        return $properties;
    }

    /**
     * Returns the answers of a page that can be saved: with a text, and no more than the lesson takes.
     *
     * A true or false page has two answers. An answer with no text is not saved by the lesson, so it is left out here
     * to keep the answers in step with their jumps.
     *
     * @param array $data The page of the blueprint.
     * @return array[]
     */
    protected function clean_answers(array $data): array {
        $answers = array_values(array_filter(
            $data['answers'] ?? [],
            fn($answer) => is_array($answer) && trim(strip_tags((string) ($answer['text'] ?? ''))) !== ''
        ));
        $limit = ($data['type'] ?? '') === 'truefalse' ? 2 : self::MAX_ANSWERS;
        if (count($answers) > $limit) {
            $this->warnings[] = get_string('buildwarnlessonanswers', 'local_aicoursebuilder', (string) ($data['title'] ?? ''));
            $answers = array_slice($answers, 0, $limit);
        }
        return $answers;
    }

    /**
     * Turns the jump of the blueprint into the value the lesson stores.
     *
     * @param string $jumpto next, this, end or the id of a page of the lesson.
     * @param int[] $pageids Blueprint page id => id of the page in the lesson.
     * @return int
     */
    protected function resolve_jump(string $jumpto, array $pageids): int {
        return match ($jumpto) {
            'end' => LESSON_EOL,
            'this' => LESSON_THISPAGE,
            'next' => LESSON_NEXTPAGE,
            default => $pageids[$jumpto] ?? $this->unknown_jump($jumpto),
        };
    }

    /**
     * Records a jump to a page the lesson does not have and sends it to the next page.
     *
     * @param string $jumpto The jump.
     * @return int
     */
    protected function unknown_jump(string $jumpto): int {
        $this->warnings[] = get_string('buildwarnlessonjump', 'local_aicoursebuilder', $jumpto);
        return LESSON_NEXTPAGE;
    }

    /**
     * Counts the answers of the page that has the most, to set the maximum number of answers of the lesson.
     *
     * @param array $node The blueprint node.
     * @return int
     */
    protected function count_most_answers(array $node): int {
        $most = 0;
        foreach ($node['content']['pages'] ?? [] as $page) {
            $most = max($most, count($page['answers'] ?? []));
        }
        return $most;
    }
}
