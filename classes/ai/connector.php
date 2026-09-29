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

namespace local_aicoursebuilder\ai;

/**
 * Contract for every AI connector (core_ai bridge or direct provider).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface connector {
    /** @var string Structured output constrained by a JSON schema. */
    public const CAP_JSON_SCHEMA = 'json_schema';

    /** @var string PDF files sent as input. */
    public const CAP_FILES_PDF = 'files_pdf';

    /** @var string Images sent as input. */
    public const CAP_VISION = 'vision';

    /** @var string Prompt prefix caching. */
    public const CAP_PROMPT_CACHE = 'prompt_cache';

    /** @var string Asynchronous batch API. */
    public const CAP_BATCH = 'batch';

    /** @var string Long outputs (tens of thousands of tokens). */
    public const CAP_LONG_OUTPUT = 'long_output';

    /** @var string[] All known capabilities. */
    public const CAPABILITIES = [
        self::CAP_JSON_SCHEMA,
        self::CAP_FILES_PDF,
        self::CAP_VISION,
        self::CAP_PROMPT_CACHE,
        self::CAP_BATCH,
        self::CAP_LONG_OUTPUT,
    ];

    /**
     * Runs one completion call.
     *
     * @param request $request The completion request.
     * @return result The completion result, with usage and cost.
     */
    public function complete(request $request): result;

    /**
     * Tells whether the connector supports a capability.
     *
     * @param string $capability One of the CAP_* constants.
     * @return bool
     */
    public function supports(string $capability): bool;

    /**
     * Estimates the cost of a request before sending it.
     *
     * @param request $request The completion request.
     * @return float Estimated cost in USD.
     */
    public function estimate_cost(request $request): float;

    /**
     * Estimates the number of tokens in a text.
     *
     * @param string $text The text.
     * @return int Estimated token count.
     */
    public function count_tokens(string $text): int;
}
