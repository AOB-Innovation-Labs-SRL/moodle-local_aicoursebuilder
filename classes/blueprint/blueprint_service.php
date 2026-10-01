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

namespace local_aicoursebuilder\blueprint;

use local_aicoursebuilder\job_manager;
use local_aicoursebuilder\task\build_course;

/**
 * What the blueprint editor does with the blueprint versions of a job: read one, save an edit, approve one (spec 3.7).
 *
 * A blueprint is never changed in place. Every save is a new version, so the teacher can always go back, and an
 * approved version is locked for good: it is the one the course is built from. The versions of a job are numbered
 * one after the other, and the same lock that numbers the versions the pipeline writes numbers the ones written
 * here, so an edit and a regeneration that finish together cannot both become the same version.
 *
 * A save that does not validate is kept, with the errors it has, because the editor saves work in progress and a
 * teacher must not lose an edit for a field they have not got to yet. Only approval insists on a blueprint without
 * errors.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class blueprint_service {
    /** @var string Status of a blueprint version the teacher can still change by saving a newer one. */
    public const STATUS_DRAFT = 'draft';

    /** @var string Status of the version the course is built from. */
    public const STATUS_APPROVED = 'approved';

    /** @var string Job status in which the blueprint is edited. */
    public const JOB_REVIEW = 'review';

    /** @var string Job status once a version is approved. */
    public const JOB_APPROVED = 'approved';

    /** @var int Seconds to wait for the lock that numbers the versions. */
    public const LOCK_TIMEOUT = 5;

    /** @var version_store Reads the versions and holds the lock type. */
    private version_store $versions;

    /**
     * Creates the service.
     *
     * @param version_store|null $versions Version store, null for the default one.
     */
    public function __construct(?version_store $versions = null) {
        $this->versions = $versions ?? new version_store();
    }

    /**
     * Returns a blueprint version of a job.
     *
     * @param int $jobid Job id.
     * @param int $version Version number, 0 for the latest.
     * @return \stdClass The local_aicb_blueprint row.
     * @throws \moodle_exception When the job has no such version.
     */
    public function get(int $jobid, int $version = 0): \stdClass {
        global $DB;

        $row = $version > 0
            ? $DB->get_record('local_aicb_blueprint', ['jobid' => $jobid, 'version' => $version])
            : $this->versions->latest($jobid);
        if (!$row) {
            throw new \moodle_exception('blueprintnotfound', 'local_aicoursebuilder');
        }
        return $row;
    }

    /**
     * Validates a blueprint against the schema and the rules of the validator, with the sources of the job.
     *
     * @param int $jobid Job id.
     * @param array $blueprint The decoded blueprint.
     * @return validation_error[] Empty when the blueprint is valid.
     */
    public function validate(int $jobid, array $blueprint): array {
        $sources = (new job_manager())->load_sources($jobid);
        return (new validator())->validate($blueprint, $sources['texts']);
    }

    /**
     * Saves an edited blueprint as the next version of the job.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param int $userid The user who edited it.
     * @param array $blueprint The decoded edited blueprint.
     * @param int $baseversion The version the edit started from.
     * @return array ['row' => the saved row, 'errors' => validation_error[]]. A blueprint identical to the latest
     *               version saves nothing and returns that version.
     * @throws \moodle_exception When the job is not in review, or a newer version exists than the one edited.
     */
    public function save(\stdClass $job, int $userid, array $blueprint, int $baseversion): array {
        $this->require_review($job);
        $errors = $this->validate((int) $job->id, $blueprint);
        $content = json_encode($blueprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->with_lock((int) $job->id, function () use ($job, $userid, $blueprint, $baseversion, $errors, $content) {
            global $DB;

            $latest = $this->get((int) $job->id);
            if ((int) $latest->version !== $baseversion) {
                throw new \moodle_exception('blueprintconflict', 'local_aicoursebuilder');
            }
            if ($latest->status === self::STATUS_DRAFT && $latest->content === $content) {
                return ['row' => $latest, 'errors' => $errors];
            }

            $now = time();
            $id = $DB->insert_record('local_aicb_blueprint', (object) [
                'jobid' => $job->id,
                'version' => (int) $latest->version + 1,
                'schemaversion' => $blueprint['version'] ?? schema_store::SCHEMA_VERSION,
                'content' => $content,
                'contenthash' => hash('sha256', $content),
                'status' => self::STATUS_DRAFT,
                'usermodified' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $DB->set_field('local_aicb_job', 'timemodified', $now, ['id' => $job->id]);
            return ['row' => $DB->get_record('local_aicb_blueprint', ['id' => $id], '*', MUST_EXIST), 'errors' => $errors];
        });
    }

    /**
     * Approves a version: locks it and queues the build of the course from it.
     *
     * The teacher approves what they saw, so the version must be the latest one and its hash must be the one the
     * teacher reviewed; a version someone else saved in the meantime is not approved by accident.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param int $userid The user who approves.
     * @param int $version The version to approve.
     * @param string $contenthash The hash the teacher reviewed.
     * @return array ['row' => the approved row, 'queued' => whether the build task was queued]
     * @throws \moodle_exception When the job is not in review, the version is not the latest or was changed, or the
     *                           blueprint still has errors.
     */
    public function approve(\stdClass $job, int $userid, int $version, string $contenthash): array {
        $this->require_review($job);

        return $this->with_lock((int) $job->id, function () use ($job, $userid, $version, $contenthash) {
            global $DB;

            $row = $this->get((int) $job->id, $version);
            $latest = $this->get((int) $job->id);
            if ((int) $latest->version !== (int) $row->version) {
                throw new \moodle_exception('blueprintconflict', 'local_aicoursebuilder');
            }
            if (!hash_equals($row->contenthash, $contenthash)) {
                throw new \moodle_exception('blueprinthashmismatch', 'local_aicoursebuilder');
            }
            $errors = $this->validate((int) $job->id, json_decode($row->content, true, 512, JSON_THROW_ON_ERROR));
            if ($errors !== []) {
                throw new \moodle_exception(
                    'blueprintnotvalid',
                    'local_aicoursebuilder',
                    '',
                    count($errors) . ': ' . $errors[0]->path . ' ' . $errors[0]->message
                );
            }

            $now = time();
            $transaction = $DB->start_delegated_transaction();
            $DB->update_record('local_aicb_blueprint', (object) [
                'id' => $row->id,
                'status' => self::STATUS_APPROVED,
                'approvedby' => $userid,
                'timeapproved' => $now,
                'timemodified' => $now,
            ]);
            $DB->update_record('local_aicb_job', (object) [
                'id' => $job->id,
                'status' => self::JOB_APPROVED,
                'stage' => self::JOB_APPROVED,
                'blueprintid' => $row->id,
                'statusmessage' => get_string('blueprintapproved', 'local_aicoursebuilder'),
                'timemodified' => $now,
            ]);
            $transaction->allow_commit();

            return [
                'row' => $DB->get_record('local_aicb_blueprint', ['id' => $row->id], '*', MUST_EXIST),
                'queued' => build_course::queue((int) $job->id),
            ];
        });
    }

    /**
     * Refuses a job that is not waiting for the teacher's review.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @throws \moodle_exception When the job is in another status.
     */
    protected function require_review(\stdClass $job): void {
        if ($job->status !== self::JOB_REVIEW) {
            throw new \moodle_exception('blueprintnotreviewable', 'local_aicoursebuilder');
        }
    }

    /**
     * Runs a callback with the lock that numbers the versions of a job held.
     *
     * @param int $jobid Job id.
     * @param callable $callback What to run.
     * @return mixed What the callback returns.
     * @throws \moodle_exception When the lock cannot be obtained in time.
     */
    protected function with_lock(int $jobid, callable $callback): mixed {
        $lock = \core\lock\lock_config::get_lock_factory(version_store::LOCK_TYPE)
            ->get_lock('job_' . $jobid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('blueprintlockfailed', 'local_aicoursebuilder');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
