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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sources/source_fixtures.php');
require_once(__DIR__ . '/../fixtures/sources/ingest_test_helpers.php');

/**
 * Tests the normaliser and the chunker on the real documents of the golden set.
 *
 * The text of each document is extracted, normalised and cut into chunks. The tests check what must hold for
 * any document: the normalised text keeps the content of the independent reference, no control character is
 * left, the chunks are within the size limits, they lose nothing and their pages and order are right.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\normalizer
 * @covers     \local_aicoursebuilder\ingest\chunker
 * @covers     \local_aicoursebuilder\ingest\extractor_pdf
 */
final class ingest_golden_test extends \advanced_testcase {
    use ingest_test_helpers;

    /** @var float Share of the words of the reference that the normalised text must keep. */
    private const MIN_KEPT_TEXT = 0.95;

    /**
     * @var float Share for a document with a running header or a title repeated on many pages or slides. The
     * normaliser keeps the first one and removes the others (pdf_text_02: the 30 words of the header on every page,
     * 5.7% of the reference; pptx_01: a section title on 13 slides, 5.8%).
     */
    private const MIN_KEPT_TEXT_WITH_RUNNING_LINES = 0.93;

    /** @var float Share of the words of the normalised text that the chunks must hold together. */
    private const MIN_CHUNKED_TEXT = 0.999;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Data provider: documents, whether they have pages, the language the normaliser must find (empty: not tested) and
     * whether they have running lines that the normaliser removes.
     *
     * @return array
     */
    public static function documents_provider(): array {
        return [
            'pdf_text_01' => ['pdf_text_01.pdf', true, 'ro', false],
            'pdf_text_02' => ['pdf_text_02.pdf', true, 'ro', true],
            'docx_01' => ['docx_01.docx', false, 'ro', false],
            'docx_02' => ['docx_02.docx', false, 'ro', false],
            'pptx_01' => ['pptx_01.pptx', true, 'ro', true],
            'pptx_02' => ['pptx_02.pptx', true, 'ro', false],
            'xlsx_01' => ['xlsx_01.xlsx', false, '', false],
        ];
    }

    /**
     * Stores a document of the golden set in the source area.
     *
     * @param string $filename Name of the document in tests/fixtures/sources.
     * @return \stored_file
     */
    private function load(string $filename): \stored_file {
        return get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'source',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => $filename,
        ], __DIR__ . '/../fixtures/sources/' . $filename);
    }

    /**
     * Sets the path of pdftotext in the settings when it is installed.
     *
     * @return bool Whether pdftotext is installed.
     */
    private function configure_pdftotext(): bool {
        $path = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($path === '' || !command_runner::is_available($path)) {
            return false;
        }
        set_config('pdftotextpath', $path, 'local_aicoursebuilder');
        return true;
    }

    /**
     * The normalised text and the chunks of a real document have what they must.
     *
     * @dataProvider documents_provider
     * @param string $filename Name of the document.
     * @param bool $paged Whether the document has pages (slides).
     * @param string $language The language the normaliser must find, empty for any.
     * @param bool $runninglines Whether the document has running lines that the normaliser removes.
     */
    public function test_document(string $filename, bool $paged, string $language, bool $runninglines): void {
        $this->configure_pdftotext();
        $file = $this->load($filename);
        $extracted = extractor_factory::for_file($file)->extract($file);

        $normalized = (new normalizer())->normalize($extracted->markdown);
        $chunks = (new chunker())->chunk($normalized->markdown);

        // The normalised text keeps the content of the independent reference and has no control characters.
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $reference = file_get_contents(__DIR__ . '/../fixtures/sources/reference/' . $name . '.md');
        $kept = source_fixtures::useful_text_ratio($reference, $normalized->markdown);
        $this->assertGreaterThanOrEqual(
            $runninglines ? self::MIN_KEPT_TEXT_WITH_RUNNING_LINES : self::MIN_KEPT_TEXT,
            $kept,
            sprintf('%s: the normalised text has %.2f%% of the words of the reference.', $filename, $kept * 100)
        );
        $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $normalized->markdown), $filename);
        if ($language !== '') {
            $this->assertSame($language, $normalized->language, $filename);
        }

        // The chunks are within the limits, in order, and together hold all the text.
        $this->assertNotEmpty($chunks, $filename);
        $longest = (int) ceil(chunker::MAX_TOKENS * (1 + chunker::OVERLAP_RATIO)) + 10;
        $last = count($chunks) - 1;
        foreach ($chunks as $position => $chunk) {
            $this->assertSame($position, $chunk->index, $filename);
            $this->assertLessThanOrEqual($longest, $chunk->tokencount, "{$filename}: chunk {$position}");
            $this->assertGreaterThan(0, $chunk->tokencount, "{$filename}: chunk {$position}");
            $this->assertStringNotContainsString('<!-- page', $chunk->content, "{$filename}: chunk {$position}");
            if ($position < $last) {
                $this->assertGreaterThanOrEqual(
                    chunker::MIN_TOKENS / 2,
                    $chunk->tokencount,
                    "{$filename}: chunk {$position} is too small"
                );
            }
        }
        $together = implode("\n\n", array_map(fn($chunk) => $chunk->content, $chunks));
        // The page markers are in the normalised text and, by design, not in the chunks.
        $text = preg_replace('/^<!-- page \d+ -->$/m', '', $normalized->markdown);
        $chunked = source_fixtures::useful_text_ratio($text, $together);
        $this->assertGreaterThanOrEqual(
            self::MIN_CHUNKED_TEXT,
            $chunked,
            sprintf('%s: the chunks hold %.3f%% of the normalised text.', $filename, $chunked * 100)
        );

        // Pages: from the page markers of the source, never past its last page, never going back.
        $previous = 0;
        foreach ($chunks as $position => $chunk) {
            if (!$paged) {
                $this->assertNull($chunk->pagefrom, $filename);
                continue;
            }
            $this->assertNotNull($chunk->pagefrom, "{$filename}: chunk {$position}");
            $this->assertLessThanOrEqual($extracted->pagecount, $chunk->pageto, "{$filename}: chunk {$position}");
            $this->assertGreaterThanOrEqual($chunk->pagefrom, $chunk->pageto, "{$filename}: chunk {$position}");
            $this->assertGreaterThanOrEqual($previous, $chunk->pagefrom, "{$filename}: chunk {$position}");
            $previous = $chunk->pagefrom;
        }
    }

    /**
     * The running headers of a real PDF are removed and its headings are found.
     */
    public function test_real_pdf_structure(): void {
        $this->configure_pdftotext();
        $file = $this->load('pdf_text_02.pdf');
        $extracted = extractor_factory::for_file($file)->extract($file);

        $normalized = (new normalizer())->normalize($extracted->markdown);

        $this->assertGreaterThan(10, $normalized->stats['headers']);
        $this->assertGreaterThan(10, $normalized->stats['headings']);
        $this->assertMatchesRegularExpression('/^#{1,3} \S/m', $normalized->markdown);
        $this->assertLessThan(strlen($extracted->markdown), strlen($normalized->markdown));
    }

    /**
     * A PDF whose font has no Unicode table gives control characters in place of letters with pdfparser; with pdftotext
     * configured the extractor uses it instead, and the Romanian letters are there.
     */
    public function test_pdf_with_lost_letters_is_read_with_pdftotext(): void {
        $file = $this->load('pdf_text_02.pdf');

        // Without pdftotext the text of pdfparser is all there is, and the extractor says that it is damaged.
        $plain = (new extractor_pdf())->extract($file);
        $this->assertSame(extraction_result::EXTRACTOR_PDFPARSER, $plain->extractor);
        $this->assertContains(extraction_result::WARNING_PDFPARSER_GARBLED, $plain->warnings);
        $this->assertTrue(extractor_pdf::is_garbled($plain->markdown));

        if (!$this->configure_pdftotext()) {
            $this->markTestSkipped('pdftotext is not installed.');
        }
        $better = (new extractor_pdf())->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_PDFTOTEXT, $better->extractor);
        $this->assertContains(extraction_result::WARNING_PDFPARSER_GARBLED, $better->warnings);
        $this->assertFalse(extractor_pdf::is_garbled($better->markdown));
        $this->assertStringContainsString('competenței', $better->markdown);
        $this->assertStringContainsString('inițiativa', $better->markdown);
    }

    /**
     * Text without control characters is not suspect, and a few of them in a long text are.
     */
    public function test_garbled_text_detection(): void {
        $this->assertFalse(extractor_pdf::is_garbled(str_repeat('Text curat, cu diacritice ăâîșț. ', 500)));
        $this->assertFalse(extractor_pdf::is_garbled("Tab\tși\nlinii noi și \x0C pagini."));
        $this->assertTrue(extractor_pdf::is_garbled(str_repeat("competen\x1Fe ini\x1Diativa. ", 50)));
        // Four characters are not enough, however short the text is.
        $this->assertFalse(extractor_pdf::is_garbled("a\x1Fb\x1Fc\x1Fd\x1F"));
    }
}
