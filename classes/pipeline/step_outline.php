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
 * Step 2: turns the brief and the source digests into the course and its sections.
 *
 * The outline reads the digests rather than the documents: it decides how the course is divided,
 * which needs the shape of the material and not its wording, and the documents themselves would
 * not fit in one call (spec 3.6).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_outline extends step {
    /**
     * Returns the pipeline step this class runs.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_OUTLINE;
    }

    /**
     * Returns the prompt template name of this step.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'outline';
    }

    /**
     * Returns the placeholder values of the outline prompt.
     *
     * @param array $input The brief, as ['brief' => array].
     * @param string $nodekey Unused: the outline is written in one call.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'brief' => $input['brief'] ?? [],
            'sources' => $this->digests(),
            'source_ids' => $this->context->source_ids_text(),
        ];
    }

    /**
     * Returns the digests the outline is built from.
     *
     * @return string
     */
    protected function digests(): string {
        if ($this->context->digests === []) {
            return 'There are no source documents: build the outline from the brief alone.';
        }
        return (string) json_encode(
            $this->context->digests,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
