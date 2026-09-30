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

use core\lock\lock;
use core\lock\lock_factory;

/**
 * Lock factory for tests: in-process bookkeeping, so a resource "held" by the test blocks
 * budget_guard the same way a real concurrent process would, without needing a second connection.
 *
 * Real lock factories (Postgres advisory locks and the like) are session-scoped, so a single
 * PHPUnit run cannot simulate contention by taking a lock and then calling code that takes the
 * "same" lock again on the same DB connection: the session already holds it and reentry succeeds.
 * This fake tracks held resources in PHP memory instead, so the test and the code under test
 * genuinely contend for the same resource key.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_lock_factory implements lock_factory {
    /** @var array<string, true> Resources currently held. */
    protected array $held = [];

    /**
     * Creates the factory; the type namespace is unused, held resources are not scoped by it.
     *
     * @param string $type Unused.
     */
    public function __construct($type = 'test') {
    }

    /**
     * Tells whether get_lock() honours its timeout.
     *
     * @return bool Always true.
     */
    public function supports_timeout() {
        return true;
    }

    /**
     * Tells whether locks are released automatically when the process ends.
     *
     * @return bool Always true.
     */
    public function supports_auto_release() {
        return true;
    }

    /**
     * Tells whether this lock type is available.
     *
     * @return bool Always true.
     */
    public function is_available() {
        return true;
    }

    /**
     * Takes a lock on a resource, unless it is already held.
     *
     * @param string $resource The resource key.
     * @param int $timeout Unused: this fake never waits, it fails immediately if held.
     * @param int $maxlifetime Unused.
     * @return lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if (isset($this->held[$resource])) {
            return false;
        }
        $this->held[$resource] = true;
        return new lock($resource, $this);
    }

    /**
     * Releases a lock obtained with get_lock().
     *
     * @param lock $lock The lock to release.
     * @return bool Always true.
     */
    public function release_lock(lock $lock) {
        unset($this->held[$lock->get_key()]);
        return true;
    }

    /**
     * Marks a resource as already held, as if by another process, without returning a lock object.
     *
     * @param string $resource The resource key.
     */
    public function hold_externally(string $resource): void {
        $this->held[$resource] = true;
    }

    /**
     * Releases a resource marked as externally held.
     *
     * @param string $resource The resource key.
     */
    public function release_externally(string $resource): void {
        unset($this->held[$resource]);
    }
}
