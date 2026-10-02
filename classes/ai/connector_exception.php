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
 * Error raised by a connector or by the router.
 *
 * The message never contains the API key or the request headers.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connector_exception extends \moodle_exception {
    /** @var string The connector has no API key or endpoint. */
    public const NOT_CONFIGURED = 'connectornotconfigured';

    /** @var string The provider answered with an HTTP error. */
    public const HTTP_ERROR = 'connectorhttperror';

    /** @var string The provider answered with HTTP 429. */
    public const RATE_LIMITED = 'connectorratelimited';

    /** @var string The provider answered with a body or content that is not valid JSON. */
    public const INVALID_JSON = 'connectorinvalidjson';

    /** @var string The answer stopped at the output token limit, so its JSON is cut off. */
    public const TRUNCATED = 'connectortruncated';

    /** @var string[] Finish reasons meaning the output limit was reached: OpenAI style, Gemini, Anthropic. */
    public const TRUNCATION_REASONS = ['length', 'MAX_TOKENS', 'max_tokens'];

    /** @var string The provider could not be reached. */
    public const NETWORK_ERROR = 'connectornetworkerror';

    /** @var string The request asks for something the connector does not support. */
    public const UNSUPPORTED = 'connectorunsupported';

    /** @var string The settings name a connector that does not exist. */
    public const UNKNOWN = 'connectorunknown';

    /** @var string The core_ai subsystem returned an error. */
    public const CORE_AI_ERROR = 'coreaierror';

    /** @var string The user has not accepted the Moodle AI policy. */
    public const POLICY_NOT_ACCEPTED = 'aipolicynotaccepted';

    /**
     * Creates the exception.
     *
     * @param string $errorcode One of the class constants, also the language string key.
     * @param mixed $a Language string placeholder values.
     * @param string|null $debuginfo Extra detail for developers, never secrets.
     * @param int $httpstatus HTTP status returned by the provider, 0 when there was none.
     * @param int|null $retryafterms Delay asked for by the provider's Retry-After header, in
     *                               milliseconds, null when the provider sent none.
     */
    public function __construct(
        string $errorcode,
        mixed $a = null,
        ?string $debuginfo = null,
        /** @var int HTTP status returned by the provider, 0 when there was none. */
        public readonly int $httpstatus = 0,
        /** @var int|null Delay asked for by the provider's Retry-After header, in milliseconds. */
        public readonly ?int $retryafterms = null,
    ) {
        parent::__construct($errorcode, 'local_aicoursebuilder', '', $a, $debuginfo);
    }

    /**
     * Returns the failure for a JSON answer that does not decode: a truncation when the model
     * stopped at the output limit, an invalid answer otherwise.
     *
     * @param string $finishreason Why the model stopped, as the provider reports it.
     * @param int $maxtokens Output limit the request was sent with, 0 when none.
     * @param string $detail Debug detail, never the answer itself.
     * @return self
     */
    public static function for_undecodable_json(string $finishreason, int $maxtokens, string $detail): self {
        if (in_array($finishreason, self::TRUNCATION_REASONS, true)) {
            return new self(self::TRUNCATED, $maxtokens, $detail);
        }
        return new self(self::INVALID_JSON, null, $detail);
    }
}
