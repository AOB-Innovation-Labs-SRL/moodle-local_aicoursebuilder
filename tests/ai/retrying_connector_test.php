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

use GuzzleHttp\Psr7\Response;

/**
 * Tests for the retry decorator, with a mocked HTTP client and a fake clock (no real sleep).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\retrying_connector
 */
final class retrying_connector_test extends \advanced_testcase {
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
        set_config('deepseek_apikey', \core\encryption::encrypt('sk-test'), 'local_aicoursebuilder');
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        \core_ai\manager::user_policy_accepted($this->userid, \context_system::instance()->id);
        $this->history = [];
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
    }

    /**
     * Builds a request for the digest step.
     *
     * @return request
     */
    private function make_request(): request {
        return new request(
            step: request::STEP_DIGEST,
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'text']],
            userid: $this->userid,
            json: true,
        );
    }

    /**
     * A completion body with the given content.
     *
     * @param string $content The generated content.
     * @return string
     */
    private function completion_body(string $content): string {
        return json_encode([
            'model' => 'deepseek-flash',
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);
    }

    /**
     * A 429 with Retry-After is retried once and then succeeds; the sleep respects Retry-After.
     */
    public function test_retry_after_header_is_respected(): void {
        $this->mock->append(new Response(429, ['Retry-After' => '2'], '{"error":{"message":"slow down"}}'));
        $this->mock->append(new Response(200, [], $this->completion_body('{"a":1}')));

        $clock = new fake_clock();
        $retrying = new retrying_connector(new deepseek_connector(), $clock, maxattempts: 3);
        $result = $retrying->complete($this->make_request());

        $this->assertSame(['a' => 1], $result->json);
        $this->assertCount(2, $this->history);
        $this->assertSame([2000], $clock->get_sleeps());
    }

    /**
     * A 500 repeated past the maximum attempts raises the last error, after sleeping between tries.
     */
    public function test_server_error_exhausts_attempts(): void {
        $this->mock->append(new Response(500, [], '{"error":{"message":"boom 1"}}'));
        $this->mock->append(new Response(500, [], '{"error":{"message":"boom 2"}}'));
        $this->mock->append(new Response(500, [], '{"error":{"message":"boom 3"}}'));

        $clock = new fake_clock();
        $retrying = new retrying_connector(new deepseek_connector(), $clock, maxattempts: 3, basedelayms: 100);
        try {
            $retrying->complete($this->make_request());
            $this->fail('500 accepted after exhausting attempts');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::HTTP_ERROR, $e->errorcode);
            $this->assertSame('boom 3', $e->debuginfo);
        }
        $this->assertCount(3, $this->history);
        $this->assertCount(2, $clock->get_sleeps());
    }

    /**
     * A 400 is never retried, even with attempts left.
     */
    public function test_client_error_is_not_retried(): void {
        $this->mock->append(new Response(400, [], '{"error":{"message":"bad request"}}'));

        $clock = new fake_clock();
        $retrying = new retrying_connector(new deepseek_connector(), $clock, maxattempts: 5);
        try {
            $retrying->complete($this->make_request());
            $this->fail('400 was retried');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::HTTP_ERROR, $e->errorcode);
        }
        $this->assertCount(1, $this->history);
        $this->assertCount(0, $clock->get_sleeps());
    }

    /**
     * A network error is retried like a 5xx.
     */
    public function test_network_error_is_retried(): void {
        $this->mock->append(new \GuzzleHttp\Exception\ConnectException(
            'Could not resolve host',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.deepseek.com/chat/completions')
        ));
        $this->mock->append(new Response(200, [], $this->completion_body('{"a":1}')));

        $clock = new fake_clock();
        $result = (new retrying_connector(new deepseek_connector(), $clock))->complete($this->make_request());
        $this->assertSame(['a' => 1], $result->json);
        $this->assertCount(2, $this->history);
    }

    /**
     * Without a Retry-After header, backoff doubles on every attempt and is bounded by jitter.
     */
    public function test_backoff_doubles_without_retry_after(): void {
        $this->mock->append(new Response(500, [], '{"error":{"message":"1"}}'));
        $this->mock->append(new Response(500, [], '{"error":{"message":"2"}}'));
        $this->mock->append(new Response(200, [], $this->completion_body('{"a":1}')));

        $clock = new fake_clock();
        (new retrying_connector(new deepseek_connector(), $clock, maxattempts: 3, basedelayms: 500))
            ->complete($this->make_request());

        $sleeps = $clock->get_sleeps();
        $this->assertCount(2, $sleeps);
        $this->assertLessThanOrEqual(500, $sleeps[0]);
        $this->assertLessThanOrEqual(1000, $sleeps[1]);
    }

    /**
     * supports(), estimate_cost() and count_tokens() delegate to the inner connector.
     */
    public function test_delegates_capabilities(): void {
        $inner = new deepseek_connector();
        $retrying = new retrying_connector($inner);
        $this->assertSame($inner->supports(connector::CAP_JSON_SCHEMA), $retrying->supports(connector::CAP_JSON_SCHEMA));
        $this->assertSame($inner->count_tokens('hello'), $retrying->count_tokens('hello'));
        $this->assertSame($inner->estimate_cost($this->make_request()), $retrying->estimate_cost($this->make_request()));
    }

    /**
     * maxattempts must be at least 1.
     */
    public function test_maxattempts_must_be_positive(): void {
        $this->expectException(\coding_exception::class);
        new retrying_connector(new deepseek_connector(), maxattempts: 0);
    }
}
