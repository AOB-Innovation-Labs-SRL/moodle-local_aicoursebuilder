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
 * What a run of the pipeline produced, and why it stopped when it did.
 *
 * A run that stopped is not a run that lost its work: every step it finished is in local_aicb_step,
 * and running it again resumes from there. The outcome only says how far this attempt got.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pipeline_outcome {
    /** @var string The pipeline ran to the end. */
    public const REASON_COMPLETED = 'completed';

    /** @var string A cost limit was reached. */
    public const REASON_BUDGET = 'budget';

    /** @var string The brief could not be written. */
    public const REASON_BRIEF = 'brief';

    /** @var string The outline could not be written. */
    public const REASON_OUTLINE = 'outline';

    /**
     * Creates the outcome.
     *
     * @param string $reason One of the REASON_* constants.
     * @param array|null $blueprint The assembled blueprint, or null when there is none yet.
     * @param array|null $brief The confirmed brief, or null when the pipeline stopped before it.
     * @param string[] $manualnodes Ids of the sections a human has to complete.
     * @param validation_error[] $errors What the validator still finds in the blueprint.
     * @param array $totals Tokens and cost of the job so far, from step_store::totals().
     * @param string $message What stopped the pipeline, empty when nothing did.
     */
    public function __construct(
        /** @var string One of the REASON_* constants. */
        public readonly string $reason,
        /** @var array|null The assembled blueprint. */
        public readonly ?array $blueprint = null,
        /** @var array|null The confirmed brief. */
        public readonly ?array $brief = null,
        /** @var string[] Ids of the sections a human has to complete. */
        public readonly array $manualnodes = [],
        /** @var validation_error[] What the validator still finds in the blueprint. */
        public readonly array $errors = [],
        /** @var array Tokens and cost of the job so far. */
        public readonly array $totals = [],
        /** @var string What stopped the pipeline. */
        public readonly string $message = '',
    ) {
    }

    /**
     * Tells whether the pipeline ran to the end.
     *
     * @return bool
     */
    public function is_complete(): bool {
        return $this->reason === self::REASON_COMPLETED;
    }

    /**
     * Tells whether the blueprint can be handed to a teacher to review.
     *
     * A blueprint with nodes marked for a human is still reviewable: that is what the review step
     * is for. One the validator rejects is not.
     *
     * @return bool
     */
    public function is_reviewable(): bool {
        return $this->is_complete() && $this->errors === [];
    }

    /**
     * Returns the outcome of a run that reached the end.
     *
     * @param array $blueprint The assembled blueprint.
     * @param array $brief The confirmed brief.
     * @param string[] $manualnodes Ids of the sections a human has to complete.
     * @param validation_error[] $errors What the validator still finds in the blueprint.
     * @param array $totals Tokens and cost of the job so far.
     * @return self
     */
    public static function completed(
        array $blueprint,
        array $brief,
        array $manualnodes,
        array $errors,
        array $totals,
    ): self {
        return new self(
            reason: self::REASON_COMPLETED,
            blueprint: $blueprint,
            brief: $brief,
            manualnodes: $manualnodes,
            errors: $errors,
            totals: $totals,
        );
    }

    /**
     * Returns the outcome of a run that stopped early.
     *
     * @param string $reason One of the REASON_* constants.
     * @param string $message What stopped it.
     * @param array $totals Tokens and cost of the job so far.
     * @return self
     */
    public static function stopped(string $reason, string $message, array $totals = []): self {
        return new self(reason: $reason, totals: $totals, message: $message);
    }
}
