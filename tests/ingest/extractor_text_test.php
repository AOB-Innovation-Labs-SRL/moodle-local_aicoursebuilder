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

namespace local_aicoursebuilder\ingest;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sources/source_fixtures.php');
require_once(__DIR__ . '/../fixtures/sources/ingest_test_helpers.php');

/**
 * Tests for the plain text, Markdown and HTML extractor, and for the choice of extractor by file type.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_text
 * @covers     \local_aicoursebuilder\ingest\extractor_factory
 */
final class extractor_text_test extends \advanced_testcase {
    use ingest_test_helpers;

    /** @var float Minimum share of the reference words that the extracted text must have. */
    private const MIN_USEFUL_TEXT = 0.95;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Plain text is kept as it is.
     */
    public function test_plain_text(): void {
        $file = $this->store(source_fixtures::text(), 'policy.txt');

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertInstanceOf(extractor_text::class, extractor_factory::for_file($file));
        $this->assertSame(extraction_result::EXTRACTOR_TEXT, $result->extractor);
        $this->assertNull($result->pagecount);
        $this->assertSame(trim(source_fixtures::text()), $result->markdown);
    }

    /**
     * Markdown is kept as it is. Moodle has no Markdown type, so the extension chooses the extractor.
     */
    public function test_markdown(): void {
        $file = $this->store(source_fixtures::markdown(), 'policy.md');

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertSame(trim(source_fixtures::markdown()), $result->markdown);
    }

    /**
     * HTML loses its tags, scripts and styles; headings become Markdown headings.
     */
    public function test_html(): void {
        $file = $this->store(source_fixtures::html(), 'policy.html');

        $result = extractor_factory::for_file($file)->extract($file);

        $markdown = $result->markdown;
        $this->assertStringContainsString('# Politica de securitate', $markdown);
        $this->assertStringContainsString('## Confirmare', $markdown);
        $this->assertStringContainsString(source_fixtures::TEXT_PARAGRAPHS[0], $markdown);
        $this->assertStringContainsString('primul punct', $markdown);
        $this->assertStringNotContainsString('<', $markdown);
        $this->assertStringNotContainsString('ascuns', $markdown);
        $this->assertStringNotContainsString('color: red', $markdown);
        $this->assertStringNotContainsString('Titlu de pagină', $markdown);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::text_reference(), $markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);
    }

    /**
     * A file in a Romanian Windows code page (not UTF-8) is read correctly, and so are a byte order mark and UTF-16.
     *
     * Windows-1250 has the letters with a cedilla (ş, ţ), not the ones with a comma below; the extractor
     * returns what the file holds and leaves the normalisation of the letters to a later step.
     */
    public function test_encodings(): void {
        $text = 'Ştefan şi Ţicu învaţă în şcoala din Braşov, ăâî.';
        $extractor = new extractor_text();

        $windows = \core_text::convert($text, 'utf-8', 'windows-1250');
        $this->assertFalse(mb_check_encoding($windows, 'UTF-8'));
        $this->assertSame($text, $extractor->extract_string($windows)->markdown);

        $this->assertSame($text, $extractor->extract_string("\xEF\xBB\xBF" . $text)->markdown);
        $utf16 = "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $this->assertSame($text, $extractor->extract_string($utf16)->markdown);
    }

    /**
     * A file with only white space is an error, not an empty result.
     */
    public function test_empty_text(): void {
        $file = $this->store("  \n\n ", 'empty.txt');

        try {
            (new extractor_text())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::NO_TEXT, $e->errorcode);
        }
    }

    /**
     * The factory finds an extractor for every supported type, by mimetype or by extension.
     */
    public function test_factory_types(): void {
        $this->assertInstanceOf(extractor_pptx::class, extractor_factory::for_mimetype(extractor_factory::MIMETYPES['pptx']));
        $this->assertInstanceOf(extractor_xlsx::class, extractor_factory::for_mimetype(extractor_factory::MIMETYPES['xlsx']));
        foreach (['txt', 'md', 'html'] as $type) {
            $this->assertInstanceOf(extractor_text::class, extractor_factory::for_mimetype(extractor_factory::MIMETYPES[$type]));
        }
        $this->assertSame('md', extractor_factory::type_for_filename('Notes.MD'));

        // A file whose mimetype Moodle does not know is found by its extension.
        $file = $this->store('# Titlu', 'notes.md');
        $this->assertInstanceOf(extractor_text::class, extractor_factory::for_file($file));

        $this->expectException(ingest_exception::class);
        extractor_factory::for_file($this->store('data', 'archive.zip'));
    }
}
