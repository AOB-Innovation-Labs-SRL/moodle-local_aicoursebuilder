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

namespace local_aicoursebuilder\pipeline;

/**
 * Tests of the local JSON repair: what it recovers, and what it must leave alone.
 *
 * The second half matters as much as the first. A repair that reaches inside a string would change
 * course content rather than fix its shape, and nothing downstream would notice.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\pipeline\json_repair
 */
final class json_repair_test extends \basic_testcase {
    /**
     * Returns the three-backtick fence, built rather than written, so this file holds none.
     *
     * @return string
     */
    private function fence(): string {
        return str_repeat("\x60", 3);
    }

    /**
     * Broken and wrapped answers are decoded into the object they meant.
     *
     * @dataProvider decode_provider
     * @param string $content What the model returned.
     * @param array|null $expected What it meant.
     */
    public function test_decode(string $content, ?array $expected): void {
        $this->assertSame($expected, json_repair::decode($content));
    }

    /**
     * The answers a model really returns, wrapped, broken and truncated.
     *
     * @return array[]
     */
    public static function decode_provider(): array {
        return [
            'a plain object' => ['{"a":1}', ['a' => 1]],
            'prose around it' => ['Rezultatul este {"a": 3} — gata.', ['a' => 3]],
            'a trailing comma in an object' => ['{"a":1,}', ['a' => 1]],
            'a trailing comma in an array' => ['{"a":[1,2,],}', ['a' => [1, 2]]],
            'python literals' => ['{"a":True,"b":False,"c":None}', ['a' => true, 'b' => false, 'c' => null]],
            'single quotes' => ["{'a': 'x'}", ['a' => 'x']],
            'a line comment' => ["{\n // notă\n \"a\": 1\n}", ['a' => 1]],
            'a block comment' => ['{/* notă */"a":1}', ['a' => 1]],
            'an object cut off mid-value' => ['{"a":1,"b":{"c":2', ['a' => 1, 'b' => ['c' => 2]]],
            'an array cut off' => ['{"a":[1,2', ['a' => [1, 2]]],
            'a string cut off' => ['{"a":"abc', ['a' => 'abc']],
            'a key with no value yet' => ['{"a":1,"b":', ['a' => 1]],
            'nothing usable' => ['Nu pot genera acest conținut.', null],
            'nothing at all' => ['', null],
        ];
    }

    /**
     * A fenced block is preferred over anything the model wrote around it.
     */
    public function test_a_fenced_block_wins(): void {
        $fence = $this->fence();

        $this->assertSame(
            ['a' => 1],
            json_repair::decode("Iată rezultatul:\n{$fence}json\n{\"a\": 1}\n{$fence}\nSper că ajută."),
        );
        $this->assertSame(['a' => 2], json_repair::decode("{$fence}\n{\"a\": 2}\n{$fence}"));
        $this->assertSame(['a' => 3], json_repair::decode("{$fence}json\n{\"a\": 3}"), 'even when never closed');
    }

    /**
     * No repair reaches inside a string, where the course content lives.
     *
     * @dataProvider string_content_provider
     * @param string $content What the model returned.
     * @param array $expected What it has to stay.
     */
    public function test_string_contents_are_untouched(string $content, array $expected): void {
        $this->assertSame($expected, json_repair::decode($content));
    }

    /**
     * Text that looks like something to repair but is course content.
     *
     * @return array[]
     */
    public static function string_content_provider(): array {
        return [
            'braces in a sentence' => ['{"a":"{not json}"}', ['a' => '{not json}']],
            'apostrophes' => ['{"a":"l\'énergie, c\'est bien"}', ['a' => "l'énergie, c'est bien"]],
            'a comma before a bracket' => ['{"a":"x, ]"}', ['a' => 'x, ]']],
            'slashes of a URL' => ['{"a":"http://x/y // z"}', ['a' => 'http://x/y // z']],
            'the word True' => ['{"a":"True story"}', ['a' => 'True story']],
            'romanian diacritics' => [
                '{"a":"Energia regenerabilă și eoliană"}',
                ['a' => 'Energia regenerabilă și eoliană'],
            ],
        ];
    }

    /**
     * A whole step fixture survives being wrapped in prose, unchanged.
     */
    public function test_a_real_fixture_survives_wrapping(): void {
        $path = dirname(__DIR__) . '/fixtures/ai/outline.json';
        $expected = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $decoded = json_repair::decode("Iată structura:\n\n" . file_get_contents($path) . "\n\nSper că ajută.");

        $this->assertSame($expected, $decoded);
    }

    /**
     * Extraction returns the JSON part alone, without decoding it.
     */
    public function test_extract_returns_the_json_part(): void {
        $this->assertSame('{"a": 1}', json_repair::extract('Text {"a": 1} text'));
        $this->assertNull(json_repair::extract('Fără JSON aici.'));
        $this->assertNull(json_repair::extract(''));
    }
}
