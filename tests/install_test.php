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
 * Tests that installation creates the tables, web services and message providers.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class install_test extends \advanced_testcase {
    /** @var string[] Tables defined in db/install.xml. */
    private const TABLES = [
        'local_aicb_job',
        'local_aicb_source',
        'local_aicb_chunk',
        'local_aicb_blueprint',
        'local_aicb_step',
        'local_aicb_ailog',
        'local_aicb_budget',
    ];

    /**
     * Every table exists, and install.xml defines exactly these tables.
     */
    public function test_tables_exist(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/xmldb/xmldb_file.php');

        $dbman = $DB->get_manager();
        foreach (self::TABLES as $table) {
            $this->assertTrue($dbman->table_exists($table), $table);
        }

        $file = new \xmldb_file(dirname(__DIR__) . '/db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        $names = array_map(fn($table) => $table->getName(), $file->getStructure()->getTables());
        $this->assertSame(self::TABLES, array_values($names));
    }

    /**
     * The build map, the approved blueprint link and the off-peak flag are stored on the job.
     */
    public function test_job_columns(): void {
        global $DB;
        $columns = $DB->get_columns('local_aicb_job');
        foreach (['userid', 'status', 'progress', 'buildmap', 'blueprintid', 'offpeak', 'estimatedcost'] as $column) {
            $this->assertArrayHasKey($column, $columns);
        }
        $this->assertEquals(1, $columns['offpeak']->default_value);
    }

    /**
     * The eight web services and the two message providers are registered.
     */
    public function test_services_and_message_providers(): void {
        global $DB;
        $functions = $DB->get_fieldset_select(
            'external_functions',
            'name',
            'component = ?',
            ['local_aicoursebuilder']
        );
        $expected = ['create_job', 'get_job_status', 'get_blueprint', 'save_blueprint', 'approve_blueprint',
            'estimate_cost', 'regenerate_node', 'start_job'];
        $this->assertEqualsCanonicalizing(
            array_map(fn($name) => 'local_aicoursebuilder_' . $name, $expected),
            $functions
        );

        $providers = $DB->get_fieldset_select('message_providers', 'name', 'component = ?', ['local_aicoursebuilder']);
        $this->assertEqualsCanonicalizing(['jobfinished', 'jobfailed'], $providers);
    }
}
