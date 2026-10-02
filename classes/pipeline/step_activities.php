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
 * Writes the interactive activities of one section, keeping failures local to that section.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_activities extends step_sections {
    /** @var int Most activity types one section may use: the few that fit it, never all of them. */
    public const MAX_TYPES = 3;

    /**
     * Returns the activities route.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_ACTIVITIES;
    }

    /**
     * Returns the versioned activities prompt name.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'activities';
    }

    /**
     * Supplies only this section's teaching material and sources.
     *
     * @param array $input Section, existing material and optional instructions.
     * @param string $nodekey Section id.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'section_id' => $nodekey,
            'section' => $input['section'] ?? [],
            'activities' => $input['activities'] ?? [],
            'sources' => $this->section_sources($input['section'] ?? []),
            'instructions' => $input['instructions'] ?? '',
            'source_ids' => $this->context->source_ids_text(),
        ];
    }

    /**
     * Checks that the answer belongs to this section and uses only interactive activity types.
     *
     * @param array|null $output Decoded answer.
     * @param array $input Step input.
     * @param string $nodekey Section id.
     * @return validation_error[]
     */
    protected function validate(?array $output, array $input, string $nodekey): array {
        $errors = parent::validate($output, $input, $nodekey);
        if ($output !== null && ($output['id'] ?? null) !== $nodekey) {
            $errors[] = new validation_error('/id', validation_error::CODE_BROKEN_REF, 'Use the requested section id.');
        }
        $types = array_unique(array_column(array_filter($output['activities'] ?? [], 'is_array'), 'type'));
        if (count($types) > self::MAX_TYPES) {
            $errors[] = new validation_error(
                '/activities',
                validation_error::CODE_MOODLE_LIMIT,
                'Use at most ' . self::MAX_TYPES . ' activity types in one section, found ' . count($types) . '.',
            );
        }
        return $errors;
    }

    /**
     * A failed activity call leaves a visible, valid marker for the teacher.
     *
     * @param string $nodekey Section id.
     * @param array $input Step input.
     * @return array
     */
    protected function placeholder(string $nodekey, array $input): array {
        return [
            'id' => $nodekey,
            'activities' => [[
                'id' => $nodekey . '.assign999',
                'type' => 'assign',
                'name' => get_string('needsmanualcompletion', 'local_aicoursebuilder'),
                'content' => ['submission_types' => ['onlinetext']],
                'review_flag' => true,
            ]],
        ];
    }
}
