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
 * Builds the course of the golden blueprint with the real builders, with an error injected, and resumes the build.
 *
 * It is the check of the build_course task: the course is made from tests/fixtures/blueprint_golden.json by the builders
 * of the plugin; the nodes named with --inject fail on the first run, and the build is resumed with the error gone. The
 * script then checks that the course has every module the blueprint asks for, once each, and that the modules built
 * before the error are the same ones after the resume. The course is hidden and stays on the site for a look, unless
 * --delete is given. No AI provider is called and nothing is paid for.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/../tests/fixtures/failing_builder.php');

use local_aicoursebuilder\ai\fake_lock_factory;
use local_aicoursebuilder\builder\builder_registry;
use local_aicoursebuilder\builder\failing_builder;
use local_aicoursebuilder\task\build_course;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'user' => 'admin',
    'category' => '',
    'inject' => 's2.assign1,s1-1',
    'delete' => false,
    'yes' => false,
], ['h' => 'help']);

if ($unrecognized || $options['help']) {
    cli_writeln(
        "Builds the golden blueprint with the real builders, with an error injected, then resumes the build.\n\n" .
        "Options:\n" .
        "  --user=USERNAME     Owner of the job and teacher of the course (default: admin).\n" .
        "  --category=ID       Category of the new course (default: the first one).\n" .
        "  --inject=A,B        Nodes that fail on the first run (default: s2.assign1,s1-1; empty for none).\n" .
        "  --delete            Delete the course and the job at the end (by default the hidden course stays).\n" .
        "  --yes               Go on: a course is made on this site.\n" .
        "  -h, --help          This help.\n\n" .
        "No AI provider is called."
    );
    exit($unrecognized ? 1 : 0);
}
if (!$options['yes']) {
    cli_error('A hidden course is made on this site. Run again with --yes.');
}

$user = $DB->get_record('user', ['username' => $options['user'], 'deleted' => 0], '*', MUST_EXIST);
$categoryid = $options['category'] !== ''
    ? (int) $options['category']
    : (int) $DB->get_field_select('course_categories', 'MIN(id)', 'visible = 1');
$DB->get_record('course_categories', ['id' => $categoryid], 'id', MUST_EXIST);
\core\session\manager::set_user($user);
set_config('enablecompletion', 1);

$blueprintfile = __DIR__ . '/../tests/fixtures/blueprint_golden.json';
$blueprint = json_decode(file_get_contents($blueprintfile), true, 512, JSON_THROW_ON_ERROR);

// The type of every node the blueprint has, to wrap the builder of the nodes that fail.
$types = [];
foreach ($blueprint['sections'] as $section) {
    $types[$section['id']] = 'section';
    foreach ($section['activities'] ?? [] as $activity) {
        $types[$activity['id']] = $activity['type'];
    }
    foreach ($section['subsections'] ?? [] as $subsection) {
        $types[$subsection['id']] = 'subsection';
        foreach ($subsection['activities'] ?? [] as $activity) {
            $types[$activity['id']] = $activity['type'];
        }
    }
}
$inject = array_values(array_filter(array_map('trim', explode(',', (string) $options['inject']))));
foreach ($inject as $nodeid) {
    if (!isset($types[$nodeid])) {
        cli_error("The blueprint has no node {$nodeid}.");
    }
}

// The job, its two sources (the golden blueprint refers to src1 and src2) and the approved blueprint.
$now = time();
$jobid = $DB->insert_record('local_aicb_job', (object) [
    'userid' => $user->id,
    'mode' => 'newcourse',
    'categoryid' => $categoryid,
    'status' => 'approved',
    'prompt' => 'Golden build check',
    'language' => 'ro',
    'timecreated' => $now,
    'timemodified' => $now,
]);
$sourceids = [];
foreach (['src1' => 'pdf_text_01.pdf', 'src2' => 'docx_01.docx'] as $key => $filename) {
    $path = __DIR__ . '/../tests/fixtures/sources/' . $filename;
    $id = $DB->insert_record('local_aicb_source', (object) [
        'jobid' => $jobid,
        'filename' => $filename,
        'mimetype' => 'application/octet-stream',
        'filesize' => filesize($path),
        'contenthash' => sha1_file($path),
        'status' => 'digested',
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
    get_file_storage()->create_file_from_pathname([
        'contextid' => context_system::instance()->id,
        'component' => 'local_aicoursebuilder',
        'filearea' => 'source',
        'itemid' => $id,
        'filepath' => '/',
        'filename' => $filename,
    ], $path);
    $sourceids[$key] = 'src' . $id;
}
$content = strtr(file_get_contents($blueprintfile), [
    '"src1"' => '"' . $sourceids['src1'] . '"',
    '"src2"' => '"' . $sourceids['src2'] . '"',
]);
$blueprintid = $DB->insert_record('local_aicb_blueprint', (object) [
    'jobid' => $jobid,
    'version' => 1,
    'schemaversion' => '1.0',
    'content' => $content,
    'contenthash' => hash('sha256', $content),
    'status' => 'approved',
    'usermodified' => $user->id,
    'timecreated' => $now,
    'timemodified' => $now,
]);
$DB->set_field('local_aicb_job', 'blueprintid', $blueprintid, ['id' => $jobid]);
cli_writeln("Job {$jobid} for {$user->username}, category {$categoryid}, golden blueprint approved.");

/**
 * Runs the build task of the job.
 *
 * @param int $jobid Job id.
 * @param int $userid Owner of the job.
 * @param builder_registry $registry The builders.
 */
function golden_build_run(int $jobid, int $userid, builder_registry $registry): void {
    $task = new build_course($registry, new fake_lock_factory());
    $task->set_custom_data(['jobid' => $jobid]);
    $task->set_userid($userid);
    ob_start();
    try {
        $task->execute();
    } finally {
        ob_end_clean();
    }
}

/**
 * Counts the modules of a course by type.
 *
 * @param int $courseid Course id.
 * @return int[] Module name => count.
 */
function golden_build_count(int $courseid): array {
    global $DB;
    $rows = $DB->get_records_sql(
        'SELECT m.name, COUNT(cm.id) AS total
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = :courseid AND cm.deletioninprogress = 0
       GROUP BY m.name',
        ['courseid' => $courseid]
    );
    $counts = [];
    foreach ($rows as $row) {
        $counts[$row->name] = (int) $row->total;
    }
    ksort($counts);
    return $counts;
}

/**
 * Returns the job row and its build map.
 *
 * @param int $jobid Job id.
 * @return array [\stdClass $job, array $map]
 */
function golden_build_state(int $jobid): array {
    global $DB;
    $job = $DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST);
    return [$job, json_decode((string) $job->buildmap, true) ?: []];
}

// First run: the builders of the nodes in --inject fail.
$registry = builder_registry::with_defaults();
$wrappers = [];
foreach ($inject as $nodeid) {
    $type = $types[$nodeid];
    if (!isset($wrappers[$type])) {
        // Every injected node of this type fails in the one wrapper.
        $nodes = array_values(array_filter($inject, fn($id) => $types[$id] === $type));
        $wrappers[$type] = new failing_builder($registry->get($type), $nodes);
        $registry->register($type, $wrappers[$type]);
    }
}
cli_writeln('First run, errors injected on: ' . ($inject ? implode(', ', $inject) : 'none'));
golden_build_run($jobid, (int) $user->id, $registry);
[$job, $map] = golden_build_state($jobid);
$failed = array_keys(array_filter($map, fn($entry) => ($entry['status'] ?? '') === 'failed'));
$firstmodules = $job->courseid ? golden_build_count((int) $job->courseid) : [];
$firstcmids = array_column(array_filter($map, fn($entry) => !empty($entry['cmid'])), 'cmid');
cli_writeln(sprintf(
    '  job %s, course %s, nodes failed: %s',
    $job->status,
    $job->courseid ?: '-',
    $failed ? implode(', ', $failed) : 'none'
));
cli_writeln('  modules: ' . json_encode($firstmodules));

// Second run: the errors are gone and the job is reopened the way a retry reopens it.
foreach ($wrappers as $wrapper) {
    $wrapper->armed = false;
}
$DB->set_field('local_aicb_job', 'status', 'approved', ['id' => $jobid]);
cli_writeln('Second run, build resumed with no error.');
golden_build_run($jobid, (int) $user->id, $registry);
[$job, $map] = golden_build_state($jobid);
$modules = golden_build_count((int) $job->courseid);
cli_writeln(sprintf('  job %s, course %d', $job->status, $job->courseid));
cli_writeln('  modules: ' . json_encode($modules));

// What the blueprint asks for: every activity, the module of each subsection, the news forum and the question bank.
$expected = ['forum' => 1];
foreach ($types as $type) {
    if (!in_array($type, ['section', 'h5pactivity', 'scorm'], true)) {
        $expected[$type] = ($expected[$type] ?? 0) + 1;
    }
}
if (!empty($expected['quiz'])) {
    $expected['qbank'] = 1;
}
ksort($expected);

$problems = [];
if ($job->status !== 'finished') {
    $problems[] = 'the job did not finish: ' . $job->error;
}
if ($modules !== $expected) {
    $problems[] = 'the modules are not the ones of the blueprint, expected ' . json_encode($expected);
}
foreach ($firstcmids as $cmid) {
    if (!$DB->record_exists('course_modules', ['id' => $cmid])) {
        $problems[] = "the module {$cmid} built before the error is gone";
    }
}
$stillfailed = array_keys(array_filter($map, fn($entry) => ($entry['status'] ?? '') === 'failed'));
if ($stillfailed) {
    $problems[] = 'nodes still failed: ' . implode(', ', $stillfailed);
}

cli_writeln('Course: ' . $CFG->wwwroot . '/course/view.php?id=' . $job->courseid . ' (hidden)');
if ($options['delete']) {
    delete_course((int) $job->courseid, false);
    $DB->delete_records('local_aicb_blueprint', ['jobid' => $jobid]);
    $DB->delete_records('local_aicb_source', ['jobid' => $jobid]);
    $DB->delete_records('local_aicb_job', ['id' => $jobid]);
    cli_writeln('The course and the job were deleted.');
}
if ($problems) {
    foreach ($problems as $problem) {
        cli_writeln('FAILED: ' . $problem);
    }
    exit(1);
}
cli_writeln('OK: every module once, none twice; the modules built before the error are the same after the resume.');
exit(0);
