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

/**
 * Saves the source files of a job and finds them again (spec 3.5).
 *
 * Files live in two file areas of the plugin, both with the source id as item id:
 * "source" holds the uploaded file, "extracted" holds the Markdown text made from it.
 * Both areas are in the context given to save_from_draft().
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_manager {
    /** @var string Component of the file areas. */
    public const COMPONENT = 'local_aicoursebuilder';

    /** @var string File area of the uploaded files. */
    public const AREA_SOURCE = 'source';

    /** @var string File area of the extracted text. */
    public const AREA_EXTRACTED = 'extracted';

    /** @var string Source status: saved, not extracted yet. */
    public const STATUS_PENDING = 'pending';

    /** @var string Source status: the text was extracted. */
    public const STATUS_EXTRACTED = 'extracted';

    /** @var string Source status: the extraction failed. */
    public const STATUS_FAILED = 'failed';

    /** @var int Default maximum size of a file, in megabytes. */
    public const DEFAULT_MAXFILESIZE = 50;

    /** @var int Default maximum number of files of a job. */
    public const DEFAULT_MAXFILES = 10;

    /**
     * Returns the source types the settings allow.
     *
     * @return string[] Keys of extractor_factory::MIMETYPES; all of them until the administrator saves the setting.
     */
    public function get_allowed_types(): array {
        $setting = get_config(self::COMPONENT, 'allowedtypes');
        if ($setting === false) {
            return array_keys(extractor_factory::MIMETYPES);
        }
        return array_values(array_intersect(array_keys(extractor_factory::MIMETYPES), explode(',', $setting)));
    }

    /**
     * Returns the maximum size of a file.
     *
     * @return int Bytes.
     */
    public function get_max_file_bytes(): int {
        return $this->get_positive_setting('maxfilesize', self::DEFAULT_MAXFILESIZE) * 1024 * 1024;
    }

    /**
     * Returns the maximum number of files of a job.
     *
     * @return int
     */
    public function get_max_files(): int {
        return $this->get_positive_setting('maxfiles', self::DEFAULT_MAXFILES);
    }

    /**
     * Saves the files of a draft area as the sources of a job.
     *
     * Either every file is saved or none. The files must pass the type, size and number limits of
     * the settings and the antivirus. Every file gets a local_aicb_source row (status pending) and
     * is stored in the "source" file area with the row id as item id. The draft area of the current
     * user is left as it is.
     *
     * @param int $jobid Job id.
     * @param int $draftitemid Draft area of the current user, as sent by a filemanager.
     * @param \context $context Context of the stored files.
     * @return \stdClass[] The new local_aicb_source rows, indexed by id.
     * @throws ingest_exception When a limit is exceeded, the type is not allowed or the antivirus refuses a file.
     */
    public function save_from_draft(int $jobid, int $draftitemid, \context $context): array {
        global $DB, $USER;

        $DB->get_record('local_aicb_job', ['id' => $jobid], 'id', MUST_EXIST);

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
        if (!$files) {
            throw new ingest_exception(ingest_exception::NO_FILES);
        }

        $maxfiles = $this->get_max_files();
        if ($DB->count_records('local_aicb_source', ['jobid' => $jobid]) + count($files) > $maxfiles) {
            throw new ingest_exception(ingest_exception::TOO_MANY, $maxfiles);
        }

        $allowed = $this->get_allowed_types();
        $maxbytes = $this->get_max_file_bytes();
        foreach ($files as $file) {
            $type = extractor_factory::type_for_filename($file->get_filename());
            if ($type === null || !in_array($type, $allowed, true)) {
                throw new ingest_exception(ingest_exception::TYPE_NOT_ALLOWED, $file->get_filename());
            }
            if ($file->get_filesize() > $maxbytes) {
                throw new ingest_exception(ingest_exception::TOO_LARGE, [
                    'filename' => $file->get_filename(),
                    'maxsize' => display_size($maxbytes),
                ]);
            }
        }
        foreach ($files as $file) {
            $this->scan($file);
        }

        $sources = [];
        $created = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($files as $file) {
                $type = extractor_factory::type_for_filename($file->get_filename());
                $now = time();
                $source = (object) [
                    'jobid' => $jobid,
                    'filename' => $file->get_filename(),
                    'mimetype' => extractor_factory::MIMETYPES[$type],
                    'filesize' => $file->get_filesize(),
                    'contenthash' => $file->get_contenthash(),
                    'status' => self::STATUS_PENDING,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $source->id = $DB->insert_record('local_aicb_source', $source);
                $created[] = $fs->create_file_from_storedfile([
                    'contextid' => $context->id,
                    'component' => self::COMPONENT,
                    'filearea' => self::AREA_SOURCE,
                    'itemid' => $source->id,
                    'filepath' => '/',
                    'filename' => $file->get_filename(),
                ], $file);
                $sources[$source->id] = $source;
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            foreach ($created as $stored) {
                $stored->delete();
            }
            $transaction->rollback($e);
        }
        return $sources;
    }

    /**
     * Returns the uploaded file of a source.
     *
     * @param int $sourceid Source id.
     * @return \stored_file|null Null when the file does not exist.
     */
    public function get_source_file(int $sourceid): ?\stored_file {
        return $this->find_file(self::AREA_SOURCE, $sourceid);
    }

    /**
     * Returns the extracted text file of a source.
     *
     * @param int $sourceid Source id.
     * @return \stored_file|null Null when the text was not extracted.
     */
    public function get_extracted_file(int $sourceid): ?\stored_file {
        return $this->find_file(self::AREA_EXTRACTED, $sourceid);
    }

    /**
     * Stores the extracted text of a source, replacing an earlier one.
     *
     * The file is stored in the context of the source file, with the same item id.
     *
     * @param \stdClass $source The local_aicb_source row.
     * @param \stored_file $sourcefile The uploaded file of the source.
     * @param string $markdown The extracted text.
     * @return \stored_file The .md file.
     */
    public function save_extracted(\stdClass $source, \stored_file $sourcefile, string $markdown): \stored_file {
        $fs = get_file_storage();
        $fs->delete_area_files($sourcefile->get_contextid(), self::COMPONENT, self::AREA_EXTRACTED, $source->id);
        $name = pathinfo($sourcefile->get_filename(), PATHINFO_FILENAME);
        return $fs->create_file_from_string([
            'contextid' => $sourcefile->get_contextid(),
            'component' => self::COMPONENT,
            'filearea' => self::AREA_EXTRACTED,
            'itemid' => $source->id,
            'filepath' => '/',
            'filename' => $name . '.md',
        ], $markdown);
    }

    /**
     * Scans a file with the antivirus plugins enabled in the site.
     *
     * The antivirus API needs a path in the local file system, so it scans a temporary copy.
     *
     * @param \stored_file $file The file.
     * @throws ingest_exception When the antivirus refuses the file.
     */
    private function scan(\stored_file $file): void {
        $path = $file->copy_content_to_temp();
        if ($path === false) {
            throw new ingest_exception(ingest_exception::FILE_MISSING);
        }
        try {
            \core\antivirus\manager::scan_file($path, $file->get_filename(), true);
        } catch (\core\antivirus\scanner_exception) {
            throw new ingest_exception(ingest_exception::INFECTED, $file->get_filename());
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Finds the file of a source in a file area, whatever its context.
     *
     * @param string $filearea File area.
     * @param int $sourceid Source id, the item id.
     * @return \stored_file|null
     */
    private function find_file(string $filearea, int $sourceid): ?\stored_file {
        global $DB;

        $record = $DB->get_record_select(
            'files',
            "component = :component AND filearea = :filearea AND itemid = :itemid AND filename <> '.'",
            ['component' => self::COMPONENT, 'filearea' => $filearea, 'itemid' => $sourceid],
            'id',
            IGNORE_MULTIPLE
        );
        if (!$record) {
            return null;
        }
        return get_file_storage()->get_file_by_id($record->id) ?: null;
    }

    /**
     * Reads a positive whole number setting.
     *
     * @param string $name Setting name.
     * @param int $default Value used when the setting is missing or not positive.
     * @return int
     */
    private function get_positive_setting(string $name, int $default): int {
        $value = (int) get_config(self::COMPONENT, $name);
        return $value > 0 ? $value : $default;
    }
}
