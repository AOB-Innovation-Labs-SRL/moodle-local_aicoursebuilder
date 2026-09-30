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
     * @param string $text The text.
     * @return int[] Occurrences of every word, lower case.
     */
    private static function count_words(string $text): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_count_values($words);
    }
}
