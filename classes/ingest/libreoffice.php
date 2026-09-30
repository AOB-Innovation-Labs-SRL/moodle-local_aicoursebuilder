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
 * Reads an office document through LibreOffice: it is converted to PDF and the PDF is read with pdftotext.
 *
 * Shared fallback of the DOCX and PPTX extractors. It needs the paths of soffice and pdftotext in the
 * plugin settings.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class libreoffice {
    /** @var int Seconds after which LibreOffice is killed. */
    public const SOFFICE_TIMEOUT = 180;

    /**
     * Converts a document to PDF with LibreOffice and reads the PDF with pdftotext.
     *
     * @param string $path Path of the document.
     * @param string $extension Extension LibreOffice needs to recognise the document, without the dot.
     * @return string[]|null Text of every page, null when soffice or pdftotext is not configured.
     * @throws ingest_exception When LibreOffice or pdftotext fails.
     */
    public static function read_pages(string $path, string $extension): ?array {
        $soffice = get_config('local_aicoursebuilder', 'sofficepath');
        if (!command_runner::is_available($soffice)) {
            return null;
        }
        $pdftotext = get_config('local_aicoursebuilder', 'pdftotextpath');
        if (!command_runner::is_available($pdftotext)) {
            return null;
        }

        // LibreOffice needs a name with the right extension, and a profile directory it can write to.
        $directory = make_request_directory();
        $profile = make_request_directory();
        $source = $directory . '/source.' . $extension;
        if (!copy($path, $source)) {
            throw new ingest_exception(ingest_exception::FILE_MISSING);
        }
        command_runner::run($soffice, [
            '--headless',
            '--norestore',
            '-env:UserInstallation=file://' . $profile,
            '--convert-to',
            'pdf',
            '--outdir',
            $directory,
            $source,
        ], self::SOFFICE_TIMEOUT);

        $pdf = $directory . '/source.pdf';
        if (!is_file($pdf)) {
            throw new ingest_exception(ingest_exception::COMMAND_FAILED, basename($soffice), 'no PDF written');
        }
        return (new extractor_pdf())->read_with_configured_pdftotext($pdf);
    }
}
