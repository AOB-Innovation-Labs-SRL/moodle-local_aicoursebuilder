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
 * Tests for the DeepSeek connector, with a mocked HTTP client and no network.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\deepseek_connector
 * @covers     \local_aicoursebuilder\ai\policy
 * @covers     \local_aicoursebuilder\ai\connector_exception
 * @covers     \local_aicoursebuilder\ai\pricing
 */
final class deepseek_connector_test extends \advanced_testcase {
    /** @var string API key used by the tests. */
    private const APIKEY = 'sk-test-0123456789';

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
        set_config('deepseek_apikey', \core\encryption::encrypt(self::APIKEY), 'local_aicoursebuilder');
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
            'id' => 'cmpl-1',
            'object' => 'chat.completion',
            'model' => 'deepseek-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant'] + $message, 'finish_reason' => $finishreason]],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 45,
                'total_tokens' => 165,
                'prompt_cache_hit_tokens' => 64,
                'prompt_cache_miss_tokens' => 56,
            ],
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
     * A JSON request without schema uses response_format json_object; the answer and usage are parsed.
     */
    public function test_json_object_request_and_response(): void {
        // Non-streaming responses may start with keep-alive empty lines.
        $this->mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            "\n\n  " . $this->completion_body(['content' => '{"titlu": "Fotosinteza", "idei": ["lumină"]}'])
        ));

        $result = (new deepseek_connector())->complete($this->make_request([
            'json' => true,
            'maxtokens' => 2000,
            'temperature' => 0.2,
        ]));

        [$sent, $body] = $this->sent();
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://api.deepseek.com/chat/completions', (string) $sent->getUri());
        $this->assertSame('Bearer ' . self::APIKEY, $sent->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        $this->assertSame([
            'model' => 'deepseek-flash',
            'messages' => [
                ['role' => 'system', 'content' => 'Rezumă documentul în JSON.'],
                ['role' => 'user', 'content' => 'Fotosinteza transformă lumina în energie chimică.'],
            ],
            'thinking' => ['type' => 'disabled'],
            'max_tokens' => 2000,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
        ], $body);

        $this->assertSame(['titlu' => 'Fotosinteza', 'idei' => ['lumină']], $result->json);
        $this->assertSame('{"titlu": "Fotosinteza", "idei": ["lumină"]}', $result->content);
        $this->assertSame(['tokensin' => 56, 'tokensout' => 45, 'tokenscached' => 64], $result->get_usage());
        $this->assertSame('deepseek-flash', $result->model);
        $this->assertSame('stop', $result->finishreason);
        $this->assertSame(deepseek_connector::NAME, $result->connector);
        $this->assertSame(pricing::cost_for(deepseek_connector::NAME, 'deepseek-flash', 56, 45, 64), $result->cost);
        $this->assertGreaterThanOrEqual(0, $result->durationms);
    }

    /**
     * A request with a schema is sent as one forced, non-strict tool call; the arguments are the result.
     */
    public function test_schema_request_uses_forced_tool_call(): void {
        $schema = [
            'type' => 'object',
            'required' => ['titlu'],
            'properties' => ['titlu' => ['type' => 'string', 'maxLength' => 100]],
        ];
        $this->mock->append(new Response(200, [], $this->completion_body([
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'emit_digest', 'arguments' => '{"titlu":"Fotosinteza"}'],
            ]],
        ], 'tool_calls')));

        $result = (new deepseek_connector())->complete($this->make_request(['schema' => $schema]));

        [, $body] = $this->sent();
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('emit_digest', $body['tools'][0]['function']['name']);
        $this->assertSame($schema, $body['tools'][0]['function']['parameters']);
        $this->assertArrayNotHasKey('strict', $body['tools'][0]['function']);
        $this->assertSame(['type' => 'function', 'function' => ['name' => 'emit_digest']], $body['tool_choice']);

        $this->assertSame(['titlu' => 'Fotosinteza'], $result->json);
        $this->assertSame('{"titlu":"Fotosinteza"}', $result->content);
        $this->assertSame('tool_calls', $result->finishreason);
    }

    /**
     * The outline schema refers to the blueprint schema, which DeepSeek cannot fetch: the tool
     * parameters have to carry everything, with no aicb:/// left.
     */
    public function test_a_step_schema_is_sent_without_external_references(): void {
        $schema = (new \local_aicoursebuilder\blueprint\schema_store())->step_schema_array(request::STEP_OUTLINE);
        $rawjson = json_encode($schema, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('aicb:///', $rawjson, 'the raw schema is not self-contained');
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => '{"course": {}}'])));

        (new deepseek_connector())->complete($this->make_request(['step' => request::STEP_OUTLINE, 'schema' => $schema]));

        [$sent, $body] = $this->sent();
        $this->assertStringNotContainsString('aicb:', (string) $sent->getBody());
        $parameters = $body['tools'][0]['function']['parameters'];
        $this->assertSame('#/$defs/blueprint_v1__course', $parameters['properties']['course']['$ref']);
        $this->assertArrayHasKey('blueprint_v1__course', $parameters['$defs']);
        $this->assertArrayHasKey('outline_section', $parameters['$defs']);
        $this->assertArrayNotHasKey('$id', $parameters);
    }

    /**
     * A free-text request has neither tools nor response_format and no decoded JSON.
     */
    public function test_text_request(): void {
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => 'Un rezumat scurt.'])));

        $result = (new deepseek_connector())->complete($this->make_request(['system' => '']));

        [, $body] = $this->sent();
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertSame(
            [['role' => 'user', 'content' => 'Fotosinteza transformă lumina în energie chimică.']],
            $body['messages']
        );
        $this->assertSame('Un rezumat scurt.', $result->content);
        $this->assertNull($result->json);
    }

    /**
     * Base URL, default model and thinking come from the settings; the route model wins over the default.
     */
    public function test_settings_are_used(): void {
        set_config('deepseek_baseurl', 'https://proxy.example.com/deepseek/', 'local_aicoursebuilder');
        set_config('deepseek_model', 'deepseek-v4-pro', 'local_aicoursebuilder');
        set_config('deepseek_thinking', 1, 'local_aicoursebuilder');
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => 'x'])));
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => 'x'])));

        $this->assertSame('deepseek-v4-pro', (new deepseek_connector())->get_model());
        (new deepseek_connector('deepseek-flash'))->complete($this->make_request());
        (new deepseek_connector())->complete($this->make_request());

        $first = json_decode((string) $this->history[0]['request']->getBody(), true);
        $second = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame(
            'https://proxy.example.com/deepseek/chat/completions',
            (string) $this->history[0]['request']->getUri()
        );
        $this->assertSame('deepseek-flash', $first['model']);
        $this->assertSame('deepseek-v4-pro', $second['model']);
        $this->assertSame(['type' => 'enabled'], $first['thinking']);
    }

    /**
     * Usage without cache fields falls back to prompt_tokens.
     */
    public function test_usage_without_cache_fields(): void {
        $this->mock->append(new Response(200, [], json_encode([
            'model' => 'deepseek-flash',
            'choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 7],
        ])));

        $result = (new deepseek_connector())->complete($this->make_request());

        $this->assertSame(['tokensin' => 30, 'tokensout' => 7, 'tokenscached' => 0], $result->get_usage());
        $this->assertSame('length', $result->finishreason);
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
            'tool arguments are not JSON' => [
                $choice(['tool_calls' => [['function' => ['name' => 'emit_digest', 'arguments' => '{"titlu":']]]]),
                ['schema' => ['type' => 'object']],
            ],
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
            (new deepseek_connector())->complete($this->make_request($params));
            $this->fail('Invalid JSON accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::INVALID_JSON, $e->errorcode);
            $this->assertSame(0, $e->httpstatus);
        }
    }

    /**
     * An answer cut off at max_tokens is a truncation carrying the limit, not invalid JSON, and the
     * limit the request set is the one sent.
     */
    public function test_answer_cut_off_at_the_output_limit_is_a_truncation(): void {
        $this->mock->append(new Response(200, [], $this->completion_body(
            ['tool_calls' => [['function' => ['name' => 'emit_digest', 'arguments' => '{"titlu": "Fotosin']]]],
            'length',
        )));
        try {
            (new deepseek_connector())->complete($this->make_request([
                'schema' => ['type' => 'object'],
                'maxtokens' => 8192,
            ]));
            $this->fail('A cut-off answer was accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::TRUNCATED, $e->errorcode);
            $this->assertSame(8192, $e->a);
            $this->assertStringContainsString('finish_reason=length', (string) $e->debuginfo);
            $this->assertStringNotContainsString('Fotosin', (string) $e->debuginfo, 'the answer itself is not kept');
            $this->assertFalse(retrying_connector::is_retryable($e), 'the same limit would cut it off again');
        }
        [, $body] = $this->sent();
        $this->assertSame(8192, $body['max_tokens']);
    }

    /**
     * HTTP 429 raises the rate limit error once, without retrying.
     */
    public function test_rate_limit_is_not_retried(): void {
        $this->mock->append(new Response(429, [], '{"error": {"message": "Rate limit reached"}}'));
        $this->mock->append(new Response(200, [], $this->completion_body(['content' => 'x'])));
        try {
            (new deepseek_connector())->complete($this->make_request());
            $this->fail('429 accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::RATE_LIMITED, $e->errorcode);
            $this->assertSame(429, $e->httpstatus);
            $this->assertSame('Rate limit reached', $e->debuginfo);
        }
        $this->assertCount(1, $this->history);
        $this->assertSame(1, $this->mock->count());
    }

    /**
     * HTTP 500 raises the HTTP error once, without retrying and without leaking the key.
     */
    public function test_server_error_is_not_retried(): void {
        $this->mock->append(new Response(500, [], '{"error": {"message": "Server error", "type": "server_error"}}'));
        try {
            (new deepseek_connector())->complete($this->make_request());
            $this->fail('500 accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::HTTP_ERROR, $e->errorcode);
            $this->assertSame(500, $e->httpstatus);
            $this->assertSame('Server error', $e->debuginfo);
            $this->assertStringContainsString('500', $e->getMessage());
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
            new HttpRequest('POST', 'https://api.deepseek.com/chat/completions')
        ));
        $this->expectExceptionObject(new connector_exception(connector_exception::NETWORK_ERROR));
        (new deepseek_connector())->complete($this->make_request());
    }

    /**
     * Without an API key nothing is sent.
     */
    public function test_missing_key(): void {
        unset_config('deepseek_apikey', 'local_aicoursebuilder');
        try {
            (new deepseek_connector())->complete($this->make_request());
            $this->fail('Call without key accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::NOT_CONFIGURED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * A user that has not accepted the AI policy is refused before any call.
     */
    public function test_policy_not_accepted(): void {
        $other = (int) $this->getDataGenerator()->create_user()->id;
        try {
            (new deepseek_connector())->complete($this->make_request(['userid' => $other]));
            $this->fail('Policy not checked');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::POLICY_NOT_ACCEPTED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * A request without user is a coding error for a real connector.
     */
    public function test_request_without_user(): void {
        $this->expectException(\coding_exception::class);
        (new deepseek_connector())->complete($this->make_request(['userid' => null]));
    }

    /**
     * Files are not supported yet.
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
            (new deepseek_connector())->complete($this->make_request(['files' => [$file]]));
            $this->fail('Files accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::UNSUPPORTED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * Capabilities and token estimate.
     */
    public function test_supports_and_tokens(): void {
        $connector = new deepseek_connector();
        foreach (connector::CAPABILITIES as $capability) {
            $this->assertSame(
                in_array($capability, deepseek_connector::SUPPORTED, true),
                $connector->supports($capability),
                $capability
            );
        }
        $this->assertTrue($connector->supports(connector::CAP_JSON_SCHEMA));
        $this->assertFalse($connector->supports(connector::CAP_VISION));
        $this->assertSame(2, $connector->count_tokens('ăîșțâ'));
    }

    /**
     * The pre-call estimate treats the whole input as a cache miss, output from maxtokens, at peak
     * pricing; it never sends a request.
     */
    public function test_estimate_cost(): void {
        $connector = new deepseek_connector();
        $request = $this->make_request(['maxtokens' => 1000]);
        $tokensin = $connector->count_tokens($request->get_input_text());
        $peakprices = pricing::DEFAULT_PRICES[deepseek_connector::NAME][deepseek_connector::DEFAULT_MODEL];

        $expected = round(($tokensin / pricing::PER_TOKENS) * $peakprices['input_miss']
            + (1000 / pricing::PER_TOKENS) * $peakprices['output'], 6);
        $this->assertSame($expected, $connector->estimate_cost($request));
        $this->assertGreaterThan(0.0, $connector->estimate_cost($request));
        $this->assertCount(0, $this->history);
    }
}
