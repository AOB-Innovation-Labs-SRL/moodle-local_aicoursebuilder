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
 * Reads a scanned PDF with OCR: pdftoppm makes an image of every page and tesseract reads it.
 *
 * It is the last fallback of the PDF extractor, used when the PDF has no text. It runs only if the
 * paths of pdftoppm and tesseract are set in the plugin settings. The language is the "ocrlanguage"
 * setting (Romanian by default); several languages are joined with "+", for example "ron+eng".
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor_ocr {
    /** @var string Default tesseract language. */
    public const DEFAULT_LANGUAGE = 'ron';

    /** @var int Resolution of the page images, in dots per inch. */
    public const DPI = 300;

    /** @var int Pages read from a PDF; the rest is left out. */
    public const MAX_PAGES = 200;

    /** @var int Seconds after which pdftoppm is killed. */
    public const PDFTOPPM_TIMEOUT = 300;

    /** @var int Seconds after which tesseract is killed, for one page. */
    public const TESSERACT_TIMEOUT = 120;

    /**
     * Tells whether pdftoppm and tesseract are both set in the settings and executable.
     *
     * @return bool
     */
    public function is_available(): bool {
        return command_runner::is_available(get_config('local_aicoursebuilder', 'pdftoppmpath'))
            && command_runner::is_available(get_config('local_aicoursebuilder', 'tesseractpath'));
    }

    /**
     * Returns the tesseract language of the settings; a value that is not a language list gives the default.
     *
     * @return string
     */
    public function get_language(): string {
        $language = trim((string) get_config('local_aicoursebuilder', 'ocrlanguage'));
        return preg_match('/^[A-Za-z0-9_]+(\+[A-Za-z0-9_]+)*$/', $language) ? $language : self::DEFAULT_LANGUAGE;
    }

    /**
     * Reads the text of a PDF in the local file system.
     *
     * @param string $path Path of the PDF.
     * @param string[] $warnings Warning codes of the extractors that failed before.
     * @return extraction_result
     * @throws ingest_exception When OCR is not configured, a program fails or the pages have no text.
     */
    public function extract_path(string $path, array $warnings = []): extraction_result {
        if (!$this->is_available()) {
            throw new ingest_exception(ingest_exception::NO_TEXT);
        }
        $pdftoppm = get_config('local_aicoursebuilder', 'pdftoppmpath');
        $tesseract = get_config('local_aicoursebuilder', 'tesseractpath');

        $directory = make_request_directory();
        command_runner::run($pdftoppm, [
            '-r', (string) self::DPI,
            '-l', (string) self::MAX_PAGES,
            '-png',
            $path,
            $directory . '/page',
        ], self::PDFTOPPM_TIMEOUT);

        $images = glob($directory . '/page-*.png') ?: [];
        natsort($images);
        if (!$images) {
            throw new ingest_exception(ingest_exception::COMMAND_FAILED, basename($pdftoppm), 'no page image written');
        }

        $pages = [];
        foreach ($images as $image) {
            $pages[] = command_runner::run(
                $tesseract,
                [$image, 'stdout', '-l', $this->get_language(), '--psm', '3'],
                self::TESSERACT_TIMEOUT
            );
            // The images are big: remove each one as soon as it is read.
            @unlink($image);
        }

        $text = markdown::join_pages($pages);
        if ($text === '') {
            throw new ingest_exception(ingest_exception::NO_TEXT);
        }
        return new extraction_result($text, count($pages), extraction_result::EXTRACTOR_OCR, $warnings);
    }
}
