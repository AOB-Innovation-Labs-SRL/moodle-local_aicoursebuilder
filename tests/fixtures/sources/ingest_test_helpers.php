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
 * Helpers shared by the extractor tests: storing a file, finding a program, a fake soffice.
 *
 * Use it in an advanced_testcase and load it with require_once.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait ingest_test_helpers {
    /**
     * Stores a file in the source area.
     *
     * @param string $content File content.
     * @param string $filename File name.
     * @return \stored_file
     */
    private function store(string $content, string $filename): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'source',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Returns the path of a program, or skips the test when it is not installed.
     *
     * @param string $name Name of the program, for example pdftotext.
     * @return string
     */
    private function require_program(string $name): string {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($path === '' || !command_runner::is_available($path)) {
            $this->markTestSkipped($name . ' is not installed.');
        }
        return $path;
    }

    /**
     * Writes a shell script that takes the place of soffice: it copies a PDF to the output directory.
     *
     * @param string $pdf PDF content that the script "converts" to.
     * @param bool $fail True for a script that exits with an error and writes nothing.
     * @return string Path of the script.
     */
    private function fake_soffice(string $pdf, bool $fail = false): string {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('The fake soffice is a shell script.');
        }
        $directory = make_request_directory();
        file_put_contents($directory . '/converted.pdf', $pdf);
        $script = "#!/bin/sh\n";
        if ($fail) {
            $script .= "exit 1\n";
        } else {
            $script .= "out=''\n"
                . "while [ \$# -gt 0 ]; do\n"
                . "  if [ \"\$1\" = '--outdir' ]; then out=\"\$2\"; fi\n"
                . "  shift\n"
                . "done\n"
                . 'cp ' . escapeshellarg($directory . '/converted.pdf') . " \"\$out/source.pdf\"\n";
        }
        file_put_contents($directory . '/soffice', $script);
        chmod($directory . '/soffice', 0755);
        return $directory . '/soffice';
    }
}
