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
 * Builds a page: a name, a description and a text.
 *
 * The page module takes its text from the editor field of its form, page[text, format, itemid], but only when the
 * form itself is given to add_instance(). Nothing gives it one here, so the text is also set as content and
 * contentformat, which are what the module stores. The display options are the site defaults of the module.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'page';
    }

    /**
     * Adds the text and the display options of the page.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $text = self::clean_html((string) ($node['content']['text'] ?? ''));
        $info->page = ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => 0];
        $info->content = $text;
        $info->contentformat = FORMAT_HTML;

        $defaults = $this->get_defaults(['display', 'popupwidth', 'popupheight', 'printintro', 'printlastmodified']);
        $info->display = (int) ($defaults['display'] ?? RESOURCELIB_DISPLAY_OPEN);
        $info->popupwidth = (int) ($defaults['popupwidth'] ?? 620);
        $info->popupheight = (int) ($defaults['popupheight'] ?? 450);
        $info->printintro = (int) ($defaults['printintro'] ?? 0);
        $info->printlastmodified = (int) ($defaults['printlastmodified'] ?? 1);
        $info->revision = 1;
    }
}
