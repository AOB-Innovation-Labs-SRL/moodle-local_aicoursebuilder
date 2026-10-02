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

namespace local_aicoursebuilder\pipeline;

use local_aicoursebuilder\ai\request;

/**
 * Step 3: writes the teaching material of one section, one sub-call per section.
 *
 * Sections are independent of each other, so they are generated as separate calls the orchestrator
 * runs in parallel. That is also what keeps a failure small: a section that cannot be written is
 * handed back marked for a human, and the other sections are unaffected (spec 3.6).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_sections extends step {
    /**
     * Returns the pipeline step this class runs.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_SECTIONS;
    }

    /**
     * Returns the prompt template name of this step.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'sections';
    }

    /**
     * Returns the placeholder values of the sections prompt.
     *
     * @param array $input Brief, outline and the one section, as
     *                     ['brief' => array, 'outline' => array, 'section' => array].
     * @param string $nodekey Id of the section this sub-call writes.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'section_id' => $nodekey,
            'brief' => $input['brief'] ?? [],
            'outline' => $this->outline_summary($input['outline'] ?? []),
            'section' => $input['section'] ?? [],
            'sources' => $this->section_sources($input['section'] ?? []),
            'source_ids' => $this->context->source_ids_text(),
        ];
    }

    /**
     * Opens every call of this step with the prefix shared by the whole job.
     *
     * @param array $input Step input.
     * @return string|null
     */
    protected function shared_prefix(array $input): ?string {
        return $this->render_shared_prefix($input);
    }

    /**
     * Returns the outline as the neighbouring context of this section.
     *
     * Only the titles and objectives of the other sections are sent: a section has to know what the
     * ones around it cover so it does not repeat them, but their content is not its business and
     * would multiply the tokens of every sub-call.
     *
     * @param array $outline The whole outline.
     * @return string
     */
    protected function outline_summary(array $outline): string {
        $summary = [];
        foreach ($outline['sections'] ?? [] as $section) {
            $summary[] = [
                'id' => $section['id'] ?? '',
                'title' => $section['title'] ?? '',
                'objectives' => array_column($section['objectives'] ?? [], 'text'),
            ];
        }
        return (string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns the source material of one section.
     *
     * Only the documents the outline tied to this section are sent. When the outline named none,
     * every source is sent: too much context costs tokens, but too little invites the model to
     * write from memory, which is what source_refs exist to prevent.
     *
     * @param array $section The section, as the outline wrote it.
     * @return string
     */
    protected function section_sources(array $section): string {
        if ($this->context->sourcetexts === []) {
            return 'There are no source documents: write the section from the outline alone.';
        }
        $wanted = array_values(array_unique(array_filter(
            array_column($section['source_refs'] ?? [], 'source'),
            fn($id) => isset($this->context->sourcetexts[$id]),
        )));
        $texts = $wanted === []
            ? $this->context->sourcetexts
            : array_intersect_key($this->context->sourcetexts, array_flip($wanted));

        $parts = [];
        foreach ($texts as $id => $text) {
            $parts[] = "[{$id}]\n{$text}";
        }
        return implode("\n\n", $parts);
    }

    /**
     * Returns the placeholder of a section nobody could write.
     *
     * The section keeps its place in the course with a single label saying what is missing, so the
     * teacher lands on the gap instead of finding a section that silently vanished.
     *
     * @param string $nodekey Id of the section.
     * @param array $input Brief, outline and the section.
     * @return array
     */
    protected function placeholder(string $nodekey, array $input): array {
        $title = $input['section']['title'] ?? $nodekey;
        return [
            'id' => $nodekey,
            'activities' => [[
                'id' => $nodekey . '.label1',
                'type' => 'label',
                'name' => $title,
                'content' => ['text' => '<p>' . s(get_string('needsmanualcompletion', 'local_aicoursebuilder')) . '</p>'],
                'review_flag' => true,
            ]],
        ];
    }
}
