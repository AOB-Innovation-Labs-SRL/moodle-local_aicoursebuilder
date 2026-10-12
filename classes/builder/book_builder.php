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

namespace local_aicoursebuilder\builder;

/**
 * Builds a book: the module, then its chapters.
 *
 * The book module has no API to add a chapter, only the form of its chapter page, so the chapters are inserted the
 * way that page does it: a row in book_chapters per chapter, in order, the revision of the book raised so that
 * what the students have cached is renewed, and the event that says a chapter was created. A book whose chapters
 * cannot be saved is deleted again by the base builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_builder extends module_builder {
    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'book';
    }

    /**
     * Adds the layout options of the book.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        $defaults = $this->get_defaults(['numbering', 'navstyle']);
        $info->numbering = (int) ($defaults['numbering'] ?? 1);
        $info->navstyle = (int) ($defaults['navstyle'] ?? 1);
        $info->customtitles = 0;
        $info->revision = 1;
    }

    /**
     * Saves the chapters of the book.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
        $this->add_chapters((int) $created->instance, (int) $created->coursemodule, $node['content']['chapters'] ?? []);
    }

    /**
     * Inserts the chapters of a book in order, then raises its revision.
     *
     * @param int $bookid Id of the book.
     * @param int $cmid Course module id of the book.
     * @param array $chapters The chapters of the blueprint: title, content and subchapter.
     */
    protected function add_chapters(int $bookid, int $cmid, array $chapters): void {
        global $DB;

        $modulecontext = \context_module::instance($cmid);
        $pagenum = 0;
        foreach ($chapters as $chapter) {
            $pagenum++;
            $now = time();
            $chapterid = $DB->insert_record('book_chapters', (object) [
                'bookid' => $bookid,
                'pagenum' => $pagenum,
                // The first chapter cannot be a subchapter: it has no chapter to belong to.
                'subchapter' => $pagenum > 1 && !empty($chapter['subchapter']) ? 1 : 0,
                'title' => self::clean_name((string) ($chapter['title'] ?? '')),
                'content' => self::clean_html((string) ($chapter['content'] ?? '')),
                'contentformat' => FORMAT_HTML,
                'hidden' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
                'importsrc' => '',
            ]);
            \mod_book\event\chapter_created::create([
                'context' => $modulecontext,
                'objectid' => $chapterid,
                'other' => ['bookid' => $bookid],
            ])->trigger();
        }
        $revision = (int) $DB->get_field('book', 'revision', ['id' => $bookid], MUST_EXIST);
        $DB->set_field('book', 'revision', $revision + 1, ['id' => $bookid]);
    }
}
