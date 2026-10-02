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
 * Builds the course itself: the new course of a job, in the category the teacher chose.
 *
 * It goes through create_course(), with the settings the form of a new course would send. The site defaults for a new
 * course (format, news items, grades, groups, size limit) are read from the moodlecourse settings, because
 * create_course() takes them from the form and not from the site. The blueprint decides the name, the short name,
 * the summary, the format, the start date and whether completion is tracked; the language is the one of the job when
 * the site has it. A short name that is already taken gets a number, since a teacher who generates the same course
 * twice must not be refused for it. The image of the course is the first relevant picture of the sources.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_builder implements builder_interface {
    /** @var int Longest full name of a course. */
    public const MAX_FULLNAME = 254;

    /** @var int Longest short name of a course. */
    public const MAX_SHORTNAME = 100;

    /**
     * Builds the course.
     *
     * @param array|\stdClass $node The course object of the blueprint.
     * @param build_context $context The build context.
     * @return build_result The id of the course is the instance id of the result.
     * @throws \moodle_exception When the job has no category to put the course in.
     */
    #[\Override]
    public function build(array|\stdClass $node, build_context $context): build_result {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $node = json_decode(json_encode($node), true);
        if ($context->is_built(build_plan::COURSE_NODEID)) {
            return new build_result(build_plan::COURSE_NODEID, build_result::STATUS_SKIPPED);
        }
        $job = $context->get_job();
        if (!$job || empty($job->categoryid)) {
            throw new \moodle_exception('buildernocategory', 'local_aicoursebuilder');
        }

        $course = create_course($this->make_data($node, $job), [
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => $CFG->maxbytes,
            'trusttext' => false,
            'noclean' => true,
        ]);

        return new build_result(
            build_plan::COURSE_NODEID,
            build_result::STATUS_CREATED,
            instanceid: (int) $course->id,
            warnings: $this->set_image($course, (int) $job->id),
        );
    }

    /**
     * Makes the data create_course() takes.
     *
     * @param array $node The course object of the blueprint.
     * @param \stdClass $job The job.
     * @return \stdClass
     */
    protected function make_data(array $node, \stdClass $job): \stdClass {
        global $CFG;

        $config = get_config('moodlecourse');
        $data = (object) [
            'category' => (int) $job->categoryid,
            'fullname' => \core_text::substr(module_builder::clean_name((string) ($node['fullname'] ?? '')), 0, self::MAX_FULLNAME),
            'shortname' => self::unique_shortname((string) ($node['shortname'] ?? '')),
            'summary_editor' => [
                'text' => module_builder::clean_html((string) ($node['summary'] ?? '')),
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ],
            'format' => in_array($node['format'] ?? '', ['topics', 'weeks'], true)
                ? $node['format']
                : ($config->format ?? 'topics'),
            'visible' => (int) ($config->visible ?? 1),
            'startdate' => self::to_timestamp($node['startdate'] ?? null),
            'newsitems' => (int) ($config->newsitems ?? 5),
            'showgrades' => (int) ($config->showgrades ?? 1),
            'showreports' => (int) ($config->showreports ?? 0),
            'maxbytes' => (int) ($config->maxbytes ?? 0),
            'groupmode' => (int) ($config->groupmode ?? 0),
            'groupmodeforce' => (int) ($config->groupmodeforce ?? 0),
            'enablecompletion' => empty($CFG->enablecompletion) ? 0 : self::completion_enabled($node, $config),
            'lang' => get_string_manager()->translation_exists((string) $job->language) ? (string) $job->language : '',
        ];
        if ($data->shortname === '') {
            throw new \moodle_exception('buildernoshortname', 'local_aicoursebuilder');
        }
        return $data;
    }

    /**
     * Tells whether the new course tracks completion: what the blueprint says, else the site default.
     *
     * @param array $node The course object of the blueprint.
     * @param \stdClass $config The moodlecourse settings.
     * @return int 1 or 0.
     */
    protected static function completion_enabled(array $node, \stdClass $config): int {
        return (int) ($node['enablecompletion'] ?? $config->enablecompletion ?? 1);
    }

    /**
     * Returns a short name that no course has, the wanted one with a number when it is taken.
     *
     * @param string $wanted The short name of the blueprint.
     * @return string
     */
    public static function unique_shortname(string $wanted): string {
        global $DB;

        $base = \core_text::substr(module_builder::clean_name($wanted), 0, self::MAX_SHORTNAME);
        if ($base === '') {
            return '';
        }
        $candidate = $base;
        for ($number = 2; $DB->record_exists('course', ['shortname' => $candidate]); $number++) {
            $suffix = '-' . $number;
            $candidate = \core_text::substr($base, 0, self::MAX_SHORTNAME - strlen($suffix)) . $suffix;
        }
        return $candidate;
    }

    /**
     * Turns the date of the blueprint into a timestamp.
     *
     * @param string|null $date A date as YYYY-MM-DD.
     * @return int The start of that day, or now when there is no valid date.
     */
    protected static function to_timestamp(?string $date): int {
        $valid = $date !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
        if ($valid) {
            return make_timestamp((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }
        return time();
    }

    /**
     * Sets the first relevant picture of the sources as the image of the course.
     *
     * A course without an image is a finished course, so a failure here is a warning and not an error.
     *
     * @param \stdClass $course The new course.
     * @param int $jobid Job id.
     * @return string[] Warnings.
     */
    protected function set_image(\stdClass $course, int $jobid): array {
        try {
            $image = course_image::find($jobid);
            if ($image !== null) {
                course_image::store($course, $image);
            }
        } catch (\Throwable $e) {
            return [get_string('buildwarncourseimage', 'local_aicoursebuilder', $e->getMessage())];
        }
        return [];
    }
}
