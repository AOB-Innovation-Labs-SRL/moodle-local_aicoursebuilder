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

namespace local_aicoursebuilder\ingest;

/**
 * Immutable result of the normalisation of the text of one source.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class normalization_result {
    /** @var string Language code when the text is too short or too mixed to tell. */
    public const LANGUAGE_UNDETERMINED = 'und';

    /**
     * Creates the result.
     *
     * @param string $markdown Normalised text as Markdown; page markers are kept when the source had them.
     * @param string $language ISO 639-1 code of the language of the text, or LANGUAGE_UNDETERMINED.
     * @param int[] $stats What the normalisation did, by name: headers (running header and footer lines
     *                     removed), duplicates (repeated blocks removed), tables (tables made from aligned
     *                     text), headings (headings found in text that had no structure).
     */
    public function __construct(
        /** @var string Normalised text. */
        public readonly string $markdown,
        /** @var string Language code. */
        public readonly string $language,
        /** @var int[] Counters of what was done. */
        public readonly array $stats = [],
    ) {
    }
}
