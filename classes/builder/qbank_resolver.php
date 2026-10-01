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

use core_question\local\bank\question_bank_helper;

/**
 * Finds the question bank the generated questions go into (spec 3.7).
 *
 * Moodle 5 keeps questions in mod_qbank instances, so the questions of a course belong to the default
 * open bank of that course rather than to the course context itself. The bank is resolved once per build
 * and kept in the build context: every quiz of the same course shares it.
 *
 * On a site upgraded from 4.x the questions of the old course contexts are moved into banks by an ad-hoc
 * task. While that task is still queued, question_bank_helper::has_bank_migration_task_completed_successfully()
 * is false and the default bank of a course may not be the one it will end up with. Writing into it then
 * risks landing in a bank the migration later replaces, so instead the plugin creates a bank of its own and
 * warns the administrator: the questions are never lost, they are simply in a bank of ours until the site
 * has finished migrating.
 *
 * question_make_default_categories() is deprecated and is never called here (spec 3.7).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbank_resolver {
    /** @var string Name of the bank created when the site has not finished migrating its questions. */
    public const OWN_BANK_IDNUMBER = 'aicb-bank';

    /** @var string[] Warnings raised while resolving, for the job to report. */
    protected array $warnings = [];

    /**
     * Returns the context of the question bank the questions of this build go into.
     *
     * The context is resolved once: when the build context already knows it, that one is returned
     * unchanged, so the second quiz of a course does not create a second bank.
     *
     * @param build_context $context The build context, whose question bank context is set on first call.
     * @return \context_module Context of the mod_qbank instance that receives the questions.
     * @throws \moodle_exception When no question bank could be found or created for the course.
     */
    public function resolve(build_context $context): \context_module {
        $known = $context->get_qbankcontext();
        if ($known instanceof \context_module) {
            return $known;
        }

        $course = $context->get_course();
        $cminfo = $this->find_bank($course);
        $bankcontext = \context_module::instance($cminfo->id);
        $context->set_qbankcontext($bankcontext);
        return $bankcontext;
    }

    /**
     * Finds or creates the mod_qbank instance of a course.
     *
     * @param \stdClass $course The course.
     * @return \cm_info The question bank module.
     * @throws \moodle_exception When the bank could not be created.
     */
    protected function find_bank(\stdClass $course): \cm_info {
        if (!question_bank_helper::has_bank_migration_task_completed_successfully()) {
            // The site is still moving its 4.x questions into banks: stay out of the default bank.
            $this->warn(get_string('buildwarnqbankmigration', 'local_aicoursebuilder'));
            return $this->create_own_bank($course);
        }

        $bank = question_bank_helper::get_default_open_instance_system_type($course, true);
        if ($bank === null) {
            // The default bank could not be made: fall back to one of ours rather than fail the quiz.
            $this->warn(get_string('buildwarnqbankdefaultmissing', 'local_aicoursebuilder'));
            return $this->create_own_bank($course);
        }
        return $bank;
    }

    /**
     * Creates, or finds again, the question bank this plugin owns in a course.
     *
     * Called on a re-run too, so it looks for the bank it made before instead of making a second one.
     *
     * @param \stdClass $course The course.
     * @return \cm_info The question bank module.
     * @throws \moodle_exception When the bank could not be created.
     */
    protected function create_own_bank(\stdClass $course): \cm_info {
        $existing = $this->find_own_bank($course);
        if ($existing !== null) {
            return $existing;
        }
        return question_bank_helper::create_default_open_instance(
            $course,
            get_string('qbankname', 'local_aicoursebuilder', $course->shortname),
            question_bank_helper::TYPE_STANDARD,
        );
    }

    /**
     * Returns the question bank this plugin made in a course on an earlier run, when there is one.
     *
     * @param \stdClass $course The course.
     * @return \cm_info|null
     */
    protected function find_own_bank(\stdClass $course): ?\cm_info {
        global $DB;

        $modinfo = get_fast_modinfo($course);
        $wantedname = get_string('qbankname', 'local_aicoursebuilder', $course->shortname);
        foreach ($modinfo->get_instances_of('qbank') as $cminfo) {
            if ($DB->get_field('qbank', 'name', ['id' => $cminfo->instance]) === $wantedname) {
                return $cminfo;
            }
        }
        return null;
    }

    /**
     * Returns the warnings raised while resolving the bank.
     *
     * @return string[]
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * Records a warning, keeping each distinct one once.
     *
     * @param string $warning The warning.
     */
    protected function warn(string $warning): void {
        if (!in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }
}
