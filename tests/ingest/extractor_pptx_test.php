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
 * Tests for the PPTX extractor.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\extractor_pptx
 * @covers     \local_aicoursebuilder\ingest\libreoffice
 */
final class extractor_pptx_test extends \advanced_testcase {
    use ingest_test_helpers;

    /** @var float Minimum share of the reference words that the extracted text must have. */
    private const MIN_USEFUL_TEXT = 0.95;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Every slide is a page; titles, bullets, tables and speaker notes are in the text.
     */
    public function test_slides_titles_bullets_tables_and_notes(): void {
        $file = $this->store(source_fixtures::pptx(), 'deck.pptx');

        $result = extractor_factory::for_file($file)->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_PHPPRESENTATION, $result->extractor);
        $this->assertSame(2, $result->pagecount);
        $this->assertSame([], $result->warnings);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::pptx_reference(), $result->markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);

        $markdown = $result->markdown;
        $this->assertStringContainsString('<!-- page 1 -->', $markdown);
        $this->assertStringContainsString('<!-- page 2 -->', $markdown);
        $this->assertStringContainsString('### ' . source_fixtures::PPTX_TITLE_1, $markdown);
        $this->assertStringContainsString('- ' . source_fixtures::PPTX_BULLETS[0], $markdown);
        $this->assertStringContainsString('> Speaker notes: ' . source_fixtures::PPTX_NOTES, $markdown);
        $this->assertStringContainsString('| Rol | Responsabilitate |', $markdown);
        $this->assertStringContainsString('| --- | --- |', $markdown);
        $this->assertStringContainsString('| Angajat | Raportează incidentele |', $markdown);
        // The slide number of the notes is not text of the slide.
        $this->assertStringNotContainsString("Speaker notes: " . source_fixtures::PPTX_NOTES . ' 1', $markdown);
        // The notes of slide 1 come before the marker of slide 2.
        $this->assertLessThan(strpos($markdown, '<!-- page 2 -->'), strpos($markdown, 'Speaker notes'));
    }

    /**
     * A presentation without text is an error, not an empty result.
     */
    public function test_pptx_without_text(): void {
        $file = $this->store(source_fixtures::empty_pptx(), 'empty.pptx');

        try {
            (new extractor_pptx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::NO_TEXT, $e->errorcode);
        }
    }

    /**
     * A file that is not a PPTX fails with the extractor error.
     */
    public function test_corrupt_pptx(): void {
        $file = $this->store('this is not a zip archive', 'broken.pptx');

        try {
            (new extractor_pptx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }

    /**
     * When PhpPresentation fails and both programs are configured, LibreOffice and pdftotext give the text.
     */
    public function test_libreoffice_fallback(): void {
        set_config('pdftotextpath', $this->require_program('pdftotext'), 'local_aicoursebuilder');
        set_config('sofficepath', $this->fake_soffice(source_fixtures::pdf()), 'local_aicoursebuilder');
        $file = $this->store('this is not a zip archive', 'broken.pptx');

        $result = (new extractor_pptx())->extract($file);

        $this->assertSame(extraction_result::EXTRACTOR_LIBREOFFICE, $result->extractor);
        $this->assertSame([extraction_result::WARNING_PHPPRESENTATION_FAILED], $result->warnings);
        $this->assertSame(3, $result->pagecount);
        $ratio = source_fixtures::useful_text_ratio(source_fixtures::pdf_reference(), $result->markdown);
        $this->assertGreaterThanOrEqual(self::MIN_USEFUL_TEXT, $ratio);
    }

    /**
     * Without a path in the settings the fallback is off, and a LibreOffice that fails does not hide the original failure.
     */
    public function test_fallback_off_or_failing(): void {
        set_config('sofficepath', '', 'local_aicoursebuilder');
        set_config('pdftotextpath', '', 'local_aicoursebuilder');
        $file = $this->store('this is not a zip archive', 'broken.pptx');
        try {
            (new extractor_pptx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }

        set_config('pdftotextpath', $this->require_program('pdftotext'), 'local_aicoursebuilder');
        set_config('sofficepath', $this->fake_soffice(source_fixtures::pdf(), true), 'local_aicoursebuilder');
        try {
            (new extractor_pptx())->extract($file);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::EXTRACTION_FAILED, $e->errorcode);
        }
    }
}
