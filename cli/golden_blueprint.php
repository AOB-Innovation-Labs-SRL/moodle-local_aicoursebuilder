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
 * Generates a whole blueprint from each document of the golden set and reports the result per document.
 *
 * It is the check of milestone M2: for every document of tests/fixtures/sources a job is made with that one document as
 * its source and a brief about it, and the plugin's own tasks run it from the ingestion (digest) to the blueprint ready
 * for review, on the real connectors of the plugin settings. The report says, per document, whether the digest and the
 * blueprint are valid, which nodes were left for manual completion and why, how many calls were repairs, the tokens
 * read, cached and written, the cost and the time. It calls the AI provider and spends money, so it asks for --yes; the
 * cost limits of the plugin settings apply as they do to any job. The adhoc tasks that are due run in this process,
 * one after the other, so run it on a development site.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

use local_aicoursebuilder\job_manager;
use local_aicoursebuilder\pipeline\failure_reason;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'user' => 'admin',
    'files' => '',
    'minutes' => 120,
    'report' => '',
    'yes' => false,
], ['h' => 'help']);

if ($unrecognized || $options['help']) {
    cli_writeln(
        "Generates a blueprint from each document of the golden set and reports the result per document.\n\n" .
        "Options:\n" .
        "  --user=USERNAME       Owner of the jobs and payer of the AI calls (default: admin).\n" .
        "  --files=a.pdf,b.docx  Only these documents (default: all those in sources.json).\n" .
        "  --minutes=N           Length of the course the brief asks for (default: 120).\n" .
        "  --report=FILE         Also write the report to this file, as Markdown.\n" .
        "  --yes                 Go on: the pipeline calls the AI provider and costs money.\n" .
        "  -h, --help            This help.\n\n" .
        "Needs: the API key of the connector in the plugin settings, the AI policy accepted by the user, and, for the\n" .
        "scanned PDF, pdftoppm and tesseract."
    );
    exit($unrecognized ? 1 : 0);
}
if (!$options['yes']) {
    cli_error('The pipeline calls the AI provider and costs money. Run again with --yes.');
}

$user = $DB->get_record('user', ['username' => $options['user'], 'deleted' => 0], '*', MUST_EXIST);
\core\session\manager::set_user($user);
if (!\core_ai\manager::get_user_policy_status((int) $user->id)) {
    cli_error("The user {$user->username} has not accepted the AI policy.");
}

$directory = __DIR__ . '/../tests/fixtures/sources/';
$manifest = json_decode(file_get_contents($directory . 'sources.json'), true, 512, JSON_THROW_ON_ERROR);
$documents = array_column($manifest['files'], null, 'file');
if ($options['files'] !== '') {
    $documents = array_intersect_key($documents, array_flip(array_map('trim', explode(',', $options['files']))));
}
if (!$documents) {
    cli_error('No document to run.');
}
$categoryid = (int) $DB->get_field_select('course_categories', 'MIN(id)', 'visible = 1');
$language = $manifest['language'] ?? 'ro';

/**
 * Runs the job of one document from its draft to the end of the pipeline and measures it.
 *
 * @param array $document The entry of sources.json.
 * @param \stdClass $user The owner of the job.
 * @param int $categoryid Category of the course the job is for.
 * @param string $language Language of the course.
 * @param int $minutes Length of the course the brief asks for.
 * @return array The measures of the run.
 */
function golden_blueprint_run(array $document, \stdClass $user, int $categoryid, string $language, int $minutes): array {
    global $CFG, $DB;

    $draftitemid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname([
        'contextid' => \context_user::instance($user->id)->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => $document['file'],
    ], __DIR__ . '/../tests/fixtures/sources/' . $document['file']);

    $manager = new job_manager();
    $started = time();
    $result = ['file' => $document['file'], 'jobid' => 0, 'startedok' => false, 'error' => ''];
    try {
        $jobid = $manager->create_draft((int) $user->id, [
            'mode' => job_manager::MODE_NEWCOURSE,
            'categoryid' => $categoryid,
            'prompt' => "Curs introductiv despre: {$document['original_title']}. Public: persoane care nu cunosc "
                . "subiectul, nivel începător. Durata: {$minutes} de minute. Limba: română.",
            'language' => $language,
        ], $draftitemid);
        $result['jobid'] = $jobid;
        $manager->start($DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST));
        $result['startedok'] = true;
    } catch (\Throwable $e) {
        $result['error'] = get_class($e) . ': ' . $e->getMessage();
        return $result;
    }

    // Since Moodle 5.1 the code is in public/ and the CLI scripts are one level above it.
    $runner = null;
    foreach ([$CFG->root ?? null, dirname($CFG->dirroot), $CFG->dirroot] as $base) {
        if ($base !== null && is_readable($base . '/admin/cli/adhoc_task.php')) {
            $runner = escapeshellarg($base . '/admin/cli/adhoc_task.php');
            break;
        }
    }
    if ($runner === null) {
        throw new coding_exception('admin/cli/adhoc_task.php not found');
    }
    $finished = [job_manager::STATUS_REVIEW, job_manager::STATUS_FAILED, job_manager::STATUS_PAUSED, 'approved', 'done'];
    // The tasks of the job run one after the other, in this process, until the job leaves the pipeline.
    for ($round = 0; $round < 30; $round++) {
        $status = $DB->get_field('local_aicb_job', 'status', ['id' => $jobid]);
        if (in_array($status, $finished, true)) {
            break;
        }
        passthru("php {$runner} --execute --keep-alive=0 > /dev/null 2>&1");
    }
    $result['seconds'] = time() - $started;
    return $result + golden_blueprint_measure($jobid);
}

/**
 * Reads what a finished job did from its step rows, call log and blueprint.
 *
 * @param int $jobid The job.
 * @return array The measures.
 */
function golden_blueprint_measure(int $jobid): array {
    global $DB;

    $job = $DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST);
    $source = $DB->get_record('local_aicb_source', ['jobid' => $jobid]);
    $steps = $DB->get_records('local_aicb_step', ['jobid' => $jobid], 'id');

    $content = array_filter($steps, fn($step) => in_array($step->step, ['sections', 'activities', 'questions'], true));
    $manual = [];
    $cleaned = 0;
    foreach ($content as $step) {
        $reason = failure_reason::decode($step->error);
        if ($step->status === 'manual' || $step->status === 'error') {
            $manual[] = "{$step->step}/{$step->nodekey}: "
                . ($reason === null ? (string) $step->error : json_encode($reason, JSON_UNESCAPED_UNICODE));
        } else if ($reason !== null && $reason['type'] === failure_reason::TYPE_CLEANED) {
            $cleaned++;
        }
    }

    $repairs = 0;
    $generations = 0;
    $calls = $DB->get_records_sql(
        'SELECT step, COUNT(1) AS calls FROM {local_aicb_ailog} WHERE jobid = ? GROUP BY step',
        [$jobid]
    );
    foreach ($calls as $row) {
        if ($row->step === 'repair') {
            $repairs += (int) $row->calls;
        } else if (in_array($row->step, ['brief', 'outline', 'sections', 'activities', 'questions'], true)) {
            $generations += (int) $row->calls;
        }
    }

    $tokens = ['in' => 0, 'cached' => 0, 'out' => 0];
    foreach ($steps as $step) {
        $tokens['in'] += (int) $step->tokensin;
        $tokens['cached'] += (int) $step->tokenscached;
        $tokens['out'] += (int) $step->tokensout;
    }
    $span = $DB->get_record_sql(
        'SELECT MIN(timecreated) AS first, MAX(timemodified) AS last FROM {local_aicb_step} WHERE jobid = ?',
        [$jobid]
    );
    $outline = array_values(array_filter($steps, fn($step) => $step->step === 'outline'));
    $outline = $outline && $outline[0]->output ? json_decode($outline[0]->output, true) : [];

    return [
        'status' => $job->status,
        'jobmessage' => (string) $job->error,
        'digested' => $source && $source->status === \local_aicoursebuilder\task\ingest_sources::STATUS_DIGESTED,
        'sourceerror' => $source ? (string) $source->error : 'no source',
        'blueprint' => $DB->record_exists('local_aicb_blueprint', ['jobid' => $jobid]),
        'sections' => count($outline['sections'] ?? []),
        'nodes' => count($content),
        'manual' => $manual,
        'cleaned' => $cleaned,
        'repairs' => $repairs,
        'generations' => $generations,
        'tokens' => $tokens,
        'cost' => (float) $job->actualcost,
        'estimated' => (float) $job->estimatedcost,
        'pipelineseconds' => $span && $span->first ? (int) $span->last - (int) $span->first : 0,
    ];
}

$results = [];
foreach ($documents as $document) {
    cli_writeln("== {$document['file']}");
    $result = golden_blueprint_run($document, $user, $categoryid, $language, (int) $options['minutes']);
    $results[] = $result;
    if (!$result['startedok']) {
        cli_writeln("   not started: {$result['error']}");
        continue;
    }
    cli_writeln(sprintf(
        '   job %d: %s, digest %s, blueprint %s, %d manual of %d nodes, %d repairs, %.4f USD, %d s',
        $result['jobid'],
        $result['status'],
        $result['digested'] ? 'ok' : 'FAILED',
        $result['blueprint'] ? 'valid' : 'NONE',
        count($result['manual']),
        $result['nodes'],
        $result['repairs'],
        $result['cost'],
        $result['seconds']
    ));
}

// The report: a table with one row per document, the reasons of the manual nodes, and the totals.
$lines = [
    '| Document | Job | Digest | Blueprint | Sections | Manual / nodes | Repairs / generations | Cached | Cost USD | Time s |',
    '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |',
];
$totals = ['cost' => 0.0, 'seconds' => 0, 'repairs' => 0, 'generations' => 0, 'manual' => 0, 'nodes' => 0, 'ok' => 0];
foreach ($results as $result) {
    if (!$result['startedok']) {
        $lines[] = "| {$result['file']} | - | not started | - | - | - | - | - | - | - |";
        continue;
    }
    $input = $result['tokens']['in'] + $result['tokens']['cached'];
    $lines[] = sprintf(
        '| %s | %d | %s | %s | %d | %d / %d | %d / %d | %s | %.4f | %d |',
        $result['file'],
        $result['jobid'],
        $result['digested'] ? 'ok' : 'FAILED',
        $result['blueprint'] ? 'valid' : 'NONE',
        $result['sections'],
        count($result['manual']),
        $result['nodes'],
        $result['repairs'],
        $result['generations'],
        $input > 0 ? sprintf('%.0f%%', 100 * $result['tokens']['cached'] / $input) : '-',
        $result['cost'],
        $result['seconds']
    );
    $totals['cost'] += $result['cost'];
    $totals['seconds'] += $result['seconds'];
    $totals['repairs'] += $result['repairs'];
    $totals['generations'] += $result['generations'];
    $totals['manual'] += count($result['manual']);
    $totals['nodes'] += $result['nodes'];
    $totals['ok'] += $result['digested'] && $result['blueprint'] && !$result['manual'] ? 1 : 0;
}
$lines[] = '';
foreach ($results as $result) {
    foreach ($result['manual'] ?? [] as $reason) {
        $lines[] = "- {$result['file']}, {$reason}";
    }
    if (!empty($result['jobmessage'])) {
        $lines[] = "- {$result['file']}, job message: {$result['jobmessage']}";
    }
    if (!empty($result['sourceerror'])) {
        $lines[] = "- {$result['file']}, source: {$result['sourceerror']}";
    }
}
$lines[] = '';
$lines[] = sprintf(
    'Documents complete (digest valid, blueprint valid, no manual node): %d of %d. Manual nodes: %d of %d. Repairs: %d of '
        . '%d generations. Cost: %.4f USD. Time: %d s.',
    $totals['ok'],
    count($results),
    $totals['manual'],
    $totals['nodes'],
    $totals['repairs'],
    $totals['generations'],
    $totals['cost'],
    $totals['seconds']
);

cli_writeln("\n" . implode("\n", $lines));
if ($options['report'] !== '') {
    file_put_contents($options['report'], implode("\n", $lines) . "\n");
    cli_writeln("Report written to {$options['report']}");
}
exit($totals['ok'] === count($results) ? 0 : 2);
