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

use local_aicoursebuilder\ai\output_limits;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\blueprint\node_tree;
use local_aicoursebuilder\blueprint\validation_error;

/**
 * Regenerates and validates one subtree, with its required ids and all other nodes preserved.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_regenerate extends step {
    /** @var string Version of the regeneration prompt. */
    public const PROMPT_VERSION = 'v2';

    /** @var string Route for the target node type. */
    protected string $route;

    /**
     * Creates a regeneration step using the route of the target node type.
     *
     * @param pipeline_context $context Pipeline context.
     * @param string $route One of the content, activities or questions routes.
     */
    public function __construct(pipeline_context $context, string $route) {
        parent::__construct($context);
        if (!in_array($route, [request::STEP_SECTIONS, request::STEP_ACTIVITIES, request::STEP_QUESTIONS], true)) {
            throw new \coding_exception('Invalid regeneration route');
        }
        $this->route = $route;
    }

    /**
     * Returns the route of this node type.
     *
     * @return string
     */
    public function get_step(): string {
        return $this->route;
    }

    /**
     * Returns the regeneration prompt name.
     *
     * @return string
     */
    public function get_prompt_name(): string {
        return 'regenerate';
    }

    /**
     * Keeps the regeneration prompt version in its own step hash.
     *
     * @param array $route Connector route.
     * @return array Hash context.
     */
    protected function hash_context(array $route): array {
        $context = parent::hash_context($route);
        $context['promptversion'] = self::PROMPT_VERSION;
        return $context;
    }

    /**
     * Renders the current regeneration prompt without changing established generation prompts.
     *
     * @param array $input Step input.
     * @param string $nodekey Target id.
     * @return string
     */
    protected function render_prompt(array $input, string $nodekey): string {
        return (new prompt('regenerate', self::PROMPT_VERSION, $this->context->promptdir))
            ->render($this->prompt_values($input, $nodekey));
    }

    /**
     * Supplies the target, immutable context and teacher instructions.
     *
     * @param array $input Blueprint, target path, required ids and instructions.
     * @param string $nodekey Target id.
     * @return array
     */
    protected function prompt_values(array $input, string $nodekey): array {
        return [
            'language_name' => $this->context->language_name(),
            'language' => $this->context->language,
            'target_id' => $nodekey,
            'node' => node_tree::get($input['blueprint'], $input['path']),
            'blueprint' => $input['blueprint'],
            'required_ids' => $input['required_ids'],
            'sources' => $this->context->sourcetexts,
            'instructions' => $input['instructions'],
            'source_ids' => $this->context->source_ids_text(),
        ];
    }

    /**
     * Sends JSON mode without the normal step fragment schema: this response is one arbitrary node.
     *
     * @param string $system Rendered regeneration prompt.
     * @param string $message User message.
     * @return request
     */
    protected function build_request(string $system, string $message): request {
        return new request(
            step: $this->get_step(),
            system: $system,
            messages: [['role' => 'user', 'content' => $message]],
            maxtokens: output_limits::for_step($this->get_step()),
            jobid: $this->context->jobid,
            userid: $this->context->userid,
            contextid: $this->context->contextid,
            json: true,
        );
    }

    /**
     * Checks preserved ids and validates the entire blueprint after this one replacement.
     *
     * @param array|null $output Model response.
     * @param array $input Source blueprint and target location.
     * @param string $nodekey Target id.
     * @return validation_error[]
     */
    protected function validate(?array $output, array $input, string $nodekey): array {
        if (
            $output === null || !isset($output['node']) || !is_array($output['node'])
            || array_keys($output) !== ['node']
        ) {
            return [new validation_error(
                '/node',
                validation_error::CODE_NOT_JSON,
                'Return one JSON object containing only the replacement node.'
            )];
        }
        $ids = [];
        $this->collect_ids($output['node'], $ids);
        $errors = [];
        foreach ($input['required_ids'] as $id) {
            if (!isset($ids[$id])) {
                $errors[] = new validation_error(
                    '/node',
                    validation_error::CODE_BROKEN_REF,
                    "Preserve mandatory id {$id} in the replacement subtree."
                );
            }
        }
        if (($output['node']['id'] ?? null) !== $nodekey) {
            $errors[] = new validation_error(
                '/node/id',
                validation_error::CODE_BROKEN_REF,
                'The target node id must remain unchanged.'
            );
        }
        if ($errors !== []) {
            return $errors;
        }
        $candidate = node_tree::replace($input['blueprint'], $input['path'], $output['node']);
        return $this->context->validator->validate($candidate, $this->context->sourcetexts);
    }

    /**
     * Collects ids under a replacement node.
     *
     * @param array $node Current subtree.
     * @param array $ids Id set to fill.
     */
    protected function collect_ids(array $node, array &$ids): void {
        if (isset($node['id']) && is_string($node['id'])) {
            $ids[$node['id']] = true;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collect_ids($child, $ids);
            }
        }
    }
}
