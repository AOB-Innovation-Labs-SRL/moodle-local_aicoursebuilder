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
 * Direct connector to one configured OpenAI-compatible /v1/chat/completions endpoint.
 *
 * One instance, one base URL (spec 3.3 MVP): the admin points it at OpenAI, Azure OpenAI, Ollama,
 * vLLM, LiteLLM or OpenRouter, all of which accept the same request shape and differ only in base
 * URL, the authentication header and, for Azure, an api-version query parameter:
 * - OpenAI: https://api.openai.com/v1/chat/completions, Authorization: Bearer.
 * - Azure OpenAI: https://{resource}.openai.azure.com/openai/deployments/{deployment}/chat/completions
 *   (the configured base URL already includes the deployment path), header api-key, query
 *   ?api-version=YYYY-MM-DD. See https://learn.microsoft.com/azure/ai-services/openai/reference.
 * - Ollama, vLLM, LiteLLM, OpenRouter: {base}/v1/chat/completions, Authorization: Bearer (vLLM and
 *   Ollama accept any value when the server enforces no key).
 * The admin also declares, per instance, which capabilities it actually offers (json_schema,
 * vision): a generic OpenAI-compatible server cannot be queried for this. With json_schema enabled,
 * schema goes through response_format: {type: json_schema, json_schema: {name, schema}} (no strict
 * flag, matching the forced-but-non-strict approach used for every other connector here); without
 * it, only response_format: json_object is used when the request asks for JSON without a schema.
 * Prompt caching, PDF files and batch are not offered by a generic endpoint in this MVP.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openaicompat_connector implements async_connector, connector {
    use token_estimator;

    /** @var string Connector name, used in settings and results. */
    public const NAME = 'openaicompat';

    /** @var string Path of the chat completions endpoint, relative to the base URL. */
    public const CHAT_PATH = '/chat/completions';

    /** @var string Authentication header is Authorization: Bearer <key>. */
    public const AUTHTYPE_BEARER = 'bearer';

    /** @var string Authentication header is api-key: <key> (Azure OpenAI). */
    public const AUTHTYPE_APIKEY = 'apikey';

    /** @var string[] Allowed values of the openaicompat_authtype setting. */
    public const AUTHTYPES = [self::AUTHTYPE_BEARER, self::AUTHTYPE_APIKEY];

    /** @var int Default timeout in seconds. */
    public const DEFAULT_TIMEOUT = 300;

    /** @var string Prefix of the tool name used for schema output. */
    public const SCHEMA_NAME_PREFIX = 'emit_';

    /** @var int Maximum length of the provider error detail kept in debug info. */
    public const ERROR_DETAIL_LENGTH = 500;

    /** @var string Base URL of the configured instance. */
    protected string $baseurl;

    /** @var string Model used for the calls. */
    protected string $model;

    /** @var string One of the AUTHTYPE_* constants. */
    protected string $authtype;

    /** @var string Azure-style api-version query parameter, empty when not set. */
    protected string $apiversion;

    /** @var bool Whether this instance supports response_format json_schema. */
    protected bool $supportsjsonschema;

    /** @var bool Whether this instance supports image input. */
    protected bool $supportsvision;

    /** @var schema_bundler Makes a request schema self-contained before it is sent. */
    protected schema_bundler $schemabundler;

    /**
     * Creates the connector from the plugin settings.
     *
     * @param string $model Model for the calls, empty for the openaicompat_model setting.
     */
    public function __construct(string $model = '') {
        $config = get_config('local_aicoursebuilder');
        $this->baseurl = rtrim(trim((string) ($config->openaicompat_baseurl ?? '')), '/');
        $this->model = trim($model) !== '' ? trim($model) : trim((string) ($config->openaicompat_model ?? ''));
        $authtype = trim((string) ($config->openaicompat_authtype ?? ''));
        $this->authtype = in_array($authtype, self::AUTHTYPES, true) ? $authtype : self::AUTHTYPE_BEARER;
        $this->apiversion = trim((string) ($config->openaicompat_apiversion ?? ''));
        $this->supportsjsonschema = !empty($config->openaicompat_supports_json_schema);
        $this->supportsvision = !empty($config->openaicompat_supports_vision);
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
     * Runs one chat completion call.
     *
     * @param request $request The completion request.
     * @return result
     * @throws connector_exception On policy, configuration, HTTP or JSON errors.
     */
    public function complete(request $request): result {
        $apikey = $this->validate($request);

        $start = hrtime(true);
        try {
            $response = \core\di::get(\core\http_client::class)->request('POST', $this->endpoint(), [
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
     * Sends one chat completion call asynchronously, through the client from the DI container.
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
        return \core\di::get(\core\http_client::class)->requestAsync('POST', $this->endpoint(), [
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
     * The API key itself may legitimately be empty (a local Ollama/vLLM server enforcing none), so
     * only the base URL and the model are required configuration.
     *
     * @param request $request The completion request.
     * @return string The API key, possibly empty.
     * @throws connector_exception When the input is unsupported or the connector is not configured.
     */
    protected function validate(request $request): string {
        policy::require_accepted($request);
        if ($request->files) {
            throw new connector_exception(connector_exception::UNSUPPORTED, 'files');
        }
        if ($this->baseurl === '' || $this->model === '') {
            throw new connector_exception(connector_exception::NOT_CONFIGURED, self::NAME);
        }
        return \core\encryption::decrypt((string) get_config('local_aicoursebuilder', 'openaicompat_apikey'));
    }

    /**
     * Returns the chat completions endpoint URL, with the api-version query parameter when set.
     *
     * @return string
     */
    protected function endpoint(): string {
        $url = $this->baseurl . self::CHAT_PATH;
        if ($this->apiversion !== '') {
            $url .= '?' . http_build_query(['api-version' => $this->apiversion]);
        }
        return $url;
    }

    /**
     * Returns the HTTP headers of the chat completions call.
     *
     * @param string $apikey The decrypted API key.
     * @return array
     */
    protected function headers(string $apikey): array {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($apikey !== '') {
            $headers += $this->authtype === self::AUTHTYPE_APIKEY
                ? ['api-key' => $apikey]
                : ['Authorization' => 'Bearer ' . $apikey];
        }
        return $headers;
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

        $body = ['model' => $this->model, 'messages' => $messages];
        if ($request->maxtokens > 0) {
            $body['max_tokens'] = $request->maxtokens;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->schema !== null && $this->supportsjsonschema) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => [
                'name' => self::SCHEMA_NAME_PREFIX . $request->step,
                'schema' => $this->schemabundler->bundle($request->schema) ?: ['type' => 'object'],
            ]];
        } else if ($request->wants_json()) {
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
        if (!is_array($data) || !isset($data['choices'][0]) || !is_array($data['choices'][0])) {
            throw new connector_exception(connector_exception::INVALID_JSON, null, 'Response body is not a chat completion');
        }

        $choice = $data['choices'][0];
        $finishreason = (string) ($choice['finish_reason'] ?? '');
        $content = (string) ($choice['message']['content'] ?? '');
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
        $model = (string) ($data['model'] ?? $this->model);
        $tokensin = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensout = (int) ($usage['completion_tokens'] ?? 0);

        return new result(
            content: $content,
            json: $json,
            tokensin: $tokensin,
            tokensout: $tokensout,
            tokenscached: 0,
            cost: pricing::cost_for(self::NAME, $model, $tokensin, $tokensout, 0),
            model: $model,
            durationms: $durationms,
            finishreason: $finishreason,
            connector: self::NAME,
        );
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
     * json_schema and vision depend on what the admin declared for this instance; prompt_cache,
     * files_pdf and batch are not offered by a generic OpenAI-compatible endpoint in this MVP.
     *
     * @param string $capability One of the connector::CAP_* constants.
     * @return bool
     */
    public function supports(string $capability): bool {
        return match ($capability) {
            connector::CAP_JSON_SCHEMA => $this->supportsjsonschema,
            connector::CAP_VISION => $this->supportsvision,
            default => false,
        };
    }

    /**
     * Estimates the cost of a request before sending it.
     *
     * @param request $request The completion request.
     * @return float Estimated cost in USD.
     */
    public function estimate_cost(request $request): float {
        $tokensin = $this->count_tokens($request->get_input_text());
        $tokensout = $request->maxtokens > 0 ? $request->maxtokens : $tokensin;
        return pricing::cost_for(self::NAME, $this->model, $tokensin, $tokensout, 0);
    }
}
