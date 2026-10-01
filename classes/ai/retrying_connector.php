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
 * Decorator that retries a connector call with exponential backoff and jitter (spec 3.3, 3.8).
 *
 * Retried: rate limiting (429), server errors (500, 502, 503, 504), Anthropic's overloaded error
 * (529, see https://platform.claude.com/docs/en/api/errors) and network errors. Not retried: client
 * errors (400, 401, 402, 422 and any other 4xx that is not 429) and invalid JSON, which are not
 * going to change on a retry. When the provider sends a Retry-After header (seconds or an HTTP
 * date), that delay is used instead of the computed backoff.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retrying_connector implements connector {
    /** @var int Default maximum number of attempts (the first try plus retries). */
    public const DEFAULT_MAXATTEMPTS = 3;

    /** @var int Default base delay in milliseconds, doubled on every attempt. */
    public const DEFAULT_BASEDELAYMS = 500;

    /** @var int Upper bound of the computed backoff, before jitter, in milliseconds. */
    public const MAX_DELAYMS = 30_000;

    /**
     * Creates the decorator.
     *
     * @param connector $inner Connector whose calls are retried.
     * @param clock $clock Time source used for the backoff sleep.
     * @param int $maxattempts Maximum number of attempts, at least 1.
     * @param int $basedelayms Base delay in milliseconds, doubled on every attempt.
     */
    public function __construct(
        /** @var connector Connector whose calls are retried. */
        protected connector $inner,
        /** @var clock Time source used for the backoff sleep. */
        protected clock $clock = new system_clock(),
        /** @var int Maximum number of attempts, at least 1. */
        protected int $maxattempts = self::DEFAULT_MAXATTEMPTS,
        /** @var int Base delay in milliseconds, doubled on every attempt. */
        protected int $basedelayms = self::DEFAULT_BASEDELAYMS,
    ) {
        if ($this->maxattempts < 1) {
            throw new \coding_exception('maxattempts must be at least 1');
        }
    }

    #[\Override]
    public function complete(request $request): result {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->inner->complete($request);
            } catch (connector_exception $e) {
                if ($attempt >= $this->maxattempts || !self::is_retryable($e)) {
                    throw $e;
                }
                $this->clock->sleep(self::delay_for($attempt, $this->basedelayms, $e));
            }
        }
    }

    #[\Override]
    public function supports(string $capability): bool {
        return $this->inner->supports($capability);
    }

    #[\Override]
    public function estimate_cost(request $request): float {
        return $this->inner->estimate_cost($request);
    }

    #[\Override]
    public function count_tokens(string $text): int {
        return $this->inner->count_tokens($text);
    }

    /**
     * Tells whether a connector error is worth retrying.
     *
     * @param connector_exception $e The error.
     * @return bool
     */
    public static function is_retryable(connector_exception $e): bool {
        return match ($e->errorcode) {
            connector_exception::RATE_LIMITED, connector_exception::NETWORK_ERROR => true,
            connector_exception::HTTP_ERROR => in_array($e->httpstatus, [500, 502, 503, 504, 529], true),
            default => false,
        };
    }

    /**
     * Computes the delay before the next attempt: the provider's Retry-After if given, else
     * exponential backoff with jitter.
     *
     * @param int $attempt The attempt that just failed, 1-based.
     * @param int $basedelayms Base delay in milliseconds.
     * @param connector_exception $e The error of the attempt that just failed.
     * @return int Delay in milliseconds.
     */
    public static function delay_for(int $attempt, int $basedelayms, connector_exception $e): int {
        if ($e->retryafterms !== null) {
            return $e->retryafterms;
        }
        $backoff = min(self::MAX_DELAYMS, $basedelayms * (2 ** ($attempt - 1)));
        return random_int(0, $backoff);
    }
}
