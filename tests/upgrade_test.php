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

use local_aicoursebuilder\ingest\extractor_factory;

/**
 * Tests for the upgrade steps.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class upgrade_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(dirname(__DIR__) . '/db/upgrade.php');
    }

    /**
     * Runs the upgrade as a site does that is on the version before the step: the installed version is set back
     * first, because the savepoint of the step refuses to set a version that is already installed.
     */
    private function run_upgrade(): void {
        set_config('version', 2026093003, 'local_aicoursebuilder');
        xmldb_local_aicoursebuilder_upgrade(2026093003);
    }

    /**
     * A site that has the list of the first version (PDF and DOCX) gets the source types that were added.
     */
    public function test_default_allowed_types_get_the_new_types(): void {
        foreach (['pdf,docx', 'docx,pdf'] as $saved) {
            set_config('allowedtypes', $saved, 'local_aicoursebuilder');

            $this->run_upgrade();

            $this->assertSame(
                implode(',', array_keys(extractor_factory::MIMETYPES)),
                get_config('local_aicoursebuilder', 'allowedtypes')
            );
        }
    }

    /**
     * A list that an administrator changed is not touched.
     */
    public function test_chosen_allowed_types_are_kept(): void {
        foreach (['pdf', 'docx', 'pdf,docx,txt', ''] as $saved) {
            set_config('allowedtypes', $saved, 'local_aicoursebuilder');

            $this->run_upgrade();

            $this->assertSame($saved, get_config('local_aicoursebuilder', 'allowedtypes'));
        }
    }

    /**
     * A site that never saved the setting keeps using the default, which has every type.
     */
    public function test_unsaved_allowed_types_stay_unsaved(): void {
        unset_config('allowedtypes', 'local_aicoursebuilder');

        $this->run_upgrade();

        $this->assertFalse(get_config('local_aicoursebuilder', 'allowedtypes'));
    }
}
