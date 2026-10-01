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
 * One availability rule of the blueprint, with the nodes it refers to.
 *
 * The rule is applied in a pass of its own, after every module has a cmid (spec 3.7), because
 * require_completion_of and min_grade point at other activities by their blueprint id and the real
 * cmid is only known once they are built. A rule that refers to a node which failed or was left manual
 * cannot be expressed in Moodle, so it is skipped with a warning rather than applied half way.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class availability_target {
    /**
     * Creates a target.
     *
     * @param string $nodeid Node the rule restricts: a section, a subsection or an activity.
     * @param array $rule The availability object of schema/blueprint.v1.json: require_completion_of,
     *                    min_grade and date_from.
     */
    public function __construct(
        /** @var string Node the rule restricts. */
        public readonly string $nodeid,
        /** @var array The availability rule. */
        public readonly array $rule,
    ) {
    }

    /**
     * Returns the activity node ids the rule refers to, which must be built before it can be applied.
     *
     * date_from refers to nothing, so a rule with only a date has no dependency.
     *
     * @return string[] Activity node ids, without duplicates.
     */
    public function dependencies(): array {
        $ids = [];
        foreach ($this->rule['require_completion_of'] ?? [] as $activityid) {
            if (is_string($activityid)) {
                $ids[] = $activityid;
            }
        }
        $mingrade = $this->rule['min_grade']['activity'] ?? null;
        if (is_string($mingrade)) {
            $ids[] = $mingrade;
        }
        return array_values(array_unique($ids));
    }
}
