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

use local_aicoursebuilder\ai\budget_exceeded_exception;
use local_aicoursebuilder\pipeline\regenerator;

/**
 * Asynchronously regenerates one blueprint subtree from a fixed source version.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regenerate_node extends \core\task\adhoc_task {
    /**
     * Creates a task pinned to the blueprint version visible when the owner requested it.
     *
     * @param int $jobid Job id.
     * @param int $userid Owner id.
     * @param int $blueprintid Source blueprint version id.
     * @param string $nodeid Target node id.
     * @param string $instructions Teacher instructions.
     * @return self
     */
    public static function instance(
        int $jobid,
        int $userid,
        int $blueprintid,
        string $nodeid,
        string $instructions,
    ): self {
        $task = new self();
        $task->set_custom_data([
            'jobid' => $jobid,
            'blueprintid' => $blueprintid,
            'nodeid' => $nodeid,
            'instructions' => $instructions,
        ]);
        $task->set_userid($userid);
        return $task;
    }

    /**
     * Returns a translated task name.
     *
     * @return string
     */
    #[\Override]
    public function get_name(): string {
        return get_string('task_regeneratenode', 'local_aicoursebuilder');
    }

    /**
     * Generates and saves a draft; a budget or validation error leaves the source version intact.
     */
    #[\Override]
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $job = $DB->get_record('local_aicb_job', ['id' => (int) ($data->jobid ?? 0)]);
        if (!$job || $job->status === 'cancelled') {
            return;
        }
        try {
            (new regenerator())->run(
                (int) $job->id,
                (int) $data->blueprintid,
                (string) $data->nodeid,
                (string) ($data->instructions ?? ''),
            );
            $DB->update_record('local_aicb_job', (object) [
                'id' => $job->id,
                'status' => 'review',
                'stage' => 'review',
                'error' => null,
                'statusmessage' => get_string('regenerationsaved', 'local_aicoursebuilder'),
                'timemodified' => time(),
            ]);
        } catch (budget_exceeded_exception | \moodle_exception $e) {
            $DB->update_record('local_aicb_job', (object) [
                'id' => $job->id,
                'error' => $e->getMessage(),
                'statusmessage' => get_string('regenerationfailed', 'local_aicoursebuilder'),
                'timemodified' => time(),
            ]);
        }
    }
}
