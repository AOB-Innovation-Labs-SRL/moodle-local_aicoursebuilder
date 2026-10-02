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

use local_aicoursebuilder\blueprint\validation_error;

/**
 * What one pipeline step, or one sub-call of it, produced.
 *
 * A result is final in one of three ways: it carries output, or it is marked for a human to
 * complete because repair ran out of attempts, or it failed outright. The first two let the
 * pipeline go on; only a failure of a step with no fallback stops the job (spec 3.6).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class step_result {
    /**
     * Creates the result.
     *
     * @param array|null $output What the step produced, or null when it failed.
     * @param bool $manual Whether the node is marked for a human to complete.
     * @param validation_error[] $errors Validation errors left when the node was given up on.
     * @param int $tokensin Input tokens spent, cache misses only.
     * @param int $tokensout Output tokens spent.
     * @param int $tokenscached Input tokens served from the prompt cache.
     * @param float $cost Cost in USD.
     * @param int $calls AI calls made, repair calls included; 0 when the step was resumed.
     * @param bool $resumed Whether the output came from a finished step instead of a new call.
     * @param string $error Message of the failure, empty when there was none.
     */
    public function __construct(
        /** @var array|null What the step produced. */
        public readonly ?array $output,
        /** @var bool Whether the node is marked for a human to complete. */
        public readonly bool $manual = false,
        /** @var validation_error[] Validation errors left when the node was given up on. */
        public readonly array $errors = [],
        /** @var int Input tokens spent. */
        public readonly int $tokensin = 0,
        /** @var int Output tokens spent. */
        public readonly int $tokensout = 0,
        /** @var int Cached input tokens. */
        public readonly int $tokenscached = 0,
        /** @var float Cost in USD. */
        public readonly float $cost = 0.0,
        /** @var int AI calls made. */
        public readonly int $calls = 0,
        /** @var bool Whether the output was resumed rather than generated. */
        public readonly bool $resumed = false,
        /** @var string Message of the failure. */
        public readonly string $error = '',
    ) {
    }

    /**
     * Tells whether the step produced usable output.
     *
     * @return bool
     */
    public function is_success(): bool {
        return $this->output !== null;
    }

    /**
     * Returns the same result marked as resumed, with its cost zeroed.
     *
     * A resumed step was paid for when it first ran, so counting its cost again would make a job
     * look more expensive every time it is restarted.
     *
     * @param array $output Output read back from the finished step.
     * @return self
     */
    public static function resumed(array $output): self {
        return new self(output: $output, resumed: true);
    }

    /**
     * Returns a result for a node nobody could produce, which a human has to complete.
     *
     * @param array $output Placeholder output, valid but empty of content.
     * @param validation_error[] $errors The errors that were left.
     * @param int $tokensin Input tokens spent trying.
     * @param int $tokensout Output tokens spent trying.
     * @param int $tokenscached Cached input tokens.
     * @param float $cost Cost spent trying, in USD.
     * @param int $calls Calls spent trying.
     * @param string $error Why the node needs a human, as failure_reason JSON.
     * @return self
     */
    public static function needs_manual(
        array $output,
        array $errors,
        int $tokensin = 0,
        int $tokensout = 0,
        int $tokenscached = 0,
        float $cost = 0.0,
        int $calls = 0,
        string $error = '',
    ): self {
        return new self(
            output: $output,
            manual: true,
            errors: $errors,
            tokensin: $tokensin,
            tokensout: $tokensout,
            tokenscached: $tokenscached,
            cost: $cost,
            calls: $calls,
            error: $error,
        );
    }

    /**
     * Returns a result for a step that could not be run at all.
     *
     * @param string $error Message of the failure.
     * @param int $calls Calls spent before giving up.
     * @param float $cost Cost spent before giving up, in USD.
     * @param validation_error[] $errors Validator errors of the last answer, for whoever started the step.
     * @return self
     */
    public static function failed(string $error, int $calls = 0, float $cost = 0.0, array $errors = []): self {
        return new self(output: null, errors: $errors, calls: $calls, cost: $cost, error: $error);
    }
}
