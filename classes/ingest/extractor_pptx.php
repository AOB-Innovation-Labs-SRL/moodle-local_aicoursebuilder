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

use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\Group;
use PhpOffice\PhpPresentation\Shape\Placeholder;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Style\Bullet;

/**
 * Extracts the text of a PPTX with PhpPresentation; LibreOffice and pdftotext are the fallback.
 *
 * Every slide is a page (the "<!-- page N -->" marker holds the slide number). The title of a slide
 * becomes a "###" heading, bulleted paragraphs become "-" items, tables become Markdown tables and the
 * speaker notes follow the slide as a quotation. The fallback (LibreOffice makes a PDF, pdftotext reads
 * it) runs when PhpPresentation fails or finds no text, and only if the paths of soffice and pdftotext
 * are set in the plugin settings.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor_pptx implements extractor {
    /** @var string Label in front of the speaker notes of a slide. */
    private const NOTES_LABEL = 'Speaker notes:';

    /**
     * @var string[] Placeholder types that hold no content: footer, date and slide number.
     *
     * Plain strings, because the library classes are not loaded when a class constant is evaluated.
     */
    private const SKIPPED_PLACEHOLDERS = ['ftr', 'dt', 'sldNum'];

    /**
     * Extracts the text of a PPTX stored in the file areas.
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
     * Extracts the text of a PPTX in the local file system.
     *
     * @param string $path Path of the PPTX.
     * @return extraction_result
     * @throws ingest_exception When no extractor gives text.
     */
    public function extract_path(string $path): extraction_result {
        $warnings = [];
        $failure = null;

        try {
            $pages = $this->read_with_phppresentation($path);
            $text = markdown::join_pages($pages);
            if ($text !== '') {
                return new extraction_result($text, count($pages), extraction_result::EXTRACTOR_PHPPRESENTATION);
            }
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        }
        $warnings[] = extraction_result::WARNING_PHPPRESENTATION_FAILED;

        try {
            $pages = libreoffice::read_pages($path, 'pptx');
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
     * Reads a PPTX with PhpPresentation.
     *
     * @param string $path Path of the PPTX.
     * @return string[] Text of every slide, with its speaker notes.
     */
    private function read_with_phppresentation(string $path): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/thirdparty/autoload.php');
        raise_memory_limit(MEMORY_EXTRA);

        $presentation = \PhpOffice\PhpPresentation\IOFactory::createReader('PowerPoint2007')->load($path);

        $pages = [];
        foreach ($presentation->getAllSlides() as $slide) {
            $blocks = $this->render_shapes($slide->getShapeCollection());
            $notes = $this->render_shapes($slide->getNote()->getShapeCollection());
            if ($notes) {
                $blocks[] = '> ' . self::NOTES_LABEL . ' ' . implode(' ', array_map(
                    fn($note) => preg_replace('/\s*\n\s*/u', ' ', $note),
                    $notes
                ));
            }
            $pages[] = implode("\n\n", $blocks);
        }
        return $pages;
    }

    /**
     * Renders the shapes of a slide or a group as Markdown blocks; the title comes first.
     *
     * @param AbstractShape[] $shapes The shapes.
     * @return string[] Markdown blocks.
     */
    private function render_shapes(array $shapes): array {
        $titles = [];
        $blocks = [];
        foreach ($shapes as $shape) {
            if ($shape instanceof Group) {
                $blocks = array_merge($blocks, $this->render_shapes($shape->getShapeCollection()));
            } else if ($shape instanceof Table) {
                $table = markdown::table($this->table_rows($shape));
                if ($table !== '') {
                    $blocks[] = $table;
                }
            } else if ($shape instanceof RichText) {
                $placeholder = $shape->isPlaceholder() ? $shape->getPlaceholder()->getType() : null;
                if (in_array($placeholder, self::SKIPPED_PLACEHOLDERS, true)) {
                    continue;
                }
                $text = $this->rich_text($shape);
                if ($text === '') {
                    continue;
                }
                if ($placeholder === Placeholder::PH_TYPE_TITLE) {
                    $titles[] = '### ' . preg_replace('/\s*\n\s*/u', ' ', $text);
                } else {
                    $blocks[] = $text;
                }
            }
        }
        return array_merge($titles, $blocks);
    }

    /**
     * Returns the text of a text shape: one line per paragraph, bulleted paragraphs as "-" items.
     *
     * @param RichText $shape The shape.
     * @return string
     */
    private function rich_text(RichText $shape): string {
        $lines = [];
        foreach ($shape->getParagraphs() as $paragraph) {
            $text = '';
            foreach ($paragraph->getRichTextElements() as $element) {
                $text .= $element instanceof \PhpOffice\PhpPresentation\Shape\RichText\BreakElement
                    ? ' '
                    : $element->getText();
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($text === '') {
                continue;
            }
            $bullet = $paragraph->getBulletStyle();
            if ($bullet !== null && $bullet->getBulletType() !== Bullet::TYPE_NONE) {
                $level = max(0, $paragraph->getAlignment()->getLevel());
                $text = str_repeat('  ', $level) . '- ' . $text;
            }
            $lines[] = $text;
        }
        return implode("\n", $lines);
    }

    /**
     * Returns the cell texts of a table.
     *
     * @param Table $table The table shape.
     * @return string[][] Rows of cell texts.
     */
    private function table_rows(Table $table): array {
        $rows = [];
        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $texts = [];
                foreach ($cell->getParagraphs() as $paragraph) {
                    foreach ($paragraph->getRichTextElements() as $element) {
                        $texts[] = $element->getText();
                    }
                }
                $cells[] = implode('', $texts);
            }
            $rows[] = $cells;
        }
        return $rows;
    }
}
