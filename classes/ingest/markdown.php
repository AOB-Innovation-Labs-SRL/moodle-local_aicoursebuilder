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

/**
 * Markdown helpers shared by the extractors.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class markdown {
    /**
     * Cleans extracted text: valid UTF-8, LF line ends, no trailing spaces, at most one empty line in a row.
     *
     * @param string $text Extracted text.
     * @return string
     */
    public static function tidy(string $text): string {
        $text = fix_utf8($text);
        $text = str_replace(["\r\n", "\r", "\0", "\t"], ["\n", "\n", '', ' '], $text);
        $text = preg_replace('/[ \x{00A0}]+$/mu', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Joins the text of the pages, each page starting with a marker comment.
     *
     * @param string[] $pages Text of every page, in order.
     * @return string Empty when no page has text.
     */
    public static function join_pages(array $pages): string {
        $parts = [];
        $number = 0;
        foreach ($pages as $page) {
            $number++;
            $page = self::tidy($page);
            if ($page !== '') {
                $parts[] = "<!-- page {$number} -->\n\n" . $page;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Builds a Markdown table; the first row is the header.
     *
     * @param string[][] $rows Rows of cell texts.
     * @return string Empty when there are no rows.
     */
    public static function table(array $rows): string {
        $columns = 0;
        foreach ($rows as $row) {
            $columns = max($columns, count($row));
        }
        if ($columns === 0) {
            return '';
        }

        $lines = [];
        foreach (array_values($rows) as $index => $row) {
            $cells = [];
            for ($i = 0; $i < $columns; $i++) {
                $cell = preg_replace('/\s+/u', ' ', (string) ($row[$i] ?? '')) ?? '';
                $cells[] = str_replace('|', '\|', trim($cell));
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
            if ($index === 0) {
                $lines[] = '|' . str_repeat(' --- |', $columns);
            }
        }
        return implode("\n", $lines);
    }
}
