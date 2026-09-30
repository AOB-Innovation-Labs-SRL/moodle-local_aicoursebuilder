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
 * files is the text of word/document.xml. Both are in tests/fixtures/sources/reference, made once by
 * generate_reference.py, so that the reference does not depend on the code under test.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_pdf
 * @covers     \local_aicoursebuilder\ingest\extractor_docx
 * @covers     \local_aicoursebuilder\ingest\extractor_factory
 */
final class extractor_golden_test extends \advanced_testcase {
    /** @var float Minimum share of the reference words that the extracted text must have. */
    private const MIN_USEFUL_TEXT = 0.95;

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
        return $files;
    }

    /**
     * The extracted text has at least 95% of the words of the independent reference.
     *
     * @dataProvider golden_files_provider
     * @param string $filename Name of the document in tests/fixtures/sources.
     */
    public function test_extracted_text_agrees_with_reference(string $filename): void {
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

        $result = extractor_factory::for_file($file)->extract($file);

        $ratio = source_fixtures::useful_text_ratio($reference, $result->markdown);
        $this->assertGreaterThanOrEqual(
            self::MIN_USEFUL_TEXT,
            $ratio,
            sprintf('%s: %.2f%% of the reference words were extracted.', $filename, $ratio * 100)
        );
    }
}
