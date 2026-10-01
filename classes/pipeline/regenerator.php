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

use local_aicoursebuilder\ai\budget_guard;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\blueprint\node_tree;
use local_aicoursebuilder\blueprint\schema_store;
use local_aicoursebuilder\blueprint\validator;
use local_aicoursebuilder\blueprint\version_store;

/**
 * Regenerates one subtree of a fixed blueprint version and publishes a validated draft.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regenerator {
    /**
     * Runs regeneration. The input version, approved or draft, is never changed.
     *
     * @param int $jobid Job id.
     * @param int $blueprintid Id of the version selected when the work was queued.
     * @param string $nodeid Target node id.
     * @param string $instructions Teacher instructions, part of the step hash.
     * @param pipeline_context|null $context Injected pipeline context for tests.
     * @return \stdClass Newly saved draft version.
     */
    public function run(
        int $jobid,
        int $blueprintid,
        string $nodeid,
        string $instructions,
        ?pipeline_context $context = null,
    ): \stdClass {
        global $DB;

        $job = $DB->get_record('local_aicb_job', ['id' => $jobid], '*', MUST_EXIST);
        $source = $DB->get_record('local_aicb_blueprint', ['id' => $blueprintid, 'jobid' => $jobid], '*', MUST_EXIST);
        $blueprint = json_decode($source->content, true, 512, JSON_THROW_ON_ERROR);
        $path = node_tree::locate($blueprint, $nodeid);
        if ($path === null) {
            throw new \moodle_exception('regenerationnodeunknown', 'local_aicoursebuilder', '', $nodeid);
        }
        $context ??= $this->context_for($job);
        $oldnode = node_tree::get($blueprint, $path);
        $route = $this->route_for($oldnode, $path);
        $input = [
            'sourceversionid' => $blueprintid,
            'blueprint' => $blueprint,
            'path' => $path,
            'required_ids' => node_tree::required_ids($blueprint, $path),
            'instructions' => $instructions,
        ];
        $result = (new step_regenerate($context, $route))->run($input, $nodeid);
        if (!$result->is_success()) {
            throw new \moodle_exception('regenerationinvalid', 'local_aicoursebuilder', '', $result->error);
        }
        $candidate = node_tree::replace($blueprint, $path, $result->output['node']);
        // Validate once more immediately before the insert, even for a resumed step.
        return (new version_store())->save(
            $jobid,
            (int) $job->userid,
            $candidate,
            $context->validator,
            $context->sourcetexts,
        );
    }

    /**
     * Chooses the existing model route for the target's kind.
     *
     * @param array $node Target node.
     * @param array $path Path to target node.
     * @return string Request step route.
     */
    protected function route_for(array $node, array $path): string {
        if (in_array('questions', $path, true) || ($node['type'] ?? '') === 'quiz') {
            return request::STEP_QUESTIONS;
        }
        if (in_array('activities', $path, true)) {
            return in_array($node['type'] ?? '', ['page', 'book', 'label', 'url', 'resource', 'folder'], true)
                ? request::STEP_SECTIONS : request::STEP_ACTIVITIES;
        }
        return request::STEP_SECTIONS;
    }

    /**
     * Builds the production pipeline context from job metadata and extracted chunks.
     *
     * @param \stdClass $job Job record.
     * @return pipeline_context
     */
    protected function context_for(\stdClass $job): pipeline_context {
        global $DB;

        $sourcetexts = [];
        $sources = array_values($DB->get_records('local_aicb_source', ['jobid' => $job->id], 'id ASC', 'id'));
        $names = [];
        foreach ($sources as $index => $source) {
            $names[$source->id] = 'src' . ($index + 1);
        }
        $chunks = $DB->get_records('local_aicb_chunk', ['jobid' => $job->id], 'sourceid ASC, chunkindex ASC');
        foreach ($chunks as $chunk) {
            $sourceid = $names[$chunk->sourceid] ?? null;
            if ($sourceid === null) {
                continue;
            }
            $sourcetexts[$sourceid] = ($sourcetexts[$sourceid] ?? '') . "\n" . $chunk->content;
        }
        return new pipeline_context(
            jobid: (int) $job->id,
            userid: (int) $job->userid,
            language: $job->language ?: pipeline_context::DEFAULT_LANGUAGE,
            router: new router(),
            budget: new budget_guard(),
            steps: new step_store(),
            validator: new validator(),
            schemas: new schema_store(),
            sourcetexts: $sourcetexts,
            contextid: \local_aicoursebuilder\external\job_api::get_job_context($job)->id,
        );
    }
}
