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

/**
 * Inserts immutable draft versions of fully validated blueprints.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class version_store {
    /** @var string Lock namespace for version allocation per job. */
    public const LOCK_TYPE = 'local_aicoursebuilder_blueprint';

    /**
     * Returns the most recently created blueprint version of a job.
     *
     * @param int $jobid Job id.
     * @return \stdClass|null Blueprint row or null.
     */
    public function latest(int $jobid): ?\stdClass {
        global $DB;

        $records = $DB->get_records('local_aicb_blueprint', ['jobid' => $jobid], 'version DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Validates and inserts a new draft, leaving every earlier version untouched.
     *
     * @param int $jobid Job id.
     * @param int $userid User saving the draft.
     * @param array $blueprint Complete decoded blueprint.
     * @param validator $validator Full blueprint validator.
     * @param string[] $sourcetexts Source text by source id.
     * @param bool $reuseequal Reuse the latest draft when its bytes are exactly equal (pipeline resume).
     * @return \stdClass Saved draft row.
     * @throws \moodle_exception When validation fails or the version lock cannot be obtained.
     */
    public function save(
        int $jobid,
        int $userid,
        array $blueprint,
        validator $validator,
        array $sourcetexts = [],
        bool $reuseequal = false,
    ): \stdClass {
        global $DB;

        $errors = $validator->validate($blueprint, $sourcetexts);
        if ($errors !== []) {
            throw new \moodle_exception('invalidblueprint', 'local_aicoursebuilder', '', validation_error::list_to_json($errors));
        }
        $content = json_encode($blueprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lock = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE)->get_lock('job_' . $jobid, 5);
        if (!$lock) {
            throw new \moodle_exception('blueprintlockfailed', 'local_aicoursebuilder');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $latest = $this->latest($jobid);
            if ($reuseequal && $latest && $latest->status === 'draft' && $latest->content === $content) {
                $transaction->allow_commit();
                return $latest;
            }
            $now = time();
            $id = $DB->insert_record('local_aicb_blueprint', (object) [
                'jobid' => $jobid,
                'version' => $latest ? (int) $latest->version + 1 : 1,
                'schemaversion' => schema_store::SCHEMA_VERSION,
                'content' => $content,
                'contenthash' => hash('sha256', $content),
                'status' => 'draft',
                'usermodified' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $transaction->allow_commit();
            return $DB->get_record('local_aicb_blueprint', ['id' => $id], '*', MUST_EXIST);
        } finally {
            $lock->release();
        }
    }
}
