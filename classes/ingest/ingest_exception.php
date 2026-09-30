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
 * Error raised while saving or extracting source files.
 *
 * The message never contains the content of the file.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ingest_exception extends \moodle_exception {
    /** @var string The draft area holds no file. */
    public const NO_FILES = 'sourcenofiles';

    /** @var string The file type is not in the allowed types. */
    public const TYPE_NOT_ALLOWED = 'sourcetypenotallowed';

    /** @var string The file is bigger than the maximum size. */
    public const TOO_LARGE = 'sourcetoolarge';

    /** @var string The job would hold more files than the maximum. */
    public const TOO_MANY = 'sourcetoomany';

    /** @var string The antivirus rejected the file. */
    public const INFECTED = 'sourceinfected';

    /** @var string The stored source file of a source row does not exist. */
    public const FILE_MISSING = 'sourcefilemissing';

    /** @var string No extractor exists for the type of the file. */
    public const UNSUPPORTED = 'extractorunsupported';

    /** @var string The PDF is encrypted. */
    public const PDF_ENCRYPTED = 'pdfencrypted';

    /** @var string The extraction gave no text. */
    public const NO_TEXT = 'extractionnotext';

    /** @var string The extraction failed. */
    public const EXTRACTION_FAILED = 'extractionfailed';

    /** @var string The AI answer for the digest of a source is not a usable digest. */
    public const DIGEST_INVALID = 'digestinvalid';

    /** @var string An external command failed. */
    public const COMMAND_FAILED = 'commandfailed';

    /** @var string An external command ran longer than its timeout. */
    public const COMMAND_TIMEOUT = 'commandtimeout';

    /**
     * Creates the exception.
     *
     * @param string $errorcode One of the class constants, also the language string key.
     * @param mixed $a Language string placeholder values.
     * @param string|null $debuginfo Extra detail for developers.
     */
    public function __construct(string $errorcode, mixed $a = null, ?string $debuginfo = null) {
        parent::__construct($errorcode, 'local_aicoursebuilder', '', $a, $debuginfo);
    }
}
