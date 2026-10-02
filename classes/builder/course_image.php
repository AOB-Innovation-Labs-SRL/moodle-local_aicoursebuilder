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
 * Finds the image of a course in the teacher's own documents, and sets it as the course image.
 *
 * The pilot does not generate images. The course image is the first relevant picture inside the sources: the
 * pictures of a Word, PowerPoint or Excel file are in its media folder. A picture is relevant when it is big enough
 * to be a picture and not an icon, a bullet or a logo strip: at least MIN_BYTES and MIN_SIDE pixels on each side,
 * and not much wider than tall or the other way round. The sources are looked at in the order they were
 * uploaded, and the pictures of a file in the order of their names.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_image {
    /** @var int Smallest file that can be a picture of the course, in bytes. */
    public const MIN_BYTES = 10240;

    /** @var int Shortest side of a picture of the course, in pixels. */
    public const MIN_SIDE = 200;

    /** @var int Most that the long side can be of the short one. */
    public const MAX_RATIO = 4;

    /** @var string[] Extensions of the pictures Moodle shows as a course image. */
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** @var string[] Folder of the pictures inside each kind of Office file. */
    public const MEDIA_FOLDERS = ['docx' => 'word/media/', 'pptx' => 'ppt/media/', 'xlsx' => 'xl/media/'];

    /**
     * Finds the course image among the sources of a job.
     *
     * @param int $jobid Job id.
     * @return array|null ['filename' => string, 'content' => string], null when no source has a relevant picture.
     */
    public static function find(int $jobid): ?array {
        global $DB;

        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $manager = new source_manager();
        foreach ($DB->get_records('local_aicb_source', ['jobid' => $jobid], 'id') as $source) {
            $folder = self::MEDIA_FOLDERS[strtolower(pathinfo($source->filename, PATHINFO_EXTENSION))] ?? null;
            $file = $folder ? $manager->get_source_file((int) $source->id) : null;
            if (!$file) {
                continue;
            }
            $image = self::find_in_archive($file, $folder);
            if ($image !== null) {
                return $image;
            }
        }
        return null;
    }

    /**
     * Finds the first relevant picture in an Office file.
     *
     * @param \stored_file $file The Office file.
     * @param string $folder The media folder inside it.
     * @return array|null The picture, as find() returns it.
     */
    protected static function find_in_archive(\stored_file $file, string $folder): ?array {
        $path = $file->copy_content_to_temp();
        if ($path === false) {
            return null;
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (str_starts_with($name, $folder) && in_array($extension, self::EXTENSIONS, true)) {
                    $names[] = $name;
                }
            }
            natsort($names);
            foreach ($names as $name) {
                $content = $zip->getFromName($name);
                if ($content !== false && self::is_relevant($content)) {
                    return ['filename' => basename($name), 'content' => $content];
                }
            }
            $zip->close();
            return null;
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Tells whether a picture is big enough, and shaped enough like a picture, to be the image of a course.
     *
     * @param string $content The bytes of the file.
     * @return bool
     */
    public static function is_relevant(string $content): bool {
        if (strlen($content) < self::MIN_BYTES) {
            return false;
        }
        $size = @getimagesizefromstring($content);
        if (!$size) {
            return false;
        }
        [$width, $height] = $size;
        if (min($width, $height) < self::MIN_SIDE) {
            return false;
        }
        return max($width, $height) <= self::MAX_RATIO * min($width, $height);
    }

    /**
     * Sets a picture as the image of a course.
     *
     * Nothing is done when the site allows no course image.
     *
     * @param \stdClass $course The course.
     * @param array $image The picture, as find() returns it.
     * @return bool Whether the image was set.
     */
    public static function store(\stdClass $course, array $image): bool {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        if (!course_overviewfiles_options($course->id)) {
            return false;
        }
        $fs = get_file_storage();
        $coursecontext = \context_course::instance($course->id);
        $fs->delete_area_files($coursecontext->id, 'course', 'overviewfiles');
        $fs->create_file_from_string([
            'contextid' => $coursecontext->id,
            'component' => 'course',
            'filearea' => 'overviewfiles',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $image['filename'],
        ], $image['content']);
        return true;
    }
}
