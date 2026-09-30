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

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for the parallel executor: concurrency, per-round retry and logging.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\parallel_executor
 */
final class parallel_executor_test extends \advanced_testcase {
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
     * Builds a request for the digest step, with a distinct message so requests can be told apart.
     *
     * @param string $key Sub-call key, used as the message content.
     * @return request
     */
    private function make_request(string $key): request {
        return new request(
            step: request::STEP_DIGEST,
            system: 'sys',
            messages: [['role' => 'user', 'content' => $key]],
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
     * All requests are sent before any response is awaited: proof of real concurrency, not sequencing.
     *
     * Middleware::history() (used by get_mocked_http_client()) only records a request once its
     * promise settles, so it cannot show dispatch order by itself. Dispatch is observed directly
     * instead: each queued response is a callable, and MockHandler invokes that callable the moment
     * the request is sent (GuzzleHttp\Handler\MockHandler::__invoke() shifts and calls it
     * synchronously, before returning a promise). Each callable here logs its own dispatch order and
     * returns a pending Promise that only resolves when Guzzle later awaits it. With the pool actually
     * running the 3 sub-calls concurrently at concurrency 3, all 3 are dispatched (and so logged)
     * before Pool ever awaits the first one's promise; a sequential (non-concurrent) implementation
     * would instead dispatch, then fully await, one sub-call at a time.
     */
    public function test_requests_are_dispatched_before_any_response_arrives(): void {
        $dispatched = [];
        $promises = [];
        for ($i = 0; $i < 3; $i++) {
            $body = $this->completion_body('{"i":' . $i . '}');
            $this->mock->append(function () use (&$dispatched, &$promises, $i, $body): Promise {
                $dispatched[] = $i;
                $promises[$i] = new Promise(function () use (&$promises, $i, $body): void {
                    $promises[$i]->resolve(new Response(200, [], $body));
                });
                return $promises[$i];
            });
        }

        $completed = [];
        $executor = new parallel_executor(new deepseek_connector(), deepseek_connector::NAME, new fake_clock(), concurrency: 3);
        $outcomes = $executor->run(
            ['a' => $this->make_request('a'), 'b' => $this->make_request('b'), 'c' => $this->make_request('c')],
            function (string $key, $outcome) use (&$completed): void {
                $completed[] = $key;
            }
        );

        $this->assertSame([0, 1, 2], $dispatched, 'All 3 sub-calls should dispatch before any is awaited');
        $this->assertCount(3, $outcomes);
        $this->assertCount(3, $completed);
        foreach ($outcomes as $outcome) {
            $this->assertInstanceOf(result::class, $outcome);
        }
    }

    /**
     * Regression test for a specific bug: run_concurrent() used to build each sub-call's Pool
     * closure as `fn() => $logging->complete_async(...)->then(...)`. An arrow function auto-captures
     * every variable used in its body by value, including ones only referenced inside a further
     * nested closure — so the nested `function () use (&$round)` bound to a private copy of $round,
     * not the $round declared in run_concurrent()'s loop. Every sub-call still fulfilled and its
     * `then()` callback still ran, but every write to $round was invisible to the caller: run()
     * returned an empty array and $oncomplete was never invoked, for any batch of requests, silently
     * (no exception, no warning). This test sends a single request through run_concurrent() and would
     * fail (0 outcomes, $oncomplete uncalled) if that bug were reintroduced, without depending on
     * timing, concurrency count, or retry behaviour to surface it.
     */
    public function test_pool_results_are_not_lost_to_closure_capture(): void {
        $this->mock->append(new Response(200, [], $this->completion_body('{"ok":true}')));

        $completed = [];
        $executor = new parallel_executor(new deepseek_connector(), deepseek_connector::NAME, new fake_clock());
        $outcomes = $executor->run(['a' => $this->make_request('a')], function (string $key, $outcome) use (&$completed): void {
            $completed[$key] = $outcome;
        });

        $this->assertCount(1, $outcomes, 'Pool result was lost: run() returned no outcomes');
        $this->assertArrayHasKey('a', $outcomes);
        $this->assertInstanceOf(result::class, $outcomes['a']);
        $this->assertSame(['ok' => true], $outcomes['a']->json);
        $this->assertCount(1, $completed, '$oncomplete was never called: the Pool result did not reach it');
    }

    /**
     * Six requests with concurrency 4 and one isolated failure: every $oncomplete callback fires, the
     * failure does not cancel the others, and outcomes keep their own keys.
     */
    public function test_six_requests_concurrency_four_one_isolated_failure(): void {
        for ($i = 0; $i < 6; $i++) {
            if ($i === 3) {
                $this->mock->append(new Response(400, [], '{"error":{"message":"bad"}}'));
            } else {
                $this->mock->append(new Response(200, [], $this->completion_body('{"i":' . $i . '}')));
            }
        }

        $completed = [];
        $requests = [];
        for ($i = 0; $i < 6; $i++) {
            $requests["k{$i}"] = $this->make_request("k{$i}");
        }

        $executor = new parallel_executor(new deepseek_connector(), deepseek_connector::NAME, new fake_clock(), concurrency: 4);
        $outcomes = $executor->run($requests, function (string $key, $outcome) use (&$completed): void {
            $completed[$key] = $outcome;
        });

        $this->assertCount(6, $outcomes);
        $this->assertCount(6, $completed);
        foreach ($requests as $key => $request) {
            $this->assertArrayHasKey($key, $outcomes);
            $this->assertSame($outcomes[$key], $completed[$key]);
        }
        $this->assertInstanceOf(connector_exception::class, $outcomes['k3']);
        foreach (['k0', 'k1', 'k2', 'k4', 'k5'] as $key) {
            $this->assertInstanceOf(result::class, $outcomes[$key]);
        }
    }

    /**
     * A retryable failure in one sub-call is retried in the next round while the others already
     * finished in round 1; the final result reflects the round-2 success.
     */
    public function test_retryable_failure_is_retried_in_next_round(): void {
        $this->mock->append(new Response(200, [], $this->completion_body('{"i":0}')));
        $this->mock->append(new Response(500, [], '{"error":{"message":"boom"}}'));
        $this->mock->append(new Response(200, [], $this->completion_body('{"i":1,"retry":true}')));

        $requests = ['a' => $this->make_request('a'), 'b' => $this->make_request('b')];
        $executor = new parallel_executor(
            new deepseek_connector(),
            deepseek_connector::NAME,
            new fake_clock(),
            concurrency: 2,
            maxattempts: 3,
            basedelayms: 10
        );
        $outcomes = $executor->run($requests, fn() => null);

        $this->assertSame(['i' => 0], $outcomes['a']->json);
        $this->assertSame(['i' => 1, 'retry' => true], $outcomes['b']->json);
        $this->assertCount(3, $this->history);
    }

    /**
     * A connector without async_connector runs its calls one at a time, still through the executor.
     */
    public function test_falls_back_to_sequential_without_async_connector(): void {
        $requests = ['a' => new request(
            request::STEP_DIGEST,
            '',
            [['role' => 'user', 'content' => 'a']],
            schema: ['type' => 'object']
        )];
        $executor = new parallel_executor(new fake_connector(), fake_connector::NAME, new fake_clock());
        $outcomes = $executor->run($requests, fn() => null);
        $this->assertInstanceOf(result::class, $outcomes['a']);
        $this->assertSame(fake_connector::NAME, $outcomes['a']->connector);
    }

    /**
     * An empty request set runs nothing and returns an empty array.
     */
    public function test_empty_requests(): void {
        $called = false;
        $executor = new parallel_executor(new deepseek_connector(), deepseek_connector::NAME);
        $outcomes = $executor->run([], function () use (&$called): void {
            $called = true;
        });
        $this->assertSame([], $outcomes);
        $this->assertFalse($called);
        $this->assertCount(0, $this->history);
    }
}
