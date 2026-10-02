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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/builder_test_helpers.php');

/**
 * Tests of the glossary builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\glossary_builder
 */
final class glossary_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * The glossary of the golden blueprint has its entries, their definitions and their aliases.
     */
    public function test_builds_the_glossary_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new glossary_builder())->build($this->node('s1.glossary1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame([], $result->warnings);
        $this->assertSame('glossary', $this->get_cm($result)->modname);
        $this->assertSame('Glosar de termeni', $this->get_cm($result)->name);

        $glossary = $DB->get_record('glossary', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('dictionary', $glossary->displayformat);
        $this->assertEquals(0, $glossary->mainglossary);

        $entries = $DB->get_records('glossary_entries', ['glossaryid' => $glossary->id], 'id');
        $this->assertCount(2, $entries);
        $entry = reset($entries);
        $this->assertSame('Fotovoltaic', $entry->concept);
        $this->assertSame('<p>Conversia directă a luminii în electricitate.</p>', $entry->definition);
        $this->assertEquals(FORMAT_HTML, $entry->definitionformat);
        $this->assertEquals($this->teacher->id, $entry->userid);
        $this->assertEquals(1, $entry->approved);
        $this->assertEquals(1, $entry->teacherentry);
        $this->assertSame(['PV'], array_values($DB->get_fieldset_select('glossary_alias', 'alias', 'entryid = ?', [$entry->id])));
        $this->assertSame([], $DB->get_fieldset_select('glossary_alias', 'alias', 'entryid = ?', [next($entries)->id]));
    }

    /**
     * Every entry fires the event of a new entry.
     */
    public function test_every_entry_fires_its_event(): void {
        $sink = $this->redirectEvents();

        (new glossary_builder())->build($this->node('s1.glossary1'), $this->make_context());

        $created = array_filter($sink->get_events(), fn($event) => $event instanceof \mod_glossary\event\entry_created);
        $this->assertCount(2, $created);
    }

    /**
     * The settings are the site defaults of a new glossary and of a new entry.
     */
    public function test_settings_come_from_the_site_defaults(): void {
        global $DB;
        set_config('glossary_entbypage', 25);
        set_config('glossary_allowcomments', 1);
        set_config('glossary_linkentries', 1);
        set_config('glossary_casesensitive', 1);

        $result = (new glossary_builder())->build($this->node('s1.glossary1'), $this->make_context());

        $glossary = $DB->get_record('glossary', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(25, $glossary->entbypage);
        $this->assertEquals(1, $glossary->allowcomments);
        $entry = $DB->get_record('glossary_entries', ['glossaryid' => $glossary->id, 'concept' => 'Fotovoltaic'], '*', MUST_EXIST);
        $this->assertEquals(1, $entry->usedynalink);
        $this->assertEquals(1, $entry->casesensitive);
    }

    /**
     * The definition is cleaned, and the concept and the aliases are plain text.
     */
    public function test_the_text_is_cleaned(): void {
        global $DB;
        $node = $this->node('s1.glossary1');
        $node['content']['entries'] = [[
            'concept' => '<b>Eolian</b>',
            'definition' => '<p>Vânt</p><script>x()</script>',
            'aliases' => ['<i>wind</i>', '  ', 'turbine  eoliene'],
        ]];

        $result = (new glossary_builder())->build($node, $this->make_context());

        $entry = $DB->get_record('glossary_entries', ['glossaryid' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('Eolian', $entry->concept);
        $this->assertStringNotContainsString('script', $entry->definition);
        $aliases = $DB->get_fieldset_select('glossary_alias', 'alias', 'entryid = ? ORDER BY id', [$entry->id]);
        $this->assertSame(['wind', 'turbine eoliene'], array_values($aliases));
    }

    /**
     * A concept that comes twice is saved once, with a warning.
     */
    public function test_a_duplicate_concept_is_left_out(): void {
        global $DB;
        $node = $this->node('s1.glossary1');
        $node['content']['entries'][] = ['concept' => 'FOTOVOLTAIC', 'definition' => '<p>Altă definiție.</p>'];

        $result = (new glossary_builder())->build($node, $this->make_context());

        $this->assertSame(2, $DB->count_records('glossary_entries', ['glossaryid' => $result->instanceid]));
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('FOTOVOLTAIC', $result->warnings[0]);
    }

    /**
     * Manual completion of the golden blueprint is set.
     */
    public function test_completion_is_set(): void {
        $result = (new glossary_builder())->build($this->node('s1.glossary1'), $this->make_context());

        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->get_cm($result)->completion);
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new glossary_builder();
        $this->record($context, $builder->build($this->node('s1.glossary1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s1.glossary1'), $context)->status);
        $this->assertSame(1, $DB->count_records('glossary'));
    }
}
