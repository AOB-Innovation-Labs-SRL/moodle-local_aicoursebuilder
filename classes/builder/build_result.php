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

namespace local_aicoursebuilder\builder;

/**
 * Immutable outcome of building one blueprint node.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class build_result {
    /** @var string The node was created. */
    public const STATUS_CREATED = 'created';

    /** @var string The node already existed (idempotent re-run). */
    public const STATUS_SKIPPED = 'skipped';

    /** @var string The node failed. */
    public const STATUS_FAILED = 'failed';

    /** @var string The node needs manual completion by the teacher. */
    public const STATUS_MANUAL = 'manual';

    /** @var string[] All statuses. */
    public const STATUSES = [self::STATUS_CREATED, self::STATUS_SKIPPED, self::STATUS_FAILED, self::STATUS_MANUAL];

    /**
     * Creates the result.
     *
     * @param string $nodeid Blueprint node id (s1, s1-1, s1.page1).
     * @param string $status One of the STATUS_* constants.
     * @param int|null $cmid Course module id, when a module was created.
     * @param int|null $instanceid Module instance id, when a module was created.
     * @param int|null $sectionnum Section number the node was placed in, or the section it created.
     * @param string[] $warnings Non-fatal problems.
     * @param string|null $error Error message when the status is failed.
     */
    public function __construct(
        /** @var string Blueprint node id. */
        public readonly string $nodeid,
        /** @var string Status. */
        public readonly string $status,
        /** @var int|null Course module id. */
        public readonly ?int $cmid = null,
        /** @var int|null Module instance id. */
        public readonly ?int $instanceid = null,
        /** @var int|null Section number. */
        public readonly ?int $sectionnum = null,
        /** @var string[] Warnings. */
        public readonly array $warnings = [],
        /** @var string|null Error message. */
        public readonly ?string $error = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \coding_exception("Unknown build status: {$status}");
        }
    }

    /**
     * Tells whether the node exists in the course after this build.
     *
     * @return bool
     */
    public function is_success(): bool {
        return $this->status === self::STATUS_CREATED || $this->status === self::STATUS_SKIPPED;
    }

    /**
     * Returns the entry stored in local_aicb_job.buildmap for this node.
     *
     * @return array ['cmid' => ?int, 'instanceid' => ?int, 'sectionnum' => ?int, 'status' => string]
     */
    public function to_buildmap_entry(): array {
        return [
            'cmid' => $this->cmid,
            'instanceid' => $this->instanceid,
            'sectionnum' => $this->sectionnum,
            'status' => $this->status,
        ];
    }
}
