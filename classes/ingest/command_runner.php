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
 * Runs an external program with escaped arguments and a timeout.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class command_runner {
    /** @var int Bytes of standard error kept for the debug info. */
    private const STDERR_LIMIT = 4096;

    /**
     * Tells whether a configured path is an executable file; an empty path means "not configured".
     *
     * @param string|false|null $path Path from the settings.
     * @return bool
     */
    public static function is_available(string|false|null $path): bool {
        return !empty($path) && is_file($path) && is_executable($path);
    }

    /**
     * Runs a program and returns what it wrote to standard output.
     *
     * @param string $executable Full path of the program.
     * @param string[] $arguments Arguments, each one escaped with escapeshellarg().
     * @param int $timeout Seconds after which the program is killed.
     * @return string Standard output.
     * @throws ingest_exception When the program cannot start, times out or exits with an error.
     */
    public static function run(string $executable, array $arguments, int $timeout): string {
        $name = basename($executable);
        // On Linux "exec" replaces the shell, so the timeout kills the program itself, not only the shell.
        $command = (DIRECTORY_SEPARATOR === '/' ? 'exec ' : '') . escapeshellarg($executable);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new ingest_exception(ingest_exception::COMMAND_FAILED, $name);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitcode = null;
        $deadline = microtime(true) + $timeout;
        while ($exitcode === null) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitcode = $status['exitcode'];
            } else if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new ingest_exception(ingest_exception::COMMAND_TIMEOUT, $name);
            }
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 100000)) {
                foreach ($read as $stream) {
                    $chunk = (string) fread($stream, 65536);
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else if (strlen($stderr) < self::STDERR_LIMIT) {
                        $stderr .= $chunk;
                    }
                }
            }
        }
        // The program has exited: read what is left in the pipes.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($exitcode !== 0) {
            $debuginfo = 'exit code ' . $exitcode . ': ' . substr(trim($stderr), 0, self::STDERR_LIMIT);
            throw new ingest_exception(ingest_exception::COMMAND_FAILED, $name, $debuginfo);
        }
        return $stdout;
    }
}
