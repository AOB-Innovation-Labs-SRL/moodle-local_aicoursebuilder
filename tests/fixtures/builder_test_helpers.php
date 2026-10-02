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

/**
 * What the tests of the builders share: a teacher with a course, a job, the golden blueprint and source files.
 *
 * Use it in an advanced_testcase and load it with require_once.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait builder_test_helpers {
    /** @var \stdClass The teacher the build runs as. */
    protected \stdClass $teacher;

    /** @var \stdClass The course the activities are built in. */
    protected \stdClass $course;

    /** @var int The job of the build. */
    protected int $jobid;

    /**
     * Creates a course with three sections and a teacher, and logs the teacher in.
     *
     * @param string $mode Job mode: existingcourse, where the activities go to section 1, or newcourse.
     */
    protected function set_up_job(string $mode = 'existingcourse'): void {
        global $DB;

        set_config('enablecompletion', 1);
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3, 'enablecompletion' => 1]);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);
        $this->jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->teacher->id,
            'mode' => $mode,
            'categoryid' => $mode === 'newcourse' ? $this->course->category : null,
            'courseid' => $mode === 'existingcourse' ? $this->course->id : null,
            'sectionnum' => $mode === 'existingcourse' ? 1 : null,
            'status' => 'building',
            'prompt' => 'Test',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the build context of the job, with the course when it is known.
     *
     * @param bool $withcourse Whether the course exists already.
     * @return build_context
     */
    protected function make_context(bool $withcourse = true): build_context {
        global $DB;
        return build_context::from_job(
            $DB->get_record('local_aicb_job', ['id' => $this->jobid], '*', MUST_EXIST),
            $withcourse ? $this->course : null
        );
    }

    /**
     * Returns the golden blueprint.
     *
     * @return array
     */
    protected function golden(): array {
        $path = __DIR__ . '/blueprint_golden.json';
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Returns a node of the golden blueprint by its id: a section, a subsection or an activity.
     *
     * @param string $id Node id, such as s1, s1-1 or s1.page1.
     * @return array
     */
    protected function node(string $id): array {
        foreach ($this->golden()['sections'] as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
            foreach ($section['activities'] as $activity) {
                if ($activity['id'] === $id) {
                    return $activity;
                }
            }
            foreach ($section['subsections'] ?? [] as $subsection) {
                if ($subsection['id'] === $id) {
                    return $subsection;
                }
                foreach ($subsection['activities'] as $activity) {
                    if ($activity['id'] === $id) {
                        return $activity;
                    }
                }
            }
        }
        throw new \coding_exception("The golden blueprint has no node {$id}");
    }

    /**
     * Stores a source file of the job, as the upload of a job does.
     *
     * @param string $filename File name.
     * @param string $content File content.
     * @return string Source id as the blueprint writes it, such as src3.
     */
    protected function add_source(string $filename, string $content): string {
        global $DB;

        $now = time();
        $id = $DB->insert_record('local_aicb_source', (object) [
            'jobid' => $this->jobid,
            'filename' => $filename,
            'mimetype' => 'application/octet-stream',
            'filesize' => strlen($content),
            'contenthash' => sha1($content),
            'status' => 'digested',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'source',
            'itemid' => $id,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return 'src' . $id;
    }

    /**
     * Returns the course module a result created, read from the course again.
     *
     * @param build_result $result The result of a builder.
     * @return \cm_info
     */
    protected function get_cm(build_result $result): \cm_info {
        rebuild_course_cache($this->course->id, true);
        return get_fast_modinfo($this->course->id)->get_cm($result->cmid);
    }

    /**
     * Records a result the way the build does, so that later builders find the node.
     *
     * @param build_context $context The build context.
     * @param build_result $result The result.
     * @return build_result The same result.
     */
    protected function record(build_context $context, build_result $result): build_result {
        $context->record($result);
        return $result;
    }
}
