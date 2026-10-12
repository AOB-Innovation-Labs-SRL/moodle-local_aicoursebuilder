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
 * Builds a glossary: the module, then its entries.
 *
 * The entries are saved with glossary_edit_entry(), the function the entry form of the glossary calls, so that an
 * entry has what the form gives it: the definition saved through the editor, the aliases, the event, the cache of
 * the automatic links reset. The entries are the teacher's own, approved, and linked automatically only when the
 * site's settings for a new entry say so. A concept that comes twice in the blueprint is saved once.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'glossary';
    }

    /**
     * Adds the settings of the glossary: the site defaults, as the form of a new glossary has them.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        $info->allowduplicatedentries = (int) $this->get_core_default('glossary_dupentries', 0);
        $info->displayformat = 'dictionary';
        $info->approvaldisplayformat = 'default';
        $info->mainglossary = 0;
        $info->showspecial = 1;
        $info->showalphabet = 1;
        $info->showall = 1;
        $info->allowcomments = (int) $this->get_core_default('glossary_allowcomments', 0);
        $info->allowprintview = 1;
        $info->usedynalink = (int) $this->get_core_default('glossary_linkbydefault', 1);
        $info->defaultapproval = (int) $this->get_core_default('glossary_defaultapproval', 1);
        $info->globalglossary = 0;
        $info->entbypage = (int) $this->get_core_default('glossary_entbypage', 10);
        $info->editalways = 0;
        $info->rsstype = 0;
        $info->rssarticles = 0;
        $info->assessed = 0;
        $info->scale = 0;
    }

    /**
     * Saves the entries of the glossary.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/glossary/lib.php');

        $glossary = $DB->get_record('glossary', ['id' => $created->instance], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('glossary', $created->coursemodule, 0, false, MUST_EXIST);
        $course = get_course((int) $created->course);
        $modulecontext = \context_module::instance($cm->id);

        $seen = [];
        foreach ($node['content']['entries'] ?? [] as $data) {
            $concept = trim(strip_tags((string) ($data['concept'] ?? '')));
            $key = \core_text::strtolower($concept);
            if ($concept === '' || isset($seen[$key])) {
                $this->warnings[] = get_string('buildwarnglossaryduplicate', 'local_aicoursebuilder', $concept);
                continue;
            }
            $seen[$key] = true;

            $aliases = array_filter(array_map(
                fn($alias) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $alias)) ?? ''),
                $data['aliases'] ?? []
            ));
            $entry = (object) [
                'id' => 0,
                'concept' => \core_text::substr($concept, 0, 255),
                'definition_editor' => [
                    'text' => self::clean_html((string) ($data['definition'] ?? '')),
                    'format' => FORMAT_HTML,
                    'itemid' => file_get_unused_draft_itemid(),
                ],
                'aliases' => implode("\n", $aliases),
                'usedynalink' => (int) $this->get_core_default('glossary_linkentries', 0),
                'casesensitive' => (int) $this->get_core_default('glossary_casesensitive', 0),
                'fullmatch' => (int) $this->get_core_default('glossary_fullmatch', 1),
            ];
            glossary_edit_entry($entry, $course, $cm, $glossary, $modulecontext);
        }
    }
}
