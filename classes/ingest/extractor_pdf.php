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
 * Extracts the text of a PDF with smalot/pdfparser; pdftotext -layout is the fallback.
 *
 * The fallback runs when pdfparser fails or finds no text, and only if the path of pdftotext is
 * set in the plugin settings. Encrypted PDFs are refused. A scanned PDF (no text) goes to the OCR
 * extractor, when the paths of pdftoppm and tesseract are set.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor_pdf implements extractor {
    /** @var int Seconds after which pdftotext is killed. */
    public const PDFTOTEXT_TIMEOUT = 120;

    /** @var int Control characters that make the text of pdfparser suspect. */
    public const GARBLED_MIN_COUNT = 5;

    /** @var float Share of control characters in the text that makes the text of pdfparser suspect. */
    public const GARBLED_MIN_RATIO = 0.0001;

    /** @var string Message pdfparser gives for an encrypted PDF. */
    private const ENCRYPTED_MESSAGE = 'Secured pdf file';

    /**
     * Extracts the text of a PDF stored in the file areas.
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
     * Extracts the text of a PDF in the local file system.
     *
     * @param string $path Path of the PDF.
     * @return extraction_result
     * @throws ingest_exception When the PDF is encrypted or gives no text.
     */
    public function extract_path(string $path): extraction_result {
        $warnings = [];
        $failure = null;
        $garbled = null;

        try {
            $pages = $this->read_with_pdfparser($path);
            $text = markdown::join_pages($pages);
            if ($text !== '' && !self::is_garbled($text)) {
                return new extraction_result($text, count($pages), extraction_result::EXTRACTOR_PDFPARSER);
            }
            if ($text !== '') {
                // Some letters came out as control characters: keep the text only if pdftotext cannot do better.
                $garbled = new extraction_result($text, count($pages), extraction_result::EXTRACTOR_PDFPARSER);
                $warnings[] = extraction_result::WARNING_PDFPARSER_GARBLED;
            } else {
                $warnings[] = extraction_result::WARNING_PDFPARSER_FAILED;
            }
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), self::ENCRYPTED_MESSAGE)) {
                throw new ingest_exception(ingest_exception::PDF_ENCRYPTED);
            }
            $warnings[] = extraction_result::WARNING_PDFPARSER_FAILED;
            $failure = $e->getMessage();
        }

        $pdftotext = get_config('local_aicoursebuilder', 'pdftotextpath');
        if (command_runner::is_available($pdftotext)) {
            try {
                $pages = $this->read_with_pdftotext($pdftotext, $path);
                $text = markdown::join_pages($pages);
                if ($text !== '') {
                    return new extraction_result(
                        $text,
                        count($pages),
                        extraction_result::EXTRACTOR_PDFTOTEXT,
                        $warnings
                    );
                }
            } catch (ingest_exception $e) {
                $failure ??= $e->getMessage();
            }
            $warnings[] = extraction_result::WARNING_PDFTOTEXT_FAILED;
        }

        if ($garbled !== null) {
            return new extraction_result($garbled->markdown, $garbled->pagecount, $garbled->extractor, $warnings);
        }

        // A scan has no text layer: read the page images with OCR, when it is configured.
        $ocr = new extractor_ocr();
        if ($ocr->is_available()) {
            try {
                return $ocr->extract_path($path, $warnings);
            } catch (ingest_exception $e) {
                $failure ??= $e->errorcode === ingest_exception::NO_TEXT ? null : $e->getMessage();
                $warnings[] = extraction_result::WARNING_OCR_FAILED;
            }
        }

        if ($failure !== null) {
            throw new ingest_exception(ingest_exception::EXTRACTION_FAILED, $failure);
        }
        throw new ingest_exception(ingest_exception::NO_TEXT);
    }

    /**
     * Tells whether text that pdfparser read has letters that came out as control characters.
     *
     * pdfparser cannot map the glyphs of a font that has no Unicode table: ț, ă, â and others come out as
     * characters 0x1B to 0x1F. A few of them in a long text is enough to tell.
     *
     * @param string $text The text.
     * @return bool
     */
    public static function is_garbled(string $text): bool {
        $count = preg_match_all('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $text);
        return $count >= self::GARBLED_MIN_COUNT && $count / max(1, strlen($text)) >= self::GARBLED_MIN_RATIO;
    }

    /**
     * Extracts the text of a PDF with pdftotext, when the path of pdftotext is set in the settings.
     *
     * Also used to read the PDF that LibreOffice makes from a DOCX.
     *
     * @param string $path Path of the PDF.
     * @return string[]|null Text of every page, null when pdftotext is not configured.
     * @throws ingest_exception When pdftotext fails.
     */
    public function read_with_configured_pdftotext(string $path): ?array {
        $pdftotext = get_config('local_aicoursebuilder', 'pdftotextpath');
        if (!command_runner::is_available($pdftotext)) {
            return null;
        }
        return $this->read_with_pdftotext($pdftotext, $path);
    }

    /**
     * Reads the pages of a PDF with pdfparser.
     *
     * @param string $path Path of the PDF.
     * @return string[] Text of every page.
     */
    private function read_with_pdfparser(string $path): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/thirdparty/autoload.php');
        raise_memory_limit(MEMORY_EXTRA);

        $config = new \Smalot\PdfParser\Config();
        $config->setRetainImageContent(false);
        $document = (new \Smalot\PdfParser\Parser([], $config))->parseFile($path);

        $pages = [];
        foreach ($document->getPages() as $page) {
            $pages[] = $page->getText();
        }
        return $pages;
    }

    /**
     * Reads the pages of a PDF with pdftotext -layout.
     *
     * @param string $pdftotext Path of pdftotext.
     * @param string $path Path of the PDF.
     * @return string[] Text of every page.
     * @throws ingest_exception When pdftotext fails.
     */
    private function read_with_pdftotext(string $pdftotext, string $path): array {
        $output = command_runner::run(
            $pdftotext,
            ['-layout', '-enc', 'UTF-8', $path, '-'],
            self::PDFTOTEXT_TIMEOUT
        );
        // Every page ends with a form feed.
        $pages = explode("\f", $output);
        if (end($pages) === '') {
            array_pop($pages);
        }
        return $pages;
    }
}
