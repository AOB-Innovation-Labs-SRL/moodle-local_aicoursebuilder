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

namespace local_aicoursebuilder\ingest;

use core\check\result;
use local_aicoursebuilder\check\ocr;

/**
 * Tests for the state of the OCR on the server and the status check that shows it.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\ocr_status
 * @covers     \local_aicoursebuilder\check\ocr
 */
final class ocr_status_test extends \advanced_testcase {
    /** @var string What tesseract --list-langs prints on a server with Romanian. */
    private const LANGUAGES = "List of available languages (3):\neng\nosd\nron\n";

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('The programs of the test are shell scripts.');
        }
    }

    /**
     * Makes a program that prints something, and returns its path.
     *
     * @param string $name Name of the program.
     * @param string $output What it prints.
     * @return string Path of the program.
     */
    private function make_program(string $name, string $output = ''): string {
        $path = make_request_directory() . '/' . $name;
        file_put_contents($path, "#!/bin/sh\nprintf '%s' " . escapeshellarg($output) . "\n");
        chmod($path, 0755);
        return $path;
    }

    /**
     * Sets the paths of the programs, and what the fake tesseract lists as its languages.
     *
     * @param string $languages What tesseract --list-langs prints.
     * @param string $ocrlanguage The OCR language of the settings.
     */
    private function set_up_programs(string $languages = self::LANGUAGES, string $ocrlanguage = 'ron'): void {
        set_config('pdftoppmpath', $this->make_program('pdftoppm'), 'local_aicoursebuilder');
        set_config('tesseractpath', $this->make_program('tesseract', $languages), 'local_aicoursebuilder');
        set_config('ocrlanguage', $ocrlanguage, 'local_aicoursebuilder');
    }

    /**
     * With the programs, the language and proc_open, scanned PDF files can be read.
     */
    public function test_ok_when_everything_is_there(): void {
        $this->set_up_programs();

        $state = ocr_status::evaluate(true, true);

        $this->assertSame(ocr_status::OK, $state['status']);
        $this->assertSame('', $state['reason']);
        $this->assertStringContainsString('ron', $state['summary']);
    }

    /**
     * Without the paths, OCR is a warning and says that it is optional.
     */
    public function test_a_warning_when_the_paths_are_not_set(): void {
        $state = ocr_status::evaluate(true, true);

        $this->assertSame(ocr_status::WARNING, $state['status']);
        $this->assertSame(ocr_status::REASON_NO_PATHS, $state['reason']);
        $this->assertStringContainsString('optional', $state['summary']);
    }

    /**
     * A path that is set but has no program behind it is named.
     */
    public function test_a_warning_when_a_program_is_missing(): void {
        set_config('pdftoppmpath', '/nonexistent/pdftoppm', 'local_aicoursebuilder');
        set_config('tesseractpath', $this->make_program('tesseract'), 'local_aicoursebuilder');

        $state = ocr_status::evaluate(true, true);

        $this->assertSame(ocr_status::REASON_NO_PROGRAMS, $state['reason']);
        $this->assertStringContainsString('pdftoppm', $state['summary']);
        $this->assertStringNotContainsString('tesseract', $state['summary']);
    }

    /**
     * Programs that cannot be run because PHP may not run programs are a warning with that reason.
     */
    public function test_a_warning_when_proc_open_is_disabled(): void {
        $this->set_up_programs();

        $state = ocr_status::evaluate(true, false);

        $this->assertSame(ocr_status::WARNING, $state['status']);
        $this->assertSame(ocr_status::REASON_NO_PROC_OPEN, $state['reason']);
        $this->assertStringContainsString('proc_open', $state['summary']);
    }

    /**
     * A language that tesseract does not have is named, and so is the one that is missing of several.
     */
    public function test_a_warning_when_the_language_is_not_installed(): void {
        $this->set_up_programs("List of available languages (2):\neng\nosd\n");
        $state = ocr_status::evaluate(true, true);
        $this->assertSame(ocr_status::REASON_NO_LANGUAGE, $state['reason']);
        $this->assertStringContainsString('ron', $state['summary']);

        $this->set_up_programs(self::LANGUAGES, 'ron+deu');
        $state = ocr_status::evaluate(true, true);
        $this->assertSame(ocr_status::REASON_NO_LANGUAGE, $state['reason']);
        $this->assertStringContainsString('deu', $state['summary']);

        $this->set_up_programs(self::LANGUAGES, 'ron+eng');
        $this->assertSame(ocr_status::OK, ocr_status::evaluate(true, true)['status']);
    }

    /**
     * Without the language check, tesseract is not run: the paths and PHP are enough.
     */
    public function test_the_language_is_not_checked_when_it_is_not_asked(): void {
        $this->set_up_programs("List of available languages (1):\nosd\n");

        $this->assertSame(ocr_status::OK, ocr_status::evaluate(false, true)['status']);
    }

    /**
     * The languages are read from the list that tesseract prints, without its title.
     */
    public function test_parse_languages(): void {
        $output = "List of available languages (3):\neng\r\nosd\nron\n\n";

        $this->assertSame(['eng', 'osd', 'ron'], ocr_status::parse_languages($output));
        $this->assertSame([], ocr_status::parse_languages(''));
    }

    /**
     * The status check shows the state: OK when OCR works and a warning, never an error, when it does not.
     */
    public function test_the_status_check(): void {
        $check = new ocr();
        $this->assertNotSame('', $check->get_name());

        $warning = $check->get_result();
        $this->assertSame(result::WARNING, $warning->get_status());
        $this->assertNotSame('', $warning->get_summary());

        if (!ocr_status::is_proc_open_allowed()) {
            $this->markTestSkipped('proc_open is disabled here.');
        }
        $this->set_up_programs();
        $this->assertSame(result::OK, $check->get_result()->get_status());
    }

    /**
     * Moodle finds the check through the function of the plugin.
     */
    public function test_moodle_lists_the_check(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicoursebuilder/lib.php');

        $checks = local_aicoursebuilder_status_checks();

        $this->assertCount(1, $checks);
        $this->assertInstanceOf(ocr::class, $checks[0]);
        $found = array_filter(
            \core\check\manager::get_checks('status'),
            fn($check) => $check instanceof ocr
        );
        $this->assertCount(1, $found, 'The check is in the system status of Moodle');
    }
}
