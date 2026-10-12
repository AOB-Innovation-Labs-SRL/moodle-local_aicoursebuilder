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
 * Builds an assignment.
 *
 * assign::add_instance() reads a long list of fields from its form and fails on one that is missing, so every one of
 * them is set: the blueprint decides the grade, the due date and what the students may hand in (an online text, files
 * of some types), and the site's defaults for a new assignment decide the rest (drafts, notifications, attempts,
 * marking). The plugins of the assignment are switched on the way the form switches them: the two submission types by
 * the blueprint, every other submission and feedback plugin by its own site default.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_builder extends module_builder {
    /** @var int Grade of an assignment, in points, when the blueprint does not give one. */
    public const DEFAULT_GRADE = 100;

    /** @var string[] The submission types the blueprint can ask for. */
    public const SUBMISSION_TYPES = ['onlinetext', 'file'];

    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'assign';
    }

    /**
     * Adds the settings of the assignment and of its plugins.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        $content = $node['content'] ?? [];
        $config = get_config('assign');

        $info->alwaysshowdescription = (int) ($config->alwaysshowdescription ?? 1);
        $info->submissiondrafts = (int) ($config->submissiondrafts ?? 0);
        $info->requiresubmissionstatement = (int) ($config->requiresubmissionstatement ?? 0);
        $info->sendnotifications = (int) ($config->sendnotifications ?? 0);
        $info->sendlatenotifications = (int) ($config->sendlatenotifications ?? 0);
        $info->sendstudentnotifications = (int) ($config->sendstudentnotifications ?? 1);
        $info->teamsubmission = 0;
        $info->requireallteammemberssubmit = 0;
        $info->blindmarking = (int) ($config->blindmarking ?? 0);
        $info->hidegrader = (int) ($config->hidegrader ?? 0);
        $info->markingworkflow = (int) ($config->markingworkflow ?? 0);
        $info->markingallocation = (int) ($config->markingallocation ?? 0);
        $info->maxattempts = (int) ($config->maxattempts ?? 1);
        $info->attemptreopenmethod = (string) ($config->attemptreopenmethod ?? 'untilpass');

        // The dates: a due date at the end of its day, and nothing else restricted.
        $info->duedate = self::date_to_timestamp($content['duedate'] ?? null, 23, 59) ?? 0;
        $info->allowsubmissionsfromdate = 0;
        $info->cutoffdate = 0;
        $info->gradingduedate = 0;

        $info->grade = (int) ($content['grade'] ?? self::DEFAULT_GRADE);

        $this->add_submission_plugins($info, $content);
        $this->add_other_plugins($info);
    }

    /**
     * Switches on the submission types of the blueprint and sets their settings.
     *
     * @param \stdClass $info Module info.
     * @param array $content The content of the blueprint node.
     */
    protected function add_submission_plugins(\stdClass $info, array $content): void {
        $types = array_intersect(self::SUBMISSION_TYPES, $content['submission_types'] ?? []);

        $info->assignsubmission_onlinetext_enabled = (int) in_array('onlinetext', $types, true);
        $info->assignsubmission_onlinetext_wordlimit_enabled = 0;
        $info->assignsubmission_onlinetext_wordlimit = 0;

        $info->assignsubmission_file_enabled = (int) in_array('file', $types, true);
        $maxfiles = $content['maxfiles'] ?? get_config('assignsubmission_file', 'maxfiles');
        $info->assignsubmission_file_maxfiles = (int) ($maxfiles ?: 1);
        $info->assignsubmission_file_maxsizebytes = (int) get_config('assignsubmission_file', 'maxbytes');
        $filetypes = array_values(array_filter(array_map('strval', $content['filetypes'] ?? [])));
        $info->assignsubmission_file_filetypes = $filetypes
            ? implode(',', $filetypes)
            : (string) get_config('assignsubmission_file', 'filetypes');
    }

    /**
     * Switches on the other plugins of the assignment as the site has them by default.
     *
     * @param \stdClass $info Module info.
     */
    protected function add_other_plugins(\stdClass $info): void {
        $manager = \core_plugin_manager::instance();
        foreach (['assignsubmission' => self::SUBMISSION_TYPES, 'assignfeedback' => []] as $subtype => $decided) {
            foreach ($manager->get_plugins_of_type($subtype) as $name => $plugin) {
                if (in_array($name, $decided, true)) {
                    continue;
                }
                $info->{"{$subtype}_{$name}_enabled"} = (int) get_config("{$subtype}_{$name}", 'default');
            }
        }
    }
}
