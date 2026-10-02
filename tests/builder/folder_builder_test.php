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
 * Tests of the folder builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\folder_builder
 */
final class folder_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * Returns the file names of a folder.
     *
     * @param build_result $result The result of the builder.
     * @return string[] Sorted file names.
     */
    private function folder_files(build_result $result): array {
        $context = \context_module::instance($result->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_folder', 'content', 0, 'filename', false);
        return array_values(array_map(fn(\stored_file $file) => $file->get_filename(), $files));
    }

    /**
     * Every source of the folder is published in it.
     */
    public function test_publishes_every_source(): void {
        global $DB;
        $node = $this->node('s1-1.folder1');
        $node['content']['sources'] = [$this->add_source('anexa1.pdf', 'one'), $this->add_source('anexa2.docx', 'two')];

        $result = (new folder_builder())->build($node, $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame(['anexa1.pdf', 'anexa2.docx'], $this->folder_files($result));
        $this->assertSame('folder', $this->get_cm($result)->modname);
        $this->assertSame('Anexe', $this->get_cm($result)->name);
        $folder = $DB->get_record('folder', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertNotNull($folder->showexpanded);
    }

    /**
     * Two sources with the same file name are both kept.
     */
    public function test_files_with_the_same_name_are_both_kept(): void {
        $node = $this->node('s1-1.folder1');
        $node['content']['sources'] = [$this->add_source('anexa.pdf', 'one'), $this->add_source('anexa.pdf', 'two')];

        $result = (new folder_builder())->build($node, $this->make_context());

        $this->assertSame(['anexa (2).pdf', 'anexa.pdf'], $this->folder_files($result));
    }

    /**
     * The folder shows its files expanded or not as the site settings say.
     */
    public function test_display_options_come_from_the_site_defaults(): void {
        global $DB;
        set_config('showexpanded', 0, 'folder');
        $node = $this->node('s1-1.folder1');
        $node['content']['sources'] = [$this->add_source('anexa.pdf', 'one')];

        $result = (new folder_builder())->build($node, $this->make_context());

        $this->assertEquals(0, $DB->get_field('folder', 'showexpanded', ['id' => $result->instanceid]));
    }

    /**
     * A folder with a source that is not found is not built half.
     */
    public function test_a_missing_source_builds_no_folder(): void {
        global $DB;
        $node = $this->node('s1-1.folder1');
        $node['content']['sources'] = [$this->add_source('anexa.pdf', 'one'), 'src99999'];

        try {
            (new folder_builder())->build($node, $this->make_context());
            $this->fail('Expected the missing source to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('buildersourcemissing', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('folder'));
    }
}
