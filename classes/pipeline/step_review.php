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
use local_aicoursebuilder\blueprint\validation_error;

/**
 * Critic pass over the complete blueprint. Its output is observations, never replacement content.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_review extends step {
    /**
     * Returns the review route, which may use a separately configured critic model.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_REVIEW;
    }

    /**
     * Returns the versioned review prompt name.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'review';
    }

    /**
     * Sends the full blueprint and source text to the critic.
     *
     * @param array $input The assembled blueprint.
     * @param string $nodekey Empty for the whole blueprint.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'blueprint' => $input['blueprint'] ?? [],
            'sources' => $this->context->sourcetexts,
            'source_ids' => $this->context->source_ids_text(),
        ];
    }

    /**
     * Rejects invented ids so a review cannot silently flag another node.
     *
     * @param array|null $output The critic's observations.
     * @param array $input The reviewed blueprint.
     * @param string $nodekey Empty for the whole blueprint.
     * @return validation_error[]
     */
    protected function validate(?array $output, array $input, string $nodekey): array {
        $errors = parent::validate($output, $input, $nodekey);
        if ($output === null) {
            return $errors;
        }
        $ids = [];
        foreach ($input['blueprint']['sections'] ?? [] as $section) {
            $ids[$section['id']] = true;
            foreach ([$section, ...($section['subsections'] ?? [])] as $container) {
                $ids[$container['id']] = true;
                foreach ($container['activities'] ?? [] as $activity) {
                    $ids[$activity['id']] = true;
                    foreach ($activity['content']['questions'] ?? [] as $question) {
                        if (isset($question['id'])) {
                            $ids[$question['id']] = true;
                        }
                    }
                }
            }
        }
        foreach ($output['issues'] ?? [] as $index => $issue) {
            if (!isset($ids[$issue['node'] ?? ''])) {
                $errors[] = new validation_error(
                    "/issues/{$index}/node",
                    validation_error::CODE_BROKEN_REF,
                    'The review may name only an existing section, activity or question id.'
                );
            }
        }
        return $errors;
    }
}
