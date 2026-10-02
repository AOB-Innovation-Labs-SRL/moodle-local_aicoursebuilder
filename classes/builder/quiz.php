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

use mod_quiz\question\display_options;
use mod_quiz\quiz_settings;

/**
 * Builds one quiz node of the blueprint, with its questions (spec 3.7, 5).
 *
 * The module is made with create_module() over prepare_new_moduleinfo_data(), as spec 3.7 requires of
 * every builder, so that the defaults of the site and of the activity are in place before the blueprint
 * is laid over them. quiz_add_instance() reads a good deal more than the blueprint carries, including
 * fields the form always submits and the database has no default for, so the builder fills the whole set
 * from get_config('quiz').
 *
 * Building a quiz is not one write: the module is created, then its questions are imported, then the
 * slots are added. The checkpoint in the build map is only written once all of that has succeeded, so a
 * run that dies half way leaves a quiz behind that the build map does not know about. That orphan is
 * found again by its idnumber (build_key) and deleted before anything else, which also frees its
 * questions: question_delete_question() will not delete a question that is still in a quiz, it hides it
 * instead, so emptying the question subcategory only works once the quiz is gone.
 *
 * Random questions are not built here: the blueprint carries explicit questions only.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz implements builder_interface {
    /** @var string The module this builder creates. */
    public const MODNAME = 'quiz';

    /** @var int Minutes to seconds, for timelimit_minutes of the blueprint. */
    protected const MINUTE = 60;

    /** @var qbank_resolver The question bank resolver. */
    protected qbank_resolver $bankresolver;

    /** @var question_importer The question importer. */
    protected question_importer $importer;

    /**
     * Creates the builder.
     *
     * @param qbank_resolver|null $bankresolver Resolver of the question bank, null for the default one.
     * @param question_importer|null $importer Importer of the questions, null for the default one.
     */
    public function __construct(?qbank_resolver $bankresolver = null, ?question_importer $importer = null) {
        $this->bankresolver = $bankresolver ?? new qbank_resolver();
        $this->importer = $importer ?? new question_importer();
    }

    /**
     * Builds one quiz node.
     *
     * @param array|\stdClass $node The blueprint quiz node.
     * @param build_context $context The build context.
     * @return build_result
     */
    #[\Override]
    public function build(array|\stdClass $node, build_context $context): build_result {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');

        $node = (array) $node;
        $nodeid = (string) ($node['id'] ?? '');
        if ($context->is_built($nodeid)) {
            return new build_result($nodeid, build_result::STATUS_SKIPPED);
        }

        $course = $context->get_course();
        $key = build_key::for_node($context->jobid, $nodeid);
        $warnings = [];

        // An earlier run may have created the quiz and died before the checkpoint. Delete it first: it
        // must be gone before the questions of that run can be deleted rather than merely hidden.
        $this->delete_orphan($course, $key);

        $sectionnum = $this->resolve_sectionnum($node, $context);
        $moduleinfo = $this->moduleinfo($node, $course, $sectionnum, $key);
        $created = create_module($moduleinfo);

        $quizrecord = $this->quiz_record((int) $created->instance, (int) $created->coursemodule);
        $questions = $this->questions($node);
        if ($questions !== []) {
            $bankcontext = $this->bankresolver->resolve($context);
            $warnings = $this->bankresolver->get_warnings();
            $questionids = $this->importer->import(
                $questions,
                $bankcontext,
                $course,
                $key,
                get_string('qbankcategoryname', 'local_aicoursebuilder', (string) ($node['name'] ?? $nodeid)),
            );
            $this->add_questions($questionids, $quizrecord);
            // The slots carry the marks, so the total of the quiz is only right once they are all in.
            quiz_settings::create($quizrecord->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        }

        return new build_result(
            $nodeid,
            build_result::STATUS_CREATED,
            (int) $created->coursemodule,
            (int) $created->instance,
            $sectionnum,
            $warnings,
        );
    }

    /**
     * Deletes the quiz an earlier run of this node left behind, when there is one.
     *
     * The index on course_modules.idnumber is not unique, so this deletes every module carrying the key
     * rather than assuming there is at most one. The deletion is synchronous: the questions of the old
     * run can only be removed once nothing uses them, and an asynchronous deletion would still be pending.
     *
     * @param \stdClass $course The course being built.
     * @param string $key Idnumber of this node, from build_key.
     */
    protected function delete_orphan(\stdClass $course, string $key): void {
        global $DB;

        $orphans = $DB->get_records('course_modules', ['course' => $course->id, 'idnumber' => $key], 'id', 'id');
        if ($orphans === []) {
            return;
        }
        // The global course_delete_module() is deprecated since 5.2 (MDL-86856) in favour of the
        // format actions, which both supported branches have.
        $cmactions = new \core_courseformat\local\cmactions($course);
        foreach ($orphans as $orphan) {
            $cmactions->delete((int) $orphan->id, false);
        }
    }

    /**
     * Returns the section number the activity is built in.
     *
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     * @return int Section number, 0 when the section of the node is not known.
     */
    protected function resolve_sectionnum(array $node, build_context $context): int {
        $nodeid = (string) ($node['id'] ?? '');
        $sectionid = strtok($nodeid, '.');
        if ($sectionid !== false) {
            $sectionnum = $context->get_sectionnum($sectionid);
            if ($sectionnum !== null) {
                return $sectionnum;
            }
        }
        return 0;
    }

    /**
     * Returns the moduleinfo passed to create_module().
     *
     * It starts from prepare_new_moduleinfo_data(), which brings the completion and visibility defaults of
     * the site, then the full set of fields quiz_add_instance() reads, then what the blueprint says.
     *
     * @param array $node The blueprint quiz node.
     * @param \stdClass $course The course being built.
     * @param int $sectionnum Section the module goes in.
     * @param string $key Idnumber of this node, from build_key.
     * @return \stdClass The moduleinfo.
     */
    protected function moduleinfo(array $node, \stdClass $course, int $sectionnum, string $key): \stdClass {
        [$module, $modulecontext, $cw, $cm, $moduleinfo] =
            prepare_new_moduleinfo_data($course, self::MODNAME, $sectionnum);
        unset($module, $modulecontext, $cw, $cm);

        $moduleinfo = (object) array_merge((array) $moduleinfo, $this->defaults());
        $content = (array) ($node['content'] ?? []);

        $moduleinfo->modulename = self::MODNAME;
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->name = (string) ($node['name'] ?? '');
        // Note create_module() takes the idnumber of the course module from cmidnumber, and copies it
        // onto the grade item too; idnumber on its own would be silently ignored.
        $moduleinfo->cmidnumber = $key;
        $moduleinfo->introeditor = [
            'text' => (string) ($node['intro'] ?? ''),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];

        if (isset($content['grade'])) {
            $moduleinfo->grade = (float) $content['grade'];
        }
        if (isset($content['attempts'])) {
            $moduleinfo->attempts = (int) $content['attempts'];
        }
        if (isset($content['timelimit_minutes'])) {
            $moduleinfo->timelimit = (int) $content['timelimit_minutes'] * self::MINUTE;
        }
        if (isset($content['questionsperpage'])) {
            $moduleinfo->questionsperpage = (int) $content['questionsperpage'];
        }
        if (isset($content['gradepass'])) {
            $moduleinfo->gradepass = (float) $content['gradepass'];
        }

        $this->apply_completion($moduleinfo, (array) ($node['completion'] ?? []));
        return $moduleinfo;
    }

    /**
     * Returns the fields quiz_add_instance() reads, with the defaults configured for the site.
     *
     * Two groups of them have no default in the database and are always submitted by the form, so
     * quiz_process_options() would fail or warn without them: quizpassword, which it reads unconditionally,
     * and the review options, which it rebuilds from one checkbox per moment out of the eight bitmasks the
     * administrator configured.
     *
     * @return array Field name => value.
     */
    protected function defaults(): array {
        $config = get_config('quiz');
        $get = fn(string $name, $default) => isset($config->$name) && $config->$name !== ''
            ? $config->$name
            : $default;

        $defaults = [
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => (int) $get('timelimit', 0),
            'overduehandling' => (string) $get('overduehandling', 'autoabandon'),
            'graceperiod' => (int) $get('graceperiod', 0),
            'preferredbehaviour' => (string) $get('preferredbehaviour', 'deferredfeedback'),
            'canredoquestions' => (int) $get('canredoquestions', 0),
            'attempts' => (int) $get('attempts', 0),
            'attemptonlast' => (int) $get('attemptonlast', 0),
            'grademethod' => (int) $get('grademethod', QUIZ_GRADEHIGHEST),
            'decimalpoints' => (int) $get('decimalpoints', 2),
            'questiondecimalpoints' => (int) $get('questiondecimalpoints', -1),
            'questionsperpage' => (int) $get('questionsperpage', 1),
            'navmethod' => (string) $get('navmethod', 'free'),
            'shuffleanswers' => (int) $get('shuffleanswers', 1),
            'grade' => (float) $get('maximumgrade', 10),
            'sumgrades' => 0,
            'showuserpicture' => (int) $get('showuserpicture', 0),
            'showblocks' => (int) $get('showblocks', 0),
            'delay1' => (int) $get('delay1', 0),
            'delay2' => (int) $get('delay2', 0),
            'browsersecurity' => (string) $get('browsersecurity', '-'),
            'allowofflineattempts' => (int) $get('allowofflineattempts', 0),
            'subnet' => (string) $get('subnet', ''),
            // Read unconditionally by quiz_process_options(), which moves it to the password field.
            'quizpassword' => (string) $get('password', ''),
            'completionattemptsexhausted' => 0,
            'completionminattempts' => 0,
            'completionminattemptsenabled' => 0,
            // Without this, quiz_process_options() leaves the completion fields alone.
            'completionunlocked' => 1,
        ];

        return array_merge($defaults, $this->review_defaults($config));
    }

    /**
     * Returns the review options as the one checkbox per moment that quiz_process_options() expects.
     *
     * The administrator configures each of the eight options as a bitmask of the four moments; the form
     * submits attemptduring, attemptimmediately and so on, and quiz_review_option_form_to_db() folds them
     * back into the bitmask. The builder has to hand them over in the form shape.
     *
     * @param \stdClass $config The configuration of mod_quiz.
     * @return array Field name => 1, for every moment that is on.
     */
    protected function review_defaults(\stdClass $config): array {
        $fields = [
            'attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
            'rightanswer', 'overallfeedback',
        ];
        $moments = [
            'during' => display_options::DURING,
            'immediately' => display_options::IMMEDIATELY_AFTER,
            'open' => display_options::LATER_WHILE_OPEN,
            'closed' => display_options::AFTER_CLOSE,
        ];
        $alwayson = display_options::DURING | display_options::IMMEDIATELY_AFTER
            | display_options::LATER_WHILE_OPEN | display_options::AFTER_CLOSE;

        $values = [];
        foreach ($fields as $field) {
            $name = 'review' . $field;
            $mask = isset($config->$name) ? (int) $config->$name : $alwayson;
            foreach ($moments as $moment => $bit) {
                $values[$field . $moment] = ($mask & $bit) ? 1 : 0;
            }
        }
        return $values;
    }

    /**
     * Applies the completion rules of the blueprint to the moduleinfo.
     *
     * @param \stdClass $moduleinfo The moduleinfo, changed in place.
     * @param array $completion The completion node of the blueprint.
     */
    protected function apply_completion(\stdClass $moduleinfo, array $completion): void {
        $mode = (string) ($completion['mode'] ?? '');
        $moduleinfo->completion = match ($mode) {
            'manual' => COMPLETION_TRACKING_MANUAL,
            'auto' => COMPLETION_TRACKING_AUTOMATIC,
            'none' => COMPLETION_TRACKING_NONE,
            default => $moduleinfo->completion ?? COMPLETION_TRACKING_NONE,
        };
        if ($moduleinfo->completion !== COMPLETION_TRACKING_AUTOMATIC) {
            return;
        }
        $moduleinfo->completionview = !empty($completion['view']) ? 1 : 0;
        $moduleinfo->completionusegrade = !empty($completion['usegrade']) ? 1 : 0;
        $moduleinfo->completionpassgrade = !empty($completion['passgrade']) ? 1 : 0;
        if ($moduleinfo->completionpassgrade) {
            // A pass grade can only be required of an activity that is graded at all.
            $moduleinfo->completionusegrade = 1;
        }
    }

    /**
     * Returns the questions of a quiz node.
     *
     * @param array $node The blueprint quiz node.
     * @return array The questions, possibly empty.
     */
    protected function questions(array $node): array {
        $content = (array) ($node['content'] ?? []);
        return array_map(fn($question): array => (array) $question, (array) ($content['questions'] ?? []));
    }

    /**
     * Adds the imported questions to the quiz, in the order of the blueprint.
     *
     * The page is left to quiz_add_quiz_question(), which starts a new page every questionsperpage
     * questions; passing a page number instead would have to repeat that arithmetic.
     *
     * @param int[] $questionids Ids of the imported questions.
     * @param \stdClass $quiz The quiz record, with cmid.
     */
    protected function add_questions(array $questionids, \stdClass $quiz): void {
        foreach ($questionids as $questionid) {
            quiz_add_quiz_question($questionid, $quiz, 0);
        }
    }

    /**
     * Returns the quiz record, with the cmid that quiz_add_quiz_question() needs.
     *
     * @param int $instanceid Quiz instance id.
     * @param int $cmid Course module id.
     * @return \stdClass The quiz record.
     */
    protected function quiz_record(int $instanceid, int $cmid): \stdClass {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $instanceid], '*', MUST_EXIST);
        $quiz->cmid = $cmid;
        return $quiz;
    }
}
