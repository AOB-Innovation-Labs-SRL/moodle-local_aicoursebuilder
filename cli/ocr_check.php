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

/**
 * Reads a scanned PDF with the OCR of the server and measures how much of its text was found.
 *
 * It is the check of the OCR on a server: the golden scan (pdf_scan_01) is read the way the ingestion reads it, with
 * pdftoppm and tesseract and no AI call, and the share of the words of the reference that the text has is the useful
 * text metric of the extractor tests (at least 95%). Nothing is saved and nothing is paid for.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../tests/fixtures/sources/source_fixtures.php');

use local_aicoursebuilder\ingest\extraction_result;
use local_aicoursebuilder\ingest\extractor_factory;
use local_aicoursebuilder\ingest\ocr_status;
use local_aicoursebuilder\ingest\source_fixtures;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'file' => __DIR__ . '/../tests/fixtures/sources/pdf_scan_01.pdf',
    'reference' => __DIR__ . '/../tests/fixtures/sources/reference/pdf_scan_01.md',
    'min' => '0.95',
], ['h' => 'help']);

if ($unrecognized || $options['help']) {
    cli_writeln(
        "Reads a scanned PDF with the OCR of the server and reports the share of the reference words that the text has.\n\n" .
        "Options:\n" .
        "  --file=PATH         The scanned PDF (default: the golden scan, pdf_scan_01.pdf).\n" .
        "  --reference=PATH    The text the PDF holds (default: the reference of pdf_scan_01).\n" .
        "  --min=RATIO         Share that the text must reach (default: 0.95).\n" .
        "  -h, --help          This help.\n\n" .
        "Needs pdftoppm and tesseract, with the language data, set in the plugin settings. No AI provider is called."
    );
    exit($unrecognized ? 1 : 0);
}
foreach (['file', 'reference'] as $name) {
    if (!is_readable($options[$name])) {
        cli_error("Cannot read the {$name}: {$options[$name]}");
    }
}

$state = ocr_status::evaluate();
cli_writeln('State of the OCR: ' . strtoupper($state['status']) . ' - ' . $state['summary']);
if ($state['status'] !== ocr_status::OK) {
    cli_error('The scan cannot be read on this server, see the state above.');
}

$file = get_file_storage()->create_file_from_pathname([
    'contextid' => context_system::instance()->id,
    'component' => 'local_aicoursebuilder',
    'filearea' => 'ocrcheck',
    'itemid' => time(),
    'filepath' => '/',
    'filename' => basename($options['file']),
], $options['file']);

$exitcode = 0;
try {
    $started = microtime(true);
    $result = extractor_factory::for_file($file)->extract($file);
    $seconds = microtime(true) - $started;
    $ratio = source_fixtures::useful_text_ratio(file_get_contents($options['reference']), $result->markdown);

    cli_writeln(sprintf('Extractor:        %s', $result->extractor));
    cli_writeln(sprintf('Pages:            %d', $result->pagecount));
    cli_writeln(sprintf('Time:             %.1f s', $seconds));
    cli_writeln(sprintf('Warnings:         %s', $result->warnings ? implode(', ', $result->warnings) : 'none'));
    cli_writeln(sprintf('Useful text:      %.2f%% (needs %.2f%%)', $ratio * 100, (float) $options['min'] * 100));
    if ($result->extractor !== extraction_result::EXTRACTOR_OCR) {
        cli_writeln('FAILED: the text did not come from the OCR.');
        $exitcode = 1;
    } else if ($ratio < (float) $options['min']) {
        cli_writeln('FAILED: not enough of the text was found.');
        $exitcode = 1;
    } else {
        cli_writeln('OK');
    }
} catch (\Throwable $e) {
    cli_writeln('FAILED: ' . get_class($e) . ': ' . $e->getMessage());
    $exitcode = 1;
} finally {
    $file->delete();
}
exit($exitcode);
