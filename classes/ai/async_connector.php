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

use GuzzleHttp\Promise\PromiseInterface;

/**
 * Optional capability of a connector that can run its HTTP call asynchronously.
 *
 * A connector implements this only when its call is a single HTTP request that can be sent with
 * \core\http_client::sendAsync() against the client from the DI container, without building a new
 * handler stack (which would bypass the check_request security middleware). complete() stays
 * synchronous and independent of this interface; parallel_executor uses complete_async() only when
 * the connector offers it, and falls back to running complete() one call at a time otherwise.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface async_connector {
    /**
     * Sends one completion call asynchronously.
     *
     * The returned promise resolves to a result on success, or rejects with a connector_exception.
     * The promise must not throw synchronously: request validation errors (policy, unsupported
     * input, missing configuration) are also delivered as a rejection, so callers can run many of
     * these concurrently without a validation error in one aborting the others.
     *
     * @param request $request The completion request.
     * @return PromiseInterface Promise of a result.
     */
    public function complete_async(request $request): PromiseInterface;
}
