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
 * Tests for the logging decorator: one local_aicb_ailog row per HTTP attempt.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\logging_connector
 */
final class logging_connector_test extends \advanced_testcase {
    /** @var int User that accepted the AI policy. */
    private int $userid;

    /** @var \GuzzleHttp\Handler\MockHandler Mocked HTTP handler. */
    private \GuzzleHttp\Handler\MockHandler $mock;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('deepseek_apikey', \core\encryption::encrypt('sk-test'), 'local_aicoursebuilder');
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        \core_ai\manager::user_policy_accepted($this->userid, \context_system::instance()->id);
        ['mock' => $this->mock] = $this->get_mocked_http_client();
    }

    /**
     * Builds a request for the digest step.
     *
     * @param int|null $jobid Job id, or null.
     * @return request
     */
    private function make_request(?int $jobid = null): request {
        return new request(
            step: request::STEP_DIGEST,
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'text']],
            userid: $this->userid,
            jobid: $jobid,
            json: true,
        );
    }

    /**
     * A completion body with the given content and usage.
     *
     * @param string $content The generated content.
     * @return string
     */
    private function completion_body(string $content): string {
        return json_encode([
            'model' => 'deepseek-flash',
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'prompt_cache_hit_tokens' => 20,
                'prompt_cache_miss_tokens' => 80,
            ],
        ]);
    }

    /**
     * A successful call writes one row with the usage, cost and model of the result.
     */
    public function test_logs_success(): void {
        global $DB;
        $this->mock->append(new Response(200, [], $this->completion_body('{"a":1}')));

        (new logging_connector(new deepseek_connector(), deepseek_connector::NAME))->complete($this->make_request(42));

        $rows = array_values($DB->get_records('local_aicb_ailog'));
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($this->userid, (int) $row->userid);
        $this->assertSame(42, (int) $row->jobid);
        $this->assertSame(request::STEP_DIGEST, $row->step);
        $this->assertSame(deepseek_connector::NAME, $row->connector);
        $this->assertSame('deepseek-flash', $row->model);
        $this->assertSame(80, (int) $row->tokensin);
        $this->assertSame(50, (int) $row->tokensout);
        $this->assertSame(20, (int) $row->tokenscached);
        $this->assertGreaterThan(0.0, (float) $row->cost);
        $this->assertSame(logging_connector::STATUS_SUCCESS, $row->status);
        $this->assertNull($row->httpstatus);
    }

    /**
     * A failed call writes one row with status error, the HTTP status and zeroed usage.
     */
    public function test_logs_failure_without_content(): void {
        global $DB;
        $this->mock->append(new Response(500, [], '{"error":{"message":"boom"}}'));

        try {
            (new logging_connector(new deepseek_connector(), deepseek_connector::NAME))->complete($this->make_request());
            $this->fail('500 accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::HTTP_ERROR, $e->errorcode);
        }

        $rows = array_values($DB->get_records('local_aicb_ailog'));
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(deepseek_connector::NAME, $row->connector);
        $this->assertSame(0, (int) $row->tokensin);
        $this->assertSame(0, (int) $row->tokensout);
        $this->assertSame(0.0, (float) $row->cost);
        $this->assertSame(logging_connector::STATUS_ERROR, $row->status);
        $this->assertSame(500, (int) $row->httpstatus);
    }

    /**
     * Two attempts (as retrying_connector would produce) write two rows, one per attempt.
     */
    public function test_logs_one_row_per_attempt(): void {
        global $DB;
        $this->mock->append(new Response(500, [], '{"error":{"message":"boom"}}'));
        $this->mock->append(new Response(200, [], $this->completion_body('{"a":1}')));

        $logging = new logging_connector(new deepseek_connector(), deepseek_connector::NAME);
        (new retrying_connector($logging, new fake_clock()))->complete($this->make_request());

        $rows = array_values($DB->get_records('local_aicb_ailog', [], 'id'));
        $this->assertCount(2, $rows);
        $this->assertSame(logging_connector::STATUS_ERROR, $rows[0]->status);
        $this->assertSame(logging_connector::STATUS_SUCCESS, $rows[1]->status);
    }

    /**
     * No row is written for a request without a user (never happens for a real connector call, since
     * policy::require_accepted() would already have refused it, but the decorator itself is defensive).
     */
    public function test_no_user_writes_nothing(): void {
        global $DB;
        $connector = $this->createMock(connector::class);
        $connector->method('complete')->willReturn(new result(
            content: 'x',
            json: null,
            tokensin: 1,
            tokensout: 1,
            tokenscached: 0,
            cost: 0.01,
            model: 'm',
            durationms: 1,
        ));
        $request = new request(step: request::STEP_DIGEST, system: '', messages: [['role' => 'user', 'content' => 'x']]);

        (new logging_connector($connector))->complete($request);

        $this->assertSame(0, $DB->count_records('local_aicb_ailog'));
    }
}
