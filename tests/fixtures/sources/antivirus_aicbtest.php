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

namespace antivirus_aicbtest;

/**
 * Antivirus scanner for the ingest tests: it reports a virus in a file that holds INFECTED_MARKER.
 *
 * The core testable scanner decides from the file name, which the ingest tests cannot choose
 * because the name must have a supported extension. Enable it with $CFG->antiviruses = 'aicbtest'.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scanner extends \core\antivirus\scanner {
    /** @var string Text that makes the scanner report a virus. */
    public const INFECTED_MARKER = 'AICB-TEST-INFECTED';

    /** @var string[] Names of the files scanned since reset(). */
    public static array $scanned = [];

    /**
     * Forgets the scanned files.
     */
    public static function reset(): void {
        self::$scanned = [];
    }

    /**
     * Tells that the scanner needs no settings.
     *
     * @return bool
     */
    #[\Override]
    public function is_configured() {
        return true;
    }

    /**
     * Scans a file: it is infected when it holds INFECTED_MARKER.
     *
     * @param string $file Full path of the file.
     * @param string $filename Name of the file.
     * @return int Scanning result constant.
     */
    #[\Override]
    public function scan_file($file, $filename) {
        self::$scanned[] = $filename;
        if (str_contains((string) file_get_contents($file), self::INFECTED_MARKER)) {
            return self::SCAN_RESULT_FOUND;
        }
        return self::SCAN_RESULT_OK;
    }
}
