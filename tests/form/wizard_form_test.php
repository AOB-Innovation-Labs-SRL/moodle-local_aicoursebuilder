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

namespace local_aicoursebuilder\form;

/**
 * Tests for the source limits of the wizard form.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\form\wizard_form
 */
final class wizard_form_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The options of the filemanager come from the settings.
     */
    public function test_filemanager_options_follow_the_settings(): void {
        set_config('maxfilesize', 1, 'local_aicoursebuilder');
        set_config('maxfiles', 3, 'local_aicoursebuilder');
        set_config('allowedtypes', 'pdf,docx', 'local_aicoursebuilder');

        $options = wizard_form::filemanager_options();

        $this->assertSame(1048576, $options['maxbytes']);
        $this->assertSame(3, $options['maxfiles']);
        $this->assertEqualsCanonicalizing(['.pdf', '.docx'], $options['accepted_types']);
    }

    /**
     * Without an allowed type the picker accepts anything, and the server refuses every file.
     */
    public function test_filemanager_options_without_types(): void {
        set_config('allowedtypes', '', 'local_aicoursebuilder');

        $this->assertSame('*', wizard_form::filemanager_options()['accepted_types']);
    }

    /**
     * The help of the sources step states the limits of the settings, not the defaults.
     */
    public function test_sources_help_states_the_limits(): void {
        set_config('maxfilesize', 1, 'local_aicoursebuilder');
        set_config('maxfiles', 3, 'local_aicoursebuilder');
        set_config('allowedtypes', 'pdf,docx', 'local_aicoursebuilder');

        $help = wizard_form::sources_help();

        // The function display_size() separates the number from the unit with a non-breaking space.
        $this->assertStringContainsString("Up to 3 files of at most 1.0\u{00A0}MB each", $help);
        $this->assertStringContainsString('.pdf', $help);
        $this->assertStringContainsString('.docx', $help);
        $this->assertStringNotContainsString('.pptx', $help);
    }

    /**
     * A site maximum below the setting is the limit a teacher meets, so the help states that one.
     */
    public function test_sources_help_is_lowered_to_the_site_maximum(): void {
        global $CFG;
        set_config('maxfilesize', 50, 'local_aicoursebuilder');

        $CFG->maxbytes = 2097152;
        $this->assertStringContainsString("at most 2.0\u{00A0}MB each", wizard_form::sources_help());

        $CFG->maxbytes = 0;
        $this->assertStringContainsString("at most 50.0\u{00A0}MB each", wizard_form::sources_help());

        // A site maximum above the setting changes nothing.
        $CFG->maxbytes = 104857600;
        $this->assertStringContainsString("at most 50.0\u{00A0}MB each", wizard_form::sources_help());
    }

    /**
     * The help is written in both languages of the plugin, and each has a place for every limit.
     *
     * The language files are read directly: the string manager falls back to English for a language that is not
     * installed on the site, which would let a broken Romanian string pass.
     */
    public function test_sources_help_strings_in_both_languages(): void {
        foreach (['en', 'ro'] as $lang) {
            $string = [];
            include(__DIR__ . '/../../lang/' . $lang . '/local_aicoursebuilder.php');
            foreach (['{$a->maxsize}', '{$a->maxfiles}', '{$a->types}'] as $placeholder) {
                $this->assertStringContainsString($placeholder, $string['wizard:sourceshelp'], $lang);
            }
        }
    }
}
