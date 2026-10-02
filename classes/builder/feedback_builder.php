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
 * Builds a feedback: the module, then its questions.
 *
 * The feedback is anonymous, as a course evaluation is. Its questions are rows of feedback_item, which is what the
 * item forms of the module save, each with the presentation its type reads: a list of choices for a multiple choice
 * question, a range for a number, a size for a text field or a text area. An item of the type info shows its title
 * and, next to it, a fact the module fills in (the course, here). The block of options a feedback has for
 * what happens after it is sent is left empty, which is the module's own default.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_builder extends module_builder {
    /** @var string[] Item types the blueprint can ask for. */
    public const ITEM_TYPES = ['multichoice', 'numeric', 'textarea', 'textfield', 'info'];

    /** @var string Presentation of a text field: size and most characters. */
    public const TEXTFIELD_PRESENTATION = '30|255';

    /** @var string Presentation of a text area: width and height. */
    public const TEXTAREA_PRESENTATION = '40|4';

    /** @var int Longest label of an item. */
    public const MAX_LABEL = 255;

    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'feedback';
    }

    /**
     * Adds the settings of the feedback.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/feedback/lib.php');

        $info->anonymous = FEEDBACK_ANONYMOUS_YES;
        $info->email_notification = 1;
        $info->multiple_submit = 1;
        $info->autonumbering = 1;
        $info->publish_stats = 0;
        $info->site_after_submit = '';
        $info->page_after_submit = '';
        $info->page_after_submitformat = FORMAT_HTML;
        // The editor field of the form is read by add_instance() even when it is empty.
        $info->page_after_submit_editor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $info->timeopen = 0;
        $info->timeclose = 0;
    }

    /**
     * Saves the questions of the feedback.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        // The separators of a multiple choice are constants of the item type, which is loaded with it.
        require_once($CFG->dirroot . '/mod/feedback/item/multichoice/lib.php');

        $position = 0;
        foreach ($node['content']['items'] ?? [] as $data) {
            $type = (string) ($data['type'] ?? '');
            if (!in_array($type, self::ITEM_TYPES, true)) {
                $this->warnings[] = get_string('buildwarnfeedbackitem', 'local_aicoursebuilder', $type);
                continue;
            }
            $item = (object) [
                'feedback' => (int) $created->instance,
                'template' => 0,
                'name' => trim(strip_tags((string) ($data['name'] ?? ''))),
                'label' => \core_text::substr(self::clean_name((string) ($data['label'] ?? '')), 0, self::MAX_LABEL),
                'presentation' => $this->presentation($type, $data),
                'typ' => $type,
                'hasvalue' => feedback_get_item_class($type)->get_hasvalue(),
                'position' => ++$position,
                'required' => $type === 'info' ? 0 : (int) !empty($data['required']),
                'dependitem' => 0,
                'dependvalue' => '',
                'options' => $type === 'multichoice' ? FEEDBACK_MULTICHOICE_HIDENOSELECT : '',
            ];
            $DB->insert_record('feedback_item', $item);
        }
    }

    /**
     * Returns the presentation a type of item reads.
     *
     * @param string $type The item type.
     * @param array $data The item of the blueprint.
     * @return string
     */
    protected function presentation(string $type, array $data): string {
        switch ($type) {
            case 'multichoice':
                // A single answer, as radio buttons: r, then the choices.
                $choices = array_map(fn($choice) => self::clean_name((string) $choice), $data['options'] ?? []);
                return 'r' . FEEDBACK_MULTICHOICE_TYPE_SEP . implode(FEEDBACK_MULTICHOICE_LINE_SEP, array_filter($choices));
            case 'numeric':
                // The range, with a dash for a limit the blueprint does not give.
                $from = isset($data['min']) && is_numeric($data['min']) ? $data['min'] : '-';
                $to = isset($data['max']) && is_numeric($data['max']) ? $data['max'] : '-';
                return $from . '|' . $to;
            case 'textarea':
                return self::TEXTAREA_PRESENTATION;
            case 'textfield':
                return self::TEXTFIELD_PRESENTATION;
            default:
                // Info: the fact shown next to the title is the name of the course.
                return '2';
        }
    }
}
