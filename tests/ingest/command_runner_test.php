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

/**
 * Tests for the external command runner and the Markdown helpers.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\command_runner
 * @covers     \local_aicoursebuilder\ingest\markdown
 */
final class command_runner_test extends \advanced_testcase {
    /**
     * Returns the path of a program, or skips the test when it is not available.
     *
     * @param string $name Program name.
     * @return string
     */
    private function require_program(string $name): string {
        $path = trim((string) shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($path === '') {
            $this->markTestSkipped($name . ' is not available.');
        }
        return $path;
    }

    /**
     * Arguments reach the program as they are, with no shell interpretation.
     */
    public function test_arguments_are_escaped(): void {
        $echo = $this->require_program('echo');
        $argument = 'a b \'c\' "d" $HOME $(id) ; echo x';

        $output = command_runner::run($echo, [$argument], 5);

        $this->assertSame($argument . "\n", $output);
    }

    /**
     * A program that runs too long is stopped.
     */
    public function test_timeout(): void {
        $sleep = $this->require_program('sleep');

        $started = microtime(true);
        try {
            command_runner::run($sleep, ['30'], 1);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::COMMAND_TIMEOUT, $e->errorcode);
            $this->assertStringContainsString('sleep', $e->getMessage());
        }
        $this->assertLessThan(10, microtime(true) - $started);
    }

    /**
     * A program that exits with an error is reported with its exit code.
     */
    public function test_failure(): void {
        $false = $this->require_program('false');

        try {
            command_runner::run($false, [], 5);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::COMMAND_FAILED, $e->errorcode);
            $this->assertStringContainsString('exit code 1', $e->debuginfo);
        }
    }

    /**
     * Only an existing executable file counts as available; an empty path means "not configured".
     */
    public function test_is_available(): void {
        $this->assertFalse(command_runner::is_available(''));
        $this->assertFalse(command_runner::is_available(false));
        $this->assertFalse(command_runner::is_available(null));
        $this->assertFalse(command_runner::is_available('/nonexistent/bin/tool'));
        $this->assertFalse(command_runner::is_available(sys_get_temp_dir()));
        $this->assertTrue(command_runner::is_available($this->require_program('echo')));
    }

    /**
     * Extracted text is cleaned: LF line ends, no trailing spaces, at most one empty line in a row.
     */
    public function test_markdown_tidy(): void {
        $this->assertSame(
            "one\n\ntwo three",
            markdown::tidy("  one  \r\n\r\n\r\n\r\ntwo\tthree \n\n")
        );
        $this->assertSame('', markdown::tidy(" \n\t\n"));
    }

    /**
     * Pages are joined with a marker; the marker keeps the page number when a page has no text.
     */
    public function test_markdown_join_pages(): void {
        $this->assertSame(
            "<!-- page 1 -->\n\nfirst\n\n<!-- page 3 -->\n\nthird",
            markdown::join_pages(['first', " \n", 'third'])
        );
        $this->assertSame('', markdown::join_pages([' ', '']));
        $this->assertSame('', markdown::join_pages([]));
    }

    /**
     * A table has a header separator, escaped pipes, and is padded to the widest row.
     */
    public function test_markdown_table(): void {
        $this->assertSame(
            "| A | B |\n| --- | --- |\n| 1 \\| 2 | x y |\n| only |  |",
            markdown::table([['A', 'B'], ["1 | 2", "x\n  y"], ['only']])
        );
        $this->assertSame('', markdown::table([]));
    }
}
