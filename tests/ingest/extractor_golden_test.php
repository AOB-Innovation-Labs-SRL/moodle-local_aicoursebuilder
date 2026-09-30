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
 * Tests the extractors on the real documents of the golden set.
 *
 * The reference of the four PDF files is the text that PyMuPDF reads; the reference of the two DOCX
 * files is the text of word/document.xml; the reference of the two PPTX files is the text of the slide
 * XML and the reference of the XLSX file is its cells, read from the XML; the reference of the scanned
 * PDF is the OCR layer of the original file. All are in tests/fixtures/sources/reference, made once by
 * generate_reference.py, so that the reference does not depend on the code under test.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_pdf
 * @covers     \local_aicoursebuilder\ingest\extractor_docx
 * @covers     \local_aicoursebuilder\ingest\extractor_pptx
 * @covers     \local_aicoursebuilder\ingest\extractor_xlsx
 * @covers     \local_aicoursebuilder\ingest\extractor_ocr
 * @covers     \local_aicoursebuilder\ingest\extractor_factory
 */
final class extractor_golden_test extends \advanced_testcase {
    /** @var float Minimum share of the reference words that the extracted text must have. */
    private const MIN_USEFUL_TEXT = 0.95;

    /** @var float Minimum share of the reference words that OCR must read from the scanned book. */
    private const MIN_OCR_TEXT = 0.95;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Data provider: the real documents and their reference texts.
     *
     * @return array
     */
    public static function golden_files_provider(): array {
        $files = [];
        foreach (['pdf_text_01', 'pdf_text_02', 'pdf_text_03', 'pdf_text_04'] as $name) {
            $files[$name] = [$name . '.pdf'];
        }
        foreach (['docx_01', 'docx_02'] as $name) {
            $files[$name] = [$name . '.docx'];
        }
        foreach (['pptx_01', 'pptx_02'] as $name) {
            $files[$name] = [$name . '.pptx'];
        }
        $files['xlsx_01'] = ['xlsx_01.xlsx'];
        return $files;
    }

    /**
     * Stores a document of the golden set in the source area and reads its reference text.
     *
     * @param string $filename Name of the document in tests/fixtures/sources.
     * @return array [the stored file, the reference text]
     */
    private function load_golden_file(string $filename): array {
        $directory = __DIR__ . '/../fixtures/sources/';
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'source',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => $filename,
        ], $directory . $filename);
        $reference = file_get_contents($directory . 'reference/' . pathinfo($filename, PATHINFO_FILENAME) . '.md');
        return [$file, $reference];
    }

    /**
     * The extracted text has at least 95% of the words of the independent reference.
     *
     * @dataProvider golden_files_provider
     * @param string $filename Name of the document in tests/fixtures/sources.
     */
    public function test_extracted_text_agrees_with_reference(string $filename): void {
        [$file, $reference] = $this->load_golden_file($filename);

        $result = extractor_factory::for_file($file)->extract($file);

        $ratio = source_fixtures::useful_text_ratio($reference, $result->markdown);
        $this->assertGreaterThanOrEqual(
            self::MIN_USEFUL_TEXT,
            $ratio,
            sprintf('%s: %.2f%% of the reference words were extracted.', $filename, $ratio * 100)
        );
    }

    /**
     * The scanned book has no text layer: OCR reads it and agrees with the OCR layer of the original file.
     *
     * The reference is the ABBYY FineReader text that the original file had before the pages were turned
     * into images (see generate_reference.py). Two OCR engines do not read a 1933 print the same way, so
     * the limit is lower than for the files that have a text layer. Needs pdftoppm and tesseract with the
     * Romanian language data; it is skipped without them.
     */
    public function test_scanned_pdf_is_read_with_ocr(): void {
        $this->require_ocr();
        [$file, $reference] = $this->load_golden_file('pdf_scan_01.pdf');

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_OCR, $result->extractor);
        $this->assertSame(34, $result->pagecount);
        $ratio = source_fixtures::useful_text_ratio($reference, $result->markdown);
        $this->assertGreaterThanOrEqual(
            self::MIN_OCR_TEXT,
            $ratio,
            sprintf('pdf_scan_01.pdf: %.2f%% of the reference words were read.', $ratio * 100)
        );
    }

    /**
     * Sets the paths of pdftoppm and tesseract in the settings, or skips the test.
     */
    private function require_ocr(): void {
        $paths = [];
        foreach (['pdftoppm', 'tesseract'] as $program) {
            $path = trim((string) shell_exec('command -v ' . $program . ' 2>/dev/null'));
            if ($path === '' || !command_runner::is_available($path)) {
                $this->markTestSkipped($program . ' is not installed.');
            }
            $paths[$program] = $path;
        }
        if (!str_contains((string) shell_exec(escapeshellarg($paths['tesseract']) . ' --list-langs 2>&1'), 'ron')) {
            $this->markTestSkipped('The Romanian language data of tesseract is not installed.');
        }
        set_config('pdftoppmpath', $paths['pdftoppm'], 'local_aicoursebuilder');
        set_config('tesseractpath', $paths['tesseract'], 'local_aicoursebuilder');
    }
}
