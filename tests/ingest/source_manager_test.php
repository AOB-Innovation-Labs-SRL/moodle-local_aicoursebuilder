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
require_once(__DIR__ . '/../fixtures/sources/antivirus_aicbtest.php');

/**
 * Tests for the source manager.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\source_manager
 */
final class source_manager_test extends \advanced_testcase {
    /** @var \stdClass Current user. */
    private \stdClass $user;

    /** @var int Job id. */
    private int $jobid;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->user->id,
            'prompt' => 'Test',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Puts files in a new draft area of the current user.
     *
     * @param string[] $files Content of the files, indexed by file name.
     * @return int Draft item id.
     */
    private function create_draft(array $files): int {
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
        return $draftitemid;
    }

    /**
     * Counts the files of the source area.
     *
     * @return int
     */
    private function count_source_files(): int {
        global $DB;
        return $DB->count_records_select(
            'files',
            "component = 'local_aicoursebuilder' AND filearea = 'source' AND filename <> '.'"
        );
    }

    /**
     * Files are saved in the source area with the row id as item id, and a pending row is created for each.
     */
    public function test_save_from_draft(): void {
        global $DB;
        $pdf = source_fixtures::pdf();
        $docx = source_fixtures::docx();
        $context = \context_system::instance();

        $sources = (new source_manager())->save_from_draft(
            $this->jobid,
            $this->create_draft(['manual.pdf' => $pdf, 'guide.DOCX' => $docx]),
            $context
        );

        $this->assertCount(2, $sources);
        $rows = $DB->get_records('local_aicb_source', ['jobid' => $this->jobid], 'filename');
        $this->assertCount(2, $rows);
        $byname = [];
        foreach ($rows as $row) {
            $byname[$row->filename] = $row;
            $this->assertSame('pending', $row->status);
            $this->assertNull($row->extractor);
            $file = get_file_storage()->get_file(
                $context->id,
                'local_aicoursebuilder',
                'source',
                $row->id,
                '/',
                $row->filename
            );
            $this->assertInstanceOf(\stored_file::class, $file);
            $this->assertEquals($row->filesize, $file->get_filesize());
            $this->assertSame($row->contenthash, $file->get_contenthash());
        }
        $this->assertSame('application/pdf', $byname['manual.pdf']->mimetype);
        $this->assertSame(extractor_factory::MIMETYPES['docx'], $byname['guide.DOCX']->mimetype);
        $this->assertSame(strlen($pdf), (int) $byname['manual.pdf']->filesize);
        $this->assertSame(sha1($pdf), $byname['manual.pdf']->contenthash);

        $manager = new source_manager();
        $this->assertSame($pdf, $manager->get_source_file($byname['manual.pdf']->id)->get_content());
        $this->assertNull($manager->get_extracted_file($byname['manual.pdf']->id));
    }

    /**
     * A draft area without files is refused.
     */
    public function test_no_files(): void {
        $this->expectException(ingest_exception::class);
        $this->expectExceptionMessage(get_string('sourcenofiles', 'local_aicoursebuilder'));
        (new source_manager())->save_from_draft($this->jobid, $this->create_draft([]), \context_system::instance());
    }

    /**
     * A job that does not exist is a coding error of the caller.
     */
    public function test_unknown_job(): void {
        $this->expectException(\dml_missing_record_exception::class);
        (new source_manager())->save_from_draft(0, $this->create_draft(['a.pdf' => 'x']), \context_system::instance());
    }

    /**
     * A file of another type is refused, and nothing is saved even when the other files are valid.
     */
    public function test_type_not_allowed(): void {
        global $DB;
        $draft = $this->create_draft(['ok.pdf' => source_fixtures::pdf(), 'photo.png' => 'image']);
        try {
            (new source_manager())->save_from_draft($this->jobid, $draft, \context_system::instance());
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::TYPE_NOT_ALLOWED, $e->errorcode);
            $this->assertStringContainsString('photo.png', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_aicb_source'));
        $this->assertSame(0, $this->count_source_files());
    }

    /**
     * The allowed types come from the settings.
     */
    public function test_allowed_types_setting(): void {
        $manager = new source_manager();
        $this->assertSame(array_keys(extractor_factory::MIMETYPES), $manager->get_allowed_types());
        $this->assertContains('pptx', $manager->get_allowed_types());

        set_config('allowedtypes', 'pdf', 'local_aicoursebuilder');
        $this->assertSame(['pdf'], $manager->get_allowed_types());
        try {
            $manager->save_from_draft(
                $this->jobid,
                $this->create_draft(['guide.docx' => source_fixtures::docx()]),
                \context_system::instance()
            );
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::TYPE_NOT_ALLOWED, $e->errorcode);
        }

        set_config('allowedtypes', '', 'local_aicoursebuilder');
        $this->assertSame([], $manager->get_allowed_types());
    }

    /**
     * A file over the maximum size is refused; a file at the limit is accepted.
     */
    public function test_size_limit(): void {
        global $DB;
        set_config('maxfilesize', 1, 'local_aicoursebuilder');
        $manager = new source_manager();
        $this->assertSame(1048576, $manager->get_max_file_bytes());

        try {
            $manager->save_from_draft(
                $this->jobid,
                $this->create_draft(['big.pdf' => str_repeat('a', 1048577)]),
                \context_system::instance()
            );
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::TOO_LARGE, $e->errorcode);
            $this->assertStringContainsString('big.pdf', $e->getMessage());
            $this->assertStringContainsString(display_size(1048576), $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_aicb_source'));

        $sources = $manager->save_from_draft(
            $this->jobid,
            $this->create_draft(['edge.pdf' => str_repeat('a', 1048576)]),
            \context_system::instance()
        );
        $this->assertCount(1, $sources);
    }

    /**
     * The number of files counts the files the job already has.
     */
    public function test_file_count_limit(): void {
        global $DB;
        set_config('maxfiles', 2, 'local_aicoursebuilder');
        $manager = new source_manager();
        $this->assertSame(2, $manager->get_max_files());
        $context = \context_system::instance();

        try {
            $manager->save_from_draft(
                $this->jobid,
                $this->create_draft(['a.pdf' => 'a', 'b.pdf' => 'b', 'c.pdf' => 'c']),
                $context
            );
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::TOO_MANY, $e->errorcode);
            $this->assertStringContainsString('2', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_aicb_source'));

        $manager->save_from_draft($this->jobid, $this->create_draft(['a.pdf' => 'a']), $context);
        $manager->save_from_draft($this->jobid, $this->create_draft(['b.pdf' => 'b']), $context);
        $this->assertSame(2, $DB->count_records('local_aicb_source'));

        $this->expectException(ingest_exception::class);
        $manager->save_from_draft($this->jobid, $this->create_draft(['c.pdf' => 'c']), $context);
    }

    /**
     * Without saved limits the defaults apply, and a value that is not positive falls back to them.
     */
    public function test_limit_defaults(): void {
        $manager = new source_manager();
        $this->assertSame(source_manager::DEFAULT_MAXFILESIZE * 1048576, $manager->get_max_file_bytes());
        $this->assertSame(source_manager::DEFAULT_MAXFILES, $manager->get_max_files());

        set_config('maxfiles', 0, 'local_aicoursebuilder');
        set_config('maxfilesize', -5, 'local_aicoursebuilder');
        $this->assertSame(source_manager::DEFAULT_MAXFILES, $manager->get_max_files());
        $this->assertSame(source_manager::DEFAULT_MAXFILESIZE * 1048576, $manager->get_max_file_bytes());
    }

    /**
     * Every file is scanned by the enabled antivirus, and clean files are saved.
     */
    public function test_antivirus_scans_every_file(): void {
        global $CFG;
        $CFG->antiviruses = 'aicbtest';
        \antivirus_aicbtest\scanner::reset();

        $sources = (new source_manager())->save_from_draft(
            $this->jobid,
            $this->create_draft(['one.pdf' => source_fixtures::pdf(), 'two.docx' => source_fixtures::docx()]),
            \context_system::instance()
        );

        $this->assertCount(2, $sources);
        $this->assertEqualsCanonicalizing(['one.pdf', 'two.docx'], \antivirus_aicbtest\scanner::$scanned);
    }

    /**
     * An infected file makes the whole upload fail, and nothing is saved.
     */
    public function test_antivirus_refuses_infected_file(): void {
        global $CFG, $DB;
        $CFG->antiviruses = 'aicbtest';
        \antivirus_aicbtest\scanner::reset();
        $messages = $this->redirectMessages();
        $draft = $this->create_draft([
            'clean.pdf' => source_fixtures::pdf(),
            'virus.pdf' => \antivirus_aicbtest\scanner::INFECTED_MARKER,
        ]);

        try {
            (new source_manager())->save_from_draft($this->jobid, $draft, \context_system::instance());
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::INFECTED, $e->errorcode);
            $this->assertStringContainsString('virus.pdf', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_aicb_source'));
        $this->assertSame(0, $this->count_source_files());
        $messages->close();
    }

    /**
     * Without an enabled antivirus plugin the files are saved.
     */
    public function test_no_antivirus_enabled(): void {
        global $CFG;
        $CFG->antiviruses = '';
        $sources = (new source_manager())->save_from_draft(
            $this->jobid,
            $this->create_draft(['one.pdf' => source_fixtures::pdf()]),
            \context_system::instance()
        );
        $this->assertCount(1, $sources);
    }
}
