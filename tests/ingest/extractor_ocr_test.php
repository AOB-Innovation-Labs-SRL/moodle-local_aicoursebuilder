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
 * Tests for the OCR extractor and for its use by the PDF extractor.
 *
 * The tests that run OCR need pdftoppm and tesseract with the Romanian language data
 * (Debian: poppler-utils, tesseract-ocr, tesseract-ocr-ron) and are skipped without them.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_ocr
 * @covers     \local_aicoursebuilder\ingest\extractor_pdf
 */
final class extractor_ocr_test extends \advanced_testcase {
    use ingest_test_helpers;

    /** @var float Minimum share of the reference words that OCR must read from a clean scan. */
    private const MIN_USEFUL_TEXT = 0.9;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Sets the paths of pdftoppm and tesseract in the settings, or skips the test.
     *
     * @return string Path of pdftoppm.
     */
    private function configure_ocr(): string {
        $pdftoppm = $this->require_program('pdftoppm');
        $tesseract = $this->require_program('tesseract');
        if (!str_contains((string) shell_exec(escapeshellarg($tesseract) . ' --list-langs 2>&1'), 'ron')) {
            $this->markTestSkipped('The Romanian language data of tesseract is not installed.');
        }
        set_config('pdftoppmpath', $pdftoppm, 'local_aicoursebuilder');
        set_config('tesseractpath', $tesseract, 'local_aicoursebuilder');
        return $pdftoppm;
    }

    /**
     * A scanned PDF has no text layer; OCR reads it, with a marker for every page.
     */
    public function test_reads_a_scanned_pdf(): void {
        $scan = source_fixtures::scanned_pdf($this->configure_ocr());
        $file = $this->store($scan, 'scan.pdf');

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_OCR, $result->extractor);
        $this->assertSame([extraction_result::WARNING_PDFPARSER_FAILED], $result->warnings);
        $this->assertSame(3, $result->pagecount);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::pdf_reference(), $result->markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);
        $this->assertStringContainsString('<!-- page 1 -->', $result->markdown);
        $this->assertStringContainsString('<!-- page 3 -->', $result->markdown);
    }

    /**
     * A PDF that has text does not go to OCR.
     */
    public function test_text_pdf_is_not_read_with_ocr(): void {
        $this->configure_ocr();
        $file = $this->store(source_fixtures::pdf(), 'text.pdf');

        $result = (new extractor_pdf())->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_PDFPARSER, $result->extractor);
    }

    /**
     * Without the paths in the settings a scan is still the "no text" error.
     */
    public function test_ocr_off(): void {
        set_config('pdftoppmpath', '', 'local_aicoursebuilder');
        set_config('tesseractpath', '', 'local_aicoursebuilder');
        $this->assertFalse((new extractor_ocr())->is_available());
        $file = $this->store(source_fixtures::blank_pdf(), 'scan.pdf');

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::NO_TEXT, $e->errorcode);
        }
    }

    /**
     * OCR needs both programs: one of them is not enough.
     */
    public function test_ocr_needs_both_programs(): void {
        set_config('pdftoppmpath', $this->require_program('pdftoppm'), 'local_aicoursebuilder');
        set_config('tesseractpath', '/nonexistent/bin/tesseract', 'local_aicoursebuilder');

        $this->assertFalse((new extractor_ocr())->is_available());
    }

    /**
     * A page without any text gives the "no text" error, and the PDF extractor reports it the same way.
     */
    public function test_blank_page(): void {
        $this->configure_ocr();
        $file = $this->store(source_fixtures::blank_pdf(), 'blank.pdf');

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::NO_TEXT, $e->errorcode);
        }
    }

    /**
     * The language comes from the settings; a value that is not a language list gives the default.
     */
    public function test_language_setting(): void {
        $ocr = new extractor_ocr();
        $this->assertSame('ron', $ocr->get_language());

        set_config('ocrlanguage', 'ron+eng', 'local_aicoursebuilder');
        $this->assertSame('ron+eng', $ocr->get_language());

        set_config('ocrlanguage', 'ron; rm -rf /', 'local_aicoursebuilder');
        $this->assertSame(extractor_ocr::DEFAULT_LANGUAGE, $ocr->get_language());
    }

    /**
     * A failing pdftoppm is an extractor error that names the program and not the content.
     */
    public function test_pdftoppm_fails(): void {
        $this->configure_ocr();
        $file = $this->store('this is not a pdf', 'broken.pdf');

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }
}
