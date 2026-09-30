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

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Extracts the text of an XLSX with PhpSpreadsheet (the copy in Moodle core).
 *
 * Every visible sheet becomes a "##" heading with the sheet name and a Markdown table; the first
 * row of the sheet is the table header. Formulas give their value, dates and numbers are written as
 * the sheet shows them. Hidden sheets, rows and columns are skipped. A sheet stops after MAX_ROWS rows.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor_xlsx implements extractor {
    /** @var int Rows read from a sheet; the rest is dropped and marked. */
    public const MAX_ROWS = 5000;

    /** @var int Columns read from a sheet. */
    public const MAX_COLUMNS = 100;

    /**
     * Extracts the text of an XLSX stored in the file areas.
     *
     * @param \stored_file $file The source file.
     * @return extraction_result
     * @throws ingest_exception When the file cannot be read or has no text.
     */
    #[\Override]
    public function extract(\stored_file $file): extraction_result {
        $path = $file->copy_content_to_temp();
        if ($path === false) {
            throw new ingest_exception(ingest_exception::FILE_MISSING);
        }
        try {
            return $this->extract_path($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Extracts the text of an XLSX in the local file system.
     *
     * @param string $path Path of the XLSX.
     * @return extraction_result
     * @throws ingest_exception When the workbook cannot be read or has no text.
     */
    public function extract_path(string $path): extraction_result {
        raise_memory_limit(MEMORY_EXTRA);

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadEmptyCells(false);
            $workbook = $reader->load($path);
        } catch (\Throwable $e) {
            throw new ingest_exception(ingest_exception::EXTRACTION_FAILED, $e->getMessage());
        }

        $blocks = [];
        foreach ($workbook->getAllSheets() as $sheet) {
            if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                continue;
            }
            $table = markdown::table($this->read_rows($sheet));
            if ($table !== '') {
                $blocks[] = '## ' . trim(preg_replace('/\s+/u', ' ', $sheet->getTitle()) ?? '') . "\n\n" . $table;
            }
        }
        $workbook->disconnectWorksheets();

        $text = markdown::tidy(implode("\n\n", $blocks));
        if ($text === '') {
            throw new ingest_exception(ingest_exception::NO_TEXT);
        }
        return new extraction_result($text, null, extraction_result::EXTRACTOR_PHPSPREADSHEET);
    }

    /**
     * Reads the cells of a sheet as rows of text, without the hidden and the empty rows and columns.
     *
     * @param Worksheet $sheet The sheet.
     * @return string[][] Rows of cell texts, all with the same number of cells.
     */
    private function read_rows(Worksheet $sheet): array {
        $rows = [];
        $width = 0;
        $count = 0;
        foreach ($sheet->getRowIterator() as $row) {
            if (!$sheet->getRowDimension($row->getRowIndex())->getVisible()) {
                continue;
            }
            if (++$count > self::MAX_ROWS) {
                $rows[] = ['<!-- rows after ' . self::MAX_ROWS . ' are not included -->'];
                break;
            }
            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(true);
            foreach ($iterator as $cell) {
                $index = Coordinate::columnIndexFromString($cell->getColumn());
                if ($index > self::MAX_COLUMNS || !$sheet->getColumnDimension($cell->getColumn())->getVisible()) {
                    continue;
                }
                $text = $this->cell_text($cell);
                if ($text !== '') {
                    $cells[$index - 1] = $text;
                    $width = max($width, $index);
                }
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }

        // Fill the gaps, so that every row has the same columns.
        foreach ($rows as $number => $cells) {
            $filled = [];
            for ($i = 0; $i < $width; $i++) {
                $filled[] = $cells[$i] ?? '';
            }
            $rows[$number] = $filled;
        }
        return $rows;
    }

    /**
     * Returns the text of a cell: the value a formula gives, or else the value the sheet shows.
     *
     * @param \PhpOffice\PhpSpreadsheet\Cell\Cell $cell The cell.
     * @return string
     */
    private function cell_text(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string {
        try {
            $text = $cell->getFormattedValue();
        } catch (\Throwable $e) {
            // A formula the library cannot calculate: use the value that Excel stored with it.
            $text = $cell->isFormula() ? (string) $cell->getOldCalculatedValue() : '';
        }
        return trim($text);
    }
}
