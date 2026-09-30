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
 * Tests for the XLSX extractor.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_xlsx
 */
final class extractor_xlsx_test extends \advanced_testcase {
    use ingest_test_helpers;

    /** @var float Minimum share of the reference words that the extracted text must have. */
    private const MIN_USEFUL_TEXT = 0.95;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The visible sheet becomes a heading and a Markdown table; dates and formulas show their values.
     */
    public function test_sheet_becomes_a_table(): void {
        $file = $this->store(source_fixtures::xlsx(), 'report.xlsx');

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_PHPSPREADSHEET, $result->extractor);
        $this->assertNull($result->pagecount);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::xlsx_reference(), $result->markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);

        $markdown = $result->markdown;
        $this->assertStringContainsString('## Participanți', $markdown);
        $this->assertStringContainsString('| Nume | Departament | Ore instruire | Data finalizării |', $markdown);
        $this->assertStringContainsString('| --- | --- | --- | --- |', $markdown);
        $this->assertStringContainsString('| Ana Popescu | Vânzări | 8 | 14.03.2026 |', $markdown);
        // The formula gives its value, not its text.
        $this->assertStringContainsString('| Total ore |  | 20 |', $markdown);
        $this->assertStringNotContainsString('SUM(', $markdown);
    }

    /**
     * A hidden sheet is not part of the text.
     */
    public function test_hidden_sheet_is_skipped(): void {
        $file = $this->store(source_fixtures::xlsx(), 'report.xlsx');

        $result = (new extractor_xlsx())->extract($file);

        $this->assertStringNotContainsString(source_fixtures::XLSX_HIDDEN_TEXT, $result->markdown);
        $this->assertStringNotContainsString('## Ascuns', $result->markdown);
    }

    /**
     * A workbook without cells is an error, not an empty result.
     */
    public function test_xlsx_without_text(): void {
        $file = $this->store(source_fixtures::empty_xlsx(), 'empty.xlsx');

        try {
            (new extractor_xlsx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::NO_TEXT, $e->errorcode);
        }
    }

    /**
     * A file that is not an XLSX fails with the extractor error.
     */
    public function test_corrupt_xlsx(): void {
        $file = $this->store('this is not a zip archive', 'broken.xlsx');

        try {
            (new extractor_xlsx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * A sheet longer than the limit is cut, and the cut is marked.
     */
    public function test_long_sheet_is_cut(): void {
        $workbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        for ($row = 1; $row <= extractor_xlsx::MAX_ROWS + 10; $row++) {
            $sheet->setCellValue('A' . $row, 'linia ' . $row);
        }
        $path = make_request_directory() . '/long.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save($path);

        $result = (new extractor_xlsx())->extract_path($path);

        $this->assertStringContainsString('linia ' . extractor_xlsx::MAX_ROWS, $result->markdown);
        $this->assertStringNotContainsString('linia ' . (extractor_xlsx::MAX_ROWS + 1) . ' ', $result->markdown . ' ');
        $this->assertStringContainsString('are not included', $result->markdown);
    }
}
