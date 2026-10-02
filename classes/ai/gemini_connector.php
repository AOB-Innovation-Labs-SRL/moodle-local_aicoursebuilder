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
 * Direct connector to the Gemini API (Google AI Studio).
 *
 * Schema is sent one of two ways, chosen by the gemini_native_json_schema setting:
 * - generationConfig.responseJsonSchema: standard JSON Schema, unconverted, but documented only for
 *   Gemini 2.5 and later and with a confusing, self-referential field description in the official
 *   API reference (see the sources below) — not safe as the unconditional default across whatever
 *   model an admin configures.
 * - generationConfig.responseSchema (the default): only a documented OpenAPI 3.0 subset of JSON
 *   Schema, works on every Gemini model; gemini_schema_transformer inlines $refs and drops what that
 *   subset does not support.
 * Our own validator is the real check on every answer regardless of which path produced it. Input
 * files are sent inline (PDF only, base64 inside the request, no File API). Gemini has no explicit
 * prompt_cache API in scope here (spec 3.3: only the provider's own implicit caching, reported
 * through usageMetadata.cachedContentTokenCount).
 *
 * responseJsonSchema could not be confirmed from Google's prose guides alone (repeated fetches of
 * https://ai.google.dev/gemini-api/docs/structured-output and
 * https://firebase.google.com/docs/ai-logic/generate-structured-output found no mention of it,
 * contradicting several independent sources that describe and use it); its existence is corroborated
 * instead by the Google AI forum thread quoting the actual API reference's field description
 * (https://discuss.ai.google.dev/t/error-in-api-reference-doco-re-generationconfig-responsejsonschema-structured-output/125182)
 * and by independent third-party SDK integrations (Vercel AI SDK, python-genai, langchain4j) that all
 * describe and ship it consistently. See https://ai.google.dev/api/generate-content for the rest.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_connector implements async_connector, connector {
    use token_estimator;

    /** @var string Connector name, used in settings and results. */
    public const NAME = 'gemini';

    /** @var string Base URL of the API. */
    public const BASEURL = 'https://generativelanguage.googleapis.com';

    /** @var string Path template of the generateContent endpoint, %s is the model. */
    public const GENERATE_PATH = '/v1beta/models/%s:generateContent';

    /** @var int Default timeout in seconds. */
    public const DEFAULT_TIMEOUT = 300;

    /** @var int Maximum length of the provider error detail kept in debug info. */
    public const ERROR_DETAIL_LENGTH = 500;

    /** @var string Media type of the only supported file input. */
    public const PDF_MEDIA_TYPE = 'application/pdf';

    /** @var string[] Capabilities implemented by this connector. */
    public const SUPPORTED = [
        connector::CAP_JSON_SCHEMA,
        connector::CAP_FILES_PDF,
        connector::CAP_VISION,
        connector::CAP_PROMPT_CACHE,
        connector::CAP_LONG_OUTPUT,
    ];

    /** @var string Model used for the calls. */
    protected string $model;

    /** @var bool Whether thinking mode is enabled. */
    protected bool $thinking;

    /** @var bool Whether to send the schema unconverted, through responseJsonSchema. */
    protected bool $nativejsonschema;

    /** @var schema_bundler Makes a request schema self-contained before it is sent. */
    protected schema_bundler $schemabundler;

    /** @var gemini_schema_transformer Turns a request schema into Gemini's responseSchema subset. */
    protected gemini_schema_transformer $schematransformer;

    /**
     * Creates the connector from the plugin settings.
     *
     * No model is hardcoded as a fallback: the official docs consulted for this connector did not
     * confirm a current default model name, so an empty gemini_model setting is a configuration
     * error rather than a silent guess.
     *
     * @param string $model Model for the calls, empty for the gemini_model setting.
     */
    public function __construct(string $model = '') {
        $config = get_config('local_aicoursebuilder');
        $this->model = trim($model) !== '' ? trim($model) : trim((string) ($config->gemini_model ?? ''));
        $this->thinking = !empty($config->gemini_thinking);
        $this->nativejsonschema = !empty($config->gemini_native_json_schema);
        $this->schematransformer = new gemini_schema_transformer();
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
     * Runs one generateContent call.
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
     * Sends one generateContent call asynchronously, through the client from the DI container.
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
        if ($this->model === '') {
            throw new connector_exception(connector_exception::NOT_CONFIGURED, self::NAME);
        }
        $apikey = \core\encryption::decrypt((string) get_config('local_aicoursebuilder', 'gemini_apikey'));
        if ($apikey === '') {
            throw new connector_exception(connector_exception::NOT_CONFIGURED, self::NAME);
        }
        return $apikey;
    }

    /**
     * Returns the generateContent endpoint URL for the configured model.
     *
     * @return string
     */
    protected function endpoint(): string {
        return self::BASEURL . sprintf(self::GENERATE_PATH, rawurlencode($this->model));
    }

    /**
     * Returns the HTTP headers of the generateContent call.
     *
     * @param string $apikey The decrypted API key.
     * @return array
     */
    protected function headers(string $apikey): array {
        return [
            'x-goog-api-key' => $apikey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Builds the JSON body of the generateContent call.
     *
     * @param request $request The completion request.
     * @return array
     */
    protected function build_body(request $request): array {
        $body = ['contents' => $this->build_contents($request)];
        if ($request->system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }

        $generationconfig = [];
        if ($request->schema !== null) {
            $generationconfig['responseMimeType'] = 'application/json';
            if ($this->nativejsonschema) {
                $generationconfig['responseJsonSchema'] = $this->schemabundler->bundle($request->schema);
            } else {
                $generationconfig['responseSchema'] = $this->schematransformer->transform($request->schema);
            }
        } else if ($request->json) {
            $generationconfig['responseMimeType'] = 'application/json';
        }
        if ($request->maxtokens > 0) {
            $generationconfig['maxOutputTokens'] = $request->maxtokens;
        }
        if ($request->temperature !== null) {
            $generationconfig['temperature'] = $request->temperature;
        }
        if (!$this->thinking) {
            $generationconfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $body['generationConfig'] = $generationconfig;
        return $body;
    }

    /**
     * Builds the contents array: source files as inline parts of the first user turn, then the
     * conversation.
     *
     * @param request $request The completion request.
     * @return array
     */
    protected function build_contents(request $request): array {
        $contents = [];
        $messages = $request->messages;

        if ($request->files) {
            $parts = [];
            foreach ($request->files as $file) {
                $parts[] = ['inlineData' => [
                    'mimeType' => self::PDF_MEDIA_TYPE,
                    'data' => base64_encode($file->get_content()),
                ]];
            }
            if ($messages && $messages[0]['role'] === 'user') {
                $parts[] = ['text' => $messages[0]['content']];
                array_shift($messages);
            }
            $contents[] = ['role' => 'user', 'parts' => $parts];
        }

        foreach ($messages as $message) {
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ];
        }
        return $contents;
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
        if (!is_array($data) || !isset($data['candidates'][0]) || !is_array($data['candidates'][0])) {
            throw new connector_exception(connector_exception::INVALID_JSON, null, 'Response body is not a generateContent answer');
        }

        $candidate = $data['candidates'][0];
        $finishreason = (string) ($candidate['finishReason'] ?? '');
        $content = $this->extract_content($candidate);
        $json = null;
        if ($request->wants_json()) {
            $json = json_decode($content, true);
            if (!is_array($json)) {
                throw new connector_exception(
                    connector_exception::INVALID_JSON,
                    null,
                    "finishReason={$finishreason}, content length=" . strlen($content)
                );
            }
        }

        $usage = $data['usageMetadata'] ?? [];
        $tokenscached = (int) ($usage['cachedContentTokenCount'] ?? 0);
        // The promptTokenCount field is the total effective prompt size and includes cached tokens,
        // unlike this project's tokensin convention, which counts only the uncached portion.
        $tokensin = max(0, (int) ($usage['promptTokenCount'] ?? 0) - $tokenscached);
        $tokensout = (int) ($usage['candidatesTokenCount'] ?? 0);

        return new result(
            content: $content,
            json: $json,
            tokensin: $tokensin,
            tokensout: $tokensout,
            tokenscached: $tokenscached,
            cost: pricing::cost_for(self::NAME, $this->model, $tokensin, $tokensout, $tokenscached),
            model: $this->model,
            durationms: $durationms,
            finishreason: $finishreason,
            connector: self::NAME,
        );
    }

    /**
     * Returns the model output: the text of the first part of the candidate's content.
     *
     * @param array $candidate The first candidate of the response.
     * @return string
     */
    protected function extract_content(array $candidate): string {
        $parts = $candidate['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text'])) {
                return (string) $part['text'];
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
     * The exact HTTP error shape is not confirmed from the official docs consulted for this
     * connector; this reads the error.message field of Google's common API error envelope
     * ({"error": {"code", "message", "status"}}), used consistently across Google Cloud and AI
     * Studio APIs, and falls back to the raw body when that shape is not found.
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
        $tokensout = $request->maxtokens > 0 ? $request->maxtokens : $tokensin;
        return pricing::cost_for(self::NAME, $this->model, $tokensin, $tokensout, 0);
    }
}
