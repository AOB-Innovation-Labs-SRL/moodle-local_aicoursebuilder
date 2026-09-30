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

use core_ai\aiactions\generate_text;

/**
 * Connector that runs requests through the Moodle AI subsystem (core_ai generate_text).
 *
 * generate_text takes one prompt text and uses the system instruction of the provider, so the
 * request system prompt, the schema instruction and the messages are joined into the prompt text.
 * The provider and the model are chosen by the site administrator in the AI settings.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coreai_connector implements connector {
    use token_estimator;

    /** @var string Connector name, used in settings and results. */
    public const NAME = 'coreai';

    /** @var string Instruction added to the prompt when a JSON schema is set, followed by the schema. */
    public const SCHEMA_INSTRUCTION = 'Respond only with one JSON object that follows this JSON schema, without any other text:';

    /** @var string Instruction added to the prompt when JSON is asked for without schema. */
    public const JSON_INSTRUCTION = 'Respond only with one valid JSON object, without any other text.';

    /** @var string[] Labels of the message roles when the conversation has more than one message. */
    public const ROLE_LABELS = ['user' => 'User', 'assistant' => 'Assistant'];

    /**
     * Runs one generate_text action through the core_ai manager.
     *
     * @param request $request The completion request.
     * @return result
     * @throws connector_exception On policy errors, unsupported input or core_ai errors.
     */
    public function complete(request $request): result {
        $userid = policy::require_accepted($request);
        if ($request->files) {
            throw new connector_exception(connector_exception::UNSUPPORTED, 'files');
        }

        $action = new generate_text(
            contextid: $request->contextid ?? \context_system::instance()->id,
            userid: $userid,
            prompttext: $this->build_prompt_text($request),
        );
        $start = hrtime(true);
        $response = \core\di::get(\core_ai\manager::class)->process_action($action);
        $durationms = (int) round((hrtime(true) - $start) / 1e6);

        if (!$response->get_success()) {
            $errorcode = $response->get_errorcode();
            throw new connector_exception(
                connector_exception::CORE_AI_ERROR,
                $response->get_errormessage(),
                $errorcode . ' ' . $response->get_error(),
                $errorcode >= 400 && $errorcode < 600 ? $errorcode : 0
            );
        }

        $data = $response->get_response_data();
        $content = trim((string) ($data['generatedcontent'] ?? ''));
        $json = $request->wants_json() ? $this->decode_json($content) : null;

        return new result(
            content: $content,
            json: $json,
            tokensin: (int) ($data['prompttokens'] ?? 0),
            tokensout: (int) ($data['completiontokens'] ?? 0),
            tokenscached: 0,
            cost: 0.0,
            model: (string) ($response->get_model_used() ?? $data['model'] ?? ''),
            durationms: $durationms,
            finishreason: (string) ($data['finishreason'] ?? ''),
            connector: self::NAME,
        );
    }

    /**
     * Joins the system prompt, the JSON instruction and the messages into one prompt text.
     *
     * @param request $request The completion request.
     * @return string
     */
    public function build_prompt_text(request $request): string {
        $parts = [];
        if ($request->system !== '') {
            $parts[] = $request->system;
        }
        if ($request->schema !== null) {
            $parts[] = self::SCHEMA_INSTRUCTION . "\n"
                . json_encode($request->schema ?: ['type' => 'object'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else if ($request->json) {
            $parts[] = self::JSON_INSTRUCTION;
        }
        if (count($request->messages) === 1 && $request->messages[0]['role'] === 'user') {
            $parts[] = $request->messages[0]['content'];
        } else {
            foreach ($request->messages as $message) {
                $parts[] = self::ROLE_LABELS[$message['role']] . ":\n" . $message['content'];
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Decodes a JSON answer, tolerating a Markdown code fence around it.
     *
     * @param string $content The generated text.
     * @return array|null The decoded JSON, or null when the text is not a JSON object or array.
     */
    protected function decode_json(string $content): ?array {
        if (preg_match('/^\x60{3}(?:json)?\s*(.*?)\s*\x60{3}$/si', $content, $matches)) {
            $content = $matches[1];
        }
        $json = json_decode($content, true);
        return is_array($json) ? $json : null;
    }

    /**
     * No capability is guaranteed: JSON is asked for through instructions only.
     *
     * @param string $capability One of the connector::CAP_* constants.
     * @return bool Always false.
     */
    public function supports(string $capability): bool {
        return false;
    }

    /**
     * Cost is billed by the core_ai provider and not known here.
     *
     * @param request $request The completion request.
     * @return float Always 0.0.
     */
    public function estimate_cost(request $request): float {
        return 0.0;
    }
}
