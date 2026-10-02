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

namespace local_aicoursebuilder\builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/builder_test_helpers.php');

/**
 * Tests of the file builder (the resource module) and of the source files it publishes.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\resource_builder
 * @covers     \local_aicoursebuilder\builder\source_files
 */
final class resource_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * Returns the files of a course module.
     *
     * @param build_result $result The result of the builder.
     * @return \stored_file[] Content of the module, by file name.
     */
    private function module_files(build_result $result): array {
        $context = \context_module::instance($result->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'filename', false);
        $byname = [];
        foreach ($files as $file) {
            $byname[$file->get_filename()] = $file;
        }
        return $byname;
    }

    /**
     * The source file is published as the file of the module.
     */
    public function test_publishes_the_source_file(): void {
        global $DB;
        $node = $this->node('s1.resource1');
        $node['content']['source'] = $this->add_source('curs.pdf', '%PDF-1.4 the course');

        $result = (new resource_builder())->build($node, $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $files = $this->module_files($result);
        $this->assertSame(['curs.pdf'], array_keys($files));
        $this->assertSame('%PDF-1.4 the course', $files['curs.pdf']->get_content());
        $this->assertSame('resource', $this->get_cm($result)->modname);
        $resource = $DB->get_record('resource', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertNotEmpty($resource->displayoptions);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $this->get_cm($result)->completion);
    }

    /**
     * The file stays where it was uploaded: the source is copied, not moved.
     */
    public function test_the_source_file_is_kept(): void {
        $node = $this->node('s1.resource1');
        $node['content']['source'] = $this->add_source('curs.pdf', 'content');

        (new resource_builder())->build($node, $this->make_context());

        $sourceid = (int) substr($node['content']['source'], 3);
        $this->assertNotNull((new \local_aicoursebuilder\ingest\source_manager())->get_source_file($sourceid));
    }

    /**
     * A source that does not exist builds nothing.
     */
    public function test_a_missing_source_is_refused(): void {
        global $DB;
        $node = $this->node('s1.resource1');
        $node['content']['source'] = 'src99999';

        try {
            (new resource_builder())->build($node, $this->make_context());
            $this->fail('Expected the source to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('buildersourcemissing', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('resource'));
    }

    /**
     * A source of another job cannot be published.
     */
    public function test_a_source_of_another_job_is_refused(): void {
        global $DB;
        $other = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->teacher->id,
            'prompt' => 'Other',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $sourceid = $this->add_source('private.pdf', 'secret');
        $DB->set_field('local_aicb_source', 'jobid', $other, ['id' => (int) substr($sourceid, 3)]);
        $node = $this->node('s1.resource1');
        $node['content']['source'] = $sourceid;

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage($sourceid);
        (new resource_builder())->build($node, $this->make_context());
    }

    /**
     * The source id must have the form the blueprint uses.
     */
    public function test_a_source_id_of_another_form_is_refused(): void {
        $node = $this->node('s1.resource1');
        $node['content']['source'] = '../../etc/passwd';

        $this->expectException(\moodle_exception::class);
        (new resource_builder())->build($node, $this->make_context());
    }

    /**
     * Two files with the same name keep both, the second with a number.
     */
    public function test_unique_names(): void {
        $this->assertSame('a.pdf', source_files::unique_name('a.pdf', []));
        $this->assertSame('a (2).pdf', source_files::unique_name('a.pdf', ['a.pdf']));
        $this->assertSame('a (3).pdf', source_files::unique_name('a.pdf', ['a.pdf', 'a (2).pdf']));
        $this->assertSame('notes (2)', source_files::unique_name('notes', ['notes']));
    }
}
