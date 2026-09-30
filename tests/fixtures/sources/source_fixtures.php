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
 * Generates the PDF and DOCX source files used by the ingest tests, and measures the extracted text.
 *
 * The files are made in memory from the text below; nothing is committed as a binary and the text
 * holds no personal data. Load it with require_once from the test.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_fixtures {
    /** @var string[][] Pages of the PDF: the first entry of a page is its heading, the others are paragraphs. */
    public const PDF_PAGES = [
        [
            'Introducere în securitatea informației',
            'Securitatea informației protejează confidențialitatea, integritatea și disponibilitatea datelor.',
            'Un program de instruire eficient combină principii, exemple practice și verificarea cunoștințelor.',
        ],
        [
            'Gestionarea parolelor',
            'O parolă puternică are cel puțin paisprezece caractere și nu se reutilizează între conturi.',
            'Managerii de parole generează și păstrează criptat parole unice pentru fiecare serviciu.',
        ],
        [
            'Raportarea incidentelor',
            'Orice activitate suspectă se raportează imediat echipei de securitate prin canalul oficial.',
        ],
    ];

    /** @var string Heading of level 1 in the DOCX. */
    public const DOCX_H1 = 'Ghid de utilizare a platformei';

    /** @var string Heading of level 2 in the DOCX. */
    public const DOCX_H2 = 'Crearea unui cont nou';

    /** @var string Heading of level 3 in the DOCX. */
    public const DOCX_H3 = 'Validarea adresei de contact';

    /** @var string[] Paragraphs of the DOCX, in order after the three headings. */
    public const DOCX_PARAGRAPHS = [
        'Platforma permite cursanților să parcurgă lecții, să rezolve teste și să descarce materiale.',
        'Contul se creează de către administrator, iar cursantul primește un mesaj cu pașii de activare.',
        'Adresa de contact se validează printr-un link trimis automat, valabil douăzeci și patru de ore.',
    ];

    /** @var string[] Items of the DOCX list. */
    public const DOCX_LIST = ['Deschide mesajul de activare', 'Alege o parolă nouă', 'Confirmă alegerea'];

    /** @var string[][] Rows of the DOCX table: the first row is the header. */
    public const DOCX_TABLE = [
        ['Rol', 'Drepturi', 'Observații'],
        ['Cursant', 'Citire', 'Doar cursurile proprii'],
        ['Profesor', 'Editare', 'Cursurile atribuite'],
    ];

    /**
     * Returns a PDF with a heading and paragraphs on every page of PDF_PAGES.
     *
     * @param bool $protected True to encrypt it with a password.
     * @return string PDF content.
     */
    public static function pdf(bool $protected = false): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        if ($protected) {
            $pdf->SetProtection(['print'], 'userpass', 'ownerpass');
        }
        foreach (self::PDF_PAGES as $page) {
            $pdf->AddPage();
            foreach ($page as $index => $text) {
                $pdf->SetFont('freesans', $index === 0 ? 'B' : '', $index === 0 ? 16 : 11);
                $pdf->MultiCell(0, 8, $text, 0, 'L');
                $pdf->Ln(3);
            }
        }
        return $pdf->Output('', 'S');
    }

    /**
     * Returns a PDF with one page and no text.
     *
     * Built by hand, because TCPDF writes its own text on the pages it makes.
     *
     * @return string PDF content.
     */
    public static function blank_pdf(): string {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * Returns the text that the PDF holds, as reference for the useful text metric.
     *
     * @return string
     */
    public static function pdf_reference(): string {
        $lines = [];
        foreach (self::PDF_PAGES as $page) {
            $lines = array_merge($lines, $page);
        }
        return implode("\n", $lines);
    }

    /**
     * Returns a DOCX with headings 1 to 3, paragraphs, a list and a table.
     *
     * @return string DOCX content.
     */
    public static function docx(): string {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/thirdparty/autoload.php');

        $document = new \PhpOffice\PhpWord\PhpWord();
        // Without title styles the writer does not tag the headings as Heading1, Heading2 and so on.
        foreach ([1 => 16, 2 => 14, 3 => 12] as $depth => $size) {
            $document->addTitleStyle($depth, ['bold' => true, 'size' => $size]);
        }
        $section = $document->addSection();
        $section->addTitle(self::DOCX_H1, 1);
        $section->addText(self::DOCX_PARAGRAPHS[0]);
        $section->addTitle(self::DOCX_H2, 2);
        $section->addText(self::DOCX_PARAGRAPHS[1]);
        foreach (self::DOCX_LIST as $item) {
            $section->addListItem($item);
        }
        $section->addTitle(self::DOCX_H3, 3);
        $section->addText(self::DOCX_PARAGRAPHS[2]);
        $table = $section->addTable();
        foreach (self::DOCX_TABLE as $row) {
            $table->addRow();
            foreach ($row as $cell) {
                $table->addCell(3000)->addText($cell);
            }
        }

        $path = make_request_directory() . '/fixture.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007')->save($path);
        return file_get_contents($path);
    }

    /**
     * Returns the DOCX of docx() with the style ids of Word in Romanian: Titlu1 for the style named "heading 1".
     *
     * @return string DOCX content.
     */
    public static function docx_localised(): string {
        $path = make_request_directory() . '/localised.docx';
        file_put_contents($path, self::docx());
        $zip = new \ZipArchive();
        $zip->open($path);
        $document = $zip->getFromName('word/document.xml');
        $styles = $zip->getFromName('word/styles.xml');
        foreach ([1, 2, 3] as $depth) {
            $document = str_replace("w:val=\"Heading{$depth}\"", "w:val=\"Titlu{$depth}\"", $document);
            $styles = str_replace("w:styleId=\"Heading{$depth}\"", "w:styleId=\"Titlu{$depth}\"", $styles);
        }
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();
        return file_get_contents($path);
    }

    /**
     * Returns a DOCX without any text.
     *
     * @return string DOCX content.
     */
    public static function empty_docx(): string {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/thirdparty/autoload.php');

        $document = new \PhpOffice\PhpWord\PhpWord();
        $document->addSection();

        $path = make_request_directory() . '/empty.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007')->save($path);
        return file_get_contents($path);
    }

    /**
     * Returns the text that the DOCX holds, as reference for the useful text metric.
     *
     * @return string
     */
    public static function docx_reference(): string {
        $lines = [self::DOCX_H1, self::DOCX_H2, self::DOCX_H3];
        $lines = array_merge($lines, self::DOCX_PARAGRAPHS, self::DOCX_LIST);
        foreach (self::DOCX_TABLE as $row) {
            $lines = array_merge($lines, $row);
        }
        return implode("\n", $lines);
    }

    /** @var string Title of the first slide of the PPTX. */
    public const PPTX_TITLE_1 = 'Protecția datelor în organizație';

    /** @var string[] Bulleted items of the first slide. */
    public const PPTX_BULLETS = [
        'Datele personale se colectează doar cu un scop clar',
        'Accesul se acordă pe principiul minimului necesar',
    ];

    /** @var string Speaker notes of the first slide. */
    public const PPTX_NOTES = 'Explicați diferența dintre date personale și date sensibile.';

    /** @var string Title of the second slide. */
    public const PPTX_TITLE_2 = 'Responsabilități';

    /** @var string[][] Rows of the table on the second slide: the first row is the header. */
    public const PPTX_TABLE = [
        ['Rol', 'Responsabilitate'],
        ['Angajat', 'Raportează incidentele'],
        ['Administrator', 'Gestionează accesul'],
    ];

    /** @var string Name of the visible sheet of the XLSX. */
    public const XLSX_SHEET = 'Participanți';

    /** @var string[][] Rows of the visible sheet; the last column holds a date and the last row a formula. */
    public const XLSX_ROWS = [
        ['Nume', 'Departament', 'Ore instruire', 'Data finalizării'],
        ['Ana Popescu', 'Vânzări', 8, '2026-03-14'],
        ['Mihai Ionescu', 'Suport tehnic', 12, '2026-04-02'],
    ];

    /** @var string Text in the hidden sheet of the XLSX, which must not be extracted. */
    public const XLSX_HIDDEN_TEXT = 'confidențial-ascuns';

    /** @var string Paragraphs of the plain text, Markdown and HTML files. */
    public const TEXT_PARAGRAPHS = [
        'Politica de securitate se revizuiește în fiecare an, după o evaluare a riscurilor.',
        'Angajații confirmă în scris că au citit politica și că o vor respecta.',
    ];

    /**
     * Returns a PPTX with two slides: a title, bullets and speaker notes on the first, a title and a table on the second.
     *
     * PhpPresentation cannot write speaker notes, so the notes part is added to the file the way PowerPoint stores it.
     *
     * @return string PPTX content.
     */
    public static function pptx(): string {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/thirdparty/autoload.php');

        $presentation = new \PhpOffice\PhpPresentation\PhpPresentation();
        $slide = $presentation->getActiveSlide();
        $title = $slide->createRichTextShape()->setHeight(80)->setWidth(800)->setOffsetX(50)->setOffsetY(20);
        $title->setPlaceHolder(new \PhpOffice\PhpPresentation\Shape\Placeholder(
            \PhpOffice\PhpPresentation\Shape\Placeholder::PH_TYPE_TITLE
        ));
        $title->createTextRun(self::PPTX_TITLE_1);
        $body = $slide->createRichTextShape()->setHeight(300)->setWidth(800)->setOffsetX(50)->setOffsetY(120);
        foreach (self::PPTX_BULLETS as $index => $bullet) {
            $paragraph = $index === 0 ? $body->getActiveParagraph() : $body->createParagraph();
            $paragraph->getBulletStyle()->setBulletType(\PhpOffice\PhpPresentation\Style\Bullet::TYPE_BULLET);
            $paragraph->createTextRun($bullet);
        }

        $second = $presentation->createSlide();
        $title = $second->createRichTextShape()->setHeight(80)->setWidth(800)->setOffsetX(50)->setOffsetY(20);
        $title->setPlaceHolder(new \PhpOffice\PhpPresentation\Shape\Placeholder(
            \PhpOffice\PhpPresentation\Shape\Placeholder::PH_TYPE_TITLE
        ));
        $title->createTextRun(self::PPTX_TITLE_2);
        $table = $second->createTableShape(count(self::PPTX_TABLE[0]))->setHeight(200)->setWidth(800);
        $table->setOffsetX(50)->setOffsetY(120);
        foreach (self::PPTX_TABLE as $cells) {
            $row = $table->createRow();
            foreach ($cells as $text) {
                $row->nextCell()->createTextRun($text);
            }
        }

        $path = make_request_directory() . '/fixture.pptx';
        \PhpOffice\PhpPresentation\IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);
        return self::add_pptx_notes($path, 1, self::PPTX_NOTES);
    }

    /**
     * Returns a PPTX whose only slide has no text.
     *
     * @return string PPTX content.
     */
    public static function empty_pptx(): string {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/thirdparty/autoload.php');

        $presentation = new \PhpOffice\PhpPresentation\PhpPresentation();
        $presentation->getActiveSlide();
        $path = make_request_directory() . '/empty.pptx';
        \PhpOffice\PhpPresentation\IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);
        return file_get_contents($path);
    }

    /**
     * Returns the text that the PPTX holds, as reference for the useful text metric.
     *
     * @return string
     */
    public static function pptx_reference(): string {
        $lines = array_merge([self::PPTX_TITLE_1], self::PPTX_BULLETS, [self::PPTX_NOTES, self::PPTX_TITLE_2]);
        foreach (self::PPTX_TABLE as $row) {
            $lines = array_merge($lines, $row);
        }
        return implode("\n", $lines);
    }

    /**
     * Adds a notes part, made like the one PowerPoint writes, to a slide of a PPTX.
     *
     * @param string $path Path of the PPTX.
     * @param int $slidenumber Number of the slide, from 1.
     * @param string $text Speaker notes.
     * @return string PPTX content.
     */
    private static function add_pptx_notes(string $path, int $slidenumber, string $text): string {
        $zip = new \ZipArchive();
        $zip->open($path);
        $relspath = "ppt/slides/_rels/slide{$slidenumber}.xml.rels";
        $rels = $zip->getFromName($relspath);
        $relationship = '<Relationship Id="rIdNotes1" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
            . 'relationships/notesSlide" Target="../notesSlides/notesSlide' . $slidenumber . '.xml"/>';
        $zip->addFromString($relspath, str_replace('</Relationships>', $relationship . '</Relationships>', $rels));

        $namespaces = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
        $placeholder = fn(int $id, string $type, string $body) => '<p:sp><p:nvSpPr>'
            . '<p:cNvPr id="' . $id . '" name="Placeholder ' . $id
            . '"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="' . $type . '" idx="' . $id
            . '"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p>' . $body . '</a:p></p:txBody></p:sp>';
        $notes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notes ' . $namespaces . '><p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
            . $placeholder(2, 'body', '<a:r><a:rPr lang="ro-RO"/><a:t>' . htmlspecialchars($text) . '</a:t></a:r>')
            . $placeholder(3, 'sldNum', '<a:fld id="{00000000-0000-0000-0000-000000000001}" type="slidenum">'
                . '<a:rPr lang="ro-RO"/><a:t>' . $slidenumber . '</a:t></a:fld>')
            . '</p:spTree></p:cSld></p:notes>';
        $zip->addFromString("ppt/notesSlides/notesSlide{$slidenumber}.xml", $notes);
        $zip->close();
        return file_get_contents($path);
    }

    /**
     * Returns an XLSX with a visible sheet (text, numbers, a date and a formula) and a hidden sheet.
     *
     * @return string XLSX content.
     */
    public static function xlsx(): string {
        $workbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle(self::XLSX_SHEET);
        foreach (self::XLSX_ROWS as $rowindex => $row) {
            foreach ($row as $columnindex => $value) {
                $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnindex + 1) . ($rowindex + 1);
                if ($rowindex > 0 && $columnindex === 3) {
                    $sheet->setCellValue($coordinate, \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($value));
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd.mm.yyyy');
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }
        $total = count(self::XLSX_ROWS) + 1;
        $sheet->setCellValue("A{$total}", 'Total ore');
        $sheet->setCellValue("C{$total}", '=SUM(C2:C' . ($total - 1) . ')');

        $hidden = $workbook->createSheet();
        $hidden->setTitle('Ascuns');
        $hidden->setCellValue('A1', self::XLSX_HIDDEN_TEXT);
        $hidden->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        $path = make_request_directory() . '/fixture.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save($path);
        return file_get_contents($path);
    }

    /**
     * Returns an XLSX whose only sheet has no cells.
     *
     * @return string XLSX content.
     */
    public static function empty_xlsx(): string {
        $path = make_request_directory() . '/empty.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx(new \PhpOffice\PhpSpreadsheet\Spreadsheet()))->save($path);
        return file_get_contents($path);
    }

    /**
     * Returns the text that the visible sheet of the XLSX shows, as reference for the useful text metric.
     *
     * @return string
     */
    public static function xlsx_reference(): string {
        $lines = [self::XLSX_SHEET, 'Nume', 'Departament', 'Ore instruire', 'Data finalizării'];
        $lines = array_merge($lines, ['Ana Popescu', 'Vânzări', '8', '14.03.2026']);
        $lines = array_merge($lines, ['Mihai Ionescu', 'Suport tehnic', '12', '02.04.2026']);
        return implode("\n", array_merge($lines, ['Total ore', '20']));
    }

    /**
     * Returns the text of a plain text file.
     *
     * @return string
     */
    public static function text(): string {
        return "Politica de securitate\n\n" . implode("\n\n", self::TEXT_PARAGRAPHS) . "\n";
    }

    /**
     * Returns the text of a Markdown file.
     *
     * @return string
     */
    public static function markdown(): string {
        return "# Politica de securitate\n\n" . self::TEXT_PARAGRAPHS[0] . "\n\n- primul punct\n- al doilea punct\n";
    }

    /**
     * Returns an HTML page with headings, paragraphs, a list, a script and a stylesheet.
     *
     * @return string
     */
    public static function html(): string {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Titlu de pagină</title>'
            . '<style>p { color: red; }</style></head><body><h1>Politica de securitate</h1>'
            . '<p>' . self::TEXT_PARAGRAPHS[0] . '</p><h2>Confirmare</h2><p>' . self::TEXT_PARAGRAPHS[1] . '</p>'
            . '<ul><li>primul punct</li><li>al doilea punct</li></ul><script>var ascuns = 1;</script></body></html>';
    }

    /**
     * Returns the text that the plain text, Markdown and HTML files hold, for the useful text metric.
     *
     * @return string
     */
    public static function text_reference(): string {
        $lines = array_merge(['Politica de securitate'], self::TEXT_PARAGRAPHS);
        return implode("\n", array_merge($lines, ['Confirmare', 'primul punct', 'al doilea punct']));
    }

    /**
     * Returns a scanned PDF: the pages of pdf() as images, with no text layer.
     *
     * @param string $pdftoppm Path of pdftoppm, which draws the pages.
     * @return string PDF content.
     */
    public static function scanned_pdf(string $pdftoppm): string {
        $directory = make_request_directory();
        file_put_contents($directory . '/text.pdf', self::pdf());
        command_runner::run($pdftoppm, ['-r', '200', '-png', $directory . '/text.pdf', $directory . '/scan'], 120);
        $images = glob($directory . '/scan-*.png');
        natsort($images);

        // Built by hand, like blank_pdf(): every page is one JPEG image and nothing else, because TCPDF
        // writes text of its own on the pages it makes.
        $objects = ['', ''];
        $pages = [];
        foreach ($images as $image) {
            [$width, $height] = getimagesize($image);
            ob_start();
            imagejpeg(imagecreatefrompng($image), null, 90);
            $jpeg = ob_get_clean();
            $objects[] = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB "
                . '/BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
            $imageid = count($objects);
            $content = 'q 595 0 0 842 0 0 cm /Im0 Do Q';
            $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $contentid = count($objects);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                . "/Resources << /XObject << /Im0 {$imageid} 0 R >> >> /Contents {$contentid} 0 R >>";
            $pages[] = count($objects) . ' 0 R';
        }
        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pages) . '] /Count ' . count($pages) . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * Useful text metric: the share of the words of the reference text that the extracted text has.
     *
     * Words are compared without case and punctuation, and a word that occurs twice in the
     * reference must occur at least twice in the extracted text to count twice.
     *
     * @param string $reference Text that the file holds.
     * @param string $extracted Text that the extractor returned.
     * @return float From 0 to 1.
     */
    public static function useful_text_ratio(string $reference, string $extracted): float {
        $referencewords = self::count_words($reference);
        $extractedwords = self::count_words($extracted);
        $total = array_sum($referencewords);
        if ($total === 0) {
            return 0.0;
        }
        $found = 0;
        foreach ($referencewords as $word => $count) {
            $found += min($count, $extractedwords[$word] ?? 0);
        }
        return $found / $total;
    }

    /**
     * Counts the words of a text.
     *
     * The Romanian letters with a cedilla (ş, ţ) and the ones with a comma below (ș, ț) are the same
     * letters: Windows code pages, OCR engines and old documents write the first, the standard asks for
     * the second. They count as equal, so that the metric measures the text that was found and not
     * which of the two forms a tool wrote.
     *
     * @param string $text The text.
     * @return int[] Occurrences of every word, lower case.
     */
    private static function count_words(string $text): array {
        $text = strtr($text, ['ş' => 'ș', 'Ş' => 'Ș', 'ţ' => 'ț', 'Ţ' => 'Ț']);
        $words = preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_count_values($words);
    }
}
