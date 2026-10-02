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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for the generic OpenAI-compatible connector, with a mocked HTTP client and no network.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\openaicompat_connector
 * @covers     \local_aicoursebuilder\ai\policy
 * @covers     \local_aicoursebuilder\ai\connector_exception
 * @covers     \local_aicoursebuilder\ai\pricing
 */
final class openaicompat_connector_test extends \advanced_testcase {
    /** @var string API key used by the tests. */
    private const APIKEY = 'sk-compat-test-0123456789';

    /** @var int User that accepted the AI policy. */
    private int $userid;

    /** @var array HTTP request/response history of the mocked client. */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler Mocked HTTP handler. */
    private \GuzzleHttp\Handler\MockHandler $mock;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('openaicompat_baseurl', 'https://api.openai.com/v1', 'local_aicoursebuilder');
        set_config('openaicompat_model', 'gpt-test', 'local_aicoursebuilder');
        set_config('openaicompat_apikey', \core\encryption::encrypt(self::APIKEY), 'local_aicoursebuilder');
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        \core_ai\manager::user_policy_accepted($this->userid, \context_system::instance()->id);
        $this->history = [];
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
    }

    /**
     * Builds a request for the digest step.
     *
     * @param array $params Named request parameters overriding the defaults.
     * @return request
     */
    private function make_request(array $params = []): request {
        return new request(...array_merge([
            'step' => request::STEP_DIGEST,
            'system' => 'Rezumă documentul în JSON.',
            'messages' => [['role' => 'user', 'content' => 'Fotosinteza transformă lumina în energie chimică.']],
            'userid' => $this->userid,
        ], $params));
    }

    /**
     * Builds a successful chat completion body.
     *
     * @param array $message The choice message.
     * @param string $finishreason The finish reason.
     * @return string
     */
    private function completion_body(array $message, string $finishreason = 'stop'): string {
        return json_encode([
            'model' => 'gpt-test',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant'] + $message, 'finish_reason' => $finishreason]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 45, 'total_tokens' => 165],
        ]);
    }

    /**
     * Returns the request the connector sent and its decoded body.
     *
     * @return array [HttpRequest, array]
     */
    private function sent(): array {
        $this->assertCount(1, $this->history);
        /** @var HttpRequest $sent */
        $sent = $this->history[0]['request'];
        return [$sent, json_decode((string) $sent->getBody(), true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * A bearer-auth request sends Authorization and parses the answer and usage.
     */
    public function test_bearer_auth_request_and_response(): void {
        $this->mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->completion_body(['content' => '{"titlu": "Fotosinteza"}'])
        ));

        $result = (new openaicompat_connector())->complete($this->make_request(['json' => true, 'maxtokens' => 2000]));

        [$sent, $body] = $this->sent();
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) $sent->getUri());
        $this->assertSame('Bearer ' . self::APIKEY, $sent->getHeaderLine('Authorization'));
        $this->assertSame('', $sent->getHeaderLine('api-key'));
        $this->assertSame([
            'model' => 'gpt-test',
            'messages' => [
                ['role' => 'system', 'content' => 'Rezumă documentul în JSON.'],
                ['role' => 'user', 'content' => 'Fotosinteza transformă lumina în energie chimică.'],
            ],
            'max_tokens' => 2000,
            'response_format' => ['type' => 'json_object'],
        ], $body);

        $this->assertSame(['titlu' => 'Fotosinteza'], $result->json);
        $this->assertSame(['tokensin' => 120, 'tokensout' => 45, 'tokenscached' => 0], $result->get_usage());
        $this->assertSame('gpt-test', $result->model);
        $this->assertSame(openaicompat_connector::NAME, $result->connector);
    }

    /**
     * Azure-style auth sends api-key instead of Authorization, plus the api-version query parameter.
     */
    public function test_apikey_auth_and_api_version(): void {
        set_config('openaicompat_baseurl', 'https://res.openai.azure.com/openai/deployments/dep1', 'local_aicoursebuilder');
        set_config('openaicompat_authtype', openaicompat_connector::AUTHTYPE_APIKEY, 'local_aicoursebuilder');
        set_config('openaicompat_apiversion', '2024-06-01', 'local_aicoursebuilder');
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => 'x'])));

        (new openaicompat_connector())->complete($this->make_request());

        [$sent] = $this->sent();
        $this->assertSame(
            'https://res.openai.azure.com/openai/deployments/dep1/chat/completions?api-version=2024-06-01',
            (string) $sent->getUri()
        );
        $this->assertSame(self::APIKEY, $sent->getHeaderLine('api-key'));
        $this->assertSame('', $sent->getHeaderLine('Authorization'));
    }

    /**
     * With json_schema declared supported, a schema request uses response_format json_schema.
     */
    public function test_schema_request_when_json_schema_supported(): void {
        set_config('openaicompat_supports_json_schema', 1, 'local_aicoursebuilder');
        $schema = ['type' => 'object', 'properties' => ['titlu' => ['type' => 'string']]];
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => '{"titlu":"x"}'])));

        (new openaicompat_connector())->complete($this->make_request(['schema' => $schema]));

        [, $body] = $this->sent();
        $this->assertSame('json_schema', $body['response_format']['type']);
        $this->assertSame('emit_digest', $body['response_format']['json_schema']['name']);
        $this->assertSame($schema, $body['response_format']['json_schema']['schema']);
        $this->assertArrayNotHasKey('strict', $body['response_format']['json_schema']);
    }

    /**
     * The outline schema refers to the blueprint schema: the json_schema has to carry everything,
     * with no aicb:/// left.
     */
    public function test_a_step_schema_is_sent_without_external_references(): void {
        set_config('openaicompat_supports_json_schema', 1, 'local_aicoursebuilder');
        $schema = (new \local_aicoursebuilder\blueprint\schema_store())->step_schema_array(request::STEP_OUTLINE);
        $rawjson = json_encode($schema, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('aicb:///', $rawjson, 'the raw schema is not self-contained');
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => '{"course": {}}'])));

        (new openaicompat_connector())->complete($this->make_request(['step' => request::STEP_OUTLINE, 'schema' => $schema]));

        [$sent, $body] = $this->sent();
        $this->assertStringNotContainsString('aicb:', (string) $sent->getBody());
        $sentschema = $body['response_format']['json_schema']['schema'];
        $this->assertSame('#/$defs/blueprint_v1__course', $sentschema['properties']['course']['$ref']);
        $this->assertArrayHasKey('blueprint_v1__course', $sentschema['$defs']);
        $this->assertArrayNotHasKey('$id', $sentschema);
    }

    /**
     * Without json_schema declared supported, a schema request falls back to json_object.
     */
    public function test_schema_request_falls_back_to_json_object_when_unsupported(): void {
        $schema = ['type' => 'object'];
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => '{}'])));

        (new openaicompat_connector())->complete($this->make_request(['schema' => $schema]));

        [, $body] = $this->sent();
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
    }

    /**
     * A free-text request has no response_format.
     */
    public function test_text_request(): void {
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => 'Un rezumat scurt.'])));

        $result = (new openaicompat_connector())->complete($this->make_request(['system' => '']));

        [, $body] = $this->sent();
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertSame('Un rezumat scurt.', $result->content);
        $this->assertNull($result->json);
    }

    /**
     * Data provider for the invalid JSON cases.
     *
     * @return array
     */
    public static function invalid_json_provider(): array {
        $choice = fn(array $message): string => json_encode([
            'choices' => [['message' => $message, 'finish_reason' => 'stop']],
        ]);
        return [
            'body is not JSON' => ['<html>gateway</html>', ['json' => true]],
            'body without choices' => ['{"object": "chat.completion"}', ['json' => true]],
            'empty content in JSON mode' => [$choice(['content' => '']), ['json' => true]],
            'content is not JSON' => [$choice(['content' => 'Iată rezumatul']), ['json' => true]],
        ];
    }

    /**
     * Invalid JSON answers raise connector_exception with the invalid JSON code.
     *
     * @dataProvider invalid_json_provider
     * @param string $body HTTP response body.
     * @param array $params Request parameters.
     */
    public function test_invalid_json(string $body, array $params): void {
        $this->mock->append(new Response(200, [], $body));
        try {
            (new openaicompat_connector())->complete($this->make_request($params));
            $this->fail('Invalid JSON accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::INVALID_JSON, $e->errorcode);
            $this->assertSame(0, $e->httpstatus);
        }
    }

    /**
     * HTTP 429 raises the rate limit error once, without retrying.
     */
    public function test_rate_limit_is_not_retried(): void {
        $this->mock->append(new Response(429, [], '{"error": {"message": "Rate limit reached"}}'));
        try {
            (new openaicompat_connector())->complete($this->make_request());
            $this->fail('429 accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::RATE_LIMITED, $e->errorcode);
            $this->assertSame(429, $e->httpstatus);
            $this->assertSame('Rate limit reached', $e->debuginfo);
        }
        $this->assertCount(1, $this->history);
    }

    /**
     * HTTP 500 raises the HTTP error once, without retrying and without leaking the key.
     */
    public function test_server_error_is_not_retried(): void {
        $this->mock->append(new Response(500, [], '{"error": {"message": "Server error"}}'));
        try {
            (new openaicompat_connector())->complete($this->make_request());
            $this->fail('500 accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::HTTP_ERROR, $e->errorcode);
            $this->assertSame(500, $e->httpstatus);
            $this->assertStringNotContainsString(self::APIKEY, $e->getMessage() . $e->debuginfo);
        }
        $this->assertCount(1, $this->history);
    }

    /**
     * A network failure raises the network error.
     */
    public function test_network_error(): void {
        $this->mock->append(new ConnectException(
            'Could not resolve host',
            new HttpRequest('POST', 'https://api.openai.com/v1/chat/completions')
        ));
        $this->expectExceptionObject(new connector_exception(connector_exception::NETWORK_ERROR));
        (new openaicompat_connector())->complete($this->make_request());
    }

    /**
     * Without a base URL or model nothing is sent.
     */
    public function test_missing_configuration(): void {
        unset_config('openaicompat_baseurl', 'local_aicoursebuilder');
        try {
            (new openaicompat_connector())->complete($this->make_request());
            $this->fail('Call without base URL accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::NOT_CONFIGURED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * An empty API key is accepted (local Ollama/vLLM servers that enforce none).
     */
    public function test_empty_key_is_allowed(): void {
        unset_config('openaicompat_apikey', 'local_aicoursebuilder');
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => 'x'])));

        (new openaicompat_connector())->complete($this->make_request());

        [$sent] = $this->sent();
        $this->assertSame('', $sent->getHeaderLine('Authorization'));
    }

    /**
     * A user that has not accepted the AI policy is refused before any call.
     */
    public function test_policy_not_accepted(): void {
        $other = (int) $this->getDataGenerator()->create_user()->id;
        try {
            (new openaicompat_connector())->complete($this->make_request(['userid' => $other]));
            $this->fail('Policy not checked');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::POLICY_NOT_ACCEPTED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * Files are not supported.
     */
    public function test_files_are_unsupported(): void {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'doc.pdf',
        ], '%PDF-1.4');
        try {
            (new openaicompat_connector())->complete($this->make_request(['files' => [$file]]));
            $this->fail('Files accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::UNSUPPORTED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * Capabilities reflect the per-instance settings; prompt_cache/batch/files_pdf are always false.
     */
    public function test_supports_reflects_settings(): void {
        $connector = new openaicompat_connector();
        $this->assertFalse($connector->supports(connector::CAP_JSON_SCHEMA));
        $this->assertFalse($connector->supports(connector::CAP_VISION));
        $this->assertFalse($connector->supports(connector::CAP_PROMPT_CACHE));
        $this->assertFalse($connector->supports(connector::CAP_FILES_PDF));
        $this->assertFalse($connector->supports(connector::CAP_BATCH));

        set_config('openaicompat_supports_json_schema', 1, 'local_aicoursebuilder');
        set_config('openaicompat_supports_vision', 1, 'local_aicoursebuilder');
        $connector = new openaicompat_connector();
        $this->assertTrue($connector->supports(connector::CAP_JSON_SCHEMA));
        $this->assertTrue($connector->supports(connector::CAP_VISION));
    }

    /**
     * The pre-call estimate never sends a request; with no confirmed pricing it is 0.
     */
    public function test_estimate_cost(): void {
        $connector = new openaicompat_connector();
        $request = $this->make_request(['maxtokens' => 1000]);
        $this->assertSame(0.0, $connector->estimate_cost($request));
        $this->assertCount(0, $this->history);
    }
}
