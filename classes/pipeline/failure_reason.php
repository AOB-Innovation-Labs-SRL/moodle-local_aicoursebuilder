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

namespace local_aicoursebuilder\pipeline;

use local_aicoursebuilder\ai\connector_exception;
use local_aicoursebuilder\blueprint\validation_error;

/**
 * Why a node was left for manual completion, as stored in local_aicb_step.error.
 *
 * The reason is a small JSON object: the type of failure, the HTTP status when there was one, the
 * path and code of the first validator errors, and how many calls the node took. It never carries
 * a validator message or anything the model wrote, since either can quote the sources or the
 * teacher's brief, and the column is shown to whoever can see the job.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class failure_reason {
    /** @var string The answer did not validate and no repair was attempted. */
    public const TYPE_VALIDATION = 'validation';

    /** @var string The answer still did not validate after every repair call. */
    public const TYPE_REPAIR_EXHAUSTED = 'repair_exhausted';

    /** @var string The provider answered with an HTTP error or could not be reached. */
    public const TYPE_HTTP = 'http';

    /** @var string The call ran out of time. */
    public const TYPE_TIMEOUT = 'timeout';

    /** @var int How many validator errors are kept. */
    public const MAX_ERRORS = 5;

    /**
     * Describes a node whose answer never validated.
     *
     * @param validation_error[] $errors The errors of the last answer.
     * @param int $repairs Repair calls made.
     * @param int $attempts Calls made in all, the first one included.
     * @return string JSON reason.
     */
    public static function from_validation(array $errors, int $repairs, int $attempts): string {
        $kept = [];
        foreach (array_slice(array_values($errors), 0, self::MAX_ERRORS) as $error) {
            $kept[] = ['path' => $error->path, 'code' => $error->code];
        }
        return self::encode([
            'type' => $repairs > 0 ? self::TYPE_REPAIR_EXHAUSTED : self::TYPE_VALIDATION,
            'httpcode' => 0,
            'errors' => $kept,
            'errorcount' => count($errors),
            'repairs' => $repairs,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Describes a node whose calls failed at the provider.
     *
     * A provider that answered with something other than JSON is a validation failure of the
     * first answer, not a transport one, so it is reported as such.
     *
     * @param connector_exception $e The failure.
     * @param int $attempts Calls made in all, the failed one included.
     * @return string JSON reason.
     */
    public static function from_connector(connector_exception $e, int $attempts): string {
        if ($e->errorcode === connector_exception::INVALID_JSON) {
            return self::encode([
                'type' => self::TYPE_VALIDATION,
                'httpcode' => 0,
                'errors' => [['path' => '', 'code' => validation_error::CODE_NOT_JSON]],
                'errorcount' => 1,
                'repairs' => 0,
                'attempts' => $attempts,
            ]);
        }
        return self::encode([
            'type' => self::is_timeout($e) ? self::TYPE_TIMEOUT : self::TYPE_HTTP,
            'httpcode' => $e->httpstatus,
            'code' => $e->errorcode,
            'errors' => [],
            'errorcount' => 0,
            'repairs' => 0,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Reads a stored reason back.
     *
     * @param string|null $stored The value of local_aicb_step.error.
     * @return array|null The reason, or null when the column holds none or holds a plain message.
     */
    public static function decode(?string $stored): ?array {
        if ($stored === null || $stored === '') {
            return null;
        }
        $reason = json_decode($stored, true);
        return is_array($reason) && isset($reason['type']) ? $reason : null;
    }

    /**
     * Tells whether a transport failure was the request timing out.
     *
     * Guzzle reports a timeout as a connect exception whose message carries cURL error 28.
     *
     * @param connector_exception $e The failure.
     * @return bool
     */
    protected static function is_timeout(connector_exception $e): bool {
        if ($e->httpstatus === 408 || $e->httpstatus === 504) {
            return true;
        }
        if ($e->errorcode !== connector_exception::NETWORK_ERROR) {
            return false;
        }
        return (bool) preg_match('/cURL error 28|timed out|timeout/i', (string) $e->debuginfo);
    }

    /**
     * Encodes a reason.
     *
     * @param array $reason The reason.
     * @return string
     */
    protected static function encode(array $reason): string {
        return (string) json_encode($reason, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
