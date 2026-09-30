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
 * Step 1: turns a teacher's request and the source summaries into the brief of the course.
 *
 * The brief is what the teacher confirms before anything is generated, so it holds the decisions
 * the rest of the pipeline is bound by: who the course is for, how long it takes, what language it
 * is written in and what the learner will be able to do (spec 3.6).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_brief extends step {
    /**
     * Returns the pipeline step this class runs.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_BRIEF;
    }

    /**
     * Returns the prompt template name of this step.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'brief';
    }

    /**
     * Returns the placeholder values of the brief prompt.
     *
     * @param array $input Teacher prompt and target, as ['prompt' => string, 'target' => string].
     * @param string $nodekey Unused: the brief is written in one call.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'prompt' => $input['prompt'] ?? '',
            'sources' => $this->summaries(),
            'target' => ($input['target'] ?? 'newcourse') === 'existingcourse'
                ? 'The material is added to a course that already exists, so target is existingcourse.'
                : 'The teacher wants a new course, so target is newcourse.',
        ];
    }

    /**
     * Returns the source summaries the brief is written from.
     *
     * The digests are used rather than the documents themselves: the brief needs the shape of the
     * material, not its detail, and the whole of it would not fit.
     *
     * @return string
     */
    protected function summaries(): string {
        if ($this->context->digests === []) {
            return 'There are no source documents: write the brief from the request alone.';
        }
        return (string) json_encode(
            $this->context->digests,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
