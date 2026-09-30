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
 * One problem found in a blueprint or in a step fragment.
 *
 * The message is written for the model: it is fed back verbatim in the repair prompt, so it stays
 * in English and out of the language packs. The code is the stable contract for everything else;
 * the blueprint editor turns codes into translated strings, which is why no message is ever parsed.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class validation_error {
    /** @var string The JSON Schema document rejected the value. */
    public const CODE_SCHEMA = 'schema';

    /** @var string An id is used more than once. */
    public const CODE_DUPLICATE_ID = 'duplicate_id';

    /** @var string A reference points at an id that does not exist. */
    public const CODE_BROKEN_REF = 'broken_ref';

    /** @var string The fractions of a question's answers do not add up as Moodle needs. */
    public const CODE_FRACTION_SUM = 'fraction_sum';

    /** @var string A URL is not present in any source document. */
    public const CODE_URL_NOT_IN_SOURCES = 'url_not_in_sources';

    /** @var string A text is longer than the Moodle column that will hold it. */
    public const CODE_MAX_LENGTH = 'max_length';

    /** @var string A question gap marker or an activity option is not usable in Moodle. */
    public const CODE_MOODLE_LIMIT = 'moodle_limit';

    /** @var string The output is not the JSON object the step asked for. */
    public const CODE_NOT_JSON = 'not_json';

    /** @var string[] Every code, in the order they are reported. */
    public const CODES = [
        self::CODE_NOT_JSON,
        self::CODE_SCHEMA,
        self::CODE_DUPLICATE_ID,
        self::CODE_BROKEN_REF,
        self::CODE_FRACTION_SUM,
        self::CODE_URL_NOT_IN_SOURCES,
        self::CODE_MAX_LENGTH,
        self::CODE_MOODLE_LIMIT,
    ];

    /**
     * Creates the error.
     *
     * @param string $path JSON Pointer of the offending value, such as /sections/0/activities/2/id.
     * @param string $code One of the CODE_* constants.
     * @param string $message English message for the model, never shown to a user as it is.
     */
    public function __construct(
        /** @var string JSON Pointer of the offending value. */
        public readonly string $path,
        /** @var string One of the CODE_* constants. */
        public readonly string $code,
        /** @var string English message for the model. */
        public readonly string $message,
    ) {
    }

    /**
     * Returns the error as an array, the shape used by the web services and the repair prompt.
     *
     * @return array ['path' => string, 'code' => string, 'message' => string]
     */
    public function to_array(): array {
        return ['path' => $this->path, 'code' => $this->code, 'message' => $this->message];
    }

    /**
     * Returns a list of errors as the JSON handed to the model in a repair call.
     *
     * @param self[] $errors The errors.
     * @return string Pretty-printed JSON array.
     */
    public static function list_to_json(array $errors): string {
        return json_encode(
            array_map(fn(self $error) => $error->to_array(), array_values($errors)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
