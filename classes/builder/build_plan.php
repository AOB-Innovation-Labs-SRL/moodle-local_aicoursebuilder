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
 * The topological order in which one blueprint is built (spec 3.7).
 *
 * The order is fixed by the dependencies between Moodle objects, not by the blueprint:
 *
 *  1. the course, because a section needs a course;
 *  2. every section and, right after each one, its subsections, because a module needs a section;
 *  3. the modules, in blueprint order, a section's own activities before its subsections' ones;
 *  4. the availability rules, in a separate pass, because a rule refers to the cmid of another module and
 *     every module must already have one (spec 3.7);
 *  5. course completion, which refers to the cmids of the activities that complete the course.
 *
 * Steps 1 to 3 are build_step objects run through the registry. Steps 4 and 5 are not builders: they are
 * lists of targets the task applies itself once the cmids are known.
 *
 * In an existing course there is no course step and no section steps: the modules go into the section the
 * job chose, so only the activities of the blueprint are built (spec 3.7).
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_plan {
    /** @var string Node id of the course itself; the schema gives no id to the course object. */
    public const COURSE_NODEID = 'course';

    /**
     * Creates a plan.
     *
     * @param build_step[] $steps Build steps, in topological order.
     * @param availability_target[] $availability Availability rules, applied after every module has a cmid.
     * @param string[] $completionactivities Activity node ids that complete the course.
     */
    protected function __construct(
        /** @var build_step[] Build steps, in topological order. */
        public readonly array $steps,
        /** @var availability_target[] Availability rules. */
        public readonly array $availability,
        /** @var string[] Activity node ids that complete the course. */
        public readonly array $completionactivities,
    ) {
    }

    /**
     * Builds the plan of a blueprint.
     *
     * @param array $blueprint Decoded, approved blueprint.
     * @param bool $newcourse True to build the course and its sections, false to only add the activities of
     *                        the blueprint to the section an existing course job chose (spec 3.7).
     * @return self
     */
    public static function from_blueprint(array $blueprint, bool $newcourse): self {
        $steps = [];
        $availability = [];
        $completion = [];

        if ($newcourse) {
            $steps[] = new build_step(
                self::COURSE_NODEID,
                builder_registry::TYPE_COURSE,
                $blueprint['course'] ?? [],
            );
        }

        foreach ($blueprint['sections'] ?? [] as $section) {
            $sectionid = (string) ($section['id'] ?? '');
            if ($sectionid === '') {
                continue;
            }
            // In an existing course the activities go into the section the job chose, so no section is built
            // and the activities have no parent step that could fail before them.
            $parentid = null;
            if ($newcourse) {
                $steps[] = new build_step($sectionid, builder_registry::TYPE_SECTION, $section);
                $parentid = $sectionid;
                self::collect_availability($sectionid, $section, $availability);
            }

            foreach (self::activity_steps($section, $parentid) as $step) {
                $steps[] = $step;
                self::collect_availability($step->nodeid, $step->node, $availability);
            }

            foreach ($section['subsections'] ?? [] as $subsection) {
                $subsectionid = (string) ($subsection['id'] ?? '');
                if ($subsectionid === '') {
                    continue;
                }
                $subparentid = $parentid;
                if ($newcourse) {
                    // A subsection is a module of its section, so the section must exist first.
                    $steps[] = new build_step(
                        $subsectionid,
                        builder_registry::TYPE_SUBSECTION,
                        $subsection,
                        $sectionid,
                    );
                    $subparentid = $subsectionid;
                    self::collect_availability($subsectionid, $subsection, $availability);
                }
                foreach (self::activity_steps($subsection, $subparentid) as $step) {
                    $steps[] = $step;
                    self::collect_availability($step->nodeid, $step->node, $availability);
                }
            }
        }

        foreach ($blueprint['course']['completion_activities'] ?? [] as $activityid) {
            if (is_string($activityid)) {
                $completion[] = $activityid;
            }
        }

        return new self($steps, $availability, $completion);
    }

    /**
     * Returns the build steps of the activities of one section or subsection, in blueprint order.
     *
     * @param array $container Section or subsection node.
     * @param string|null $parentid Node id the activities are built in, null in an existing course.
     * @return build_step[]
     */
    protected static function activity_steps(array $container, ?string $parentid): array {
        $steps = [];
        foreach ($container['activities'] ?? [] as $activity) {
            $activityid = (string) ($activity['id'] ?? '');
            $type = (string) ($activity['type'] ?? '');
            if ($activityid === '' || $type === '') {
                continue;
            }
            $steps[] = new build_step($activityid, $type, $activity, $parentid);
        }
        return $steps;
    }

    /**
     * Adds the availability rule of a node to the list, when it has one.
     *
     * @param string $nodeid Node the rule is applied to.
     * @param array $node The section, subsection or activity node.
     * @param availability_target[] $availability List to add to.
     */
    protected static function collect_availability(string $nodeid, array $node, array &$availability): void {
        $rule = $node['availability'] ?? null;
        if (is_array($rule) && $rule !== []) {
            $availability[] = new availability_target($nodeid, $rule);
        }
    }
}
