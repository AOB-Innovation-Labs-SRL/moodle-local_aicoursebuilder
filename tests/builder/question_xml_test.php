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
 * Tests for the blueprint to Moodle XML generator.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\question_xml
 */
final class question_xml_test extends \advanced_testcase {
    /**
     * Returns the questions of the quiz node of the golden blueprint.
     *
     * @return array
     */
    private function golden_questions(): array {
        $blueprint = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($blueprint['sections'] as $section) {
            foreach ($section['activities'] ?? [] as $activity) {
                if ($activity['type'] === 'quiz') {
                    return $activity['content']['questions'];
                }
            }
        }
        throw new \coding_exception('The golden blueprint has no quiz node.');
    }

    /**
     * The same blueprint produces byte identical XML every time it is generated.
     */
    public function test_generation_is_deterministic(): void {
        $this->resetAfterTest();
        $questions = $this->golden_questions();

        $first = (new question_xml())->generate($questions);
        $second = (new question_xml())->generate($questions);

        $this->assertSame($first, $second);
        // A second generator instance must not differ either: the generator keeps no state.
        $this->assertSame($first, (new question_xml())->generate($questions));
    }

    /**
     * The document is well formed XML and holds one question per blueprint question.
     */
    public function test_document_is_well_formed(): void {
        $this->resetAfterTest();
        $xml = (new question_xml())->generate($this->golden_questions());

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml), 'The generated document parses');
        $this->assertSame('quiz', $document->documentElement->nodeName);

        $questions = $document->getElementsByTagName('question');
        $this->assertSame(8, $questions->length);
        $types = [];
        foreach ($questions as $question) {
            $types[] = $question->getAttribute('type');
        }
        $this->assertSame(
            ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay', 'gapselect', 'ddwtos'],
            $types
        );
    }

    /**
     * A fraction of the blueprint is written as the percentage the import expects.
     */
    public function test_fractions_are_percentages(): void {
        $this->resetAfterTest();
        $xml = (new question_xml())->generate([[
            'qtype' => 'multichoice',
            'name' => 'Partial credit',
            'questiontext' => '<p>Pick one.</p>',
            'answers' => [
                ['text' => 'Right', 'fraction' => 1],
                ['text' => 'Half', 'fraction' => 0.5],
                ['text' => 'Wrong', 'fraction' => -0.25],
            ],
        ]]);

        $this->assertStringContainsString('fraction="100"', $xml);
        $this->assertStringContainsString('fraction="50"', $xml);
        $this->assertStringContainsString('fraction="-25"', $xml);
    }

    /**
     * The text of a question is carried in CDATA, so its HTML survives unescaped.
     */
    public function test_text_is_wrapped_in_cdata(): void {
        $this->resetAfterTest();
        $xml = (new question_xml())->generate([[
            'qtype' => 'shortanswer',
            'name' => 'Markup',
            'questiontext' => '<p>A <strong>bold</strong> question?</p>',
            'answers' => [['text' => 'yes', 'fraction' => 1]],
        ]]);

        $this->assertStringContainsString('<![CDATA[<p>A <strong>bold</strong> question?</p>]]>', $xml);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml));
    }

    /**
     * The HTML of the model is cleaned before it is stored, so a script never reaches the question bank.
     */
    public function test_html_is_cleaned(): void {
        $this->resetAfterTest();
        $xml = (new question_xml())->generate([[
            'qtype' => 'shortanswer',
            'name' => 'Dirty',
            'questiontext' => '<p>Safe<script>alert(1)</script></p>',
            'answers' => [['text' => 'yes', 'fraction' => 1]],
        ]]);

        $this->assertStringNotContainsString('<script', $xml);
        $this->assertStringContainsString('Safe', $xml);
    }

    /**
     * A text that would end the CDATA section early is split instead of breaking the document.
     */
    public function test_cdata_end_sequence_is_escaped(): void {
        $this->resetAfterTest();
        $xml = (new question_xml())->generate([[
            'qtype' => 'shortanswer',
            'name' => 'Edgy ]]> name',
            'questiontext' => '<p>Ends with ]]> inside.</p>',
            'answers' => [['text' => 'yes', 'fraction' => 1]],
        ]]);

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml), 'The document is still well formed');
        $this->assertStringContainsString('Edgy ]]> name', $document->getElementsByTagName('name')[0]->textContent);
    }

    /**
     * ddwtos marks an unlimited choice, which gapselect has no notion of.
     */
    public function test_ddwtos_infinite_choice(): void {
        $this->resetAfterTest();
        $question = [
            'qtype' => 'ddwtos',
            'name' => 'Drag',
            'questiontext' => '<p>A [[1]] gap.</p>',
            'choices' => [
                ['text' => 'one', 'group' => 1, 'infinite' => true],
                ['text' => 'two', 'group' => 2],
            ],
        ];
        $xml = (new question_xml())->generate([$question]);
        $this->assertStringContainsString('<dragbox>', $xml);
        $this->assertStringContainsString('<infinite/>', $xml);
        $this->assertStringContainsString('<group>2</group>', $xml);

        $question['qtype'] = 'gapselect';
        $gapselect = (new question_xml())->generate([$question]);
        $this->assertStringContainsString('<selectoption>', $gapselect);
        $this->assertStringNotContainsString('<infinite/>', $gapselect);
    }

    /**
     * multianswer is in the schema but reserved, so it is refused with a message of its own.
     */
    public function test_multianswer_is_refused(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(
            get_string('errorqtypereserved', 'local_aicoursebuilder', 'multianswer')
        );

        (new question_xml())->generate([[
            'qtype' => 'multianswer',
            'name' => 'Cloze',
            'questiontext' => '<p>A {1:SHORTANSWER:=answer} gap.</p>',
        ]]);
    }

    /**
     * A question whose type needs answers and has none is refused rather than written broken.
     */
    public function test_question_without_answers_is_refused(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);

        (new question_xml())->generate([[
            'qtype' => 'shortanswer',
            'name' => 'Empty',
            'questiontext' => '<p>Nothing to match.</p>',
            'answers' => [],
        ]]);
    }

    /**
     * A true/false question needs both of its answers, named true and false.
     */
    public function test_truefalse_needs_both_answers(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);

        (new question_xml())->generate([[
            'qtype' => 'truefalse',
            'name' => 'Half',
            'questiontext' => '<p>Only one answer.</p>',
            'answers' => [['text' => 'true', 'fraction' => 1]],
        ]]);
    }
}
