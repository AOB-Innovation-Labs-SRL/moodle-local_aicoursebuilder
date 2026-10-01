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
 * Imports the questions of one quiz node into the question bank with qformat_xml (spec 3.7).
 *
 * Every quiz node gets a subcategory of its own under the default category of the bank, which is what
 * makes a re-run safe: the subcategory belongs to that node of that job and to nothing else, so it can be
 * emptied and refilled without ever touching a question a teacher wrote. It is found again by its
 * idnumber, not by its name: (contextid, idnumber) is a unique index on question_categories, while a name
 * is translated and could be changed by hand.
 *
 * The order of a re-run is not interchangeable. question_delete_question() refuses to delete a question
 * that is still used somewhere and marks its version hidden instead, so the quiz of the previous run has
 * to be deleted first: only then are the slots and the question_references gone, questions_in_use() is
 * false, and the questions really disappear instead of lingering as hidden versions. The quiz builder
 * deletes the orphan module before it calls this importer.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_importer {
    /**
     * Imports the questions of one quiz node, replacing whatever an earlier run of the same node left.
     *
     * @param array $questions Blueprint questions of the quiz node.
     * @param \context_module $bankcontext Context of the mod_qbank instance that receives them.
     * @param \stdClass $course The course being built.
     * @param string $key Idnumber of the subcategory of this node, from build_key.
     * @param string $categoryname Name of the subcategory, shown to the teacher.
     * @return int[] Ids of the imported questions, in the order of the blueprint.
     * @throws \moodle_exception When the questions cannot be generated or imported.
     */
    public function import(
        array $questions,
        \context_module $bankcontext,
        \stdClass $course,
        string $key,
        string $categoryname,
    ): array {
        $category = $this->prepare_category($bankcontext, $key, $categoryname);
        $xml = (new question_xml())->generate($questions);
        return $this->run_import($xml, $category, $bankcontext, $course);
    }

    /**
     * Returns the subcategory of a node, emptied of anything an earlier run put in it.
     *
     * @param \context_module $bankcontext Context of the question bank.
     * @param string $key Idnumber of the subcategory.
     * @param string $categoryname Name of the subcategory.
     * @return \stdClass The question_categories record.
     * @throws \moodle_exception When the bank has no default category to put it under.
     */
    protected function prepare_category(\context_module $bankcontext, string $key, string $categoryname): \stdClass {
        global $DB;

        $existing = $DB->get_record('question_categories', ['contextid' => $bankcontext->id, 'idnumber' => $key]);
        if ($existing) {
            $this->empty_category($existing);
            return $existing;
        }

        // The deprecated question_make_default_categories() is forbidden by spec 3.7: the default category
        // of the bank comes from question_get_default_category(), which creates the top category if needed.
        $parent = question_get_default_category($bankcontext->id, true);
        if (!$parent) {
            throw new \moodle_exception('errorqbanknocategory', 'local_aicoursebuilder');
        }

        $category = (object) [
            'name' => shorten_text($categoryname, 1333),
            'contextid' => $bankcontext->id,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'parent' => $parent->id,
            'sortorder' => 999,
            'stamp' => make_unique_id_code(),
            'idnumber' => $key,
        ];
        $category->id = $DB->insert_record('question_categories', $category);
        return $category;
    }

    /**
     * Deletes every question an earlier run left in the subcategory of this node.
     *
     * The quiz that used them has already been deleted by the caller, so none of them is in use any more
     * and question_delete_question() really removes them rather than hiding them.
     *
     * @param \stdClass $category The subcategory.
     */
    protected function empty_category(\stdClass $category): void {
        global $DB;

        $sql = "SELECT qv.questionid
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                 WHERE qbe.questioncategoryid = :categoryid";
        $questionids = $DB->get_fieldset_sql($sql, ['categoryid' => $category->id]);
        foreach ($questionids as $questionid) {
            question_delete_question((int) $questionid);
        }
    }

    /**
     * Runs qformat_xml over the generated document and returns the ids it created.
     *
     * The format writes progress straight to the output, which must not reach the task log or a web
     * response, so the whole import runs inside an output buffer (spec 3.7).
     *
     * @param string $xml The Moodle XML document.
     * @param \stdClass $category The category the questions go into.
     * @param \context_module $bankcontext Context of the question bank.
     * @param \stdClass $course The course being built.
     * @return int[] Ids of the imported questions, in the order of the document.
     * @throws \moodle_exception When the import fails.
     */
    protected function run_import(
        string $xml,
        \stdClass $category,
        \context_module $bankcontext,
        \stdClass $course,
    ): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $filename = make_request_directory() . '/questions.xml';
        file_put_contents($filename, $xml);

        $format = new \qformat_xml();
        $format->setCategory($category);
        $format->setContexts([$bankcontext]);
        $format->setCourse($course);
        $format->setFilename($filename);
        // The category and the context come from us, never from the file, so that the questions cannot be
        // redirected somewhere else by what the model generated.
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        // A question that does not import is a failed build, not a quiz silently missing a question.
        $format->setStoponerror(true);

        ob_start();
        try {
            $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            unlink($filename);
        }

        if (!$ok) {
            throw new \moodle_exception('errorquestionimport', 'local_aicoursebuilder', '', html_to_text($output));
        }
        return array_map('intval', $format->questionids);
    }
}
