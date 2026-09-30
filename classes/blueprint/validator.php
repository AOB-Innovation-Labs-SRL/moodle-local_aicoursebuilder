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

namespace local_aicoursebuilder\blueprint;

/**
 * Checks a blueprint, or one step's fragment of it, against the JSON schemas and the rules that a
 * schema cannot express (spec 3.6 and 4).
 *
 * Two layers, one list of errors. The schema layer runs opis/json-schema over the documents in
 * schema/; the semantic layer checks id uniqueness, cross-references, the Moodle lengths the schema
 * does not cover, answer fractions, and that every URL comes from a source document. Both layers
 * report validation_error objects, so the caller never has to know which layer complained.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {
    /** @var int Longest course fullname, from the blueprint schema. */
    public const MAX_COURSE_FULLNAME = 254;

    /** @var int Longest course shortname, from the blueprint schema. */
    public const MAX_COURSE_SHORTNAME = 100;

    /** @var int Longest name of a section, an activity or a question, from the blueprint schema. */
    public const MAX_NAME = 255;

    /** @var float Tolerance when adding answer fractions, which arrive as decimals. */
    protected const FRACTION_EPSILON = 0.0001;

    /** @var schema_store Store holding the schema documents. */
    protected schema_store $schemas;

    /**
     * Creates the validator.
     *
     * @param schema_store|null $schemas Schema store, null for the plugin's own schema/ directory.
     */
    public function __construct(?schema_store $schemas = null) {
        $this->schemas = $schemas ?? new schema_store();
    }

    /**
     * Validates a whole blueprint.
     *
     * @param array|null $blueprint Decoded blueprint, or null when the output was not JSON.
     * @param string[] $sourcetexts Text of each source document, keyed by source id (src1, src2...),
     *                              used to check that URLs are not invented. Empty skips that rule.
     * @return validation_error[] Empty when the blueprint is valid.
     */
    public function validate(?array $blueprint, array $sourcetexts = []): array {
        if ($blueprint === null) {
            return [new validation_error('', validation_error::CODE_NOT_JSON, 'The output is not a JSON object.')];
        }
        $errors = $this->validate_against($blueprint, $this->schemas->uri(schema_store::BLUEPRINT));
        return array_merge($errors, $this->semantic_errors($blueprint, $sourcetexts));
    }

    /**
     * Validates the fragment one pipeline step returned.
     *
     * Only the rules that make sense on a fragment run: a section's activities cannot be checked
     * against objectives the fragment does not carry, so cross-references are checked once the
     * orchestrator has assembled the whole blueprint.
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @param array|null $fragment Decoded fragment, or null when the output was not JSON.
     * @param string[] $sourcetexts Text of each source document, keyed by source id.
     * @return validation_error[] Empty when the fragment is valid.
     */
    public function validate_step(string $step, ?array $fragment, array $sourcetexts = []): array {
        if ($fragment === null) {
            return [new validation_error('', validation_error::CODE_NOT_JSON, 'The output is not a JSON object.')];
        }
        $errors = $this->validate_against($fragment, $this->schemas->step_uri($step));

        // Ids must already be unique inside the fragment, and URLs must already come from a source.
        $errors = array_merge($errors, $this->duplicate_id_errors($this->collect_ids($fragment)));
        foreach ($this->walk_activities($fragment) as $path => $activity) {
            $errors = array_merge($errors, $this->url_errors($activity, $path, $sourcetexts));
            $errors = array_merge($errors, $this->fraction_errors($activity, $path));
        }
        return $errors;
    }

    /**
     * Runs the schema layer over a value.
     *
     * @param array $value Decoded value.
     * @param string $schemauri URI the schema is registered under.
     * @return validation_error[]
     */
    protected function validate_against(array $value, string $schemauri): array {
        $result = $this->schemas->validator()->validate($this->to_object($value), $schemauri);
        if ($result->isValid()) {
            return [];
        }
        $formatter = new \Opis\JsonSchema\Errors\ErrorFormatter();
        $errors = [];
        foreach ($formatter->formatKeyed($result->error()) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = new validation_error(
                    $path === '/' ? '' : $path,
                    validation_error::CODE_SCHEMA,
                    $message,
                );
            }
        }
        return $errors;
    }

    /**
     * Returns the errors of the semantic layer of a whole blueprint.
     *
     * @param array $blueprint Decoded blueprint.
     * @param string[] $sourcetexts Text of each source document, keyed by source id.
     * @return validation_error[]
     */
    protected function semantic_errors(array $blueprint, array $sourcetexts): array {
        $ids = $this->collect_ids($blueprint);
        $errors = $this->duplicate_id_errors($ids);
        $errors = array_merge($errors, $this->length_errors($blueprint));
        $errors = array_merge($errors, $this->reference_errors($blueprint, $ids, $sourcetexts));

        foreach ($this->walk_activities($blueprint) as $path => $activity) {
            $errors = array_merge($errors, $this->url_errors($activity, $path, $sourcetexts));
            $errors = array_merge($errors, $this->fraction_errors($activity, $path));
        }
        return $errors;
    }

    /**
     * Collects every declared id of a blueprint or fragment, with the path each was declared at.
     *
     * @param array $value Decoded blueprint or fragment.
     * @return array<string, array<string, string[]>> Kind => id => paths it was declared at.
     */
    protected function collect_ids(array $value): array {
        $ids = ['section' => [], 'activity' => [], 'objective' => [], 'question' => []];

        foreach ($this->walk_containers($value) as $path => $container) {
            if (isset($container['id']) && is_string($container['id'])) {
                $ids['section'][$container['id']][] = $path . '/id';
            }
            foreach ($container['objectives'] ?? [] as $index => $objective) {
                if (is_array($objective) && isset($objective['id']) && is_string($objective['id'])) {
                    $ids['objective'][$objective['id']][] = "{$path}/objectives/{$index}/id";
                }
            }
        }
        foreach ($this->walk_activities($value) as $path => $activity) {
            if (isset($activity['id']) && is_string($activity['id'])) {
                $ids['activity'][$activity['id']][] = $path . '/id';
            }
            foreach ($activity['content']['questions'] ?? [] as $index => $question) {
                if (is_array($question) && isset($question['id']) && is_string($question['id'])) {
                    $ids['question'][$question['id']][] = "{$path}/content/questions/{$index}/id";
                }
            }
        }
        return $ids;
    }

    /**
     * Returns one error per id declared more than once.
     *
     * @param array<string, array<string, string[]>> $ids Output of collect_ids().
     * @return validation_error[]
     */
    protected function duplicate_id_errors(array $ids): array {
        $errors = [];
        foreach ($ids as $kind => $byid) {
            foreach ($byid as $id => $paths) {
                if (count($paths) < 2) {
                    continue;
                }
                // Report every repeat but the first, so the model knows which ones to change.
                foreach (array_slice($paths, 1) as $path) {
                    $errors[] = new validation_error(
                        $path,
                        validation_error::CODE_DUPLICATE_ID,
                        "Duplicate {$kind} id '{$id}': ids must be unique across the blueprint.",
                    );
                }
            }
        }
        return $errors;
    }

    /**
     * Returns the errors of the length rules, measured in characters so diacritics are not truncated.
     *
     * @param array $blueprint Decoded blueprint.
     * @return validation_error[]
     */
    protected function length_errors(array $blueprint): array {
        $errors = [];
        $check = function (?string $text, int $max, string $path, string $what) use (&$errors): void {
            if ($text !== null && \core_text::strlen($text) > $max) {
                $errors[] = new validation_error(
                    $path,
                    validation_error::CODE_MAX_LENGTH,
                    "The {$what} is longer than the {$max} characters Moodle stores.",
                );
            }
        };

        $course = $blueprint['course'] ?? [];
        $check($course['fullname'] ?? null, self::MAX_COURSE_FULLNAME, '/course/fullname', 'course full name');
        $check($course['shortname'] ?? null, self::MAX_COURSE_SHORTNAME, '/course/shortname', 'course short name');

        foreach ($this->walk_containers($blueprint) as $path => $container) {
            $check($container['title'] ?? null, self::MAX_NAME, $path . '/title', 'section title');
        }
        foreach ($this->walk_activities($blueprint) as $path => $activity) {
            $check($activity['name'] ?? null, self::MAX_NAME, $path . '/name', 'activity name');
            foreach ($activity['content']['chapters'] ?? [] as $index => $chapter) {
                $check(
                    $chapter['title'] ?? null,
                    self::MAX_NAME,
                    "{$path}/content/chapters/{$index}/title",
                    'book chapter title',
                );
            }
            foreach ($activity['content']['questions'] ?? [] as $index => $question) {
                $check(
                    $question['name'] ?? null,
                    self::MAX_NAME,
                    "{$path}/content/questions/{$index}/name",
                    'question name',
                );
            }
        }
        return $errors;
    }

    /**
     * Returns the errors of every reference that does not resolve to a declared id.
     *
     * @param array $blueprint Decoded blueprint.
     * @param array<string, array<string, string[]>> $ids Output of collect_ids().
     * @param string[] $sourcetexts Text of each source document, keyed by source id.
     * @return validation_error[]
     */
    protected function reference_errors(array $blueprint, array $ids, array $sourcetexts): array {
        $errors = [];
        $activities = array_keys($ids['activity']);
        $objectives = array_keys($ids['objective']);
        $sources = array_keys($sourcetexts);

        $check = function (?string $value, array $known, string $kind, string $path) use (&$errors): void {
            if ($value !== null && $known !== [] && !in_array($value, $known, true)) {
                $errors[] = new validation_error(
                    $path,
                    validation_error::CODE_BROKEN_REF,
                    "Unknown {$kind} '{$value}': it is not declared anywhere in the blueprint.",
                );
            }
        };
        $checklist = function (?array $values, array $known, string $kind, string $path) use ($check): void {
            foreach ($values ?? [] as $index => $value) {
                if (is_string($value)) {
                    $check($value, $known, $kind, "{$path}/{$index}");
                }
            }
        };
        $checkavailability = function (?array $availability, string $path) use ($check, $checklist, $activities): void {
            if ($availability === null) {
                return;
            }
            $checklist(
                $availability['require_completion_of'] ?? null,
                $activities,
                'activity id',
                $path . '/require_completion_of',
            );
            if (isset($availability['min_grade']['activity'])) {
                $check(
                    $availability['min_grade']['activity'],
                    $activities,
                    'activity id',
                    $path . '/min_grade/activity',
                );
            }
        };
        $checksourcerefs = function (?array $refs, string $path) use ($check, $sources): void {
            foreach ($refs ?? [] as $index => $ref) {
                if (is_array($ref) && isset($ref['source']) && is_string($ref['source'])) {
                    $check($ref['source'], $sources, 'source id', "{$path}/{$index}/source");
                }
            }
        };

        $course = $blueprint['course'] ?? [];
        $checklist($course['completion_activities'] ?? null, $activities, 'activity id', '/course/completion_activities');
        foreach ($course['competencies'] ?? [] as $index => $competency) {
            $checklist(
                $competency['activities'] ?? null,
                $activities,
                'activity id',
                "/course/competencies/{$index}/activities",
            );
        }
        foreach ($course['badges'] ?? [] as $index => $badge) {
            $checklist(
                $badge['criteria']['activities'] ?? null,
                $activities,
                'activity id',
                "/course/badges/{$index}/criteria/activities",
            );
        }

        foreach ($this->walk_containers($blueprint) as $path => $container) {
            $checkavailability($container['availability'] ?? null, $path . '/availability');
            $checksourcerefs($container['source_refs'] ?? null, $path . '/source_refs');
        }
        foreach ($this->walk_activities($blueprint) as $path => $activity) {
            $checkavailability($activity['availability'] ?? null, $path . '/availability');
            $checksourcerefs($activity['source_refs'] ?? null, $path . '/source_refs');
            if (isset($activity['content']['source'])) {
                $check($activity['content']['source'], $sources, 'source id', $path . '/content/source');
            }
            $checklist($activity['content']['sources'] ?? null, $sources, 'source id', $path . '/content/sources');

            foreach ($activity['content']['questions'] ?? [] as $index => $question) {
                if (!is_array($question)) {
                    continue;
                }
                $qpath = "{$path}/content/questions/{$index}";
                if (isset($question['objective_ref'])) {
                    $check($question['objective_ref'], $objectives, 'objective id', $qpath . '/objective_ref');
                }
                $checksourcerefs($question['source_refs'] ?? null, $qpath . '/source_refs');
            }
        }
        return $errors;
    }

    /**
     * Returns the errors of the answer fraction rules of an activity's questions.
     *
     * The rules are the ones the question type edit forms enforce, so a blueprint that passes here
     * can be imported: a single-answer multichoice question needs its highest fraction to be exactly
     * 1 (errfractionsnomax), a multiple-answer one needs its positive fractions to add up to 1
     * (errfractionsaddwrong), and the answer-list types need at least one answer worth full marks
     * (fractionsnomax). Several answers may share fraction 1, which is how alternative spellings of
     * a shortanswer answer are written.
     *
     * @param array $activity Decoded activity.
     * @param string $path JSON Pointer of the activity.
     * @return validation_error[]
     */
    protected function fraction_errors(array $activity, string $path): array {
        $errors = [];
        foreach ($activity['content']['questions'] ?? [] as $index => $question) {
            if (!is_array($question) || !isset($question['answers']) || !is_array($question['answers'])) {
                continue;
            }
            $qtype = $question['qtype'] ?? '';
            $fractions = array_map(
                fn($answer) => is_array($answer) && isset($answer['fraction']) ? (float) $answer['fraction'] : 0.0,
                $question['answers'],
            );
            $positive = array_filter($fractions, fn(float $fraction) => $fraction > 0.0);
            $qpath = "{$path}/content/questions/{$index}/answers";

            if ($qtype === 'multichoice' && ($question['single'] ?? true)) {
                $max = $fractions === [] ? 0.0 : max($fractions);
                if (abs($max - 1.0) > self::FRACTION_EPSILON) {
                    $errors[] = new validation_error(
                        $qpath,
                        validation_error::CODE_FRACTION_SUM,
                        'The highest fraction of a single-answer multichoice question must be 1, not '
                            . $this->format_fraction($max) . '.',
                    );
                }
            } else if ($qtype === 'multichoice') {
                $sum = array_sum($positive);
                if (abs($sum - 1.0) > self::FRACTION_EPSILON) {
                    $errors[] = new validation_error(
                        $qpath,
                        validation_error::CODE_FRACTION_SUM,
                        'The positive fractions of a multiple-answer multichoice question must add up to 1, not '
                            . $this->format_fraction($sum) . '.',
                    );
                }
            } else if (in_array($qtype, ['truefalse', 'shortanswer', 'numerical'], true)) {
                $full = array_filter($fractions, fn(float $fraction) => abs($fraction - 1.0) <= self::FRACTION_EPSILON);
                if ($full === []) {
                    $errors[] = new validation_error(
                        $qpath,
                        validation_error::CODE_FRACTION_SUM,
                        "A {$qtype} question needs at least one answer with fraction 1, worth full marks.",
                    );
                } else if ($qtype === 'truefalse' && count($full) !== 1) {
                    // True and false cannot both be right: the answer pair carries a single truth.
                    $errors[] = new validation_error(
                        $qpath,
                        validation_error::CODE_FRACTION_SUM,
                        'A truefalse question needs exactly one of its two answers to have fraction 1, found '
                            . count($full) . '.',
                    );
                }
            }
        }
        return $errors;
    }

    /**
     * Formats a fraction for an error message, without trailing zeros.
     *
     * @param float $fraction The fraction.
     * @return string
     */
    protected function format_fraction(float $fraction): string {
        return rtrim(rtrim(number_format($fraction, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Returns the errors of the rule that a URL must appear in a source document (spec 7, R1).
     *
     * @param array $activity Decoded activity.
     * @param string $path JSON Pointer of the activity.
     * @param string[] $sourcetexts Text of each source document, keyed by source id.
     * @return validation_error[]
     */
    protected function url_errors(array $activity, string $path, array $sourcetexts): array {
        $url = $activity['content']['externalurl'] ?? null;
        if (!is_string($url) || $sourcetexts === []) {
            return [];
        }
        foreach ($sourcetexts as $text) {
            if (str_contains($text, $url)) {
                return [];
            }
        }
        return [new validation_error(
            $path . '/content/externalurl',
            validation_error::CODE_URL_NOT_IN_SOURCES,
            "The URL '{$url}' does not appear in any source document: use only URLs found in the sources.",
        )];
    }

    /**
     * Walks the sections and subsections of a blueprint or fragment.
     *
     * @param array $value Decoded blueprint or fragment.
     * @return \Generator<string, array> JSON Pointer => container.
     */
    protected function walk_containers(array $value): \Generator {
        foreach ($value['sections'] ?? [] as $index => $section) {
            if (!is_array($section)) {
                continue;
            }
            yield "/sections/{$index}" => $section;
            foreach ($section['subsections'] ?? [] as $subindex => $subsection) {
                if (is_array($subsection)) {
                    yield "/sections/{$index}/subsections/{$subindex}" => $subsection;
                }
            }
        }
        // A sections-step fragment is a single container, with no sections wrapper of its own.
        if (!isset($value['sections']) && isset($value['activities'])) {
            yield '' => $value;
        }
    }

    /**
     * Walks every activity of a blueprint or fragment.
     *
     * @param array $value Decoded blueprint or fragment.
     * @return \Generator<string, array> JSON Pointer => activity.
     */
    protected function walk_activities(array $value): \Generator {
        foreach ($this->walk_containers($value) as $path => $container) {
            foreach ($container['activities'] ?? [] as $index => $activity) {
                if (is_array($activity)) {
                    yield "{$path}/activities/{$index}" => $activity;
                }
            }
        }
    }

    /**
     * Converts a decoded array into the objects opis/json-schema validates.
     *
     * json_decode with associative arrays is what the rest of the plugin uses, but a JSON object and
     * a JSON array are the same PHP array, so the value is re-encoded to keep the distinction.
     *
     * @param array $value Decoded value.
     * @return mixed
     */
    protected function to_object(array $value): mixed {
        return json_decode(json_encode($value), false);
    }
}
