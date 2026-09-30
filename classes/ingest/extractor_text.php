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
 * Extracts the text of TXT, Markdown and HTML files.
 *
 * TXT and Markdown are already text: only the character encoding is fixed. HTML goes through
 * html_to_text(); headings become #, ## and ### first, so that the structure is not lost.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor_text implements extractor {
    /** @var string Mimetype of HTML files. */
    private const HTML_MIMETYPE = 'text/html';

    /** @var int Deepest Markdown heading level produced from HTML. */
    private const MAX_HEADING_LEVEL = 3;

    /** @var string Encoding assumed for a file that is not valid UTF-8 (Romanian Windows text files). */
    private const FALLBACK_ENCODING = 'windows-1250';

    /**
     * Extracts the text of a file stored in the file areas.
     *
     * @param \stored_file $file The source file.
     * @return extraction_result
     * @throws ingest_exception When the file cannot be read or has no text.
     */
    #[\Override]
    public function extract(\stored_file $file): extraction_result {
        $content = $file->get_content();
        if ($content === false) {
            throw new ingest_exception(ingest_exception::FILE_MISSING);
        }
        $ishtml = $file->get_mimetype() === self::HTML_MIMETYPE
            || extractor_factory::type_for_filename($file->get_filename()) === 'html';
        return $this->extract_string($content, $ishtml);
    }

    /**
     * Extracts the text of a file content.
     *
     * @param string $content File content.
     * @param bool $ishtml True when the content is HTML.
     * @return extraction_result
     * @throws ingest_exception When there is no text.
     */
    public function extract_string(string $content, bool $ishtml = false): extraction_result {
        $text = $this->to_utf8($content);
        if ($ishtml) {
            $text = $this->html_to_markdown($text);
        }
        $text = markdown::tidy($text);
        if ($text === '') {
            throw new ingest_exception(ingest_exception::NO_TEXT);
        }
        return new extraction_result($text, null, extraction_result::EXTRACTOR_TEXT);
    }

    /**
     * Converts the content to UTF-8: a byte order mark decides, else valid UTF-8 is kept, else Windows-1250.
     *
     * @param string $content File content.
     * @return string
     */
    private function to_utf8(string $content): string {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return (string) mb_convert_encoding($content, 'UTF-8', 'UTF-16');
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        return \core_text::convert($content, self::FALLBACK_ENCODING, 'utf-8');
    }

    /**
     * Turns HTML into text, with headings as Markdown headings.
     *
     * @param string $html HTML.
     * @return string
     */
    private function html_to_markdown(string $html): string {
        $html = preg_replace('~<(script|style|head)\b.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace_callback(
            '~<h([1-6])\b[^>]*>(.*?)</h\1>~is',
            function (array $match): string {
                $level = min(self::MAX_HEADING_LEVEL, (int) $match[1]);
                $title = trim(preg_replace('/\s+/u', ' ', strip_tags($match[2])) ?? '');
                return $title === '' ? '' : "\n\n" . str_repeat('#', $level) . ' ' . $title . "\n\n";
            },
            $html
        ) ?? $html;
        return html_to_text($html, 0, false);
    }
}
