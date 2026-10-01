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

/**
 * Upgrade steps for local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin data.
 *
 * The schema in db/install.xml is the first released version; the steps below change settings only.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_local_aicoursebuilder_upgrade(int $oldversion): bool {
    if ($oldversion < 2026093005) {
        // The source types PPTX, XLSX, TXT, Markdown and HTML were added. A site that saved the list of the first
        // version (PDF and DOCX, what the setting offered when it was saved) gets the new types too; a list that
        // an administrator changed, or that already has other types, is left as it is.
        $allowed = get_config('local_aicoursebuilder', 'allowedtypes');
        if ($allowed !== false) {
            $types = explode(',', $allowed);
            sort($types);
            if ($types === ['docx', 'pdf']) {
                set_config(
                    'allowedtypes',
                    implode(',', array_keys(\local_aicoursebuilder\ingest\extractor_factory::MIMETYPES)),
                    'local_aicoursebuilder'
                );
            }
        }
        upgrade_plugin_savepoint(true, 2026093005, 'local', 'aicoursebuilder');
    }

    return true;
}
