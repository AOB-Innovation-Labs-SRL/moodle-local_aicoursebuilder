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
 * Builds a URL: a link to a page outside the course.
 *
 * The address is checked again here, though the validator already did: a link that is not http or https must never
 * reach the module, whatever the blueprint says.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'url';
    }

    /**
     * Adds the address and the display options of the link.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     * @throws \moodle_exception When the address is not an http or https one.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $externalurl = trim((string) ($node['content']['externalurl'] ?? ''));
        if (!preg_match('~^https?://~i', $externalurl) || clean_param($externalurl, PARAM_URL) === '') {
            throw new \moodle_exception('builderurlinvalid', 'local_aicoursebuilder', '', $externalurl);
        }
        $info->externalurl = $externalurl;

        $defaults = $this->get_defaults(['display', 'popupwidth', 'popupheight', 'printintro']);
        $info->display = (int) ($defaults['display'] ?? RESOURCELIB_DISPLAY_AUTO);
        $info->popupwidth = (int) ($defaults['popupwidth'] ?? 620);
        $info->popupheight = (int) ($defaults['popupheight'] ?? 450);
        $info->printintro = (int) ($defaults['printintro'] ?? 1);
    }
}
