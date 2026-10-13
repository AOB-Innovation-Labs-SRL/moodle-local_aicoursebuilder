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

use availability_completion\condition as completion_condition;
use availability_date\condition as date_condition;
use availability_grade\condition as grade_condition;
use core_availability\tree;

/**
 * Turns the availability rule of a blueprint node into the JSON of Moodle's access restrictions.
 *
 * The blueprint says what a learner must have done: complete other activities (require_completion_of), reach a grade
 * in one of them (min_grade) or wait for a date (date_from). Moodle stores that as a tree of conditions in
 * course_modules.availability or course_sections.availability, built here from the condition classes of the three
 * restriction plugins, joined with AND, so that the JSON is the one the restriction editor would have written.
 *
 * A condition that cannot hold is left out with a warning instead of being written: a completion rule on an activity
 * that does not track completion would lock the learner out for good, and a grade rule on an activity with no grade
 * item has nothing to compare with.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class availability_mapper {
    /**
     * Builds the restriction of a node.
     *
     * @param availability_target $target The rule and the node it restricts.
     * @param build_context $context The build context, which knows the course module of every built activity.
     * @return array [string|null, string[]]: the JSON to store, null when no condition can be written, and a
     *                                        warning for each condition that was left out.
     */
    public static function map(availability_target $target, build_context $context): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $children = [];
        $warnings = [];

        foreach ($target->rule['require_completion_of'] ?? [] as $activityid) {
            $cm = self::course_module((string) $activityid, $context);
            if ($cm === null) {
                continue;
            }
            if ((int) $cm->completion === COMPLETION_TRACKING_NONE) {
                $warnings[] = get_string('buildwarnavailabilitynocompletion', 'local_aicoursebuilder', [
                    'node' => $target->nodeid,
                    'activity' => $activityid,
                ]);
                continue;
            }
            $children[] = completion_condition::get_json((int) $cm->id, COMPLETION_COMPLETE);
        }

        $mingrade = $target->rule['min_grade'] ?? null;
        if (is_array($mingrade) && isset($mingrade['activity'], $mingrade['min'])) {
            $gradeitem = self::grade_item((string) $mingrade['activity'], $context);
            if ($gradeitem === null) {
                $warnings[] = get_string('buildwarnavailabilitynograde', 'local_aicoursebuilder', [
                    'node' => $target->nodeid,
                    'activity' => $mingrade['activity'],
                ]);
            } else {
                // The condition compares a percentage of the grade range, which is what the blueprint's 0 to 100 is.
                $children[] = grade_condition::get_json((int) $gradeitem->id, (float) $mingrade['min']);
            }
        }

        if (!empty($target->rule['date_from'])) {
            $time = self::timestamp((string) $target->rule['date_from']);
            if ($time !== null) {
                $children[] = date_condition::get_json(date_condition::DIRECTION_FROM, $time);
            }
        }

        if ($children === []) {
            return [null, $warnings];
        }
        return [json_encode(tree::get_root_json($children, tree::OP_AND, true)), $warnings];
    }

    /**
     * Returns the course module of a built activity.
     *
     * @param string $activityid Blueprint id of the activity.
     * @param build_context $context The build context.
     * @return \stdClass|null The course_modules row, null when the activity was not built.
     */
    protected static function course_module(string $activityid, build_context $context): ?\stdClass {
        global $DB;

        $cmid = $context->get_cmid($activityid);
        if ($cmid === null) {
            return null;
        }
        return $DB->get_record('course_modules', ['id' => $cmid]) ?: null;
    }

    /**
     * Returns the grade item of the activity: the one its learners are graded on.
     *
     * @param string $activityid Blueprint id of the activity.
     * @param build_context $context The build context.
     * @return \grade_item|null Null when the activity was not built or has no grade item.
     */
    protected static function grade_item(string $activityid, build_context $context): ?\grade_item {
        global $DB;

        $cm = self::course_module($activityid, $context);
        if ($cm === null) {
            return null;
        }
        $module = $DB->get_field('modules', 'name', ['id' => $cm->module]);
        $item = \grade_item::fetch([
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $module,
            'iteminstance' => $cm->instance,
            'itemnumber' => 0,
        ]);
        return $item ?: null;
    }

    /**
     * Returns the start of a day, in the timezone of the user who runs the build.
     *
     * @param string $date Date as the blueprint writes it, YYYY-MM-DD.
     * @return int|null Null when it is not a date.
     */
    protected static function timestamp(string $date): ?int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return null;
        }
        return make_timestamp((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }
}
