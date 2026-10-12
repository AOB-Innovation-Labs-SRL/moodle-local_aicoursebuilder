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
 * Builds a wiki: the module, its shared wiki and the pages it starts with.
 *
 * The wiki is a collaborative one in HTML, the only mode in which pages written beforehand are everybody's: in an
 * individual wiki every student has a wiki of their own, which is made when they open it. The first page is the one
 * the wiki opens on, so its title is the first page title of the module. The pages are made the way the wiki
 * makes them: an empty page first, then its content saved as a new version.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_builder extends module_builder {
    /** @var string Format of the pages: the blueprint writes HTML. */
    public const FORMAT = 'html';

    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'wiki';
    }

    /**
     * Adds the mode, the format and the title of the first page.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        $first = self::clean_name((string) ($node['content']['pages'][0]['title'] ?? ''));
        $info->firstpagetitle = $first !== '' ? $first : self::clean_name($info->name);
        $info->wikimode = 'collaborative';
        $info->defaultformat = self::FORMAT;
        $info->forceformat = 1;
        $info->editbegin = 0;
        $info->editend = 0;
    }

    /**
     * Makes the shared wiki and its pages.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/wiki/locallib.php');

        $subwikiid = wiki_add_subwiki((int) $created->instance, 0, 0);
        $titles = [];
        foreach ($node['content']['pages'] ?? [] as $data) {
            $title = self::clean_name((string) ($data['title'] ?? ''));
            if ($title === '' || in_array(\core_text::strtolower($title), $titles, true)) {
                // A page with a title that is already taken would be written over the first one.
                $this->warnings[] = get_string('buildwarnwikiduplicate', 'local_aicoursebuilder', $title);
                continue;
            }
            $titles[] = \core_text::strtolower($title);

            $pageid = wiki_create_page($subwikiid, $title, self::FORMAT, (int) $USER->id);
            wiki_save_page(wiki_get_page($pageid), self::clean_html((string) ($data['content'] ?? '')), (int) $USER->id);
        }
    }
}
