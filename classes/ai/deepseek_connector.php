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

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Direct connector to the DeepSeek chat completions API (OpenAI-compatible).
 *
 * A request with a schema is sent as one forced, non-strict tool call whose parameters are the
 * schema; a JSON request without schema uses response_format json_object; anything else is free
 * text. See https://api-docs.deepseek.com/.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deepseek_connector implements connector {
    use token_estimator;

    /** @var string Connector name, used in settings and results. */
    public const NAME = 'deepseek';

    /** @var string Default base URL of the API, without /v1. */
    public const DEFAULT_BASEURL = 'https://api.deepseek.com';

    /** @var string Default model. */
    public const DEFAULT_MODEL = 'deepseek-flash';

    /** @var string Path of the chat completions endpoint, relative to the base URL. */
    public const CHAT_PATH = '/chat/completions';

    /** @var int Default timeout in seconds. */
    public const DEFAULT_TIMEOUT = 300;

    /** @var string Prefix of the tool name used for schema output, followed by the step. */
    public const TOOL_PREFIX = 'emit_';

    /** @var int Maximum length of the provider error detail kept in debug info. */
    public const ERROR_DETAIL_LENGTH = 500;

    /** @var string[] Capabilities implemented by this connector. */
    public const SUPPORTED = [
        connector::CAP_JSON_SCHEMA,
        connector::CAP_PROMPT_CACHE,
        connector::CAP_LONG_OUTPUT,
    ];

    /** @var string Model used for the calls. */
    protected string $model;

    /** @var string Base URL of the API. */
    protected string $baseurl;

    /** @var bool Whether thinking mode is enabled. */
    protected bool $thinking;

    /**
     * Creates the connector from the plugin settings.
     *
     * @param string $model Model for the calls, empty for the deepseek_model setting.
     */
    public function __construct(string $model = '') {
        $config = get_config('local_aicoursebuilder');
        $this->model = trim($model) !== '' ? trim($model)
            : (trim((string) ($config->deepseek_model ?? '')) ?: self::DEFAULT_MODEL);
        $this->baseurl = rtrim(trim((string) ($config->deepseek_baseurl ?? '')) ?: self::DEFAULT_BASEURL, '/');
        $this->thinking = !empty($config->deepseek_thinking);
    }

    /**
     * Returns the model used for the calls.
     *
     * @return string
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * Runs one chat completion call.
     *
     * @param request $request The completion request.
     * @return result
     * @throws connector_exception On policy, configuration, HTTP or JSON errors.
     */
    public function complete(request $request): result {
        policy::require_accepted($request);
        if ($request->files) {
            throw new connector_exception(connector_exception::UNSUPPORTED, 'files');
        }
        $apikey = \core\encryption::decrypt((string) get_config('local_aicoursebuilder', 'deepseek_apikey'));
        if ($apikey === '') {
            throw new connector_exception(connector_exception::NOT_CONFIGURED, self::NAME);
        }

        $start = hrtime(true);
        try {
            $response = \core\di::get(\core\http_client::class)->request('POST', $this->baseurl . self::CHAT_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apikey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($this->build_body($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'timeout' => $request->timeout ?: self::DEFAULT_TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new connector_exception(connector_exception::NETWORK_ERROR, null, get_class($e) . ': ' . $e->getMessage());
        }
        $durationms = (int) round((hrtime(true) - $start) / 1e6);

        return $this->parse_response($request, $response, $durationms);
    }

    /**
     * Builds the JSON body of the chat completions call.
     *
     * @param request $request The completion request.
     * @return array
     */
    protected function build_body(request $request): array {
        $messages = [];
        if ($request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'thinking' => ['type' => $this->thinking ? 'enabled' : 'disabled'],
        ];
        if ($request->maxtokens > 0) {
            $body['max_tokens'] = $request->maxtokens;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->schema !== null) {
            $name = self::TOOL_PREFIX . $request->step;
            $body['tools'] = [[
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => "Returns the {$request->step} result as JSON that follows the parameters schema.",
                    'parameters' => $request->schema ?: ['type' => 'object'],
                ],
            ]];
            $body['tool_choice'] = ['type' => 'function', 'function' => ['name' => $name]];
        } else if ($request->json) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        return $body;
    }

    /**
     * Turns the HTTP response into a result, or throws.
     *
     * @param request $request The completion request.
     * @param ResponseInterface $response The HTTP response.
     * @param int $durationms Call duration in milliseconds.
     * @return result
     * @throws connector_exception On HTTP or JSON errors.
     */
    protected function parse_response(request $request, ResponseInterface $response, int $durationms): result {
        $status = $response->getStatusCode();
        // Non-streaming requests may receive empty keep-alive lines before the JSON body.
        $raw = trim((string) $response->getBody());
        $data = json_decode($raw, true);

        if ($status === 429) {
            throw new connector_exception(connector_exception::RATE_LIMITED, null, $this->error_detail($data, $raw), $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new connector_exception(connector_exception::HTTP_ERROR, $status, $this->error_detail($data, $raw), $status);
        }
        if (!is_array($data) || !isset($data['choices'][0]) || !is_array($data['choices'][0])) {
            throw new connector_exception(connector_exception::INVALID_JSON, null, 'Response body is not a chat completion');
        }

        $choice = $data['choices'][0];
        $finishreason = (string) ($choice['finish_reason'] ?? '');
        $content = $this->extract_content($request, $choice['message'] ?? []);
        $json = null;
        if ($request->wants_json()) {
            $json = json_decode($content, true);
            if (!is_array($json)) {
                throw new connector_exception(
                    connector_exception::INVALID_JSON,
                    null,
                    "finish_reason={$finishreason}, content length=" . strlen($content)
                );
            }
        }

        $usage = $data['usage'] ?? [];
        $cached = (int) ($usage['prompt_cache_hit_tokens'] ?? 0);
        $uncached = isset($usage['prompt_cache_miss_tokens'])
            ? (int) $usage['prompt_cache_miss_tokens']
            : max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cached);

        return new result(
            content: $content,
            json: $json,
            tokensin: $uncached,
            tokensout: (int) ($usage['completion_tokens'] ?? 0),
            tokenscached: $cached,
            cost: 0.0,
            model: (string) ($data['model'] ?? $this->model),
            durationms: $durationms,
            finishreason: $finishreason,
            connector: self::NAME,
        );
    }

    /**
     * Returns the model output: the tool arguments for a schema request, the message content otherwise.
     *
     * @param request $request The completion request.
     * @param mixed $message The choice message.
     * @return string
     */
    protected function extract_content(request $request, mixed $message): string {
        if (!is_array($message)) {
            return '';
        }
        if ($request->schema !== null) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                if (($call['function']['name'] ?? '') === self::TOOL_PREFIX . $request->step) {
                    return (string) ($call['function']['arguments'] ?? '');
                }
            }
        }
        return (string) ($message['content'] ?? '');
    }

    /**
     * Returns the provider error message for debug info, truncated.
     *
     * @param mixed $data Decoded response body.
     * @param string $raw Raw response body.
     * @return string
     */
    protected function error_detail(mixed $data, string $raw): string {
        $detail = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : $raw;
        return \core_text::substr($detail, 0, self::ERROR_DETAIL_LENGTH);
    }

    /**
     * Tells whether the connector supports a capability.
     *
     * @param string $capability One of the connector::CAP_* constants.
     * @return bool
     */
    public function supports(string $capability): bool {
        return in_array($capability, self::SUPPORTED, true);
    }

    /**
     * Estimates the cost of a request.
     *
     * @param request $request The completion request.
     * @return float Always 0.0 until pricing is implemented.
     */
    public function estimate_cost(request $request): float {
        // Pricing per model and peak/off-peak window is added by task 1.2 (pricing).
        return 0.0;
    }
}
