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

namespace local_aicoursebuilder\pipeline;

/**
 * Persists every pipeline step in local_aicb_step and hands back the ones already paid for.
 *
 * The key of a step is the hash of everything that could change its answer: the job, the step, the
 * sub-call, the prompt version, the connector, the model, the schema version and the input itself.
 * A finished row with the same hash is returned instead of calling the model again, so restarting
 * a job costs only what it has not yet done (spec 3.6). Change the prompt, the model or the schema
 * and the hash changes with it, so the step runs again rather than serving a stale answer.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_store {
    /** @var string Table holding the steps. */
    public const TABLE = 'local_aicb_step';

    /** @var string The step has not run yet. */
    public const STATUS_PENDING = 'pending';

    /** @var string The step is running now. */
    public const STATUS_RUNNING = 'running';

    /** @var string The step finished and its output can be reused. */
    public const STATUS_DONE = 'done';

    /** @var string The step failed. */
    public const STATUS_ERROR = 'error';

    /** @var string The step finished but a human has to complete its node. */
    public const STATUS_MANUAL = 'manual';

    /**
     * Returns the hash identifying one step or sub-call of a job.
     *
     * @param int $jobid Job the step belongs to.
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @param array $input Everything the step is given, which decides its answer.
     * @param array $context Prompt version, connector, model and schema version.
     * @return string sha256 hex digest.
     */
    public static function hash(int $jobid, string $step, string $nodekey, array $input, array $context): string {
        $payload = [
            'jobid' => $jobid,
            'step' => $step,
            'nodekey' => $nodekey,
            'promptversion' => $context['promptversion'] ?? '',
            'connector' => $context['connector'] ?? '',
            'model' => $context['model'] ?? '',
            'schemaversion' => $context['schemaversion'] ?? '',
            'input' => self::canonicalise($input),
        ];
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Returns the output of a finished step, or null when it has to run.
     *
     * Only a done step is reused. A step left running by a job that died is not: whatever it was
     * doing was never finished, so it is run again.
     *
     * @param int $jobid Job the step belongs to.
     * @param string $inputhash Hash returned by hash().
     * @return array|null Decoded output, or null when there is nothing to resume.
     */
    public function find_output(int $jobid, string $inputhash): ?array {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['jobid' => $jobid, 'inputhash' => $inputhash]);
        if (!$record || !in_array($record->status, [self::STATUS_DONE, self::STATUS_MANUAL], true)) {
            return null;
        }
        if ($record->output === null || $record->output === '') {
            return null;
        }
        $output = json_decode($record->output, true);
        return is_array($output) ? $output : null;
    }

    /**
     * Returns the row of a step, or null when it has never run.
     *
     * @param int $jobid Job the step belongs to.
     * @param string $inputhash Hash returned by hash().
     * @return \stdClass|null
     */
    public function find(int $jobid, string $inputhash): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['jobid' => $jobid, 'inputhash' => $inputhash]) ?: null;
    }

    /**
     * Marks a step as running, creating its row the first time.
     *
     * @param int $jobid Job the step belongs to.
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @param string $nodekey Sub-call key, empty for a step that runs once.
     * @param string $inputhash Hash returned by hash().
     * @param array $context Prompt version, connector and model, stored for the audit trail.
     * @return int Id of the row.
     */
    public function start(int $jobid, string $step, string $nodekey, string $inputhash, array $context): int {
        global $DB;

        $now = time();
        $record = $this->find($jobid, $inputhash);
        if ($record) {
            $record->status = self::STATUS_RUNNING;
            $record->attempts = (int) $record->attempts + 1;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
            return (int) $record->id;
        }
        return (int) $DB->insert_record(self::TABLE, (object) [
            'jobid' => $jobid,
            'step' => $step,
            'nodekey' => $nodekey === '' ? null : $nodekey,
            'inputhash' => $inputhash,
            'status' => self::STATUS_RUNNING,
            'connector' => $context['connector'] ?? null,
            'model' => $context['model'] ?? null,
            'promptversion' => $context['promptversion'] ?? null,
            'attempts' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Stores what a step produced and what it cost.
     *
     * @param int $id Id returned by start().
     * @param step_result $result What the step produced.
     */
    public function finish(int $id, step_result $result): void {
        global $DB;

        $status = self::STATUS_DONE;
        if (!$result->is_success()) {
            $status = self::STATUS_ERROR;
        } else if ($result->manual) {
            $status = self::STATUS_MANUAL;
        }

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => $status,
            'output' => $result->output === null
                ? null
                : json_encode($result->output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'tokensin' => $result->tokensin,
            'tokensout' => $result->tokensout,
            'tokenscached' => $result->tokenscached,
            'cost' => $result->cost,
            'error' => $result->error === '' ? null : $result->error,
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the rows of a job's steps, oldest first.
     *
     * @param int $jobid Job id.
     * @param string|null $step Only this pipeline step, null for all of them.
     * @return \stdClass[]
     */
    public function all_for_job(int $jobid, ?string $step = null): array {
        global $DB;

        $conditions = ['jobid' => $jobid];
        if ($step !== null) {
            $conditions['step'] = $step;
        }
        return array_values($DB->get_records(self::TABLE, $conditions, 'id ASC'));
    }

    /**
     * Returns what a job has spent so far, across every step.
     *
     * @param int $jobid Job id.
     * @return array ['tokensin' => int, 'tokensout' => int, 'tokenscached' => int, 'cost' => float]
     */
    public function totals(int $jobid): array {
        global $DB;

        $sql = 'SELECT COALESCE(SUM(tokensin), 0) AS tokensin,
                       COALESCE(SUM(tokensout), 0) AS tokensout,
                       COALESCE(SUM(tokenscached), 0) AS tokenscached,
                       COALESCE(SUM(cost), 0) AS cost
                  FROM {' . self::TABLE . '}
                 WHERE jobid = :jobid';
        $row = $DB->get_record_sql($sql, ['jobid' => $jobid]);

        return [
            'tokensin' => (int) $row->tokensin,
            'tokensout' => (int) $row->tokensout,
            'tokenscached' => (int) $row->tokenscached,
            'cost' => (float) $row->cost,
        ];
    }

    /**
     * Sorts an array's keys, and those of every array inside it, so the hash does not depend on the
     * order PHP happens to hold them in.
     *
     * @param mixed $value The value.
     * @return mixed The value with its keys in a fixed order.
     */
    protected static function canonicalise(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = array_map(fn($item) => self::canonicalise($item), $value);
        if (!array_is_list($sorted)) {
            ksort($sorted);
        }
        return $sorted;
    }
}
