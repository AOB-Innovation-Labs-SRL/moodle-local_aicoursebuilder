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

/**
 * A builder that fails on the nodes it is told to, before it builds them, and builds them once it is disarmed.
 *
 * It wraps a real builder, so that a test can inject an error into a build made with the builders of the plugin.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_builder implements builder_interface {
    /** @var bool Whether the builder fails; a test turns it off for the run that resumes the build. */
    public bool $armed = true;

    /** @var string[] Node ids the builder was asked to build, in order. */
    public array $calls = [];

    /**
     * Creates the builder.
     *
     * @param builder_interface $inner The real builder.
     * @param string[] $nodeids Nodes to fail on.
     */
    public function __construct(
        /** @var builder_interface The real builder. */
        private readonly builder_interface $inner,
        /** @var string[] Nodes to fail on. */
        private readonly array $nodeids
    ) {
    }

    /**
     * Builds a node, or fails on it while armed.
     *
     * @param array|\stdClass $node The blueprint node.
     * @param build_context $context The build context.
     * @return build_result
     * @throws \RuntimeException On a node it was told to fail on, while armed.
     */
    #[\Override]
    public function build(array|\stdClass $node, build_context $context): build_result {
        $nodeid = (string) (((array) $node)['id'] ?? '');
        $this->calls[] = $nodeid;
        if ($this->armed && in_array($nodeid, $this->nodeids, true)) {
            throw new \RuntimeException("Injected error on {$nodeid}");
        }
        return $this->inner->build($node, $context);
    }
}
