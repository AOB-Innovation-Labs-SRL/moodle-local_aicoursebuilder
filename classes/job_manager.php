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

namespace local_aicoursebuilder;

use local_aicoursebuilder\ai\budget_guard;
use local_aicoursebuilder\ai\budget_notifier;
use local_aicoursebuilder\ingest\source_manager;
use local_aicoursebuilder\pipeline\cost_estimator;
use local_aicoursebuilder\pipeline\step_store;
use local_aicoursebuilder\task\generate_blueprint;
use local_aicoursebuilder\task\ingest_sources;

/**
 * Creates, estimates and starts the generation jobs (spec 3.7).
 *
 * A job lives in two moments. The teacher first fills the wizard in: the job is created as a draft, which costs
 * nothing, so that the sources can be saved and the cost shown. Only when the teacher confirms is the job
 * started, which is what queues the ad-hoc tasks and from then on spends money. No AI call is made in a web
 * request: starting a job only queues the task that will.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_manager {
    /** @var string Job status: created by the wizard, nothing has run. */
    public const STATUS_DRAFT = 'draft';

    /** @var string Job status: started, waiting for its first task. */
    public const STATUS_QUEUED = 'queued';

    /** @var string Job status: the blueprint is ready for the teacher to review. */
    public const STATUS_REVIEW = 'review';

    /** @var string Job status: stopped for good. */
    public const STATUS_FAILED = 'failed';

    /** @var string Job status: stopped by a cost limit, with its work kept, until it is resumed. */
    public const STATUS_PAUSED = 'paused';

    /** @var string Job mode: a new course. */
    public const MODE_NEWCOURSE = 'newcourse';

    /** @var string Job mode: activities added to an existing course. */
    public const MODE_EXISTINGCOURSE = 'existingcourse';

    /**
     * Creates a job as a draft and saves its sources.
     *
     * @param int $userid Owner of the job.
     * @param array $data Job fields: mode, categoryid, courseid, sectionnum, prompt, language, brief (JSON text,
     *                    may be empty) and offpeak.
     * @param int $draftitemid Draft area with the source files, 0 for none.
     * @return int The job id.
     * @throws ingest\ingest_exception When the sources are refused; the job is not left behind.
     */
    public function create_draft(int $userid, array $data, int $draftitemid = 0): int {
        global $DB;

        $now = time();
        $job = (object) [
            'userid' => $userid,
            'mode' => $data['mode'],
            'categoryid' => $data['mode'] === self::MODE_NEWCOURSE ? $data['categoryid'] : null,
            'courseid' => $data['mode'] === self::MODE_EXISTINGCOURSE ? $data['courseid'] : null,
            'sectionnum' => $data['mode'] === self::MODE_EXISTINGCOURSE ? $data['sectionnum'] : null,
            'status' => self::STATUS_DRAFT,
            'progress' => 0,
            'prompt' => $data['prompt'],
            'brief' => ($data['brief'] ?? '') === '' ? null : $data['brief'],
            'language' => $data['language'],
            'offpeak' => empty($data['offpeak']) ? 0 : 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $job->id = $DB->insert_record('local_aicb_job', $job);
        if ($draftitemid > 0 && $this->draft_has_files($draftitemid)) {
            try {
                (new source_manager())->save_from_draft($job->id, $draftitemid, self::get_context($job));
            } catch (\Throwable $e) {
                // The sources are saved all or none, so refusing them leaves only the job to remove.
                $DB->delete_records('local_aicb_job', ['id' => $job->id]);
                throw $e;
            }
        }
        return (int) $job->id;
    }

    /**
     * Tells whether a draft area of the current user holds any file.
     *
     * The sources are optional: a teacher can start from the request alone, and an untouched filemanager still
     * has a draft area, only an empty one.
     *
     * @param int $draftitemid The draft item id.
     * @return bool
     */
    private function draft_has_files(int $draftitemid): bool {
        global $USER;

        $context = \context_user::instance($USER->id);
        return !empty(get_file_storage()->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id', false));
    }

    /**
     * Estimates the cost of a job and checks it against the cost limits.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return array ['estimatedcost', 'tokensin', 'tokensout', 'withinbudget', 'joblimit', 'userremaining', 'useralert'];
     *               userremaining is -1 when the user has no monthly limit; useralert says that the user has used
     *               the alert percentage of it.
     */
    public function estimate(\stdClass $job): array {
        global $DB;

        $estimate = (new cost_estimator())->estimate($job);
        $DB->set_field('local_aicb_job', 'estimatedcost', $estimate['cost'], ['id' => $job->id]);

        $joblimit = max(0.0, (float) get_config('local_aicoursebuilder', 'joblimitusd'));
        $userremaining = $this->remaining((int) $job->userid);
        $siteremaining = $this->remaining(budget_guard::SITE_USERID);

        $within = ($joblimit <= 0 || $estimate['cost'] <= $joblimit)
            && ($userremaining < 0 || $estimate['cost'] <= $userremaining)
            && ($siteremaining < 0 || $estimate['cost'] <= $siteremaining);

        return [
            'estimatedcost' => $estimate['cost'],
            'tokensin' => $estimate['tokensin'],
            'tokensout' => $estimate['tokensout'],
            'withinbudget' => $within,
            'joblimit' => $joblimit,
            'userremaining' => $userremaining,
            'useralert' => $this->at_alert_level((int) $job->userid),
        ];
    }

    /**
     * Starts a draft job: checks it, then queues the task that does the first part of the work.
     *
     * A job with sources starts with their ingestion, which queues the generation when it is done; a job with none
     * goes straight to the generation.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @throws \moodle_exception When the job is not a draft, the owner has not accepted the AI policy, or the
     *                           estimated cost does not fit the limits.
     */
    public function start(\stdClass $job): void {
        global $DB;

        if ($job->status !== self::STATUS_DRAFT) {
            throw new \moodle_exception('jobnotdraft', 'local_aicoursebuilder');
        }
        if (!\core_ai\manager::get_user_policy_status((int) $job->userid)) {
            throw new \moodle_exception('aipolicynotaccepted', 'local_aicoursebuilder');
        }
        if (!$this->estimate($job)['withinbudget']) {
            throw new \moodle_exception('startoverbudget', 'local_aicoursebuilder');
        }

        $hassources = $DB->record_exists('local_aicb_source', ['jobid' => $job->id]);
        $DB->update_record('local_aicb_job', (object) [
            'id' => $job->id,
            'status' => self::STATUS_QUEUED,
            'stage' => $hassources ? ingest_sources::STAGE : generate_blueprint::STAGE,
            'progress' => 0,
            'timemodified' => time(),
        ]);

        $task = $hassources
            ? ingest_sources::instance((int) $job->id, (int) $job->userid)
            : generate_blueprint::instance((int) $job->id, (int) $job->userid);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Tells whether a user may use the plugin anywhere: in the system, in a category or in a course.
     *
     * The capability is a course one, so a teacher holds it in their courses only. The answer decides whether the
     * plugin is offered to the user at all, on every page, so it is kept in the session for a few minutes.
     *
     * @param int $userid The user.
     * @return bool
     */
    public function can_use(int $userid): bool {
        $cache = \cache::make('local_aicoursebuilder', 'canuse');
        $cached = $cache->get($userid);
        if ($cached !== false) {
            return (bool) $cached;
        }

        $capability = 'local/aicoursebuilder:use';
        $can = has_capability($capability, \context_system::instance(), $userid);
        if (!$can) {
            // One course or category is enough, so there is no need to list them all.
            [$categories, $courses] = get_user_capability_contexts($capability, true, $userid, true, '', '', '', '', 1);
            $can = !empty($categories) || !empty($courses);
        }
        $cache->set($userid, (int) $can);
        return $can;
    }

    /**
     * Stops a job that a cost limit has stopped, keeping the work it did.
     *
     * The steps the job finished are in local_aicb_step, so resuming it pays only for what is left. The job keeps its
     * stage, which says where to resume from, and the reason, which the teacher sees and the notification carries.
     *
     * @param \stdClass $job The local_aicb_job row, updated in place.
     * @param string $reason What stopped it, as budget_exceeded_exception words it.
     */
    public function pause(\stdClass $job, string $reason): void {
        global $DB;

        $job->status = self::STATUS_PAUSED;
        $job->error = $reason;
        $job->statusmessage = null;
        $job->actualcost = (new step_store())->totals((int) $job->id)['cost'] ?? $job->actualcost;
        $job->timemodified = time();
        $DB->update_record('local_aicb_job', $job);

        (new budget_notifier())->exceeded($job, $reason);
    }

    /**
     * Tells whether every cost limit that applies to a paused job has room again.
     *
     * Only a limit that is used up stops it: how much the rest of the work needs is not known before it runs, so a job
     * that is let go with little room stops again, before it pays for anything, at the first call that does not fit.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @return bool
     */
    public function has_room_to_resume(\stdClass $job): bool {
        $joblimit = (float) get_config('local_aicoursebuilder', 'joblimitusd');
        if ($joblimit > 0 && (float) $job->actualcost >= $joblimit) {
            return false;
        }
        return $this->remaining((int) $job->userid) != 0.0 && $this->remaining(budget_guard::SITE_USERID) != 0.0;
    }

    /**
     * Resumes a paused job: queues the task of the stage it stopped in, which carries on from its checkpoints.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @throws \moodle_exception When the job is not paused, the owner has not accepted the AI policy, or a cost
     *                           limit that stopped it has still no room.
     */
    public function resume(\stdClass $job): void {
        global $DB;

        if ($job->status !== self::STATUS_PAUSED) {
            throw new \moodle_exception('jobnotpaused', 'local_aicoursebuilder');
        }
        if (!\core_ai\manager::get_user_policy_status((int) $job->userid)) {
            throw new \moodle_exception('aipolicynotaccepted', 'local_aicoursebuilder');
        }
        if (!$this->has_room_to_resume($job)) {
            throw new \moodle_exception('resumestillover', 'local_aicoursebuilder');
        }

        $ingesting = $job->stage === ingest_sources::STAGE;
        $DB->update_record('local_aicb_job', (object) [
            'id' => $job->id,
            'status' => self::STATUS_QUEUED,
            'stage' => $ingesting ? ingest_sources::STAGE : generate_blueprint::STAGE,
            'error' => null,
            'timemodified' => time(),
        ]);

        $task = $ingesting
            ? ingest_sources::instance((int) $job->id, (int) $job->userid)
            : generate_blueprint::instance((int) $job->id, (int) $job->userid);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Tells whether a user has used the alert percentage of the monthly limit.
     *
     * @param int $userid The user.
     * @return bool False when there is no limit or no alert percentage.
     */
    private function at_alert_level(int $userid): bool {
        global $DB;

        $percent = (float) get_config('local_aicoursebuilder', 'alertpercent');
        $row = $DB->get_record('local_aicb_budget', ['userid' => $userid, 'period' => budget_guard::current_period()]);
        if (!$row || $percent <= 0) {
            return false;
        }
        $limit = $row->limitusd !== null ? (float) $row->limitusd : (float) get_config('local_aicoursebuilder', 'userlimitusd');
        return $limit > 0 && ((float) $row->spentusd + (float) $row->reservedusd) / $limit * 100 >= $percent;
    }

    /**
     * Returns the text and the digest of the sources of a job that the ingestion finished.
     *
     * Both are keyed the way the pipeline and the validator know the sources: src and the source id.
     *
     * @param int $jobid Job id.
     * @return array ['texts' => string[], 'digests' => array[]]
     */
    public function load_sources(int $jobid): array {
        global $DB;

        $manager = new source_manager();
        $texts = [];
        $digests = [];
        $sources = $DB->get_records(
            'local_aicb_source',
            ['jobid' => $jobid, 'status' => ingest_sources::STATUS_DIGESTED],
            'id'
        );
        foreach ($sources as $source) {
            $file = $manager->get_extracted_file((int) $source->id);
            $digest = json_decode((string) $source->digest, true);
            if (!$file || !is_array($digest)) {
                continue;
            }
            $texts['src' . $source->id] = $file->get_content();
            $digests['src' . $source->id] = $digest;
        }
        return ['texts' => $texts, 'digests' => $digests];
    }

    /**
     * Returns how much a user, or the whole site, may still spend this month.
     *
     * @param int $userid User id, or budget_guard::SITE_USERID for the whole site.
     * @return float USD, 0 when the limit is used up, -1 when there is no limit.
     */
    public function remaining(int $userid): float {
        global $DB;

        $row = $DB->get_record('local_aicb_budget', ['userid' => $userid, 'period' => budget_guard::current_period()]);
        $default = (float) get_config(
            'local_aicoursebuilder',
            $userid === budget_guard::SITE_USERID ? 'sitelimitusd' : 'userlimitusd'
        );
        $limit = $row && $row->limitusd !== null ? (float) $row->limitusd : $default;
        if ($limit <= 0) {
            return -1.0;
        }
        $used = $row ? (float) $row->spentusd + (float) $row->reservedusd : 0.0;
        return max(0.0, round($limit - $used, 6));
    }

    /**
     * Returns the context of a job: its course, else its category, else the system.
     *
     * @param \stdClass $job The local_aicb_job record.
     * @return \context
     */
    public static function get_context(\stdClass $job): \context {
        if (!empty($job->courseid)) {
            return \context_course::instance($job->courseid);
        }
        if (!empty($job->categoryid)) {
            return \context_coursecat::instance($job->categoryid);
        }
        return \context_system::instance();
    }
}
