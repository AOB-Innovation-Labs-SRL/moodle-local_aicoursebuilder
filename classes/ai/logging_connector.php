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

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Decorator that writes one local_aicb_ailog row per HTTP attempt, success or failure (spec 3.3).
 *
 * Placed as the innermost decorator, wrapped by retrying_connector (sequential path) or by
 * parallel_executor's own per-round retry (async path), so every attempt is logged, including ones
 * that are later retried; never prompt or output content, only tokens, cost, model, duration and
 * status. complete_async() is only valid when the inner connector implements async_connector.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logging_connector implements async_connector, connector {
    /** @var string A call that returned a result. */
    public const STATUS_SUCCESS = 'success';

    /** @var string A call that raised a connector_exception. */
    public const STATUS_ERROR = 'error';

    /**
     * Creates the decorator.
     *
     * @param connector $inner Connector whose calls are logged.
     * @param string $connectorname Name written to the log on failure, when the inner connector
     *                              raised before a result (and its own name) was available.
     */
    public function __construct(
        /** @var connector Connector whose calls are logged. */
        protected connector $inner,
        /** @var string Name written to the log on failure, when the inner connector raised
         * before a result (and its own name) was available. */
        protected string $connectorname = '',
    ) {
    }

    #[\Override]
    public function complete(request $request): result {
        $start = hrtime(true);
        try {
            $result = $this->inner->complete($request);
        } catch (connector_exception $e) {
            $this->log($request, self::STATUS_ERROR, $e->httpstatus, self::duration_ms($start), null);
            throw $e;
        }
        $this->log($request, self::STATUS_SUCCESS, 0, self::duration_ms($start), $result);
        return $result;
    }

    /**
     * Sends one completion call asynchronously and logs it once it settles.
     *
     * @param request $request The completion request.
     * @return PromiseInterface Promise of a result.
     * @throws \coding_exception When the inner connector does not implement async_connector.
     */
    public function complete_async(request $request): PromiseInterface {
        if (!$this->inner instanceof async_connector) {
            throw new \coding_exception(get_class($this->inner) . ' does not implement async_connector');
        }
        $start = hrtime(true);
        return $this->inner->complete_async($request)->then(
            function (result $result) use ($request, $start): result {
                $this->log($request, self::STATUS_SUCCESS, 0, self::duration_ms($start), $result);
                return $result;
            },
            function (connector_exception $e) use ($request, $start): PromiseInterface {
                $this->log($request, self::STATUS_ERROR, $e->httpstatus, self::duration_ms($start), null);
                return Create::rejectionFor($e);
            }
        );
    }

    #[\Override]
    public function supports(string $capability): bool {
        return $this->inner->supports($capability);
    }

    #[\Override]
    public function estimate_cost(request $request): float {
        return $this->inner->estimate_cost($request);
    }

    #[\Override]
    public function count_tokens(string $text): int {
        return $this->inner->count_tokens($text);
    }

    /**
     * Writes one local_aicb_ailog row.
     *
     * @param request $request The completion request.
     * @param string $status One of the STATUS_* constants.
     * @param int $httpstatus HTTP status returned by the provider, 0 when there was none.
     * @param int $durationms Call duration in milliseconds.
     * @param result|null $result The result, null on failure.
     */
    protected function log(request $request, string $status, int $httpstatus, int $durationms, ?result $result): void {
        global $DB;
        if ($request->userid === null) {
            return;
        }
        $DB->insert_record('local_aicb_ailog', (object) [
            'userid' => $request->userid,
            'jobid' => $request->jobid,
            'step' => $request->step,
            'connector' => $result->connector ?? $this->connectorname,
            'model' => $result->model ?? '',
            'tokensin' => $result->tokensin ?? 0,
            'tokensout' => $result->tokensout ?? 0,
            'tokenscached' => $result->tokenscached ?? 0,
            'cost' => $result->cost ?? 0.0,
            'durationms' => $durationms,
            'status' => $status,
            'httpstatus' => $httpstatus ?: null,
            'timecreated' => time(),
        ]);
    }

    /**
     * Computes an elapsed duration in milliseconds from an hrtime(true) start.
     *
     * @param int $start Start time from hrtime(true).
     * @return int
     */
    protected static function duration_ms(int $start): int {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
