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
 * Immutable result of the extraction of one source file.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extraction_result {
    /** @var string Extractor: smalot/pdfparser. */
    public const EXTRACTOR_PDFPARSER = 'pdfparser';

    /** @var string Extractor: pdftotext -layout. */
    public const EXTRACTOR_PDFTOTEXT = 'pdftotext';

    /** @var string Extractor: phpoffice/phpword. */
    public const EXTRACTOR_PHPWORD = 'phpword';

    /** @var string Extractor: LibreOffice (to PDF) and pdftotext. */
    public const EXTRACTOR_LIBREOFFICE = 'libreoffice';

    /** @var string Warning: pdfparser failed or found no text. */
    public const WARNING_PDFPARSER_FAILED = 'pdfparser_failed';

    /** @var string Warning: PhpWord failed or found no text. */
    public const WARNING_PHPWORD_FAILED = 'phpword_failed';

    /** @var string Warning: the pdftotext fallback failed. */
    public const WARNING_PDFTOTEXT_FAILED = 'pdftotext_failed';

    /** @var string Warning: the LibreOffice fallback failed. */
    public const WARNING_LIBREOFFICE_FAILED = 'libreoffice_failed';

    /**
     * Creates the result.
     *
     * @param string $markdown Extracted text as Markdown.
     * @param int|null $pagecount Number of pages, null when the format does not tell.
     * @param string $extractor The EXTRACTOR_* constant of the extractor that gave the text.
     * @param string[] $warnings WARNING_* codes of the steps that failed before the extractor that worked.
     */
    public function __construct(
        /** @var string Extracted text as Markdown. */
        public readonly string $markdown,
        /** @var int|null Number of pages. */
        public readonly ?int $pagecount,
        /** @var string Extractor that gave the text. */
        public readonly string $extractor,
        /** @var string[] Warning codes. */
        public readonly array $warnings = [],
    ) {
    }
}
