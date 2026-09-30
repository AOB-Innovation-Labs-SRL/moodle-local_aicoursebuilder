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
 * Immutable completion request sent to a connector.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request {
    /** @var string Per-document digest. */
    public const STEP_DIGEST = 'digest';

    /** @var string Course brief. */
    public const STEP_BRIEF = 'brief';

    /** @var string Course outline. */
    public const STEP_OUTLINE = 'outline';

    /** @var string Section content (page, book, label). */
    public const STEP_SECTIONS = 'sections';

    /** @var string Activities (lesson, assign, forum and the rest). */
    public const STEP_ACTIVITIES = 'activities';

    /** @var string Quiz questions. */
    public const STEP_QUESTIONS = 'questions';

    /** @var string Critic review of the full blueprint. */
    public const STEP_REVIEW = 'review';

    /** @var string JSON repair after a validation error. */
    public const STEP_REPAIR = 'repair';

    /** @var string[] All pipeline steps. */
    public const STEPS = [
        self::STEP_DIGEST,
        self::STEP_BRIEF,
        self::STEP_OUTLINE,
        self::STEP_SECTIONS,
        self::STEP_ACTIVITIES,
        self::STEP_QUESTIONS,
        self::STEP_REVIEW,
        self::STEP_REPAIR,
    ];

    /** @var string[] Allowed message roles. */
    public const ROLES = ['user', 'assistant'];

    /**
     * Creates the request.
     *
     * @param string $step Pipeline step, one of the STEP_* constants.
     * @param string $system System prompt.
     * @param array $messages Conversation, list of ['role' => user|assistant, 'content' => string].
     * @param array|null $schema JSON schema the output must follow, or null for free text.
     * @param \stored_file[] $files Files sent as input.
     * @param int $maxtokens Maximum output tokens, 0 for the connector default.
     * @param float|null $temperature Sampling temperature, null for the connector default.
     * @param int $timeout Timeout in seconds, 0 for the connector default.
     * @param int|null $jobid Job the call belongs to, used for logging.
     * @param int|null $userid User the call runs for (AI policy check, core_ai), null when unknown.
     * @param int|null $contextid Context the call runs in (core_ai), null for the system context.
     * @param bool $json Whether the output must be a JSON object even without a schema.
     */
    public function __construct(
        /** @var string Pipeline step. */
        public readonly string $step,
        /** @var string System prompt. */
        public readonly string $system,
        /** @var array Conversation messages. */
        public readonly array $messages,
        /** @var array|null JSON schema for the output. */
        public readonly ?array $schema = null,
        /** @var \stored_file[] Input files. */
        public readonly array $files = [],
        /** @var int Maximum output tokens. */
        public readonly int $maxtokens = 0,
        /** @var float|null Sampling temperature. */
        public readonly ?float $temperature = null,
        /** @var int Timeout in seconds. */
        public readonly int $timeout = 0,
        /** @var int|null Job id. */
        public readonly ?int $jobid = null,
        /** @var int|null User id. */
        public readonly ?int $userid = null,
        /** @var int|null Context id. */
        public readonly ?int $contextid = null,
        /** @var bool JSON object output without a schema. */
        public readonly bool $json = false,
    ) {
        if (!in_array($step, self::STEPS, true)) {
            throw new \coding_exception("Unknown AI step: {$step}");
        }
        foreach ($messages as $message) {
            if (
                !is_array($message) || !isset($message['role'], $message['content'])
                || !in_array($message['role'], self::ROLES, true) || !is_string($message['content'])
            ) {
                throw new \coding_exception('Each message needs a role (user or assistant) and a string content');
            }
        }
        foreach ($files as $file) {
            if (!$file instanceof \stored_file) {
                throw new \coding_exception('Request files must be stored_file instances');
            }
        }
        if ($maxtokens < 0 || $timeout < 0) {
            throw new \coding_exception('maxtokens and timeout cannot be negative');
        }
        if (($userid !== null && $userid <= 0) || ($contextid !== null && $contextid <= 0)) {
            throw new \coding_exception('userid and contextid must be positive when set');
        }
    }

    /**
     * Tells whether the output must be JSON (a schema is set or JSON was asked for).
     *
     * @return bool
     */
    public function wants_json(): bool {
        return $this->schema !== null || $this->json;
    }

    /**
     * Returns the whole input text (system prompt and messages), for token estimates.
     *
     * @return string
     */
    public function get_input_text(): string {
        $parts = [$this->system];
        foreach ($this->messages as $message) {
            $parts[] = $message['content'];
        }
        return implode("\n", $parts);
    }
}
