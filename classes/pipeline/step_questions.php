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
 * Writes one quiz with inline questions from a section's objectives and referenced chunks.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_questions extends step {
    /** @var int Lowest supported number of questions per objective. */
    public const MIN_QUESTIONS = 5;

    /** @var int Highest supported number of questions per objective. */
    public const MAX_QUESTIONS = 15;

    /**
     * Returns the question route.
     *
     * @return string
     */
    public function get_step(): string {
        return request::STEP_QUESTIONS;
    }

    /**
     * Returns the versioned question prompt name.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'questions';
    }

    /**
     * Returns the configured inclusive question range, clamped to the supported 5–15 interval.
     *
     * @return array [minimum, maximum].
     */
    public static function limits(): array {
        $minimum = (int) get_config('local_aicoursebuilder', 'questions_min');
        $maximum = (int) get_config('local_aicoursebuilder', 'questions_max');
        $minimum = min(self::MAX_QUESTIONS, max(self::MIN_QUESTIONS, $minimum ?: self::MIN_QUESTIONS));
        $maximum = min(self::MAX_QUESTIONS, max($minimum, $maximum ?: self::MAX_QUESTIONS));
        return [$minimum, $maximum];
    }

    /**
     * Makes the referenced chunks part of the step input and therefore its resume hash.
     *
     * @param array $section Section from the outline.
     * @param int $first First numeric question id reserved for this section.
     * @return array Complete step input.
     */
    public function input_for(array $section, int $first): array {
        [$minimum, $maximum] = self::limits();
        return [
            'section' => $section,
            'question_first' => $first,
            'chunks' => $this->referenced_chunks($section),
            'questions_min' => $minimum,
            'questions_max' => $maximum,
        ];
    }

    /**
     * Builds the prompt from objectives and the referenced source chunks only.
     *
     * @param array $input Section, question id seed and optional instructions.
     * @param string $nodekey Section id.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        $minimum = $input['questions_min'] ?? self::limits()[0];
        $maximum = $input['questions_max'] ?? self::limits()[1];
        $first = (int) ($input['question_first'] ?? 1);
        $objectives = $input['section']['objectives'] ?? [];
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'section_id' => $nodekey,
            'quiz_id' => $nodekey . '.quiz1',
            'section' => $input['section'] ?? [],
            'chunks' => $input['chunks'] ?? [],
            'questions_min' => $minimum,
            'questions_max' => $maximum,
            'question_first' => 'q' . $first,
            'question_last' => 'q' . ($first + count($objectives) * $maximum - 1),
            'instructions' => $input['instructions'] ?? '',
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
     * Loads chunks named by the section, preserving source id, page and title in the prompt.
     *
     * @param array $section Section from the outline.
     * @return array
     */
    protected function referenced_chunks(array $section): array {
        global $DB;

        $wanted = [];
        foreach ($section['source_refs'] ?? [] as $ref) {
            if (!isset($ref['source']) || !preg_match('/^src([0-9]+)$/', $ref['source'], $matches)) {
                continue;
            }
            $wanted[(int) $matches[1]][$ref['chunk'] ?? 0] = true;
        }
        $sources = array_values($DB->get_records(
            'local_aicb_source',
            ['jobid' => $this->context->jobid],
            'id ASC',
            'id'
        ));
        $chunks = [];
        foreach ($wanted as $ordinal => $indexes) {
            if (!isset($sources[$ordinal - 1])) {
                continue;
            }
            $sourceid = (int) $sources[$ordinal - 1]->id;
            foreach ($indexes as $index => $unused) {
                $record = $DB->get_record('local_aicb_chunk', [
                    'jobid' => $this->context->jobid,
                    'sourceid' => $sourceid,
                    'chunkindex' => $index,
                ]);
                if ($record) {
                    $chunks[] = [
                        'source' => 'src' . $ordinal,
                        'chunk' => (int) $index,
                        'page' => $record->pagefrom === null ? null : (int) $record->pagefrom,
                        'title' => $record->title,
                        'content' => $record->content,
                    ];
                }
            }
        }
        return $chunks;
    }

    /**
     * Checks quiz identity, question counts, metadata and source references.
     *
     * @param array|null $output Decoded answer.
     * @param array $input Step input.
     * @param string $nodekey Section id.
     * @return validation_error[]
     */
    protected function validate(?array $output, array $input, string $nodekey): array {
        $errors = parent::validate($output, $input, $nodekey);
        if ($output === null) {
            return $errors;
        }
        if (($output['id'] ?? null) !== $nodekey || ($output['quiz']['id'] ?? null) !== $nodekey . '.quiz1') {
            $errors[] = new validation_error(
                '/quiz/id',
                validation_error::CODE_BROKEN_REF,
                'Use the requested section and quiz ids.'
            );
        }
        $minimum = $input['questions_min'] ?? self::limits()[0];
        $maximum = $input['questions_max'] ?? self::limits()[1];
        $counts = array_fill_keys(array_column($input['section']['objectives'] ?? [], 'id'), 0);
        foreach ($output['quiz']['content']['questions'] ?? [] as $index => $question) {
            if (!is_array($question)) {
                continue;
            }
            $ref = $question['objective_ref'] ?? '';
            if (!array_key_exists($ref, $counts)) {
                $errors[] = new validation_error(
                    "/quiz/content/questions/{$index}/objective_ref",
                    validation_error::CODE_BROKEN_REF,
                    'Use an objective id of this section.'
                );
            } else {
                $counts[$ref]++;
            }
            foreach (['id', 'generalfeedback', 'difficulty'] as $field) {
                if (empty($question[$field])) {
                    $errors[] = new validation_error(
                        "/quiz/content/questions/{$index}/{$field}",
                        validation_error::CODE_MOODLE_LIMIT,
                        "Every generated question needs {$field}."
                    );
                }
            }
            if (
                !isset($question['source_refs']) || !is_array($question['source_refs'])
                || (!empty($input['section']['source_refs']) && $question['source_refs'] === [])
            ) {
                $errors[] = new validation_error(
                    "/quiz/content/questions/{$index}/source_refs",
                    validation_error::CODE_MOODLE_LIMIT,
                    'Use the source references of this section.'
                );
            }
        }
        foreach ($counts as $id => $count) {
            if ($count < $minimum || $count > $maximum) {
                $errors[] = new validation_error(
                    '/quiz/content/questions',
                    validation_error::CODE_MOODLE_LIMIT,
                    "Objective {$id} needs between {$minimum} and {$maximum} questions, found {$count}."
                );
            }
        }
        return $errors;
    }

    /**
     * A failed quiz call leaves a visible valid question for manual completion.
     *
     * @param string $nodekey Section id.
     * @param array $input Step input.
     * @return array
     */
    protected function placeholder(string $nodekey, array $input): array {
        return [
            'id' => $nodekey,
            'quiz' => [
                'id' => $nodekey . '.quiz1',
                'type' => 'quiz',
                'name' => get_string('needsmanualcompletion', 'local_aicoursebuilder'),
                'review_flag' => true,
                'content' => ['questions' => [[
                    'id' => 'q' . ($input['question_first'] ?? 1),
                    'qtype' => 'essay',
                    'name' => get_string('needsmanualcompletion', 'local_aicoursebuilder'),
                    'questiontext' => '<p>' . s(get_string('needsmanualcompletion', 'local_aicoursebuilder')) . '</p>',
                ]]],
            ],
        ];
    }
}
