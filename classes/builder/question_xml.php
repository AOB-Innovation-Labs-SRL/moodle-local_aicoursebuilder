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
 * Turns the questions of a blueprint quiz into a Moodle XML document (spec 3.7, 4, 5).
 *
 * The questions are created by importing this document with qformat_xml rather than by calling
 * save_question(), which spec 3.7 forbids: the import format is the only supported way to create a
 * question of any type without knowing the internals of its qtype.
 *
 * The generator is deterministic and has no state of its own: the same blueprint always produces
 * byte-identical XML, which is what makes a re-run comparable with the run before it. It reads only the
 * fields of schema/blueprint.v1.json and invents none.
 *
 * Two details of the format matter and are easy to get wrong:
 *  - a fraction is a percentage in the XML (1.0 in the blueprint is fraction="100"), because the import
 *    divides it by 100;
 *  - the order of the choices of gapselect and ddwtos is the contract: the gap [[1]] of the question text
 *    takes the first choice of its group, [[2]] the second, so the generator never reorders them.
 *
 * multianswer (cloze) is in the schema but is reserved: its sub-questions live inside the question text in
 * a syntax of their own, which the pilot does not generate, so it is refused with a clear message instead
 * of being written as a broken question (spec 5).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_xml {
    /** @var string The qtype that is in the schema but is not generated. */
    public const RESERVED_QTYPE = 'multianswer';

    /** @var string[] The qtypes this generator writes. */
    public const SUPPORTED_QTYPES = [
        'multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay', 'gapselect', 'ddwtos',
    ];

    /** @var int Default mark of a question the blueprint does not give one for. */
    public const DEFAULT_MARK = 1;

    /**
     * Returns the Moodle XML document of a list of blueprint questions.
     *
     * The document carries no category: the category is chosen by the importer with setCategory(), so that
     * the questions land in the subcategory of this quiz whatever the file says (setCatfromfile(false)).
     *
     * @param array $questions Blueprint questions, as defined by $defs/question of the schema.
     * @return string The XML document.
     * @throws \moodle_exception When a question cannot be written, including a reserved qtype.
     */
    public function generate(array $questions): string {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n";
        foreach ($questions as $question) {
            $xml .= $this->question((array) $question);
        }
        $xml .= "</quiz>\n";
        return $xml;
    }

    /**
     * Returns the XML of one question.
     *
     * @param array $question One blueprint question.
     * @return string
     * @throws \moodle_exception When the qtype is reserved or unknown.
     */
    protected function question(array $question): string {
        $qtype = (string) ($question['qtype'] ?? '');
        if ($qtype === self::RESERVED_QTYPE) {
            throw new \moodle_exception('errorqtypereserved', 'local_aicoursebuilder', '', $qtype);
        }
        if (!in_array($qtype, self::SUPPORTED_QTYPES, true)) {
            throw new \moodle_exception('errorqtypeunknown', 'local_aicoursebuilder', '', $qtype);
        }

        $xml = "  <question type=\"{$qtype}\">\n";
        $xml .= "    <name>\n" . $this->text((string) ($question['name'] ?? ''), 3) . "    </name>\n";
        $xml .= "    <questiontext format=\"html\">\n"
            . $this->text($this->html((string) ($question['questiontext'] ?? '')), 3)
            . "    </questiontext>\n";
        if (isset($question['generalfeedback'])) {
            $xml .= "    <generalfeedback format=\"html\">\n"
                . $this->text($this->html((string) $question['generalfeedback']), 3)
                . "    </generalfeedback>\n";
        }
        $xml .= '    <defaultgrade>' . $this->number($question['defaultmark'] ?? self::DEFAULT_MARK)
            . "</defaultgrade>\n";
        $xml .= $this->body($qtype, $question);
        $xml .= "  </question>\n";
        return $xml;
    }

    /**
     * Returns the part of the XML that is particular to one qtype.
     *
     * @param string $qtype The question type.
     * @param array $question The blueprint question.
     * @return string
     * @throws \moodle_exception When the question lacks what its type needs.
     */
    protected function body(string $qtype, array $question): string {
        return match ($qtype) {
            'multichoice' => $this->multichoice($question),
            'truefalse' => $this->truefalse($question),
            'shortanswer' => $this->shortanswer($question),
            'numerical' => $this->numerical($question),
            'match' => $this->match($question),
            'essay' => $this->essay($question),
            'gapselect' => $this->gapselect($question, 'selectoption'),
            'ddwtos' => $this->gapselect($question, 'dragbox'),
        };
    }

    /**
     * Returns the body of a multichoice question, single or multiple answer.
     *
     * @param array $question The blueprint question.
     * @return string
     * @throws \moodle_exception When the question has no answers.
     */
    protected function multichoice(array $question): string {
        $answers = $this->answers($question);
        $single = (bool) ($question['single'] ?? true);
        $xml = '    <single>' . $this->bool($single) . "</single>\n";
        $xml .= '    <shuffleanswers>' . $this->bool($question['shuffleanswers'] ?? true) . "</shuffleanswers>\n";
        $xml .= "    <answernumbering>abc</answernumbering>\n";
        foreach ($answers as $answer) {
            $xml .= $this->answer($answer);
        }
        return $xml;
    }

    /**
     * Returns the body of a true/false question.
     *
     * The import matches the answers by their text, which has to be the literal true and false, and takes
     * the one with fraction 100 as the correct one.
     *
     * @param array $question The blueprint question.
     * @return string
     * @throws \moodle_exception When the answers are not a true and a false.
     */
    protected function truefalse(array $question): string {
        $answers = $this->answers($question);
        $bytext = [];
        foreach ($answers as $answer) {
            $text = \core_text::strtolower(trim((string) ($answer['text'] ?? '')));
            if ($text === 'true' || $text === 'false') {
                $bytext[$text] = $answer;
            }
        }
        if (!isset($bytext['true'], $bytext['false'])) {
            throw new \moodle_exception(
                'errorquestiontruefalse',
                'local_aicoursebuilder',
                '',
                (string) ($question['name'] ?? '')
            );
        }
        $xml = '';
        foreach (['true', 'false'] as $text) {
            $answer = $bytext[$text];
            $xml .= '    <answer fraction="' . $this->fraction($answer['fraction'] ?? 0) . "\" format=\"html\">\n";
            $xml .= $this->text($text, 3);
            $xml .= $this->feedback($answer, 3);
            $xml .= "    </answer>\n";
        }
        return $xml;
    }

    /**
     * Returns the body of a short answer question.
     *
     * @param array $question The blueprint question.
     * @return string
     * @throws \moodle_exception When the question has no answers.
     */
    protected function shortanswer(array $question): string {
        $xml = "    <usecase>0</usecase>\n";
        foreach ($this->answers($question) as $answer) {
            $xml .= $this->answer($answer);
        }
        return $xml;
    }

    /**
     * Returns the body of a numerical question.
     *
     * The tolerance belongs to the answer in the schema, which is also where the XML keeps it.
     *
     * @param array $question The blueprint question.
     * @return string
     * @throws \moodle_exception When the question has no answers.
     */
    protected function numerical(array $question): string {
        $xml = '';
        foreach ($this->answers($question) as $answer) {
            $xml .= '    <answer fraction="' . $this->fraction($answer['fraction'] ?? 0) . "\" format=\"moodle_auto_format\">\n";
            $xml .= $this->text((string) ($answer['text'] ?? ''), 3);
            $xml .= $this->feedback($answer, 3);
            $xml .= '      <tolerance>' . $this->number($answer['tolerance'] ?? 0) . "</tolerance>\n";
            $xml .= "    </answer>\n";
        }
        return $xml;
    }

    /**
     * Returns the body of a matching question.
     *
     * @param array $question The blueprint question.
     * @return string
     * @throws \moodle_exception When the question has fewer than the two pairs the qtype needs.
     */
    protected function match(array $question): string {
        $subquestions = $question['subquestions'] ?? [];
        if (count($subquestions) < 2) {
            throw new \moodle_exception(
                'errorquestionmatch',
                'local_aicoursebuilder',
                '',
                (string) ($question['name'] ?? '')
            );
        }
        $xml = '    <shuffleanswers>' . $this->bool($question['shuffleanswers'] ?? true) . "</shuffleanswers>\n";
        foreach ($subquestions as $pair) {
            $pair = (array) $pair;
            $xml .= "    <subquestion format=\"html\">\n";
            $xml .= $this->text($this->html((string) ($pair['text'] ?? '')), 3);
            $xml .= "      <answer>\n";
            $xml .= $this->text((string) ($pair['answer'] ?? ''), 4);
            $xml .= "      </answer>\n";
            $xml .= "    </subquestion>\n";
        }
        return $xml;
    }

    /**
     * Returns the body of an essay question.
     *
     * @param array $question The blueprint question.
     * @return string
     */
    protected function essay(array $question): string {
        $xml = '    <responseformat>' . s((string) ($question['responseformat'] ?? 'editor')) . "</responseformat>\n";
        $xml .= "    <responserequired>1</responserequired>\n";
        $xml .= "    <responsefieldlines>10</responsefieldlines>\n";
        $xml .= "    <attachments>0</attachments>\n";
        $xml .= "    <attachmentsrequired>0</attachmentsrequired>\n";
        $xml .= "    <graderinfo format=\"html\">\n"
            . $this->text($this->html((string) ($question['graderinfo'] ?? '')), 3)
            . "    </graderinfo>\n";
        return $xml;
    }

    /**
     * Returns the body of a gapselect or ddwtos question, which differ only in the name of the choice tag.
     *
     * The choices keep the order of the blueprint, because the gaps of the question text refer to them by
     * position: [[1]] is the first choice of its group.
     *
     * @param array $question The blueprint question.
     * @param string $tag selectoption for gapselect, dragbox for ddwtos.
     * @return string
     * @throws \moodle_exception When the question has no choices.
     */
    protected function gapselect(array $question, string $tag): string {
        $choices = $question['choices'] ?? [];
        if ($choices === []) {
            throw new \moodle_exception(
                'errorquestionchoices',
                'local_aicoursebuilder',
                '',
                (string) ($question['name'] ?? '')
            );
        }
        $xml = '    <shuffleanswers>' . $this->bool($question['shuffleanswers'] ?? true) . "</shuffleanswers>\n";
        foreach ($choices as $choice) {
            $choice = (array) $choice;
            $xml .= "    <{$tag}>\n";
            $xml .= $this->text((string) ($choice['text'] ?? ''), 3);
            $xml .= '      <group>' . (int) ($choice['group'] ?? 1) . "</group>\n";
            if ($tag === 'dragbox' && !empty($choice['infinite'])) {
                $xml .= "      <infinite/>\n";
            }
            $xml .= "    </{$tag}>\n";
        }
        return $xml;
    }

    /**
     * Returns the answers of a question, refusing one that has none.
     *
     * @param array $question The blueprint question.
     * @return array The answers, as arrays.
     * @throws \moodle_exception When the question has no answers.
     */
    protected function answers(array $question): array {
        $answers = $question['answers'] ?? [];
        if ($answers === []) {
            throw new \moodle_exception(
                'errorquestionnoanswers',
                'local_aicoursebuilder',
                '',
                (string) ($question['name'] ?? '')
            );
        }
        return array_map(fn($answer): array => (array) $answer, $answers);
    }

    /**
     * Returns the XML of one plain answer, with its fraction and its feedback.
     *
     * @param array $answer One blueprint answer.
     * @return string
     */
    protected function answer(array $answer): string {
        $xml = '    <answer fraction="' . $this->fraction($answer['fraction'] ?? 0) . "\" format=\"html\">\n";
        $xml .= $this->text($this->html((string) ($answer['text'] ?? '')), 3);
        $xml .= $this->feedback($answer, 3);
        $xml .= "    </answer>\n";
        return $xml;
    }

    /**
     * Returns the feedback element of an answer, when it has one.
     *
     * @param array $answer One blueprint answer.
     * @param int $indent Indent level.
     * @return string Empty string when the answer has no feedback.
     */
    protected function feedback(array $answer, int $indent): string {
        if (!isset($answer['feedback']) || trim((string) $answer['feedback']) === '') {
            return '';
        }
        $pad = str_repeat('  ', $indent);
        return $pad . "<feedback format=\"html\">\n"
            . $this->text($this->html((string) $answer['feedback']), $indent + 1)
            . $pad . "</feedback>\n";
    }

    /**
     * Returns a text element, with the value in CDATA so that no HTML has to be escaped (spec 3.7).
     *
     * @param string $value The text.
     * @param int $indent Indent level.
     * @return string
     */
    protected function text(string $value, int $indent): string {
        $pad = str_repeat('  ', $indent);
        // A CDATA section cannot hold the sequence that ends it, so it is split across two sections.
        $value = str_replace(']]>', ']]]]><![CDATA[>', $value);
        return $pad . "<text><![CDATA[{$value}]]></text>\n";
    }

    /**
     * Cleans the HTML that the model produced, before it is stored (spec 9.1, security checklist).
     *
     * @param string $value The HTML.
     * @return string
     */
    protected function html(string $value): string {
        return clean_text($value, FORMAT_HTML);
    }

    /**
     * Returns a fraction as the percentage the import expects.
     *
     * @param float|int|string $fraction Fraction of the blueprint, between -1 and 1.
     * @return string
     */
    protected function fraction(float|int|string $fraction): string {
        return $this->number((float) $fraction * 100);
    }

    /**
     * Formats a number the same way on every run and on every locale.
     *
     * @param float|int|string $value The number.
     * @return string
     */
    protected function number(float|int|string $value): string {
        $value = (float) $value;
        if ($value === floor($value) && abs($value) < 1.0e+15) {
            return (string) (int) $value;
        }
        return rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');
    }

    /**
     * Returns a boolean as the 1 or 0 the import expects.
     *
     * @param mixed $value The value.
     * @return string
     */
    protected function bool(mixed $value): string {
        return $value ? '1' : '0';
    }
}
