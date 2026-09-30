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

/**
 * Class loader for the libraries in thirdparty/ (the plugin has no Composer vendor directory).
 *
 * Load it with require_once before using Smalot\PdfParser, PhpOffice\PhpWord or PhpOffice\Math.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

spl_autoload_register(function (string $class): void {
    // Namespace prefix => directory holding the classes of that namespace.
    static $map = [
        'Smalot\\PdfParser\\' => __DIR__ . '/pdfparser/src/Smalot/PdfParser/',
        'PhpOffice\\PhpWord\\' => __DIR__ . '/phpword/src/PhpWord/',
        'PhpOffice\\Math\\' => __DIR__ . '/phpoffice-math/src/Math/',
    ];

    foreach ($map as $prefix => $directory) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_readable($file)) {
                require_once($file);
            }
            return;
        }
    }
});
