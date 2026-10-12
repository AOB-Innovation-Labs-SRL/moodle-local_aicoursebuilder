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
 * Tells whether a scanned PDF can be read on this server, and if it cannot, why.
 *
 * OCR is an optional dependency: the plugin installs and runs without it, and only the PDF files that have no text
 * layer cannot be read. The state is worked out in one place, so that the status check of Moodle (Site administration >
 * Reports > System status) and the line in the settings say the same thing.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ocr_status {
    /** @var string State: scanned PDF files can be read. */
    public const OK = 'ok';

    /** @var string State: scanned PDF files cannot be read. */
    public const WARNING = 'warning';

    /** @var string Reason: the paths of pdftoppm and tesseract are not set. */
    public const REASON_NO_PATHS = 'nopaths';

    /** @var string Reason: a path is set but its program is missing or cannot be run. */
    public const REASON_NO_PROGRAMS = 'noprograms';

    /** @var string Reason: PHP may not run programs, because proc_open is disabled. */
    public const REASON_NO_PROC_OPEN = 'noprocopen';

    /** @var string Reason: tesseract does not have the language data of the OCR language. */
    public const REASON_NO_LANGUAGE = 'nolanguage';

    /** @var string Reason: tesseract could not be run to list its languages. */
    public const REASON_TESSERACT_FAILED = 'tesseractfailed';

    /** @var int Seconds after which tesseract is killed when it lists its languages. */
    public const LIST_TIMEOUT = 15;

    /**
     * Works out whether scanned PDF files can be read.
     *
     * @param bool $checklanguage Whether to run tesseract to see that it has the language data; the paths and PHP are
     *                            checked either way.
     * @param bool|null $procopenallowed Whether proc_open may be used, null to look at the PHP settings; for tests.
     * @return array ['status' => OK or WARNING, 'reason' => REASON_* or '', 'summary' => localised text]
     */
    public static function evaluate(bool $checklanguage = true, ?bool $procopenallowed = null): array {
        $paths = [
            'pdftoppm' => get_config('local_aicoursebuilder', 'pdftoppmpath'),
            'tesseract' => get_config('local_aicoursebuilder', 'tesseractpath'),
        ];
        $empty = array_filter($paths, fn($path) => empty($path));
        if (count($empty) === count($paths)) {
            return self::warning(self::REASON_NO_PATHS);
        }
        $missing = array_keys(array_filter($paths, fn($path) => !command_runner::is_available($path)));
        if ($missing) {
            return self::warning(self::REASON_NO_PROGRAMS, implode(', ', $missing));
        }
        if (!($procopenallowed ?? self::is_proc_open_allowed())) {
            return self::warning(self::REASON_NO_PROC_OPEN);
        }

        $language = (new extractor_ocr())->get_language();
        if ($checklanguage) {
            try {
                $output = command_runner::run($paths['tesseract'], ['--list-langs'], self::LIST_TIMEOUT);
            } catch (ingest_exception $e) {
                return self::warning(self::REASON_TESSERACT_FAILED, $e->getMessage());
            }
            $absent = array_diff(explode('+', $language), self::parse_languages($output));
            if ($absent) {
                return self::warning(self::REASON_NO_LANGUAGE, implode(', ', $absent));
            }
        }
        return [
            'status' => self::OK,
            'reason' => '',
            'summary' => get_string('ocrstatus_ok', 'local_aicoursebuilder', $language),
        ];
    }

    /**
     * Tells whether PHP may run programs.
     *
     * @return bool False when proc_open does not exist or is in disable_functions.
     */
    public static function is_proc_open_allowed(): bool {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }

    /**
     * Reads the language codes from what tesseract --list-langs prints.
     *
     * @param string $output Standard output of tesseract.
     * @return string[] Language codes.
     */
    public static function parse_languages(string $output): array {
        $languages = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            // The first line is a title ("List of available languages (3):").
            if ($line !== '' && preg_match('/^[A-Za-z0-9_]+$/', $line)) {
                $languages[] = $line;
            }
        }
        return $languages;
    }

    /**
     * Builds the answer for a server that cannot read scanned PDF files.
     *
     * @param string $reason One of the REASON_* constants.
     * @param string $a What the text names, such as the missing programs.
     * @return array
     */
    private static function warning(string $reason, string $a = ''): array {
        return [
            'status' => self::WARNING,
            'reason' => $reason,
            'summary' => get_string('ocrstatus_' . $reason, 'local_aicoursebuilder', $a),
        ];
    }
}
