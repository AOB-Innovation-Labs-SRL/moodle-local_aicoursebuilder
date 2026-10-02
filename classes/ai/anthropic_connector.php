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
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Direct connector to the Anthropic Messages API.
 *
 * A request with a schema asks for native structured output (output_config.format), never forced
 * tool use: forced tool_choice (any or tool) is rejected with a 400 on several current models
 * (Opus 5.5, Sonnet 5.5, Fable 5.1, Mythos 5.1) and whenever manual extended thinking is enabled,
 * while structured outputs has none of those restrictions (see
 * https://platform.claude.com/docs/en/agents-and-tools/tool-use/define-tools#forcing-tool-use and
 * https://platform.claude.com/docs/en/build-with-claude/structured-outputs). Input files are sent as
 * document content blocks (PDF only); cache_control is placed on the system prompt and on the last
 * source document, so a repeated prefix of instructions and source material is served from cache.
 * Usage mapping: input_tokens (never cached) and cache_creation_input_tokens (written to the cache
 * on this call) both count as tokensin, since neither was served from the cache; cost splits them,
 * pricing cache_creation_input_tokens at the pricing table's optional cache_write rate (falls back
 * to input_miss) instead of input_miss, reflecting Anthropic's own, usually higher, cache-write
 * price. cache_read_input_tokens maps to tokenscached.
 *
 * Thinking defaults to omitted (the model's own default, which several current models always have
 * on and cannot turn off) rather than an explicit thinking: {type: disabled}: several current models
 * (Fable 5.1, Mythos 5.1, Fable 5, Mythos 5, Opus 5.5, Mythos Preview, and Sonnet 5.5, which instead
 * needs between_tools) reject that value outright with a 400 ("thinking cannot be disabled"), so it
 * is never sent; anthropic_thinking only ever adds thinking: {type: enabled} when turned on, never
 * turns it off. See https://platform.claude.com/docs/en/api/errors#thinking-cannot-be-disabled and
 * https://platform.claude.com/docs/en/api/messages.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anthropic_connector implements async_connector, connector {
    use token_estimator;

    /** @var string Connector name, used in settings and results. */
    public const NAME = 'anthropic';

    /** @var string Base URL of the API. */
    public const BASEURL = 'https://api.anthropic.com';

    /** @var string Path of the messages endpoint, relative to the base URL. */
    public const MESSAGES_PATH = '/v1/messages';

    /** @var string API version sent with every request (the only version currently documented). */
    public const API_VERSION = '2023-06-01';

    /** @var string Default model. */
    public const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

    /** @var int Default timeout in seconds. */
    public const DEFAULT_TIMEOUT = 300;

    /** @var int Output tokens requested when the request sets none; Anthropic requires max_tokens. */
    public const DEFAULT_MAXTOKENS = 8192;

    /** @var int Maximum length of the provider error detail kept in debug info. */
    public const ERROR_DETAIL_LENGTH = 500;

    /** @var string Media type of the only supported file input. */
    public const PDF_MEDIA_TYPE = 'application/pdf';

    /** @var string[] Capabilities implemented by this connector. */
    public const SUPPORTED = [
        connector::CAP_JSON_SCHEMA,
        connector::CAP_FILES_PDF,
        connector::CAP_PROMPT_CACHE,
        connector::CAP_LONG_OUTPUT,
    ];

    /** @var string Model used for the calls. */
    protected string $model;

    /** @var bool Whether extended thinking is enabled. */
    protected bool $thinking;

    /** @var schema_bundler Makes a request schema self-contained before it is sent. */
    protected schema_bundler $schemabundler;

    /**
     * Creates the connector from the plugin settings.
     *
     * @param string $model Model for the calls, empty for the anthropic_model setting.
     */
    public function __construct(string $model = '') {
        $config = get_config('local_aicoursebuilder');
        $this->model = trim($model) !== '' ? trim($model)
            : (trim((string) ($config->anthropic_model ?? '')) ?: self::DEFAULT_MODEL);
        $this->thinking = !empty($config->anthropic_thinking);
        $this->schemabundler = new schema_bundler();
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
     * Runs one Messages API call.
     *
     * @param request $request The completion request.
     * @return result
     * @throws connector_exception On policy, configuration, HTTP or JSON errors.
     */
    public function complete(request $request): result {
        $apikey = $this->validate($request);

        $start = hrtime(true);
        try {
            $response = \core\di::get(\core\http_client::class)->request('POST', self::BASEURL . self::MESSAGES_PATH, [
                'headers' => $this->headers($apikey),
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
     * Sends one Messages API call asynchronously, through the client from the DI container.
     *
     * @param request $request The completion request.
     * @return PromiseInterface Promise of a result, rejected with a connector_exception on failure.
     */
    public function complete_async(request $request): PromiseInterface {
        try {
            $apikey = $this->validate($request);
        } catch (connector_exception $e) {
            return Create::rejectionFor($e);
        }

        $start = hrtime(true);
        return \core\di::get(\core\http_client::class)->requestAsync('POST', self::BASEURL . self::MESSAGES_PATH, [
            'headers' => $this->headers($apikey),
            'body' => json_encode($this->build_body($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'timeout' => $request->timeout ?: self::DEFAULT_TIMEOUT,
            'http_errors' => false,
        ])->then(
            function (ResponseInterface $response) use ($request, $start): result {
                $durationms = (int) round((hrtime(true) - $start) / 1e6);
                return $this->parse_response($request, $response, $durationms);
            },
            function (\Throwable $e): PromiseInterface {
                if ($e instanceof connector_exception) {
                    return Create::rejectionFor($e);
                }
                return Create::rejectionFor(new connector_exception(
                    connector_exception::NETWORK_ERROR,
                    null,
                    get_class($e) . ': ' . $e->getMessage()
                ));
            }
        );
    }

    /**
     * Checks the policy, the input and the configuration, and returns the decrypted API key.
     *
     * @param request $request The completion request.
     * @return string The API key.
     * @throws connector_exception When the input is unsupported or the connector is not configured.
     */
    protected function validate(request $request): string {
        policy::require_accepted($request);
        foreach ($request->files as $file) {
            if ($file->get_mimetype() !== self::PDF_MEDIA_TYPE) {
                throw new connector_exception(connector_exception::UNSUPPORTED, 'files:' . $file->get_mimetype());
            }
        }
        $apikey = \core\encryption::decrypt((string) get_config('local_aicoursebuilder', 'anthropic_apikey'));
        if ($apikey === '') {
            throw new connector_exception(connector_exception::NOT_CONFIGURED, self::NAME);
        }
        return $apikey;
    }

    /**
     * Returns the HTTP headers of the messages call.
     *
     * @param string $apikey The decrypted API key.
     * @return array
     */
    protected function headers(string $apikey): array {
        return [
            'x-api-key' => $apikey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
    }

    /**
     * Builds the JSON body of the messages call.
     *
     * @param request $request The completion request.
     * @return array
     */
    protected function build_body(request $request): array {
        $body = [
            'model' => $this->model,
            'max_tokens' => $request->maxtokens > 0 ? $request->maxtokens : self::DEFAULT_MAXTOKENS,
            'messages' => $this->build_messages($request),
        ];
        if ($request->system !== '') {
            $body['system'] = [
                ['type' => 'text', 'text' => $request->system, 'cache_control' => ['type' => 'ephemeral']],
            ];
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($this->thinking) {
            $body['thinking'] = ['type' => 'enabled'];
        }
        if ($request->schema !== null) {
            $body['output_config'] = ['format' => [
                'type' => 'json_schema',
                'schema' => $this->schemabundler->bundle($request->schema) ?: ['type' => 'object'],
            ]];
        }
        return $body;
    }

    /**
     * Builds the messages array: source files as document blocks before the conversation, the
     * cache_control of the source prefix on the last block (file, or system when there is none).
     *
     * @param request $request The completion request.
     * @return array
     */
    protected function build_messages(request $request): array {
        $messages = [];
        if ($request->files) {
            $blocks = [];
            $last = array_key_last($request->files);
            foreach ($request->files as $key => $file) {
                $block = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => self::PDF_MEDIA_TYPE,
                        'data' => base64_encode($file->get_content()),
                    ],
                ];
                if ($key === $last) {
                    $block['cache_control'] = ['type' => 'ephemeral'];
                }
                $blocks[] = $block;
            }
            $messages[] = ['role' => 'user', 'content' => $blocks];
        }
        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }
        return $messages;
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
        $raw = trim((string) $response->getBody());
        $data = json_decode($raw, true);

        if ($status === 429) {
            throw new connector_exception(
                connector_exception::RATE_LIMITED,
                null,
                $this->error_detail($data, $raw),
                $status,
                $this->retry_after_ms($response)
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new connector_exception(
                connector_exception::HTTP_ERROR,
                $status,
                $this->error_detail($data, $raw),
                $status,
                $this->retry_after_ms($response)
            );
        }
        if (!is_array($data) || !isset($data['content']) || !is_array($data['content'])) {
            throw new connector_exception(connector_exception::INVALID_JSON, null, 'Response body is not a message');
        }

        $stopreason = (string) ($data['stop_reason'] ?? '');
        $content = $this->extract_content($data['content']);
        $json = null;
        if ($request->wants_json()) {
            $json = json_decode($content, true);
            if (!is_array($json)) {
                throw new connector_exception(
                    connector_exception::INVALID_JSON,
                    null,
                    "stop_reason={$stopreason}, content length=" . strlen($content)
                );
            }
        }

        $usage = $data['usage'] ?? [];
        $cachewrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $uncached = (int) ($usage['input_tokens'] ?? 0);
        // The tokensin field keeps the project convention (every input token not served from the
        // cache), while the cost split keeps cache-write tokens separate, so they price at the
        // pricing table's optional cache_write rate instead of input_miss when set (spec: the
        // mapping of each provider's usage fields is connector-specific and documented here).
        $tokensin = $uncached + $cachewrite;
        $tokenscached = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $model = (string) ($data['model'] ?? $this->model);
        $tokensout = (int) ($usage['output_tokens'] ?? 0);

        return new result(
            content: $content,
            json: $json,
            tokensin: $tokensin,
            tokensout: $tokensout,
            tokenscached: $tokenscached,
            cost: pricing::cost_for(self::NAME, $model, $uncached, $tokensout, $tokenscached, tokenscachewrite: $cachewrite),
            model: $model,
            durationms: $durationms,
            finishreason: $stopreason,
            connector: self::NAME,
        );
    }

    /**
     * Returns the model output: the text of the first text content block.
     *
     * @param array $blocks The response content blocks.
     * @return string
     */
    protected function extract_content(array $blocks): string {
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }
        return '';
    }

    /**
     * Parses the Retry-After header of a response, in milliseconds.
     *
     * @param ResponseInterface $response The HTTP response.
     * @return int|null The delay in milliseconds, or null when the header is absent or unparsable.
     */
    protected function retry_after_ms(ResponseInterface $response): ?int {
        $header = trim($response->getHeaderLine('Retry-After'));
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return max(0, (int) $header) * 1000;
        }
        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }
        return max(0, ($timestamp - time()) * 1000);
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
     * Estimates the cost of a request before sending it.
     *
     * The whole input is treated as a cache miss and the output is estimated from maxtokens, both
     * worst-case for a budget reservation made before the call.
     *
     * @param request $request The completion request.
     * @return float Estimated cost in USD.
     */
    public function estimate_cost(request $request): float {
        $tokensin = $this->count_tokens($request->get_input_text());
        $tokensout = $request->maxtokens > 0 ? $request->maxtokens : self::DEFAULT_MAXTOKENS;
        return pricing::cost_for(self::NAME, $this->model, $tokensin, $tokensout, 0);
    }
}
