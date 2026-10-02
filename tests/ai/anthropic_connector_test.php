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
 * Tests for the Anthropic connector, with a mocked HTTP client and no network.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\anthropic_connector
 * @covers     \local_aicoursebuilder\ai\policy
 * @covers     \local_aicoursebuilder\ai\connector_exception
 * @covers     \local_aicoursebuilder\ai\pricing
 */
final class anthropic_connector_test extends \advanced_testcase {
    /** @var string API key used by the tests. */
    private const APIKEY = 'sk-ant-test-0123456789';

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
        set_config('anthropic_apikey', \core\encryption::encrypt(self::APIKEY), 'local_aicoursebuilder');
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
     * Builds a successful Messages API response body.
     *
     * @param array $content Content blocks of the response.
     * @param string $stopreason The stop reason.
     * @param array $usage Usage overrides.
     * @return string
     */
    private function message_body(array $content, string $stopreason = 'end_turn', array $usage = []): string {
        return json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'model' => 'claude-haiku-4-5-20251001',
            'content' => $content,
            'stop_reason' => $stopreason,
            'usage' => array_merge([
                'input_tokens' => 120,
                'output_tokens' => 45,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 64,
            ], $usage),
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
     * A JSON request without schema omits thinking (the model's own default) and sends a cached
     * system prompt; the answer and usage are parsed.
     */
    public function test_json_request_and_response(): void {
        $this->mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->message_body([['type' => 'text', 'text' => '{"titlu": "Fotosinteza", "idei": ["lumină"]}']])
        ));

        $result = (new anthropic_connector())->complete($this->make_request(['json' => true, 'temperature' => 0.2]));

        [$sent, $body] = $this->sent();
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $sent->getUri());
        $this->assertSame(self::APIKEY, $sent->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $sent->getHeaderLine('anthropic-version'));
        $this->assertSame('application/json', $sent->getHeaderLine('content-type'));
        $this->assertSame([
            'model' => anthropic_connector::DEFAULT_MODEL,
            'max_tokens' => anthropic_connector::DEFAULT_MAXTOKENS,
            'messages' => [
                ['role' => 'user', 'content' => 'Fotosinteza transformă lumina în energie chimică.'],
            ],
            'system' => [
                ['type' => 'text', 'text' => 'Rezumă documentul în JSON.', 'cache_control' => ['type' => 'ephemeral']],
            ],
            'temperature' => 0.2,
        ], $body);
        $this->assertArrayNotHasKey('thinking', $body);

        $this->assertSame(['titlu' => 'Fotosinteza', 'idei' => ['lumină']], $result->json);
        $this->assertSame(['tokensin' => 120, 'tokensout' => 45, 'tokenscached' => 64], $result->get_usage());
        $this->assertSame('claude-haiku-4-5-20251001', $result->model);
        $this->assertSame('end_turn', $result->finishreason);
        $this->assertSame(anthropic_connector::NAME, $result->connector);
        $this->assertSame(pricing::cost_for(anthropic_connector::NAME, 'claude-haiku-4-5-20251001', 120, 45, 64), $result->cost);
    }

    /**
     * A request with a schema asks for native structured output, never forced tool use.
     */
    public function test_schema_request_uses_structured_output(): void {
        $schema = [
            'type' => 'object',
            'required' => ['titlu'],
            'properties' => ['titlu' => ['type' => 'string', 'maxLength' => 100]],
        ];
        $this->mock->append(new Response(200, [], $this->message_body([
            ['type' => 'text', 'text' => '{"titlu":"Fotosinteza"}'],
        ])));

        $result = (new anthropic_connector())->complete($this->make_request(['schema' => $schema]));

        [, $body] = $this->sent();
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('tool_choice', $body);
        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config']);
        $this->assertSame(['titlu' => 'Fotosinteza'], $result->json);
    }

    /**
     * The outline schema refers to the blueprint schema: the structured output schema has to carry
     * everything, with no aicb:/// left.
     */
    public function test_a_step_schema_is_sent_without_external_references(): void {
        $schema = (new \local_aicoursebuilder\blueprint\schema_store())->step_schema_array(request::STEP_OUTLINE);
        $rawjson = json_encode($schema, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('aicb:///', $rawjson, 'the raw schema is not self-contained');
        $this->mock->append(new Response(200, [], $this->message_body([['type' => 'text', 'text' => '{"course": {}}']])));

        (new anthropic_connector())->complete($this->make_request(['step' => request::STEP_OUTLINE, 'schema' => $schema]));

        [$sent, $body] = $this->sent();
        $this->assertStringNotContainsString('aicb:', (string) $sent->getBody());
        $sentschema = $body['output_config']['format']['schema'];
        $this->assertSame('#/$defs/blueprint_v1__course', $sentschema['properties']['course']['$ref']);
        $this->assertArrayHasKey('blueprint_v1__course', $sentschema['$defs']);
        $this->assertArrayNotHasKey('$id', $sentschema);
    }

    /**
     * Source files are sent as cached document blocks before the conversation.
     */
    public function test_files_are_sent_as_cached_document_blocks(): void {
        $file1 = $this->make_pdf('a.pdf', '%PDF-1.4 one');
        $file2 = $this->make_pdf('b.pdf', '%PDF-1.4 two');
        $this->mock->append(new Response(200, [], $this->message_body([['type' => 'text', 'text' => 'ok']])));

        (new anthropic_connector())->complete($this->make_request(['files' => [$file1, $file2], 'system' => '']));

        [, $body] = $this->sent();
        $this->assertCount(2, $body['messages']);
        $filemessage = $body['messages'][0];
        $this->assertSame('user', $filemessage['role']);
        $this->assertCount(2, $filemessage['content']);
        $this->assertSame('document', $filemessage['content'][0]['type']);
        $this->assertSame('application/pdf', $filemessage['content'][0]['source']['media_type']);
        $this->assertSame(base64_encode('%PDF-1.4 one'), $filemessage['content'][0]['source']['data']);
        $this->assertArrayNotHasKey('cache_control', $filemessage['content'][0]);
        $this->assertSame(['type' => 'ephemeral'], $filemessage['content'][1]['cache_control']);
        $this->assertSame(
            ['role' => 'user', 'content' => 'Fotosinteza transformă lumina în energie chimică.'],
            $body['messages'][1]
        );
    }

    /**
     * Only PDF files are supported.
     */
    public function test_non_pdf_files_are_unsupported(): void {
        $file = $this->make_file('doc.txt', 'text/plain', 'hello');
        try {
            (new anthropic_connector())->complete($this->make_request(['files' => [$file]]));
            $this->fail('Non-PDF file accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::UNSUPPORTED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * Settings choose the default model; the route model wins over the default; thinking is configurable.
     */
    public function test_settings_are_used(): void {
        set_config('anthropic_model', 'claude-sonnet-5-5', 'local_aicoursebuilder');
        set_config('anthropic_thinking', 1, 'local_aicoursebuilder');
        $this->mock->append(new Response(200, [], $this->message_body([['type' => 'text', 'text' => 'x']])));
        $this->mock->append(new Response(200, [], $this->message_body([['type' => 'text', 'text' => 'x']])));

        $this->assertSame('claude-sonnet-5-5', (new anthropic_connector())->get_model());
        (new anthropic_connector('claude-haiku-4-5-20251001'))->complete($this->make_request());
        (new anthropic_connector())->complete($this->make_request());

        $first = json_decode((string) $this->history[0]['request']->getBody(), true);
        $second = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('claude-haiku-4-5-20251001', $first['model']);
        $this->assertSame('claude-sonnet-5-5', $second['model']);
        $this->assertSame(['type' => 'enabled'], $first['thinking']);
    }

    /**
     * cache_creation_input_tokens counts as uncached input (it is written to the cache now).
     */
    public function test_cache_creation_tokens_count_as_input(): void {
        $this->mock->append(new Response(200, [], $this->message_body(
            [['type' => 'text', 'text' => 'x']],
            usage: ['input_tokens' => 50, 'cache_creation_input_tokens' => 30, 'cache_read_input_tokens' => 0]
        )));

        $result = (new anthropic_connector())->complete($this->make_request());

        $this->assertSame(['tokensin' => 80, 'tokensout' => 45, 'tokenscached' => 0], $result->get_usage());
    }

    /**
     * Cache-creation tokens are costed at the pricing table's optional cache_write rate, not input_miss.
     */
    public function test_cache_creation_tokens_cost_at_cache_write_rate(): void {
        set_config('pricing_anthropic', json_encode([
            anthropic_connector::DEFAULT_MODEL => ['input_miss' => 1.0, 'input_hit' => 0.1, 'output' => 2.0, 'cache_write' => 5.0],
        ]), 'local_aicoursebuilder');
        $this->mock->append(new Response(200, [], $this->message_body([['type' => 'text', 'text' => 'x']], usage: [
            'input_tokens' => 1_000_000,
            'cache_creation_input_tokens' => 1_000_000,
            'cache_read_input_tokens' => 0,
            'output_tokens' => 0,
        ])));

        $result = (new anthropic_connector())->complete($this->make_request());

        // 1M input_miss tokens at 1.0 + 1M cache-write tokens at 5.0 (not 1.0): 1.0 + 5.0 = 6.0 USD.
        $this->assertSame(6.0, $result->cost);
        $this->assertSame(2_000_000, $result->tokensin);
    }

    /**
     * Without a cache_write price, cache-creation tokens fall back to the input_miss rate.
     */
    public function test_cache_creation_tokens_fall_back_to_input_miss_rate(): void {
        set_config('pricing_anthropic', json_encode([
            anthropic_connector::DEFAULT_MODEL => ['input_miss' => 1.0, 'input_hit' => 0.1, 'output' => 2.0],
        ]), 'local_aicoursebuilder');
        $this->mock->append(new Response(200, [], $this->message_body([['type' => 'text', 'text' => 'x']], usage: [
            'input_tokens' => 0,
            'cache_creation_input_tokens' => 1_000_000,
            'cache_read_input_tokens' => 0,
            'output_tokens' => 0,
        ])));

        $result = (new anthropic_connector())->complete($this->make_request());

        $this->assertSame(1.0, $result->cost);
    }

    /**
     * Data provider for the invalid JSON cases.
     *
     * @return array
     */
    public static function invalid_json_provider(): array {
        return [
            'body is not JSON' => ['<html>gateway</html>', ['json' => true]],
            'body without content' => ['{"type": "message"}', ['json' => true]],
            'empty text content in JSON mode' => [
                json_encode(['content' => [['type' => 'text', 'text' => '']]]),
                ['json' => true],
            ],
            'content is not JSON' => [
                json_encode(['content' => [['type' => 'text', 'text' => 'Iată rezumatul']]]),
                ['json' => true],
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
            (new anthropic_connector())->complete($this->make_request($params));
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
        $this->mock->append(new Response(429, [], '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'));
        try {
            (new anthropic_connector())->complete($this->make_request());
            $this->fail('429 accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::RATE_LIMITED, $e->errorcode);
            $this->assertSame(429, $e->httpstatus);
            $this->assertSame('slow down', $e->debuginfo);
        }
        $this->assertCount(1, $this->history);
    }

    /**
     * HTTP 529 (overloaded) raises the HTTP error once; retrying_connector is what retries it.
     */
    public function test_overloaded_is_http_error(): void {
        $this->mock->append(new Response(529, [], '{"type":"error","error":{"type":"overloaded_error","message":"busy"}}'));
        try {
            (new anthropic_connector())->complete($this->make_request());
            $this->fail('529 accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::HTTP_ERROR, $e->errorcode);
            $this->assertSame(529, $e->httpstatus);
            $this->assertSame('busy', $e->debuginfo);
        }
    }

    /**
     * A network failure raises the network error.
     */
    public function test_network_error(): void {
        $this->mock->append(new ConnectException(
            'Could not resolve host',
            new HttpRequest('POST', 'https://api.anthropic.com/v1/messages')
        ));
        $this->expectExceptionObject(new connector_exception(connector_exception::NETWORK_ERROR));
        (new anthropic_connector())->complete($this->make_request());
    }

    /**
     * Without an API key nothing is sent.
     */
    public function test_missing_key(): void {
        unset_config('anthropic_apikey', 'local_aicoursebuilder');
        try {
            (new anthropic_connector())->complete($this->make_request());
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
            (new anthropic_connector())->complete($this->make_request(['userid' => $other]));
            $this->fail('Policy not checked');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::POLICY_NOT_ACCEPTED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * Capabilities and token estimate.
     */
    public function test_supports_and_tokens(): void {
        $connector = new anthropic_connector();
        foreach (connector::CAPABILITIES as $capability) {
            $this->assertSame(
                in_array($capability, anthropic_connector::SUPPORTED, true),
                $connector->supports($capability),
                $capability
            );
        }
        $this->assertTrue($connector->supports(connector::CAP_JSON_SCHEMA));
        $this->assertTrue($connector->supports(connector::CAP_FILES_PDF));
        $this->assertFalse($connector->supports(connector::CAP_VISION));
    }

    /**
     * The pre-call estimate treats the whole input as a cache miss, output from maxtokens (or the default).
     */
    public function test_estimate_cost(): void {
        $connector = new anthropic_connector();
        $request = $this->make_request(['maxtokens' => 1000]);
        $tokensin = $connector->count_tokens($request->get_input_text());
        $prices = pricing::DEFAULT_PRICES[anthropic_connector::NAME][anthropic_connector::DEFAULT_MODEL] ?? null;

        $expected = pricing::cost_for(anthropic_connector::NAME, anthropic_connector::DEFAULT_MODEL, $tokensin, 1000, 0);
        $this->assertSame($expected, $connector->estimate_cost($request));
        $this->assertCount(0, $this->history);
        // No confirmed official pricing was fetched for Anthropic in this task: cost is 0 until the
        // admin configures pricing_anthropic (spec 3.3, 8: "empty or invalid falls back to zero cost").
        if ($prices === null) {
            $this->assertSame(0.0, $expected);
        }
    }

    /**
     * Creates a PDF stored_file.
     *
     * @param string $filename File name.
     * @param string $content File content.
     * @return \stored_file
     */
    private function make_pdf(string $filename, string $content): \stored_file {
        return $this->make_file($filename, 'application/pdf', $content);
    }

    /**
     * Creates a stored_file with a given mimetype.
     *
     * @param string $filename File name.
     * @param string $mimetype MIME type; stored_file infers it from the filename, so a matching
     *                         extension is used and the declared type is asserted.
     * @param string $content File content.
     * @return \stored_file
     */
    private function make_file(string $filename, string $mimetype, string $content): \stored_file {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        $this->assertSame($mimetype, $file->get_mimetype());
        return $file;
    }
}
