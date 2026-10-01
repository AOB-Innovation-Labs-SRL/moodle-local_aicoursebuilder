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
use local_aicoursebuilder\ingest\digest_builder;

/**
 * The digest step: one call that turns a source document, or a window of its chunks, into its digest.
 *
 * It runs once per source document before the pipeline itself (spec 3.5), through the same machinery as
 * the other steps: the prompt is a versioned template, the answer is checked against the schema and sent
 * back for repair when it does not fit, the cost is reserved and settled, and a finished call is found in
 * local_aicb_step and not paid for again. The digest is what the brief and the outline are written from.
 *
 * The input is ['document' => the chunks of the document as text, 'indexes' => the numbers of the chunks].
 * The sub-call key names the source and, for a long document, the window.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_digest extends step {
    /**
     * Returns the pipeline step this class runs.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_DIGEST;
    }

    /**
     * Returns the prompt template name of this step.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'digest';
    }

    /**
     * Returns the placeholder values of the digest prompt.
     *
     * @param array $input The chunks as text in 'document'.
     * @param string $nodekey Unused: the source is in the document.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'maxconcepts' => digest_builder::LIMITS['concepts'],
            'maxdefinitions' => digest_builder::LIMITS['definitions'],
            'maxobjectives' => digest_builder::LIMITS['objectives'],
            'maxprocedures' => digest_builder::LIMITS['procedures'],
            'document' => $input['document'] ?? '',
        ];
    }

    /**
     * Checks the answer against the schema, and asks for another when it holds nothing.
     *
     * A digest with every list empty is no use to the brief, so it counts as an error here and is repaired like
     * any other answer that does not fit.
     *
     * @param array|null $output The decoded answer, or null when it was not JSON.
     * @param array $input Everything the step needs.
     * @param string $nodekey Sub-call key.
     * @return validation_error[]
     */
    protected function validate(?array $output, array $input, string $nodekey): array {
        $errors = parent::validate($output, $input, $nodekey);
        if ($output !== null && $errors === []) {
            $items = count($output['concepts']) + count($output['definitions']) + count($output['objectives'])
                + count($output['procedures']);
            if ($items === 0) {
                $errors[] = new validation_error(
                    '',
                    validation_error::CODE_SCHEMA,
                    'The digest has no concepts, definitions, objectives or procedures.'
                );
            }
        }
        return $errors;
    }
}
