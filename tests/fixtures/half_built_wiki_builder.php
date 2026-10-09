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
 * A wiki builder that fails after the module and its wiki exist, while it makes the pages: an error in the middle of a
 * real builder, where half of the work is already in the database.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class half_built_wiki_builder extends wiki_builder {
    /** @var bool Whether the builder fails; a test turns it off for the run that resumes the build. */
    public bool $armed = true;

    /**
     * Makes the pages of the wiki, and then fails while armed.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     * @throws \RuntimeException While armed.
     */
    #[\Override]
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
        parent::after_created($created, $node, $context);
        if ($this->armed) {
            throw new \RuntimeException('Injected error after the pages of the wiki were made');
        }
    }
}
