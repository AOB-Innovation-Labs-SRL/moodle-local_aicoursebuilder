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

use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;

/**
 * Extracts the text of a DOCX with PhpWord; LibreOffice and pdftotext are the fallback.
 *
 * Headings become #, ## and ### (deeper levels stay ###), lists become "-" items and tables
 * become Markdown tables. The fallback (LibreOffice makes a PDF, pdftotext reads it) runs when
 * PhpWord fails or finds no text, and only if the paths of soffice and pdftotext are set in the
 * plugin settings.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor_docx implements extractor {
    /** @var int Deepest Markdown heading level produced. */
    private const MAX_HEADING_LEVEL = 3;

    /**
     * Extracts the text of a DOCX stored in the file areas.
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
     * Extracts the text of a DOCX in the local file system.
     *
     * @param string $path Path of the DOCX.
     * @return extraction_result
     * @throws ingest_exception When no extractor gives text.
     */
    public function extract_path(string $path): extraction_result {
        $warnings = [];
        $failure = null;

        try {
            $text = $this->read_with_phpword($path);
            if ($text !== '') {
                return new extraction_result(
                    $text,
                    $this->read_page_count($path),
                    extraction_result::EXTRACTOR_PHPWORD
                );
            }
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        }
        $warnings[] = extraction_result::WARNING_PHPWORD_FAILED;

        try {
            $pages = $this->read_with_libreoffice($path);
            if ($pages !== null) {
                $text = markdown::join_pages($pages);
                if ($text !== '') {
                    return new extraction_result(
                        $text,
                        count($pages),
                        extraction_result::EXTRACTOR_LIBREOFFICE,
                        $warnings
                    );
                }
                $warnings[] = extraction_result::WARNING_LIBREOFFICE_FAILED;
            }
        } catch (ingest_exception $e) {
            $failure ??= $e->getMessage();
            $warnings[] = extraction_result::WARNING_LIBREOFFICE_FAILED;
        }

        if ($failure !== null) {
            throw new ingest_exception(ingest_exception::EXTRACTION_FAILED, $failure);
        }
        throw new ingest_exception(ingest_exception::NO_TEXT);
    }

    /**
     * Reads a DOCX with PhpWord and returns it as Markdown.
     *
     * @param string $path Path of the DOCX.
     * @return string Markdown, empty when the document has no text.
     */
    private function read_with_phpword(string $path): string {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/thirdparty/autoload.php');
        raise_memory_limit(MEMORY_EXTRA);

        // PhpWord 1.4.0 passes null to htmlspecialchars() for a heading whose only run has no text. That is
        // a PHP deprecation with no effect on the result, so it is dropped here instead of patching the library.
        set_error_handler(
            fn(int $errno, string $errstr, string $errfile): bool => str_contains(
                str_replace('\\', '/', $errfile),
                '/thirdparty/phpword/'
            ),
            E_DEPRECATED
        );
        try {
            $document = \PhpOffice\PhpWord\IOFactory::load($this->normalise_heading_styles($path), 'Word2007');
        } finally {
            restore_error_handler();
        }

        $blocks = [];
        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $block = $this->render_element($element);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        // Consecutive list items stay together, every other block is separated by an empty line.
        $markdown = '';
        $previous = null;
        foreach ($blocks as [$type, $text]) {
            if ($previous !== null) {
                $markdown .= ($type === 'list' && $previous === 'list') ? "\n" : "\n\n";
            }
            $markdown .= $text;
            $previous = $type;
        }
        return markdown::tidy($markdown);
    }

    /**
     * Gives the heading styles their English ids, so that PhpWord recognises the headings.
     *
     * PhpWord finds a heading only when the style id of the paragraph is Heading1, Heading2 and so
     * on, or Title. Word in other languages keeps the English name of the built-in style in styles.xml
     * (heading 1) but uses a local id (Titlu1 in Romanian). The ids are changed in a copy of the file.
     *
     * @param string $path Path of the DOCX.
     * @return string Path of the file to read: a corrected copy, or $path when no id has to change.
     */
    private function normalise_heading_styles(string $path): string {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return $path;
        }
        $styles = $zip->getFromName('word/styles.xml');
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($styles === false || $document === false) {
            return $path;
        }

        $renames = [];
        preg_match_all('~<w:style\b[^>]*\bw:styleId="([^"]+)"[^>]*>(.*?)</w:style>~s', $styles, $definitions, PREG_SET_ORDER);
        foreach ($definitions as [, $id, $body]) {
            if (!preg_match('~<w:name w:val="([^"]+)"~', $body, $name)) {
                continue;
            }
            $name = strtolower($name[1]);
            if ($name === 'title') {
                $canonical = 'Title';
            } else if (preg_match('~^heading (\d)$~', $name, $level)) {
                $canonical = 'Heading' . $level[1];
            } else {
                continue;
            }
            if ($id !== $canonical) {
                $renames[$id] = $canonical;
            }
        }
        if (!$renames) {
            return $path;
        }

        $document = preg_replace_callback(
            '~<w:pStyle w:val="([^"]+)"~',
            fn($match) => '<w:pStyle w:val="' . ($renames[$match[1]] ?? $match[1]) . '"',
            $document
        );
        $copy = make_request_directory() . '/headings.docx';
        if (!copy($path, $copy) || $zip->open($copy) !== true) {
            return $path;
        }
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        return $copy;
    }

    /**
     * Renders a top level element as a Markdown block.
     *
     * @param mixed $element PhpWord element.
     * @return array|null [type, text] where type is heading, list, table or text; null when there is no text.
     */
    private function render_element(mixed $element): ?array {
        if ($element instanceof Title) {
            $title = $element->getText();
            $text = trim($title instanceof TextRun ? $this->inline_text($title) : (string) $title);
            $level = max(1, min(self::MAX_HEADING_LEVEL, (int) $element->getDepth()));
            return $text === '' ? null : ['heading', str_repeat('#', $level) . ' ' . $text];
        }
        if ($element instanceof ListItem || $element instanceof ListItemRun) {
            $text = trim($this->block_text($element));
            return $text === '' ? null : ['list', str_repeat('  ', (int) $element->getDepth()) . '- ' . $text];
        }
        if ($element instanceof Table) {
            $table = markdown::table($this->table_rows($element));
            return $table === '' ? null : ['table', $table];
        }
        $text = trim($this->inline_text($element));
        return $text === '' ? null : ['text', $text];
    }

    /**
     * Returns the text of a run of text, a link or a line break.
     *
     * @param mixed $element PhpWord element.
     * @return string
     */
    private function inline_text(mixed $element): string {
        if ($element instanceof Text) {
            return (string) $element->getText();
        }
        if ($element instanceof Link) {
            return $element->getText();
        }
        if ($element instanceof TextBreak) {
            return ' ';
        }
        if ($element instanceof TextRun) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->inline_text($child);
            }
            return $text;
        }
        return '';
    }

    /**
     * Returns the text of any element that can be inside a table cell.
     *
     * @param mixed $element PhpWord element.
     * @return string
     */
    private function block_text(mixed $element): string {
        if ($element instanceof ListItem) {
            return (string) $element->getText();
        }
        if ($element instanceof Table) {
            $cells = [];
            foreach ($this->table_rows($element) as $row) {
                $cells = array_merge($cells, $row);
            }
            return implode(' ', $cells);
        }
        return $this->inline_text($element);
    }

    /**
     * Returns the cell texts of a table.
     *
     * @param Table $table PhpWord table.
     * @return string[][] Rows of cell texts.
     */
    private function table_rows(Table $table): array {
        $rows = [];
        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $texts = [];
                foreach ($cell->getElements() as $element) {
                    $texts[] = $this->block_text($element);
                }
                $cells[] = implode(' ', array_filter($texts, fn($text) => trim($text) !== ''));
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    /**
     * Reads the page count that Word stores in docProps/app.xml.
     *
     * @param string $path Path of the DOCX.
     * @return int|null Null when the document does not tell.
     */
    private function read_page_count(string $path): ?int {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $properties = $zip->getFromName('docProps/app.xml');
        $zip->close();
        if ($properties !== false && preg_match('~<Pages>(\d+)</Pages>~', $properties, $matches)) {
            return max(1, (int) $matches[1]);
        }
        return null;
    }

    /**
     * Converts the DOCX to PDF with LibreOffice and reads the PDF with pdftotext.
     *
     * @param string $path Path of the DOCX.
     * @return string[]|null Text of every page, null when soffice or pdftotext is not configured.
     * @throws ingest_exception When LibreOffice or pdftotext fails.
     */
    private function read_with_libreoffice(string $path): ?array {
        return libreoffice::read_pages($path, 'docx');
    }
}
