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

use local_aicoursebuilder\ingest\source_manager;

/**
 * Hands the source files of a job to the modules that publish them: a file module and a folder.
 *
 * The blueprint refers to a source by its id (src12), the number being the row of local_aicb_source. A module that
 * publishes files takes them from a draft area, as the form of the module does, so the files are copied into a new
 * draft area of the user the build runs as, and the draft item id is what the module is given.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_files {
    /** @var int|null Job whose sources may be used, null to accept any (a build with no job, in a test). */
    protected ?int $jobid;

    /**
     * Creates the helper.
     *
     * @param int|null $jobid Job the build belongs to.
     */
    public function __construct(?int $jobid) {
        $this->jobid = $jobid;
    }

    /**
     * Returns the stored file of a source.
     *
     * @param string $sourceid Source id as the blueprint writes it, such as src12.
     * @return \stored_file
     * @throws \moodle_exception When the source is not one of the job, or its file is gone.
     */
    public function get_file(string $sourceid): \stored_file {
        global $DB;

        if (!preg_match('/^src([0-9]+)$/', $sourceid, $matches)) {
            throw new \moodle_exception('buildersourcemissing', 'local_aicoursebuilder', '', $sourceid);
        }
        $row = $DB->get_record('local_aicb_source', ['id' => (int) $matches[1]]);
        if (!$row || ($this->jobid !== null && (int) $row->jobid !== $this->jobid)) {
            throw new \moodle_exception('buildersourcemissing', 'local_aicoursebuilder', '', $sourceid);
        }
        $file = (new source_manager())->get_source_file((int) $row->id);
        if (!$file) {
            throw new \moodle_exception('buildersourcemissing', 'local_aicoursebuilder', '', $sourceid);
        }
        return $file;
    }

    /**
     * Copies source files into a new draft area of the current user.
     *
     * Two sources with the same file name would overwrite each other in the draft area, so the second one gets a
     * number before its extension.
     *
     * @param string[] $sourceids Source ids, in the order the files are listed.
     * @return int The draft item id.
     * @throws \moodle_exception When a source cannot be found.
     */
    public function to_draft_area(array $sourceids): int {
        global $USER;

        $files = array_map(fn(string $sourceid): \stored_file => $this->get_file($sourceid), $sourceids);

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $taken = [];
        foreach ($files as $file) {
            $filename = self::unique_name($file->get_filename(), $taken);
            $taken[] = $filename;
            $fs->create_file_from_storedfile([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $file);
        }
        return $draftitemid;
    }

    /**
     * Returns a file name that is not in a list, adding a number before the extension when it is.
     *
     * @param string $filename The wanted name.
     * @param string[] $taken Names already used.
     * @return string
     */
    public static function unique_name(string $filename, array $taken): string {
        if (!in_array($filename, $taken, true)) {
            return $filename;
        }
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        for ($number = 2;; $number++) {
            $candidate = $name . ' (' . $number . ')' . ($extension === '' ? '' : '.' . $extension);
            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }
}
