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
 * What the builders of activities have in common: the module is made the way Moodle's own form would make it.
 *
 * The recipe is the same for every module (spec 3.7). The module info starts from prepare_new_moduleinfo_data(),
 * which gives what Moodle gives a new module of the course (visibility, completion defaults, the intro editor),
 * the builder adds every field the module's add_instance() reads, with the site defaults of the module for the
 * settings the blueprint does not carry, and create_module() does the rest: the course module, its place in the
 * section, the events, the completion date. Going through the same functions as the form means a module built
 * here behaves like one the teacher added by hand.
 *
 * A builder is idempotent: a node already in the build map is skipped without a call to Moodle. The text that
 * comes from the model is cleaned with clean_text() before it is stored, because it is the one input that no
 * teacher typed.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class module_builder implements builder_interface {
    /** @var int Longest name of a module, from the blueprint schema and Moodle's own limit. */
    public const MAX_NAME = 255;

    /** @var string[] Warnings of the module being built, handed to the result. */
    protected array $warnings = [];

    /**
     * Returns the name of the Moodle module this builder creates, such as page.
     *
     * @return string
     */
    abstract protected function get_modulename(): string;

    /**
     * Adds to the module info the fields that are specific to the module.
     *
     * @param \stdClass $info Module info from prepare_new_moduleinfo_data(), with the name, intro and completion set.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    abstract protected function add_fields(\stdClass $info, array $node, build_context $context): void;

    /**
     * Builds one activity.
     *
     * @param array|\stdClass $node The blueprint node.
     * @param build_context $context The build context.
     * @return build_result
     */
    #[\Override]
    public function build(array|\stdClass $node, build_context $context): build_result {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $node = json_decode(json_encode($node), true);
        $nodeid = (string) ($node['id'] ?? '');
        if ($context->is_built($nodeid)) {
            return new build_result($nodeid, build_result::STATUS_SKIPPED);
        }
        $this->warnings = [];

        $course = $context->get_course();
        $sectionnum = $this->find_sectionnum($nodeid, $context);
        [, , , , $info] = prepare_new_moduleinfo_data($course, $this->get_modulename(), $sectionnum);

        $info->name = self::clean_name((string) ($node['name'] ?? ''));
        // Only a module with an intro has the editor; prepare_new_moduleinfo_data() adds it for those.
        if (isset($info->introeditor)) {
            $info->introeditor['text'] = self::clean_html((string) ($node['intro'] ?? ''));
            $info->introeditor['format'] = FORMAT_HTML;
        }
        // The ID number of the module is a field of the form, which the modules and the gradebook read.
        $info->cmidnumber = '';
        $this->add_completion($info, $node['completion'] ?? null);
        $this->add_fields($info, $node, $context);

        $created = create_module($info);
        try {
            $this->after_created($created, $node, $context);
        } catch (\Throwable $e) {
            // A module without its content would be skipped by the next run, which finds it in the course, so it goes.
            \core_courseformat\formatactions::cm((int) $created->course)->delete((int) $created->coursemodule);
            throw $e;
        }

        return new build_result(
            $nodeid,
            build_result::STATUS_CREATED,
            cmid: (int) $created->coursemodule,
            instanceid: (int) $created->instance,
            sectionnum: $this->result_sectionnum($created, $sectionnum, $context),
            warnings: $this->warnings,
        );
    }

    /**
     * Returns the section number recorded for the node: the section it was built in.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param int $sectionnum The section the module was built in.
     * @param build_context $context The build context.
     * @return int
     */
    protected function result_sectionnum(\stdClass $created, int $sectionnum, build_context $context): int {
        return $sectionnum;
    }

    /**
     * Does what has to happen once the module exists and before the build is recorded.
     *
     * @param \stdClass $created The module info create_module() returned, with coursemodule and instance.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
    }

    /**
     * Finds the section a node is built in.
     *
     * The id of an activity starts with the id of its section: s1.page1 is in s1 and s2-1.label1 is in the subsection
     * s2-1. In an existing course there are no section nodes, and the activities go into the section the teacher
     * chose for the job.
     *
     * @param string $nodeid Blueprint node id of the activity.
     * @param build_context $context The build context.
     * @return int Section number.
     */
    protected function find_sectionnum(string $nodeid, build_context $context): int {
        $sectionid = strstr($nodeid, '.', true);
        if ($sectionid !== false && $sectionid !== '') {
            $sectionnum = $context->locate_section($sectionid);
            if ($sectionnum !== null) {
                return $sectionnum;
            }
        }
        $job = $context->get_job();
        return $job && $job->sectionnum !== null ? (int) $job->sectionnum : 0;
    }

    /**
     * Sets the completion tracking of the module from the completion object of the node.
     *
     * Only the fields of Moodle's completion API are set, never an arbitrary name from the blueprint. A node with no
     * completion keeps what prepare_new_moduleinfo_data() gave it: the defaults of the course.
     *
     * @param \stdClass $info Module info.
     * @param array|null $completion The completion object of the node.
     */
    protected function add_completion(\stdClass $info, ?array $completion): void {
        if (!$completion) {
            return;
        }
        $info->completion = match ($completion['mode'] ?? 'none') {
            'auto' => COMPLETION_TRACKING_AUTOMATIC,
            'manual' => COMPLETION_TRACKING_MANUAL,
            default => COMPLETION_TRACKING_NONE,
        };
        if ($info->completion === COMPLETION_TRACKING_AUTOMATIC) {
            $fields = [
                'view' => 'completionview',
                'usegrade' => 'completionusegrade',
                'passgrade' => 'completionpassgrade',
            ];
            foreach ($fields as $key => $field) {
                if (array_key_exists($key, $completion)) {
                    $info->{$field} = (int) (bool) $completion[$key];
                }
            }
            foreach ($completion['rules'] ?? [] as $field => $value) {
                // The rules of a module are fields of the module's own form, all named completion...
                if (is_string($field) && preg_match('/^completion[a-z]+$/', $field)) {
                    $info->{$field} = is_bool($value) ? (int) $value : $value;
                }
            }
        }
    }

    /**
     * Returns the site defaults of a module, for the settings the blueprint does not carry.
     *
     * Only the settings the module has are returned, so a name that a Moodle version does not know is left out
     * instead of being set to nothing.
     *
     * @param string[] $names Names of the settings.
     * @return array Setting => value.
     */
    protected function get_defaults(array $names): array {
        $config = get_config($this->get_modulename());
        $defaults = [];
        foreach ($names as $name) {
            if (isset($config->{$name})) {
                $defaults[$name] = $config->{$name};
            }
        }
        return $defaults;
    }

    /**
     * Returns a site default that Moodle keeps in the core settings under the name of the module, such as forum_maxbytes.
     *
     * @param string $name Name of the setting.
     * @param mixed $fallback What to use when the site has no such setting.
     * @return mixed
     */
    protected function get_core_default(string $name, mixed $fallback): mixed {
        $value = get_config('core', $name);
        return $value === false ? $fallback : $value;
    }

    /**
     * Turns a date of the blueprint into a timestamp.
     *
     * @param string|null $date A date as YYYY-MM-DD.
     * @param int $hour Hour of the day, 0 for the start of the day and 23 for the end of it.
     * @param int $minute Minute of the hour.
     * @return int|null The timestamp, null when there is no valid date.
     */
    public static function date_to_timestamp(?string $date, int $hour = 0, int $minute = 0): ?int {
        $valid = $date !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
        return $valid ? make_timestamp((int) $matches[1], (int) $matches[2], (int) $matches[3], $hour, $minute) : null;
    }

    /**
     * Cleans the name of a module.
     *
     * @param string $name The name from the blueprint.
     * @return string Plain text of at most MAX_NAME characters.
     */
    public static function clean_name(string $name): string {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        return \core_text::substr($name, 0, self::MAX_NAME);
    }

    /**
     * Cleans the HTML of a module, a section or a course.
     *
     * @param string $html The HTML from the blueprint.
     * @return string HTML Moodle accepts from a user without the capability to write any HTML.
     */
    public static function clean_html(string $html): string {
        return clean_text($html, FORMAT_HTML);
    }
}
