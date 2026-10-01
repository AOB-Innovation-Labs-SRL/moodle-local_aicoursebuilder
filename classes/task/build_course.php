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

namespace local_aicoursebuilder\task;

use local_aicoursebuilder\builder\availability_target;
use local_aicoursebuilder\builder\build_context;
use local_aicoursebuilder\builder\build_plan;
use local_aicoursebuilder\builder\build_result;
use local_aicoursebuilder\builder\build_step;
use local_aicoursebuilder\builder\builder_registry;

/**
 * Builds the approved blueprint of a job into a Moodle course (spec 3.7, 3.8).
 *
 * The task runs as the owner of the job (set_userid), never in a web request, and builds the blueprint
 * version named by local_aicb_job.blueprintid. Only an approved version of that same job is built: a draft,
 * or a version belonging to another job, fails the job without touching the course.
 *
 * The order is the topological one of build_plan: course, then sections and subsections, then the modules,
 * then availability in a pass of its own once every module has a cmid, then course completion.
 *
 * Every node goes through the builder registered for its type and its result is recorded in the build
 * context, which writes local_aicb_job.buildmap straight away. That is the checkpoint: a second run restores
 * the map and skips every node already in it, without calling its builder, so the build is idempotent and a
 * run interrupted half way resumes where it stopped.
 *
 * A node that fails is recorded with its error and the independent nodes keep going; only the course node is
 * fatal, because without a course there is nowhere to build. A type with no registered builder is recorded
 * manual with a warning, which is also what competencies and badges do until their builders land in phase 5.
 *
 * Two runners never build the same job at once: the task takes a lock on the job and, if another runner
 * holds it, returns without building and without changing the job.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_course extends \core\task\adhoc_task {
    /** @var string Pipeline stage written in the job while the course is built. */
    public const STAGE = 'build';

    /** @var string Job status while the course is built. */
    public const STATUS_BUILDING = 'building';

    /** @var string Job status when the build is over, with or without warnings. */
    public const STATUS_FINISHED = 'finished';

    /** @var string Job status when the course could not be built at all. */
    public const STATUS_FAILED = 'failed';

    /** @var string Blueprint status that may be built; a draft is refused. */
    public const BLUEPRINT_APPROVED = 'approved';

    /** @var string Lock type namespace passed to lock_config::get_lock_factory(). */
    public const LOCK_TYPE = 'local_aicoursebuilder_build';

    /** @var int Seconds to wait for the lock of a job before giving up to the runner that holds it. */
    public const LOCK_TIMEOUT = 5;

    /** @var string[] Job statuses in which the task does nothing. */
    protected const DONE_STATUSES = ['cancelled', self::STATUS_FAILED, self::STATUS_FINISHED];

    /** @var builder_registry|null Builders used by this run, null for the plugin's own registry. */
    protected ?builder_registry $registry;

    /** @var \core\lock\lock_factory|null Lock factory, null for the site's configured one. */
    protected ?\core\lock\lock_factory $lockfactory;

    /** @var string[] Warnings collected while building, in order. */
    protected array $warnings = [];

    /** @var int Steps of the plan that have been dealt with, for the progress of the job. */
    protected int $stepsdone = 0;

    /** @var int Steps of the plan in total, for the progress of the job. */
    protected int $stepstotal = 0;

    /**
     * Creates the task.
     *
     * @param builder_registry|null $registry Builders to use, null for the plugin's own registry.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory, null for the site's configured one.
     */
    public function __construct(
        ?builder_registry $registry = null,
        ?\core\lock\lock_factory $lockfactory = null,
    ) {
        $this->registry = $registry;
        $this->lockfactory = $lockfactory;
    }

    /**
     * Creates the task of a job, ready to be queued.
     *
     * @param int $jobid Job id.
     * @param int $userid Owner of the job, the user the task runs as.
     * @return self
     */
    public static function instance(int $jobid, int $userid): self {
        $task = new self();
        $task->set_custom_data(['jobid' => $jobid]);
        $task->set_userid($userid);
        return $task;
    }

    /**
     * Queues the build of a job. This is what approve_blueprint calls once a version is approved.
     *
     * Phase 6 schedules the task off-peak here, with set_next_run_time(), when the job asks for it
     * (local_aicb_job.offpeak); until then it runs at the next cron.
     *
     * @param int $jobid Job id.
     * @return bool True when the task was queued, false when the job does not exist or is already over.
     */
    public static function queue(int $jobid): bool {
        global $DB;

        $job = $DB->get_record('local_aicb_job', ['id' => $jobid]);
        if (!$job || in_array($job->status, self::DONE_STATUSES, true)) {
            return false;
        }
        \core\task\manager::queue_adhoc_task(self::instance((int) $job->id, (int) $job->userid), true);
        return true;
    }

    /**
     * Returns the name of the task.
     *
     * @return string
     */
    #[\Override]
    public function get_name(): string {
        return get_string('task_buildcourse', 'local_aicoursebuilder');
    }

    /**
     * Builds the course of the job, under the lock of the job.
     */
    #[\Override]
    public function execute(): void {
        global $DB;

        $jobid = (int) ($this->get_custom_data()->jobid ?? 0);
        $job = $DB->get_record('local_aicb_job', ['id' => $jobid]);
        if (!$job || in_array($job->status, self::DONE_STATUSES, true)) {
            return;
        }

        $factory = $this->lockfactory ?? \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock('job_' . $jobid, self::LOCK_TIMEOUT);
        if ($lock === false) {
            // Another runner is building this job: leave it alone, and leave the job as it found it.
            mtrace("Job {$jobid} is being built by another runner, skipping.");
            return;
        }
        try {
            $this->build_job($job);
        } finally {
            $lock->release();
        }
    }

    /**
     * Builds one job: checks the blueprint, runs the plan, then finishes the job.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     */
    protected function build_job(\stdClass $job): void {
        $blueprint = $this->load_blueprint($job);
        if ($blueprint === null) {
            return;
        }

        $newcourse = $this->is_new_course($job);
        $plan = build_plan::from_blueprint($blueprint, $newcourse);
        // The modules, plus the availability pass and the course completion pass.
        $this->stepstotal = count($plan->steps) + count($plan->availability) + ($plan->completionactivities ? 1 : 0);
        $this->stepsdone = 0;
        $this->warnings = [];

        $this->update_job($job, [
            'status' => self::STATUS_BUILDING,
            'stage' => self::STAGE,
            'timestarted' => $job->timestarted ?: time(),
            'error' => null,
        ]);

        $context = $this->make_context($job, $newcourse);
        if (!$this->run_steps($job, $plan, $context, $newcourse)) {
            return;
        }
        $this->apply_availability($job, $plan->availability, $context);
        $this->apply_course_completion($job, $plan->completionactivities, $context);

        // Phase 5 extension points, run here once every node has its cmid, in this order:
        // competencies (\core_competency\api::add_competency_to_course_module) and then badges, which may
        // depend on the activities that award them. Both are plain registry types today, so a blueprint that
        // carries them is recorded manual with a warning until their builders exist.

        $this->finish_job($job);
    }

    /**
     * Loads the approved blueprint of the job, failing the job when there is none.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @return array|null The decoded blueprint, null when the job cannot be built.
     */
    protected function load_blueprint(\stdClass $job): ?array {
        global $DB;

        $blueprintid = (int) ($job->blueprintid ?? 0);
        if ($blueprintid <= 0) {
            $this->fail_job($job, get_string('builderrornoblueprint', 'local_aicoursebuilder'));
            return null;
        }
        $version = $DB->get_record('local_aicb_blueprint', ['id' => $blueprintid]);
        // A version of another job would build the wrong course, so the owner is checked as well.
        if (!$version || (int) $version->jobid !== (int) $job->id) {
            $this->fail_job($job, get_string('builderrornoblueprint', 'local_aicoursebuilder'));
            return null;
        }
        if ($version->status !== self::BLUEPRINT_APPROVED) {
            $this->fail_job($job, get_string('builderrornotapproved', 'local_aicoursebuilder', $version->version));
            return null;
        }
        try {
            $blueprint = json_decode($version->content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->fail_job($job, get_string('builderrorbadblueprint', 'local_aicoursebuilder'));
            return null;
        }
        if (!is_array($blueprint)) {
            $this->fail_job($job, get_string('builderrorbadblueprint', 'local_aicoursebuilder'));
            return null;
        }
        return $blueprint;
    }

    /**
     * Tells whether the job builds a whole course, or only adds activities to a course that already exists.
     *
     * The mode of the job decides, not its courseid: a new course job that is resumed already has the course
     * its first run created, and must still build the sections the first run did not reach. The course node
     * is not built twice either way, because the build map has it.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return bool
     */
    protected function is_new_course(\stdClass $job): bool {
        return ($job->mode ?? 'newcourse') !== 'existingcourse';
    }

    /**
     * Creates the build context, restoring the build map of an earlier run.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param bool $newcourse Whether the course node still has to create the course.
     * @return build_context
     */
    protected function make_context(\stdClass $job, bool $newcourse): build_context {
        $course = null;
        if (!$newcourse) {
            $course = get_course((int) $job->courseid);
        }
        return build_context::from_job($job, $course);
    }

    /**
     * Runs the build steps in order.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param build_plan $plan The plan.
     * @param build_context $context The build context.
     * @param bool $newcourse Whether the course node is part of the plan.
     * @return bool False when the build stopped, because the course itself could not be built.
     */
    protected function run_steps(\stdClass $job, build_plan $plan, build_context $context, bool $newcourse): bool {
        foreach ($plan->steps as $step) {
            if ($step->nodeid === build_plan::COURSE_NODEID) {
                if (!$this->run_course_step($job, $step, $context)) {
                    return false;
                }
                continue;
            }
            if ($newcourse && !$context->has_course()) {
                // The course step comes first in the plan, so this only happens on a blueprint with no
                // course at all. Stop before the builders run against a context with no course.
                $this->fail_job($job, get_string('builderrornocourse', 'local_aicoursebuilder'));
                return false;
            }
            $this->run_step($job, $step, $context);
        }
        return true;
    }

    /**
     * Runs the course node, then enrols the owner of the job as editing teacher.
     *
     * The enrolment happens before any other builder, so that the capability checks of the Moodle APIs the
     * builders call (question bank, files) pass for the user the task runs as (spec 3.7).
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param build_step $step The course step.
     * @param build_context $context The build context.
     * @return bool False when the course could not be built, which ends the job.
     */
    protected function run_course_step(\stdClass $job, build_step $step, build_context $context): bool {
        $result = $this->run_step($job, $step, $context);
        if (!$result->is_success()) {
            $this->fail_job($job, $result->error ?? get_string('builderrornocourse', 'local_aicoursebuilder'));
            return false;
        }

        // A skipped course node carries no id: the course is the one the first run recorded in the job.
        $courseid = (int) ($result->instanceid ?? 0) ?: (int) ($job->courseid ?? 0);
        if ($courseid <= 0 || !($course = $this->fetch_course($courseid))) {
            $this->fail_job($job, get_string('builderrornocourse', 'local_aicoursebuilder'));
            return false;
        }
        $context->set_course($course);
        // The course of the job, so that a resumed run adds to it instead of creating a second one.
        $this->update_job($job, ['courseid' => $courseid]);
        if ($result->status === build_result::STATUS_CREATED) {
            $this->enrol_owner($job, $courseid);
        }
        return true;
    }

    /**
     * Returns the course a builder created, or null when it is gone.
     *
     * @param int $courseid Course id reported by the course builder.
     * @return \stdClass|null
     */
    protected function fetch_course(int $courseid): ?\stdClass {
        global $DB;
        return $DB->get_record('course', ['id' => $courseid]) ?: null;
    }

    /**
     * Enrols the owner of the job in the new course as editing teacher.
     *
     * A failure is a warning, not an error: the course exists and an admin can still fix the enrolment,
     * but the builders that follow may not be able to do everything.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param int $courseid The course that was just created.
     */
    protected function enrol_owner(\stdClass $job, int $courseid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        if (!$roleid) {
            $this->warn(get_string('buildwarnnoteacherrole', 'local_aicoursebuilder'));
            $roleid = null;
        }
        if (!enrol_try_internal_enrol($courseid, (int) $job->userid, $roleid)) {
            $this->warn(get_string('buildwarnenrolfailed', 'local_aicoursebuilder'));
        }
    }

    /**
     * Runs one step: skips a node that is already built, otherwise calls its builder and records the result.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param build_step $step The step.
     * @param build_context $context The build context.
     * @return build_result What was recorded for the node.
     */
    protected function run_step(\stdClass $job, build_step $step, build_context $context): build_result {
        $result = $this->resolve_step($step, $context);
        $context->record($result);
        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }
        if ($result->status === build_result::STATUS_FAILED) {
            mtrace("  Node {$step->nodeid} failed: " . ($result->error ?? ''));
        }
        $this->stepsdone++;
        $this->report($job);
        return $result;
    }

    /**
     * Works out the result of one step, without recording it.
     *
     * @param build_step $step The step.
     * @param build_context $context The build context.
     * @return build_result
     */
    protected function resolve_step(build_step $step, build_context $context): build_result {
        // The checkpoint: a node already in the build map is never built again, and its builder is never called.
        if ($context->is_built($step->nodeid)) {
            return new build_result($step->nodeid, build_result::STATUS_SKIPPED);
        }
        // A module whose section failed has nowhere to go, so its builder is not called at all.
        if ($step->parentid !== null && !$context->is_built($step->parentid)) {
            return new build_result(
                $step->nodeid,
                build_result::STATUS_FAILED,
                error: get_string('builderrorparentfailed', 'local_aicoursebuilder', $step->parentid),
            );
        }
        $builder = $this->registry()->get($step->type);
        if ($builder === null) {
            // No builder for the type: the teacher finishes this node by hand, the build goes on.
            $this->warn(get_string('buildwarnmanualnode', 'local_aicoursebuilder', [
                'node' => $step->nodeid,
                'type' => $step->type,
            ]));
            return new build_result($step->nodeid, build_result::STATUS_MANUAL);
        }
        try {
            return $builder->build($step->node, $context);
        } catch (\Throwable $e) {
            // One node failing must not take the independent ones with it.
            return new build_result($step->nodeid, build_result::STATUS_FAILED, error: $e->getMessage());
        }
    }

    /**
     * Applies the availability rules, now that every module has its cmid.
     *
     * A rule that refers to a node which failed or was left manual cannot be expressed, so it is skipped
     * with a warning; the node it restricts stays available instead of being restricted by half a rule.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param availability_target[] $targets The rules.
     * @param build_context $context The build context.
     */
    protected function apply_availability(\stdClass $job, array $targets, build_context $context): void {
        foreach ($targets as $target) {
            $this->stepsdone++;
            if (!$context->is_built($target->nodeid)) {
                $this->warn(get_string('buildwarnavailabilityskipped', 'local_aicoursebuilder', [
                    'node' => $target->nodeid,
                    'missing' => $target->nodeid,
                ]));
                $this->report($job);
                continue;
            }
            $missing = array_values(array_filter(
                $target->dependencies(),
                fn(string $dependency): bool => $context->get_cmid($dependency) === null
            ));
            if ($missing !== []) {
                $this->warn(get_string('buildwarnavailabilityskipped', 'local_aicoursebuilder', [
                    'node' => $target->nodeid,
                    'missing' => implode(', ', $missing),
                ]));
                $this->report($job);
                continue;
            }
            $this->apply_availability_rule($target, $context);
            $this->report($job);
        }
    }

    /**
     * Writes one availability rule onto its node.
     *
     * The rule is turned into the JSON of \core_availability\tree and written to course_modules.availability
     * or course_sections.availability, then the course cache is rebuilt (spec 3.7). The mapper that builds
     * that JSON is the builders' task, so until it lands the rule is recorded as a node left to do by hand.
     *
     * @param availability_target $target The rule.
     * @param build_context $context The build context.
     */
    protected function apply_availability_rule(availability_target $target, build_context $context): void {
        $this->warn(get_string('buildwarnavailabilitymanual', 'local_aicoursebuilder', $target->nodeid));
    }

    /**
     * Sets course completion from the activities the blueprint names, those that were really built.
     *
     * An activity that was not built is left out with a warning, rather than failing the course completion
     * of a course that is otherwise fine.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param string[] $activityids Activity node ids that complete the course.
     * @param build_context $context The build context.
     */
    protected function apply_course_completion(\stdClass $job, array $activityids, build_context $context): void {
        if ($activityids === []) {
            return;
        }
        $this->stepsdone++;
        $cmids = [];
        $missing = [];
        foreach ($activityids as $activityid) {
            $cmid = $context->get_cmid($activityid);
            if ($cmid === null) {
                $missing[] = $activityid;
                continue;
            }
            $cmids[$activityid] = $cmid;
        }
        if ($missing !== []) {
            $this->warn(get_string('buildwarncompletionmissing', 'local_aicoursebuilder', implode(', ', $missing)));
        }
        if ($cmids === []) {
            $this->warn(get_string('buildwarncompletionskipped', 'local_aicoursebuilder'));
            $this->report($job);
            return;
        }
        $this->set_course_completion($context, $cmids);
        $this->report($job);
    }

    /**
     * Writes the course completion criteria of the activities that were built.
     *
     * completion_criteria_activity::update_config() with criteria_activity = [cmid => 1] and the ALL
     * aggregation (spec 3.7). It belongs with the course builder, which owns enablecompletion, so until
     * that builder lands this records a node left to do by hand.
     *
     * @param build_context $context The build context.
     * @param int[] $cmids Activity node id => course module id, all of them built.
     */
    protected function set_course_completion(build_context $context, array $cmids): void {
        $this->warn(get_string('buildwarncompletionmanual', 'local_aicoursebuilder'));
    }

    /**
     * Returns the registry of builders used by this run.
     *
     * @return builder_registry
     */
    protected function registry(): builder_registry {
        // The plugin registers no builder yet: they land with the builder tasks, so every node is manual.
        $this->registry ??= new builder_registry();
        return $this->registry;
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
        mtrace('  ' . $warning);
    }

    /**
     * Finishes the job: the course exists, with or without warnings.
     *
     * Phase 6 sends the jobfinished notification here, with message_send() and a link to the course.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     */
    protected function finish_job(\stdClass $job): void {
        $this->update_job($job, [
            'status' => self::STATUS_FINISHED,
            'progress' => 100,
            'statusmessage' => $this->warnings
                ? get_string('buildfinishedwarnings', 'local_aicoursebuilder', count($this->warnings))
                : get_string('buildfinished', 'local_aicoursebuilder'),
            'error' => null,
            'timefinished' => time(),
        ]);
    }

    /**
     * Fails the job with a reason. Only a job with no course at all gets here.
     *
     * Phase 6 sends the jobfailed notification here, and offers the retry or the rollback
     * (delete_course for a new course, course_delete_module over get_cmidmap() in an existing one).
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param string $error The reason.
     */
    protected function fail_job(\stdClass $job, string $error): void {
        $this->update_job($job, [
            'status' => self::STATUS_FAILED,
            'stage' => self::STAGE,
            'error' => $error,
            'statusmessage' => get_string('buildfailed', 'local_aicoursebuilder'),
            'timefinished' => time(),
        ]);
        mtrace("Job {$job->id} failed: {$error}");
    }

    /**
     * Writes the progress of the job: the share of the plan that is done.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     */
    protected function report(\stdClass $job): void {
        $done = min($this->stepsdone, $this->stepstotal);
        $this->update_job($job, [
            'progress' => $this->stepstotal ? intdiv($done * 100, $this->stepstotal) : 0,
            'statusmessage' => get_string('buildprogress', 'local_aicoursebuilder', [
                'done' => $done,
                'total' => $this->stepstotal,
            ]),
        ]);
    }

    /**
     * Updates fields of the job row.
     *
     * Only the named fields are written, never the whole row: the build context writes
     * local_aicb_job.buildmap after every node, and writing back a row read before that would
     * undo the checkpoints of this very run.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param array $fields Field values.
     */
    protected function update_job(\stdClass $job, array $fields): void {
        global $DB;

        foreach ($fields as $name => $value) {
            $job->{$name} = $value;
        }
        $job->timemodified = time();
        $DB->update_record('local_aicb_job', (object) [
            'id' => $job->id,
            ...$fields,
            'timemodified' => $job->timemodified,
        ]);
    }
}
