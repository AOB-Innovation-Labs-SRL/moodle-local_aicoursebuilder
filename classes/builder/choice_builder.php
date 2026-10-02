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
 * Builds a choice: a question with the options the students pick from.
 *
 * The module takes its options from the option field of its form, a list of texts with a list of limits next to
 * it, and saves them in add_instance(). The choice is open without a closing date, shows its results to a student
 * after they answered, without names, and can be answered again when the blueprint allows it.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'choice';
    }

    /**
     * Adds the options and the settings of the choice.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choice/lib.php');

        $options = array_values(array_filter(array_map(
            fn($option) => self::clean_name((string) $option),
            $node['content']['options'] ?? []
        ), fn(string $option) => $option !== ''));
        $info->option = $options;
        $info->limit = array_fill(0, count($options), 0);

        $info->allowupdate = (int) ($node['content']['allowupdate'] ?? true);
        $info->allowmultiple = 0;
        $info->limitanswers = 0;
        $info->publish = CHOICE_PUBLISH_ANONYMOUS;
        $info->showresults = CHOICE_SHOWRESULTS_AFTER_ANSWER;
        $info->display = CHOICE_DISPLAY_VERTICAL;
        $info->showunanswered = 0;
        $info->includeinactive = 1;
        $info->showpreview = 0;
        $info->showavailable = 0;
        $info->timeopen = 0;
        $info->timeclose = 0;
    }
}
