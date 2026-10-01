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
 * One chunk of the text of a source, with the metadata that the digest and the generation steps refer to.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chunk {
    /**
     * Creates the chunk.
     *
     * @param int $index Order of the chunk in the source, from 0.
     * @param string|null $title Nearest heading of the start of the chunk, null when the text has no heading yet.
     * @param int|null $pagefrom First page of the chunk, null when the source has no pages.
     * @param int|null $pageto Last page of the chunk, null when the source has no pages.
     * @param string $content Text of the chunk as Markdown, without page markers.
     * @param int $tokencount Estimated tokens of the content.
     */
    public function __construct(
        /** @var int Order of the chunk in the source. */
        public readonly int $index,
        /** @var string|null Nearest heading. */
        public readonly ?string $title,
        /** @var int|null First page. */
        public readonly ?int $pagefrom,
        /** @var int|null Last page. */
        public readonly ?int $pageto,
        /** @var string Text of the chunk. */
        public readonly string $content,
        /** @var int Estimated tokens. */
        public readonly int $tokencount,
    ) {
    }
}
