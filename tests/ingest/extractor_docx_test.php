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
 * Tests for the DOCX extractor.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_docx
 */
final class extractor_docx_test extends \advanced_testcase {
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
    private function store(string $content, string $filename = 'source.docx'): \stored_file {
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
     * Writes a shell script that takes the place of soffice: it copies a PDF to the output directory.
     *
     * @param string $pdf PDF content that the script "converts" to.
     * @param bool $fail True for a script that exits with an error and writes nothing.
     * @return string Path of the script.
     */
    private function fake_soffice(string $pdf, bool $fail = false): string {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('The fake soffice is a shell script.');
        }
        $directory = make_request_directory();
        file_put_contents($directory . '/converted.pdf', $pdf);
        $script = "#!/bin/sh\n";
        if ($fail) {
            $script .= "exit 1\n";
        } else {
            $script .= "out=''\n"
                . "while [ \$# -gt 0 ]; do\n"
                . "  if [ \"\$1\" = '--outdir' ]; then out=\"\$2\"; fi\n"
                . "  shift\n"
                . "done\n"
                . 'cp ' . escapeshellarg($directory . '/converted.pdf') . " \"\$out/source.pdf\"\n"
                . 'touch ' . escapeshellarg($directory . '/was-run') . "\n";
        }
        file_put_contents($directory . '/soffice', $script);
        chmod($directory . '/soffice', 0755);
        return $directory . '/soffice';
    }

    /**
     * Headings become #, ## and ###, lists become items and the table becomes a Markdown table.
     */
    public function test_headings_lists_and_tables(): void {
        $file = $this->store(source_fixtures::docx());

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_PHPWORD, $result->extractor);
        $this->assertSame([], $result->warnings);
        $markdown = $result->markdown;
        $this->assertStringContainsString("# Ghid de utilizare a platformei\n", $markdown);
        $this->assertStringContainsString("\n## Crearea unui cont nou\n", $markdown);
        $this->assertStringContainsString("\n### Validarea adresei de contact\n", $markdown);
        $this->assertStringNotContainsString('#### ', $markdown);
        $this->assertStringContainsString(
            "- Deschide mesajul de activare\n- Alege o parolă nouă\n- Confirmă alegerea",
            $markdown
        );
        $this->assertStringContainsString(
            "| Rol | Drepturi | Observații |\n| --- | --- | --- |\n"
                . "| Cursant | Citire | Doar cursurile proprii |\n| Profesor | Editare | Cursurile atribuite |",
            $markdown
        );
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::docx_reference(), $markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);
    }

    /**
     * Headings are found when the style ids are local, as in Word in Romanian (Titlu1 for "heading 1").
     */
    public function test_headings_with_local_style_ids(): void {
        $file = $this->store(source_fixtures::docx_localised());

        $result = (new extractor_docx())->extract($file);

        $this->assertStringContainsString("# Ghid de utilizare a platformei\n", $result->markdown);
        $this->assertStringContainsString("\n## Crearea unui cont nou\n", $result->markdown);
        $this->assertStringContainsString("\n### Validarea adresei de contact\n", $result->markdown);
    }

    /**
     * A document without text is an error, not an empty result.
     */
    public function test_docx_without_text(): void {
        $file = $this->store(source_fixtures::empty_docx());

        try {
            (new extractor_docx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::NO_TEXT, $e->errorcode);
        }
    }

    /**
     * A file that is not a DOCX fails with the extractor error.
     */
    public function test_corrupt_docx(): void {
        $file = $this->store('this is not a zip archive');

        try {
            (new extractor_docx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * When PhpWord fails and both programs are configured, LibreOffice and pdftotext give the text.
     */
    public function test_libreoffice_fallback(): void {
        set_config('pdftotextpath', $this->require_pdftotext(), 'local_aicoursebuilder');
        set_config('sofficepath', $this->fake_soffice(source_fixtures::pdf()), 'local_aicoursebuilder');
        $file = $this->store('this is not a zip archive');

        $result = (new extractor_docx())->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_LIBREOFFICE, $result->extractor);
        $this->assertSame([extraction_result::WARNING_PHPWORD_FAILED], $result->warnings);
        $this->assertSame(3, $result->pagecount);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::pdf_reference(), $result->markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);
    }

    /**
     * Without a path in the settings the fallback is off.
     */
    public function test_fallback_skipped_when_not_configured(): void {
        set_config('sofficepath', '', 'local_aicoursebuilder');
        set_config('pdftotextpath', '', 'local_aicoursebuilder');
        $file = $this->store('this is not a zip archive');

        try {
            (new extractor_docx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * A path to a program that does not exist is skipped cleanly.
     */
    public function test_fallback_skipped_when_binary_missing(): void {
        set_config('sofficepath', '/nonexistent/bin/soffice', 'local_aicoursebuilder');
        set_config('pdftotextpath', '/nonexistent/bin/pdftotext', 'local_aicoursebuilder');
        $file = $this->store('this is not a zip archive');

        try {
            (new extractor_docx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * LibreOffice is not run when pdftotext, which reads its PDF, is not configured.
     */
    public function test_libreoffice_not_run_without_pdftotext(): void {
        $soffice = $this->fake_soffice(source_fixtures::pdf());
        set_config('sofficepath', $soffice, 'local_aicoursebuilder');
        set_config('pdftotextpath', '', 'local_aicoursebuilder');
        $file = $this->store('this is not a zip archive');

        try {
            (new extractor_docx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
        $this->assertFileDoesNotExist(dirname($soffice) . '/was-run');
    }

    /**
     * A LibreOffice that fails does not hide the original failure.
     */
    public function test_libreoffice_fails(): void {
        set_config('pdftotextpath', $this->require_pdftotext(), 'local_aicoursebuilder');
        set_config('sofficepath', $this->fake_soffice(source_fixtures::pdf(), true), 'local_aicoursebuilder');
        $file = $this->store('this is not a zip archive');

        try {
            (new extractor_docx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }
}
