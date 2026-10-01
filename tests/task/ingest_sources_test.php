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

namespace local_aicoursebuilder\task;

use local_aicoursebuilder\ingest\extraction_result;
use local_aicoursebuilder\ingest\source_fixtures;
use local_aicoursebuilder\ingest\source_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sources/source_fixtures.php');

/**
 * Tests for the ingest_sources task.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\task\ingest_sources
 * @covers     \local_aicoursebuilder\ingest\source_manager
 */
final class ingest_sources_test extends \advanced_testcase {
    /** @var float Minimum share of the reference words that the extracted text must have. */
    private const MIN_USEFUL_TEXT = 0.95;

    /** @var \stdClass Owner of the job. */
    private \stdClass $user;

    /** @var int Job id. */
    private int $jobid;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'status' => 'queued',
            'prompt' => 'Test',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Saves files as sources of the job, as the upload of a job does.
     *
     * @param string[] $files Content of the files, indexed by file name.
     * @return \stdClass[] The source rows, indexed by file name.
     */
    private function add_sources(array $files): array {
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($this->user->id);
        foreach ($files as $filename => $content) {
            get_file_storage()->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $content);
        }
        $sources = (new source_manager())->save_from_draft($this->jobid, $draftitemid, \context_system::instance());
        $byname = [];
        foreach ($sources as $source) {
            $byname[$source->filename] = $source;
        }
        return $byname;
    }

    /**
     * Queues the task of the job and runs it as cron does.
     */
    private function run_task(): void {
        \core\task\manager::queue_adhoc_task(ingest_sources::instance($this->jobid, $this->user->id));
        $this->runAdhocTasks(ingest_sources::class);
    }

    /**
     * Loads a row again.
     *
     * @param string $table Table.
     * @param int $id Row id.
     * @return \stdClass
     */
    private function reload(string $table, int $id): \stdClass {
        global $DB;
        return $DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * A job with two sources: both are extracted, their text is saved in the extracted area and the job is updated.
     */
    public function test_extracts_two_sources(): void {
        $sources = $this->add_sources(['manual.pdf' => source_fixtures::pdf(), 'guide.docx' => source_fixtures::docx()]);
        $manager = new source_manager();

        $this->run_task();

        $pdf = $this->reload('local_aicb_source', $sources['manual.pdf']->id);
        $this->assertSame('digested', $pdf->status);
        $this->assertSame(extraction_result::EXTRACTOR_PDFPARSER, $pdf->extractor);
        $this->assertEquals(3, $pdf->pagecount);
        $this->assertNull($pdf->error);
        $text = $manager->get_extracted_file($pdf->id);
        $this->assertSame('manual.md', $text->get_filename());
        $this->assertSame('extracted', $text->get_filearea());
        $this->assertEquals($pdf->id, $text->get_itemid());
        $this->assertGreaterThanOrEqual(
            self::MIN_USEFUL_TEXT,
            source_fixtures::useful_text_ratio(source_fixtures::pdf_reference(), $text->get_content())
        );

        $docx = $this->reload('local_aicb_source', $sources['guide.docx']->id);
        $this->assertSame('digested', $docx->status);
        $this->assertSame(extraction_result::EXTRACTOR_PHPWORD, $docx->extractor);
        $text = $manager->get_extracted_file($docx->id);
        $this->assertSame('guide.md', $text->get_filename());
        $this->assertStringContainsString('## Crearea unui cont nou', $text->get_content());
        $this->assertGreaterThanOrEqual(
            self::MIN_USEFUL_TEXT,
            source_fixtures::useful_text_ratio(source_fixtures::docx_reference(), $text->get_content())
        );

        $job = $this->reload('local_aicb_job', $this->jobid);
        $this->assertSame('ingesting', $job->status);
        $this->assertSame('ingest', $job->stage);
        $this->assertEquals(100, $job->progress);
        $this->assertNotEmpty($job->timestarted);
        $this->assertNull($job->error);
        $this->assertStringContainsString('2 of 2', $job->statusmessage);
    }

    /**
     * A repeated run skips the sources that are already extracted, and a resumed run only does the pending ones.
     */
    public function test_resume_skips_extracted_sources(): void {
        global $DB;
        $sources = $this->add_sources(['first.pdf' => source_fixtures::pdf(), 'second.pdf' => source_fixtures::pdf()]);
        $manager = new source_manager();
        $first = $sources['first.pdf'];
        $second = $sources['second.pdf'];

        // The first source was extracted by an earlier run that stopped before the second one.
        $file = $manager->get_source_file($first->id);
        $manager->save_extracted($first, $file, 'EARLIER RUN');
        $DB->update_record('local_aicb_source', (object) [
            'id' => $first->id,
            'status' => 'extracted',
            'extractor' => 'earlier',
            'pagecount' => 7,
        ]);
        $DB->set_field('local_aicb_job', 'progress', 50, ['id' => $this->jobid]);

        $this->run_task();

        $first = $this->reload('local_aicb_source', $first->id);
        $this->assertSame('earlier', $first->extractor);
        $this->assertEquals(7, $first->pagecount);
        $this->assertSame('EARLIER RUN', $manager->get_extracted_file($first->id)->get_content());
        $second = $this->reload('local_aicb_source', $second->id);
        $this->assertSame('digested', $second->status);
        $this->assertSame(extraction_result::EXTRACTOR_PDFPARSER, $second->extractor);
        $this->assertEquals(100, $this->reload('local_aicb_job', $this->jobid)->progress);

        // Running the task again changes nothing.
        $before = $DB->get_records('local_aicb_source', ['jobid' => $this->jobid], 'id');
        $textbefore = $manager->get_extracted_file($second->id)->get_contenthash();
        $this->run_task();
        $this->assertEquals($before, $DB->get_records('local_aicb_source', ['jobid' => $this->jobid], 'id'));
        $this->assertSame($textbefore, $manager->get_extracted_file($second->id)->get_contenthash());
        $this->assertSame(
            1,
            $DB->count_records('files', ['filearea' => 'extracted', 'itemid' => $second->id, 'filename' => 'second.md'])
        );
    }

    /**
     * A source that fails is marked failed with its error, and the other sources are still extracted.
     */
    public function test_failed_source_does_not_stop_the_others(): void {
        $sources = $this->add_sources(['good.pdf' => source_fixtures::pdf(), 'broken.docx' => 'this is not a zip archive']);
        $this->expectOutputRegex('/Source \d+ failed/');

        $this->run_task();

        $good = $this->reload('local_aicb_source', $sources['good.pdf']->id);
        $this->assertSame('digested', $good->status);
        $broken = $this->reload('local_aicb_source', $sources['broken.docx']->id);
        $this->assertSame('failed', $broken->status);
        $this->assertNotEmpty($broken->error);
        $this->assertNull((new source_manager())->get_extracted_file($broken->id));

        $job = $this->reload('local_aicb_job', $this->jobid);
        $this->assertSame('ingesting', $job->status);
        $this->assertEquals(100, $job->progress);
    }

    /**
     * When no source has text the job fails, and a failed source is not tried again.
     */
    public function test_job_fails_when_no_source_is_extracted(): void {
        $sources = $this->add_sources(['broken.docx' => 'this is not a zip archive']);
        $this->expectOutputRegex('/Source \d+ failed/');

        $this->run_task();

        $job = $this->reload('local_aicb_job', $this->jobid);
        $this->assertSame('failed', $job->status);
        $this->assertSame(get_string('ingestallfailed', 'local_aicoursebuilder'), $job->error);
        $this->assertNotEmpty($job->timefinished);
        $source = $this->reload('local_aicb_source', $sources['broken.docx']->id);
        $this->assertSame('failed', $source->status);
    }

    /**
     * A job without sources fails with its own message.
     */
    public function test_job_without_sources(): void {
        $this->run_task();

        $job = $this->reload('local_aicb_job', $this->jobid);
        $this->assertSame('failed', $job->status);
        $this->assertSame(get_string('ingestnosources', 'local_aicoursebuilder'), $job->error);
    }

    /**
     * The task does nothing to a cancelled job or to a job that does not exist.
     */
    public function test_cancelled_and_missing_jobs(): void {
        global $DB;
        $sources = $this->add_sources(['manual.pdf' => source_fixtures::pdf()]);
        $DB->set_field('local_aicb_job', 'status', 'cancelled', ['id' => $this->jobid]);

        $this->run_task();

        $this->assertSame('cancelled', $this->reload('local_aicb_job', $this->jobid)->status);
        $this->assertSame('pending', $this->reload('local_aicb_source', $sources['manual.pdf']->id)->status);

        $task = ingest_sources::instance($this->jobid + 1000, $this->user->id);
        $task->execute();
        $this->assertSame('pending', $this->reload('local_aicb_source', $sources['manual.pdf']->id)->status);
    }

    /**
     * After the run a source has its chunks, its language and size and its digest, and the digest call is a checkpoint.
     */
    public function test_sources_get_chunks_and_a_digest(): void {
        global $DB;
        $sources = $this->add_sources(['manual.pdf' => source_fixtures::pdf(), 'guide.docx' => source_fixtures::docx()]);

        $this->run_task();

        foreach ($sources as $name => $source) {
            $row = $this->reload('local_aicb_source', $source->id);
            $this->assertSame('digested', $row->status, $name);
            $this->assertNotEmpty($row->language, $name);
            $chunks = array_values($DB->get_records('local_aicb_chunk', ['sourceid' => $source->id], 'chunkindex'));
            $this->assertNotEmpty($chunks, $name);
            $this->assertEquals(array_sum(array_column($chunks, 'tokencount')), $row->tokencount, $name);
            foreach ($chunks as $position => $chunk) {
                $this->assertEquals($position, $chunk->chunkindex);
                $this->assertEquals($this->jobid, $chunk->jobid);
                $this->assertSame(sha1($chunk->content), $chunk->contenthash);
                $this->assertGreaterThan(0, $chunk->tokencount);
            }
            $digest = json_decode($row->digest, true);
            $this->assertSame('src' . $source->id, $digest['source'], $name);
            $this->assertNotEmpty($digest['concepts'], $name);
        }

        $pdfchunks = $DB->get_records('local_aicb_chunk', ['sourceid' => $sources['manual.pdf']->id]);
        $this->assertStringContainsString('Gestionarea parolelor', reset($pdfchunks)->content);
        $this->assertSame(
            2,
            $DB->count_records('local_aicb_step', ['jobid' => $this->jobid, 'step' => 'digest', 'status' => 'done'])
        );
    }

    /**
     * A run that stops after the chunks goes on with the digest: the chunks stay and the digest comes from its checkpoint.
     */
    public function test_resume_after_the_chunks(): void {
        global $DB;
        $sources = $this->add_sources(['manual.pdf' => source_fixtures::pdf()]);
        $id = $sources['manual.pdf']->id;
        $this->run_task();
        $chunkids = $DB->get_fieldset_select('local_aicb_chunk', 'id', 'sourceid = ?', [$id]);
        $digest = $this->reload('local_aicb_source', $id)->digest;

        // As if the run had stopped before the digest was saved.
        $DB->update_record('local_aicb_source', (object) ['id' => $id, 'status' => 'extracted', 'digest' => null]);
        $this->run_task();

        $this->assertSame($chunkids, $DB->get_fieldset_select('local_aicb_chunk', 'id', 'sourceid = ?', [$id]));
        $this->assertSame(1, $DB->count_records('local_aicb_step', ['jobid' => $this->jobid]));
        $row = $this->reload('local_aicb_source', $id);
        $this->assertSame('digested', $row->status);
        $this->assertSame($digest, $row->digest);
    }

    /**
     * The chunks are made from the text in the extracted area, with its headings.
     */
    public function test_chunks_come_from_the_extracted_text(): void {
        global $DB;
        $sources = $this->add_sources(['manual.pdf' => source_fixtures::pdf()]);
        $source = $sources['manual.pdf'];
        $manager = new source_manager();
        $manager->save_extracted($source, $manager->get_source_file($source->id), "# Titlu\n\nText nou al documentului.");
        $DB->update_record('local_aicb_source', (object) ['id' => $source->id, 'status' => 'extracted', 'extractor' => 'test']);

        $this->run_task();

        $chunks = $DB->get_records('local_aicb_chunk', ['sourceid' => $source->id]);
        $this->assertCount(1, $chunks);
        $chunk = reset($chunks);
        $this->assertSame('Titlu', $chunk->title);
        $this->assertSame("# Titlu\n\nText nou al documentului.", $chunk->content);
        $this->assertNull($chunk->pagefrom);
    }

    /**
     * A source whose extracted text is missing fails on its own; the others are still digested.
     */
    public function test_missing_extracted_text_fails_only_that_source(): void {
        global $DB;
        $sources = $this->add_sources(['lost.pdf' => source_fixtures::pdf(), 'good.pdf' => source_fixtures::pdf()]);
        $DB->update_record('local_aicb_source', (object) ['id' => $sources['lost.pdf']->id, 'status' => 'extracted']);
        $this->expectOutputRegex('/Source \d+ failed/');

        $this->run_task();

        $lost = $this->reload('local_aicb_source', $sources['lost.pdf']->id);
        $this->assertSame('failed', $lost->status);
        $this->assertNotEmpty($lost->error);
        $this->assertSame('digested', $this->reload('local_aicb_source', $sources['good.pdf']->id)->status);
        $job = $this->reload('local_aicb_job', $this->jobid);
        $this->assertSame('ingesting', $job->status);
        $this->assertEquals(100, $job->progress);
        $this->assertStringContainsString('2 of 2', $job->statusmessage);
    }

    /**
     * When the digest cannot be made for any source the job fails with its own message, and the text and the chunks
     * stay.
     */
    public function test_job_fails_when_no_source_can_be_digested(): void {
        global $DB;
        // The real connector without an API key cannot answer.
        set_config('defaultconnector', 'deepseek', 'local_aicoursebuilder');
        $sources = $this->add_sources(['manual.pdf' => source_fixtures::pdf()]);
        $this->expectOutputRegex('/Source \d+ failed/');

        $this->run_task();

        $job = $this->reload('local_aicb_job', $this->jobid);
        $this->assertSame('failed', $job->status);
        $this->assertSame(get_string('ingestnodigest', 'local_aicoursebuilder'), $job->error);
        $source = $this->reload('local_aicb_source', $sources['manual.pdf']->id);
        $this->assertSame('failed', $source->status);
        $this->assertNotEmpty($source->error);
        $this->assertNotNull($source->extractor);
        $this->assertNotNull((new source_manager())->get_extracted_file($source->id));
        $this->assertTrue($DB->record_exists('local_aicb_chunk', ['sourceid' => $source->id]));
        $this->assertSame('error', $DB->get_field('local_aicb_step', 'status', ['jobid' => $this->jobid]));
    }

    /**
     * When the sources are digested the task queues the generation of the blueprint, once, for the same owner.
     */
    public function test_queues_the_generation_when_the_sources_are_digested(): void {
        $this->add_sources(['notes.txt' => 'Energia regenerabilă provine din surse care se refac natural.']);

        $this->run_task();
        $this->run_task();

        $this->assertSame('ingesting', $this->reload('local_aicb_job', $this->jobid)->status);
        $tasks = \core\task\manager::get_adhoc_tasks(generate_blueprint::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertEquals($this->jobid, $task->get_custom_data()->jobid);
        $this->assertEquals($this->user->id, $task->get_userid());
    }

    /**
     * A job that failed has nothing to generate from, so no generation is queued.
     */
    public function test_does_not_queue_the_generation_when_the_job_fails(): void {
        $this->run_task();

        $this->assertSame('failed', $this->reload('local_aicb_job', $this->jobid)->status);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_blueprint::class));
    }

    /**
     * The task runs as the job owner and has a readable name.
     */
    public function test_task_definition(): void {
        $task = ingest_sources::instance($this->jobid, $this->user->id);
        $this->assertEquals($this->user->id, $task->get_userid());
        $this->assertEquals($this->jobid, $task->get_custom_data()->jobid);
        $this->assertSame(get_string('task_ingestsources', 'local_aicoursebuilder'), $task->get_name());
    }
}
