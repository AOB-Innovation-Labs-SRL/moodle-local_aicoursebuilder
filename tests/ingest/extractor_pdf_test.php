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

/**
 * Tests for the PDF extractor.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_pdf
 * @covers     \local_aicoursebuilder\ingest\extractor_factory
 */
final class extractor_pdf_test extends \advanced_testcase {
    /** @var float Minimum share of the reference words that the extracted text must have. */
    private const MIN_USEFUL_TEXT = 0.95;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Stores a file in the source area.
     *
     * @param string $content File content.
     * @param string $filename File name.
     * @return \stored_file
     */
    private function store(string $content, string $filename = 'source.pdf'): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'source',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Returns the path of pdftotext, or skips the test when it is not installed.
     *
     * @return string
     */
    private function require_pdftotext(): string {
        $path = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($path === '' || !command_runner::is_available($path)) {
            $this->markTestSkipped('pdftotext is not installed.');
        }
        return $path;
    }

    /**
     * Returns a PDF that pdfparser cannot read (broken cross-reference table) and pdftotext can.
     *
     * @return string
     */
    private function broken_pdf(): string {
        return str_replace('startxref', 'startxrfe', source_fixtures::pdf());
    }

    /**
     * The text of the PDF is extracted with pdfparser, with a marker for every page.
     */
    public function test_extracts_text(): void {
        $file = $this->store(source_fixtures::pdf());

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_PDFPARSER, $result->extractor);
        $this->assertSame(3, $result->pagecount);
        $this->assertSame([], $result->warnings);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::pdf_reference(), $result->markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);
        $this->assertStringContainsString('<!-- page 1 -->', $result->markdown);
        $this->assertStringContainsString('<!-- page 3 -->', $result->markdown);
        $this->assertStringNotContainsString('<!-- page 4 -->', $result->markdown);
        $this->assertStringContainsString('Gestionarea parolelor', $result->markdown);
    }

    /**
     * An encrypted PDF gives a clear error, even when the pdftotext fallback is configured.
     */
    public function test_encrypted_pdf(): void {
        set_config('pdftotextpath', $this->require_pdftotext(), 'local_aicoursebuilder');
        $file = $this->store(source_fixtures::pdf(true));

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::PDF_ENCRYPTED, $e->errorcode);
            $this->assertStringContainsString('encrypted', $e->getMessage());
        }
    }

    /**
     * A PDF without text (a scan, for example) is an error, not an empty result.
     */
    public function test_pdf_without_text(): void {
        $file = $this->store(source_fixtures::blank_pdf());

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::NO_TEXT, $e->errorcode);
        }
    }

    /**
     * When pdfparser fails and pdftotext is configured, pdftotext gives the text.
     */
    public function test_pdftotext_fallback(): void {
        set_config('pdftotextpath', $this->require_pdftotext(), 'local_aicoursebuilder');
        $file = $this->store($this->broken_pdf());

        $result = (new extractor_pdf())->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_PDFTOTEXT, $result->extractor);
        $this->assertSame([extraction_result::WARNING_PDFPARSER_FAILED], $result->warnings);
        $this->assertSame(3, $result->pagecount);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::pdf_reference(), $result->markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);
        $this->assertStringContainsString('<!-- page 2 -->', $result->markdown);
    }

    /**
     * Without a path in the settings the fallback is off and the pdfparser error is reported.
     */
    public function test_fallback_skipped_when_not_configured(): void {
        set_config('pdftotextpath', '', 'local_aicoursebuilder');
        $file = $this->store($this->broken_pdf());

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * A path to a program that does not exist is skipped cleanly, like an empty path.
     */
    public function test_fallback_skipped_when_binary_missing(): void {
        set_config('pdftotextpath', '/nonexistent/bin/pdftotext', 'local_aicoursebuilder');
        $file = $this->store($this->broken_pdf());

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * A fallback program that exits with an error does not hide the original failure.
     */
    public function test_fallback_program_fails(): void {
        $false = trim((string) shell_exec('which false 2>/dev/null'));
        if ($false === '') {
            $this->markTestSkipped('The false program is not available.');
        }
        set_config('pdftotextpath', $false, 'local_aicoursebuilder');
        $file = $this->store($this->broken_pdf());

        try {
            (new extractor_pdf())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * The factory chooses the extractor by mimetype and refuses the others.
     */
    public function test_factory(): void {
        $this->assertInstanceOf(extractor_pdf::class, extractor_factory::for_mimetype('application/pdf'));
        $this->assertInstanceOf(
            extractor_docx::class,
            extractor_factory::for_mimetype(extractor_factory::MIMETYPES['docx'])
        );
        $this->assertSame('pdf', extractor_factory::type_for_filename('A.PDF'));
        $this->assertSame('docx', extractor_factory::type_for_filename('b.docx'));
        $this->assertNull(extractor_factory::type_for_filename('notes.txt'));
        $this->assertNull(extractor_factory::type_for_filename('pdf'));

        try {
            extractor_factory::for_file($this->store('text', 'notes.txt'));
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::UNSUPPORTED, $e->errorcode);
        }
    }
}
