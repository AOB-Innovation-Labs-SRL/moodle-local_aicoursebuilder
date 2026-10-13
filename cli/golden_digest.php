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
 * Runs the ingestion (text, chunks, digest) on the documents of the golden set and reports the result.
 *
 * This is the check of milestone M1: the digest must run on the real connector of the digest step (deepseek_connector)
 * for every one of the 10 documents. The script makes a job for a user, saves the documents as its sources and runs the
 * ingest_sources task at once, in this process. It calls the AI provider and spends money, so it asks for --yes; the
 * cost limits of the plugin settings (per job, per user and per month) apply as they do to any job.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

use local_aicoursebuilder\ingest\source_manager;
use local_aicoursebuilder\task\ingest_sources;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'user' => 'admin',
    'files' => '',
    'connector' => '',
    'yes' => false,
], ['h' => 'help']);

if ($unrecognized || $options['help']) {
    cli_writeln(
        "Runs the ingestion on the golden set (tests/fixtures/sources) and reports the result per document.\n\n" .
        "Options:\n" .
        "  --user=USERNAME       Owner of the job and payer of the AI calls (default: admin).\n" .
        "  --files=a.pdf,b.docx  Only these documents (default: all those in sources.json).\n" .
        "  --connector=NAME      Use this default connector for the run: deepseek or coreai (default: the plugin settings).\n" .
        "  --yes                 Go on: the digest calls the AI provider and costs money.\n" .
        "  -h, --help            This help.\n\n" .
        "Needs: the API key of the connector in the plugin settings, and, for the scanned PDF, pdftoppm and tesseract."
    );
    exit($unrecognized ? 1 : 0);
}

// Everything is checked before any setting is changed.
if ($options['connector'] !== '' && !in_array($options['connector'], \local_aicoursebuilder\ai\router::CONNECTORS, true)) {
    cli_error('Unknown connector. Choose one of: ' . implode(', ', \local_aicoursebuilder\ai\router::CONNECTORS) . '.');
}
$connector = $options['connector'] !== '' ? $options['connector']
    : (trim((string) get_config('local_aicoursebuilder', 'defaultconnector'))
        ?: \local_aicoursebuilder\ai\router::DEFAULT_CONNECTOR);
if (!$options['yes']) {
    cli_error(
        "The digest will run on the '{$connector}' connector, which calls the AI provider and costs money. " .
        'Run again with --yes.'
    );
}
if ($connector === 'deepseek' && trim((string) get_config('local_aicoursebuilder', 'deepseek_apikey')) === '') {
    cli_error('The DeepSeek API key is not set in Site administration > Plugins > Local plugins > AI Course Builder.');
}

// The settings that the run changes are put back at the end.
$original = [
    'defaultconnector' => get_config('local_aicoursebuilder', 'defaultconnector'),
    'maxfiles' => get_config('local_aicoursebuilder', 'maxfiles'),
];
register_shutdown_function(function () use ($original): void {
    foreach ($original as $name => $value) {
        if ($value === false) {
            unset_config($name, 'local_aicoursebuilder');
        } else {
            set_config($name, $value, 'local_aicoursebuilder');
        }
    }
});
set_config('defaultconnector', $connector, 'local_aicoursebuilder');

$user = $DB->get_record('user', ['username' => $options['user'], 'deleted' => 0], '*', MUST_EXIST);
\core\session\manager::set_user($user);

$directory = __DIR__ . '/../tests/fixtures/sources/';
$manifest = json_decode(file_get_contents($directory . 'sources.json'), true, 512, JSON_THROW_ON_ERROR);
$filenames = array_column($manifest['files'], 'file');
if ($options['files'] !== '') {
    $filenames = array_values(array_intersect($filenames, array_map('trim', explode(',', $options['files']))));
}
if (!$filenames) {
    cli_error('No document to run.');
}

$now = time();
$jobid = $DB->insert_record('local_aicb_job', (object) [
    'userid' => $user->id,
    'status' => 'queued',
    'prompt' => 'Golden set digest check (M1)',
    'timecreated' => $now,
    'timemodified' => $now,
]);
$draftitemid = file_get_unused_draft_itemid();
$usercontext = context_user::instance($user->id);
foreach ($filenames as $filename) {
    get_file_storage()->create_file_from_pathname([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => $filename,
    ], $directory . $filename);
}
$manager = new source_manager();
// One job holds at most the number of files of the setting; the check of M1 needs all the documents in one job.
set_config('maxfiles', max($manager->get_max_files(), count($filenames)), 'local_aicoursebuilder');
$manager->save_from_draft($jobid, $draftitemid, context_system::instance());

cli_writeln("Job {$jobid} for user {$user->username}, connector {$connector}, " . count($filenames) . ' documents.');
$start = microtime(true);
ingest_sources::instance($jobid, (int) $user->id)->execute();
$seconds = microtime(true) - $start;
// The ingestion queues the generation of the blueprint when it is done; this check is of the digest only, so the task is
// taken off the queue, or the next adhoc run would generate a whole course and pay for it.
foreach ($DB->get_records_select('task_adhoc', 'classname LIKE ?', ['%generate_blueprint']) as $queued) {
    if ((int) (json_decode((string) $queued->customdata, true)['jobid'] ?? 0) === $jobid) {
        $DB->delete_records('task_adhoc', ['id' => $queued->id]);
    }
}

$sources = $DB->get_records('local_aicb_source', ['jobid' => $jobid], 'id');
$digested = 0;
// What each document cost, from the step rows: a long document is digested in windows (s7w0, s7w1), one node each.
$perdocument = [];
foreach ($DB->get_records('local_aicb_step', ['jobid' => $jobid, 'step' => 'digest']) as $step) {
    if (preg_match('/^s(\d+)(?:w\d+)?$/', $step->nodekey, $matches)) {
        $row = $perdocument[(int) $matches[1]] ?? ['nodes' => 0, 'in' => 0, 'cached' => 0, 'out' => 0, 'cost' => 0.0];
        $perdocument[(int) $matches[1]] = [
            'nodes' => $row['nodes'] + 1,
            'in' => $row['in'] + (int) $step->tokensin,
            'cached' => $row['cached'] + (int) $step->tokenscached,
            'out' => $row['out'] + (int) $step->tokensout,
            'cost' => $row['cost'] + (float) $step->cost,
        ];
    }
}
cli_writeln(sprintf(
    "\n%-18s %-9s %-16s %-5s %6s %8s %5s %8s %8s %8s %8s  %s",
    'document',
    'status',
    'extractor',
    'lang',
    'chunks',
    'tokens',
    'nodes',
    'in',
    'cached',
    'out',
    'USD',
    'digest'
));
foreach ($sources as $source) {
    $digest = $source->digest ? json_decode($source->digest, true) : null;
    $digested += $source->status === ingest_sources::STATUS_DIGESTED ? 1 : 0;
    $spent = $perdocument[(int) $source->id] ?? ['nodes' => 0, 'in' => 0, 'cached' => 0, 'out' => 0, 'cost' => 0.0];
    cli_writeln(sprintf(
        '%-18s %-9s %-16s %-5s %6d %8d %5d %8d %8d %8d %8.4f  %s',
        $source->filename,
        $source->status,
        (string) $source->extractor,
        (string) $source->language,
        $DB->count_records('local_aicb_chunk', ['sourceid' => $source->id]),
        (int) $source->tokencount,
        $spent['nodes'],
        $spent['in'],
        $spent['cached'],
        $spent['out'],
        $spent['cost'],
        $digest
            ? count($digest['concepts']) . ' concepts, ' . count($digest['definitions']) . ' definitions, '
                . count($digest['objectives']) . ' objectives, ' . count($digest['procedures']) . ' procedures'
            : (string) $source->error
    ));
}

$steps = $DB->get_records('local_aicb_step', ['jobid' => $jobid, 'step' => 'digest']);
$done = count(array_filter($steps, fn($step) => $step->status === 'done'));
$job = $DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST);
$stepcost = array_sum(array_column($steps, 'cost'));
// The step rows do not keep how many calls a node made (repairs and regenerations included), so the calls are counted in
// the call log of the job: the digest calls and the repair calls that followed them.
$calls = $DB->get_records_sql(
    'SELECT step, COUNT(1) AS total FROM {local_aicb_ailog} WHERE jobid = ? AND step IN (?, ?) GROUP BY step',
    [$jobid, 'digest', 'repair']
);
cli_writeln(sprintf(
    "\nDigest nodes: %d done of %d (%.0f%% valid). AI calls: %d digest, %d repair. Tokens in/cached/out: %d/%d/%d. " .
    "Cost: %.4f USD. Time: %.0f s.",
    $done,
    count($steps),
    $steps ? 100 * $done / count($steps) : 0,
    (int) ($calls['digest']->total ?? 0),
    (int) ($calls['repair']->total ?? 0),
    array_sum(array_column($steps, 'tokensin')),
    array_sum(array_column($steps, 'tokenscached')),
    array_sum(array_column($steps, 'tokensout')),
    $job->actualcost,
    $seconds
));
if (abs((float) $job->actualcost - $stepcost) > 0.000001) {
    // The ingestion keeps the cost of the job up to date after every node; a difference is a defect, not a rounding.
    cli_writeln(sprintf(
        'WARNING: the cost of the job (%.6f) is not the sum of its digest step rows (%.6f).',
        $job->actualcost,
        $stepcost
    ));
}
$allok = $digested === count($sources);
cli_writeln("Digested: {$digested} of " . count($sources) . ($allok ? ' - M1 check passed.' : ' - M1 check NOT passed.'));
exit($allok ? 0 : 2);
