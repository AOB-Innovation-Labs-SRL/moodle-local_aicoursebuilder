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

namespace local_aicoursebuilder;

/**
 * Tests that the administrator guide documents every setting of settings.php.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_documented_test extends \advanced_testcase {
    /**
     * Returns the names of the settings in settings.php, a name made for each connector or step as `pricing_{name}`.
     *
     * Headings are not settings. The names in the guide are written the same way, in code formatting.
     *
     * @return string[]
     */
    private function setting_names(): array {
        $source = file_get_contents(dirname(__DIR__) . '/settings.php');
        preg_match_all('~["\']local_aicoursebuilder/([A-Za-z0-9_{}$]+)["\']~', $source, $matches);
        $names = [];
        foreach ($matches[1] as $name) {
            if (str_ends_with($name, 'heading')) {
                continue;
            }
            $names[] = str_replace(['{$name}', '{$step}'], ['{name}', '{step}'], $name);
        }
        return array_values(array_unique($names));
    }

    /**
     * Every setting has a row in a table of docs/admin-guide.md: its name in code formatting is the first cell.
     */
    public function test_every_setting_is_in_the_admin_guide(): void {
        $guide = file_get_contents(dirname(__DIR__) . '/docs/admin-guide.md');
        $names = $this->setting_names();
        $this->assertGreaterThan(40, count($names), 'settings.php was not read.');

        $tick = chr(96);
        $missing = array_filter($names, function (string $name) use ($guide, $tick): bool {
            $row = '~^\|\s*' . $tick . preg_quote($name, '~') . $tick . '\s*\|~m';
            return !preg_match($row, $guide);
        });
        $this->assertSame([], array_values($missing), 'Settings without a row in a table of docs/admin-guide.md.');
    }
}
