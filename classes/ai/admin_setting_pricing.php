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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting for a per-connector pricing table: a JSON textarea, validated at save time.
 *
 * The value is never rejected outright: pricing::table_for() already falls back to
 * pricing::DEFAULT_PRICES for an empty or invalid value, so the site keeps working even with a
 * malformed setting. Save-time validation only warns the administrator instead of saving silently
 * broken JSON (spec 3.3, 8).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_pricing extends \admin_setting_configtextarea {
    #[\Override]
    public function write_setting($data) {
        $data = trim((string) $data);
        if ($data !== '' && !pricing::is_valid_table(json_decode($data, true))) {
            return get_string('pricinginvalidjson', 'local_aicoursebuilder');
        }
        return parent::write_setting($data);
    }
}
