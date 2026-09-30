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
 * Chooses the extractor of a source file by its mimetype.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extractor_factory {
    /** @var string[] Supported source types (file extension) and their mimetype. */
    public const MIMETYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'html' => 'text/html',
    ];

    /**
     * Returns the extractor of a mimetype.
     *
     * @param string $mimetype The mimetype.
     * @return extractor
     * @throws ingest_exception When no extractor supports the mimetype.
     */
    public static function for_mimetype(string $mimetype): extractor {
        return match ($mimetype) {
            self::MIMETYPES['pdf'] => new extractor_pdf(),
            self::MIMETYPES['docx'] => new extractor_docx(),
            self::MIMETYPES['pptx'] => new extractor_pptx(),
            self::MIMETYPES['xlsx'] => new extractor_xlsx(),
            self::MIMETYPES['txt'], self::MIMETYPES['md'], self::MIMETYPES['html'] => new extractor_text(),
            default => throw new ingest_exception(ingest_exception::UNSUPPORTED, $mimetype),
        };
    }

    /**
     * Returns the extractor of a stored file.
     *
     * The mimetype decides. Moodle does not know every type (it has no Markdown type, for one), so
     * when the mimetype has no extractor the extension of the file name is tried.
     *
     * @param \stored_file $file The source file.
     * @return extractor
     * @throws ingest_exception When no extractor supports the file.
     */
    public static function for_file(\stored_file $file): extractor {
        try {
            return self::for_mimetype($file->get_mimetype());
        } catch (ingest_exception $e) {
            $type = self::type_for_filename($file->get_filename());
            if ($type === null) {
                throw $e;
            }
            return self::for_mimetype(self::MIMETYPES[$type]);
        }
    }

    /**
     * Returns the source type of a file name, from its extension.
     *
     * @param string $filename The file name.
     * @return string|null A key of MIMETYPES, null when the extension is not supported.
     */
    public static function type_for_filename(string $filename): ?string {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return array_key_exists($extension, self::MIMETYPES) ? $extension : null;
    }
}
