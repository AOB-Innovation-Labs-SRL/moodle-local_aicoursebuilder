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

use GuzzleHttp\Pool;

/**
 * Runs independent AI calls concurrently, with retry and logging (spec 3.8).
 *
 * complete() is synchronous, so a Pool of closures that call it would not overlap the HTTP waits:
 * true concurrency needs async_connector::complete_async(), sent through Guzzle's Pool with the
 * configured concurrency. A connector that does not implement async_connector runs its calls one at
 * a time instead, each one still retried and logged the same way.
 *
 * Retry runs by round: a whole batch is sent concurrently, the retryable failures are collected,
 * the executor sleeps once (respecting the longest Retry-After of the round, or a computed backoff)
 * and only the failed sub-calls are resent in the next round, up to maxattempts rounds in total. A
 * non-retryable failure, or a failure on the last round, is final: it is delivered to $oncomplete
 * without cancelling or delaying the other sub-calls, which keep progressing in their own rounds.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parallel_executor {
    /** @var int Default concurrency. */
    public const DEFAULT_CONCURRENCY = 4;

    /** @var int Minimum concurrency accepted from the setting. */
    public const MIN_CONCURRENCY = 1;

    /** @var int Maximum concurrency accepted from the setting. */
    public const MAX_CONCURRENCY = 6;

    /**
     * Creates the executor.
     *
     * @param connector $connector Connector the requests run on (async_connector for real
     *                             concurrency), already the raw one from router::raw_connector().
     * @param string $connectorname Connector name, for the logged rows and the retry policy.
     * @param clock $clock Time source used for the between-round sleep.
     * @param int|null $concurrency Concurrent sub-calls, null for the pool_concurrency setting.
     * @param int|null $maxattempts Rounds per sub-call, null for the retry_maxattempts setting.
     * @param int|null $basedelayms Base backoff delay, null for the retry_basedelayms setting.
     */
    public function __construct(
        /** @var connector Connector the requests run on. */
        protected connector $connector,
        /** @var string Connector name, for the logged rows and the retry policy. */
        protected string $connectorname,
        /** @var clock Time source used for the between-round sleep. */
        protected clock $clock = new system_clock(),
        /** @var int|null Concurrent sub-calls, null for the pool_concurrency setting. */
        protected ?int $concurrency = null,
        /** @var int|null Rounds per sub-call, null for the retry_maxattempts setting. */
        protected ?int $maxattempts = null,
        /** @var int|null Base backoff delay, null for the retry_basedelayms setting. */
        protected ?int $basedelayms = null,
    ) {
    }

    /**
     * Runs a set of requests, calling $oncomplete once per key as soon as that sub-call is final.
     *
     * @param request[] $requests Requests keyed by an arbitrary sub-call key.
     * @param callable $oncomplete Called for each key as function(string|int, result|connector_exception): void.
     * @return array Final outcome (result or connector_exception) per key, same keys as $requests.
     */
    public function run(array $requests, callable $oncomplete): array {
        if ($requests === []) {
            return [];
        }
        $logging = new logging_connector($this->connector, $this->connectorname);
        if ($this->connector instanceof async_connector) {
            return $this->run_concurrent($logging, $requests, $oncomplete);
        }
        return $this->run_sequential($logging, $requests, $oncomplete);
    }

    /**
     * Runs the requests one at a time, through retrying_connector.
     *
     * @param connector $logging The logging-wrapped connector.
     * @param request[] $requests Requests keyed by an arbitrary sub-call key.
     * @param callable $oncomplete Callback, see run().
     * @return array Final outcome (result or connector_exception) per key.
     */
    protected function run_sequential(connector $logging, array $requests, callable $oncomplete): array {
        $retrying = new retrying_connector($logging, $this->clock, $this->get_maxattempts(), $this->get_basedelayms());
        $outcomes = [];
        foreach ($requests as $key => $request) {
            try {
                $outcomes[$key] = $retrying->complete($request);
            } catch (connector_exception $e) {
                $outcomes[$key] = $e;
            }
            $oncomplete($key, $outcomes[$key]);
        }
        return $outcomes;
    }

    /**
     * Runs the requests concurrently, in rounds, through the connector's complete_async().
     *
     * @param connector $logging The logging-wrapped connector, also an async_connector.
     * @param request[] $requests Requests keyed by an arbitrary sub-call key.
     * @param callable $oncomplete Callback, see run().
     * @return array Final outcome (result or connector_exception) per key.
     */
    protected function run_concurrent(connector $logging, array $requests, callable $oncomplete): array {
        $concurrency = $this->get_concurrency();
        $maxattempts = $this->get_maxattempts();
        $basedelayms = $this->get_basedelayms();
        $outcomes = [];
        $pending = $requests;

        for ($attempt = 1; $pending !== []; $attempt++) {
            $round = [];
            $retryable = [];
            $funcs = [];
            foreach ($pending as $key => $request) {
                // A plain closure, not fn(): an arrow function auto-captures &$round by value, so
                // the nested use (&$round) below would bind to a copy instead of this $round.
                $funcs[$key] = function () use ($logging, $request, $key, &$round) {
                    return $logging->complete_async($request)->then(
                        function (result $result) use ($key, &$round): void {
                            $round[$key] = $result;
                        },
                        function (connector_exception $e) use ($key, &$round): void {
                            $round[$key] = $e;
                        }
                    );
                };
            }

            (new Pool($this->http_client(), $funcs, ['concurrency' => $concurrency]))->promise()->wait();

            $delayms = 0;
            foreach ($round as $key => $outcome) {
                $final = $attempt >= $maxattempts
                    || !($outcome instanceof connector_exception)
                    || !retrying_connector::is_retryable($outcome);
                if ($final) {
                    $outcomes[$key] = $outcome;
                    $oncomplete($key, $outcome);
                } else {
                    $retryable[$key] = $pending[$key];
                    $delayms = max($delayms, retrying_connector::delay_for($attempt, $basedelayms, $outcome));
                }
            }

            $pending = $retryable;
            if ($pending !== []) {
                $this->clock->sleep($delayms);
            }
        }

        return $outcomes;
    }

    /**
     * Returns the \core\http_client instance from the DI container, used as the Pool's client.
     *
     * Pool sends each yielded closure's promise through the client it is given, but here the
     * closures already build their own promise via the connector; the client is only required by
     * Pool's constructor signature and is never used to send a request directly.
     *
     * @return \core\http_client
     */
    protected function http_client(): \core\http_client {
        return \core\di::get(\core\http_client::class);
    }

    /**
     * Returns the configured concurrency, clamped to [MIN_CONCURRENCY, MAX_CONCURRENCY].
     *
     * @return int
     */
    protected function get_concurrency(): int {
        if ($this->concurrency !== null) {
            $value = $this->concurrency;
        } else {
            $configured = (int) get_config('local_aicoursebuilder', 'pool_concurrency');
            $value = $configured > 0 ? $configured : self::DEFAULT_CONCURRENCY;
        }
        return max(self::MIN_CONCURRENCY, min(self::MAX_CONCURRENCY, $value));
    }

    /**
     * Returns the configured maximum number of attempts.
     *
     * @return int
     */
    protected function get_maxattempts(): int {
        $value = $this->maxattempts ?? (int) get_config('local_aicoursebuilder', 'retry_maxattempts');
        return $value > 0 ? $value : retrying_connector::DEFAULT_MAXATTEMPTS;
    }

    /**
     * Returns the configured base backoff delay in milliseconds.
     *
     * @return int
     */
    protected function get_basedelayms(): int {
        $value = $this->basedelayms ?? (int) get_config('local_aicoursebuilder', 'retry_basedelayms');
        return $value > 0 ? $value : retrying_connector::DEFAULT_BASEDELAYMS;
    }
}
