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

use core_competency\api;
use core_competency\competency;
use core_competency\course_module_competency;

/**
 * Links the competencies of the blueprint to the course and to its activities.
 *
 * The plugin never creates a competency: the blueprint names competencies by their idnumber, and they are looked up in
 * the framework the administrator chose in the settings (competencyframework). A competency is linked to the course
 * first, which Moodle asks for before it takes it on an activity, and then to each activity that develops it
 * (\core_competency\api::add_competency_to_course() and add_competency_to_course_module(), spec 3.7).
 *
 * Whatever cannot be done leaves a warning and nothing else: no framework chosen, competencies switched off on the
 * site, an idnumber that the framework does not have, a framework the teacher may not link, an activity that was not
 * built. The course is built all the same.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class competency_builder {
    /** @var int Most competencies named in the warning about those the framework does not have. */
    public const MAX_NAMED = 5;

    /**
     * Links the competencies.
     *
     * @param array[] $competencies ['idnumber' => string, 'activities' => string[]], as the build plan collects them.
     * @param build_context $context The build context, with the course and the cmid of every built activity.
     * @return string[] A warning for everything that was left out.
     */
    public static function apply(array $competencies, build_context $context): array {
        if ($competencies === []) {
            return [];
        }
        if (!api::is_enabled()) {
            return [get_string('buildwarncompetencydisabled', 'local_aicoursebuilder')];
        }
        $frameworkid = (int) get_config('local_aicoursebuilder', 'competencyframework');
        if ($frameworkid <= 0) {
            return [get_string('buildwarncompetencynoframework', 'local_aicoursebuilder')];
        }

        $warnings = [];
        $missing = [];
        $courseid = (int) $context->get_course()->id;
        foreach ($competencies as $entry) {
            $competency = competency::get_record(['idnumber' => $entry['idnumber'], 'competencyframeworkid' => $frameworkid]);
            if (!$competency) {
                $missing[] = $entry['idnumber'];
                continue;
            }
            try {
                api::add_competency_to_course($courseid, (int) $competency->get('id'));
            } catch (\Throwable $e) {
                $warnings[] = get_string('buildwarncompetencyfailed', 'local_aicoursebuilder', [
                    'idnumber' => $entry['idnumber'],
                    'reason' => $e->getMessage(),
                ]);
                continue;
            }
            foreach ($entry['activities'] as $activityid) {
                $warning = self::link_activity($entry['idnumber'], (int) $competency->get('id'), $activityid, $context);
                if ($warning !== null) {
                    $warnings[] = $warning;
                }
            }
        }
        if ($missing !== []) {
            // One warning for all of them: a model writes labels of its own, and each would be a line of its own.
            $shown = array_slice($missing, 0, self::MAX_NAMED);
            $warnings[] = get_string('buildwarncompetencymissing', 'local_aicoursebuilder', [
                'count' => count($missing),
                'idnumbers' => implode(', ', $shown) . (count($missing) > count($shown) ? ', ...' : ''),
            ]);
        }
        return $warnings;
    }

    /**
     * Links a competency, which is already in the course, to an activity.
     *
     * @param string $idnumber Idnumber of the competency, for the warning.
     * @param int $competencyid Id of the competency.
     * @param string $activityid Blueprint id of the activity.
     * @param build_context $context The build context.
     * @return string|null A warning, null when the activity has the competency.
     */
    protected static function link_activity(
        string $idnumber,
        int $competencyid,
        string $activityid,
        build_context $context,
    ): ?string {
        $cmid = $context->get_cmid($activityid);
        if ($cmid === null) {
            return get_string('buildwarncompetencyactivity', 'local_aicoursebuilder', [
                'idnumber' => $idnumber,
                'activity' => $activityid,
            ]);
        }
        // A competency already on the activity is left as it is, so that building again changes nothing.
        if (course_module_competency::get_records(['cmid' => $cmid, 'competencyid' => $competencyid])) {
            return null;
        }
        try {
            api::add_competency_to_course_module($cmid, $competencyid);
        } catch (\Throwable $e) {
            return get_string('buildwarncompetencyfailed', 'local_aicoursebuilder', [
                'idnumber' => $idnumber,
                'reason' => $e->getMessage(),
            ]);
        }
        return null;
    }
}
