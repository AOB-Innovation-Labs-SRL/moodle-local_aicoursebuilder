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

use core_badges\badge;

/**
 * Creates the badges of the blueprint in the course.
 *
 * A badge is a course badge made with \core_badges\badge::create_badge(), which leaves it inactive, as the plugin
 * wants: the teacher activates it once they have looked at it (spec 3.7). Its criteria are the aggregation that every
 * badge needs and then the one the blueprint asks for, completing the course or completing the activities that award
 * the badge. The image is a plain disc, because the pilot generates no images and a badge needs one; the teacher
 * replaces it.
 *
 * Building again must change nothing, so a badge of the course with the name of a badge of the blueprint is left as
 * it is. What cannot be done leaves a warning: badges switched off on the site, or a criterion with no activity that
 * could award it.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class badge_builder {
    /** @var int Side of the image of the badge, in pixels. */
    public const IMAGE_SIDE = 300;

    /**
     * Creates the badges.
     *
     * @param array[] $badges The badges of the blueprint: name, description and criteria (type, activities).
     * @param build_context $context The build context, with the course and the cmid of every built activity.
     * @return string[] A warning for everything that was left out.
     */
    public static function apply(array $badges, build_context $context): array {
        global $CFG;

        if ($badges === []) {
            return [];
        }
        if (empty($CFG->enablebadges)) {
            return [get_string('buildwarnbadgesdisabled', 'local_aicoursebuilder')];
        }
        require_once($CFG->libdir . '/badgeslib.php');
        require_once($CFG->dirroot . '/badges/criteria/award_criteria.php');

        $warnings = [];
        foreach ($badges as $definition) {
            $warnings = array_merge($warnings, self::create($definition, $context));
        }
        return $warnings;
    }

    /**
     * Creates one badge with its criteria and its image.
     *
     * @param array $definition The badge of the blueprint.
     * @param build_context $context The build context.
     * @return string[] Warnings.
     */
    protected static function create(array $definition, build_context $context): array {
        global $DB;

        $course = $context->get_course();
        $name = trim((string) $definition['name']);
        if ($DB->record_exists('badge', ['courseid' => $course->id, 'name' => $name])) {
            return [];
        }

        $badge = badge::create_badge(self::data($definition, $course), (int) $course->id);
        badges_process_badge_image($badge, self::image($name));
        return self::criteria($badge, $definition['criteria'] ?? [], $name, $context);
    }

    /**
     * Returns the data \core_badges\badge::create_badge() takes, from the badge of the blueprint.
     *
     * @param array $definition The badge of the blueprint.
     * @param \stdClass $course The course.
     * @return \stdClass
     */
    protected static function data(array $definition, \stdClass $course): \stdClass {
        global $CFG, $SITE, $USER;

        $data = new \stdClass();
        $data->name = (string) $definition['name'];
        $data->version = '';
        $data->language = $course->lang ?: ($CFG->lang ?? 'en');
        $data->description = (string) ($definition['description'] ?? '');
        $data->imagecaption = '';
        // The issuer is the one the site gives its own badges, and the site itself when it has not set one.
        $data->issuername = ($CFG->badges_defaultissuername ?? '') ?: $SITE->fullname;
        $data->issuerurl = ($CFG->badges_defaultissuerurl ?? '') ?: $CFG->wwwroot;
        $data->issuercontact = ($CFG->badges_defaultissuercontact ?? '') ?: (($CFG->supportemail ?? '') ?: $USER->email);
        return $data;
    }

    /**
     * Adds the criteria of a badge: the aggregation, then what has to be completed.
     *
     * @param badge $badge The badge.
     * @param array $criteria The criteria object of the blueprint: type and, for activities, the activity ids.
     * @param string $name Name of the badge, for the warnings.
     * @param build_context $context The build context.
     * @return string[] Warnings.
     */
    protected static function criteria(badge $badge, array $criteria, string $name, build_context $context): array {
        $overall = \award_criteria::build(['criteriatype' => BADGE_CRITERIA_TYPE_OVERALL, 'badgeid' => $badge->id]);
        $overall->save(['agg' => BADGE_CRITERIA_AGGREGATION_ALL]);

        $courseid = (int) $context->get_course()->id;
        if (($criteria['type'] ?? '') === 'course') {
            $criterion = \award_criteria::build(['criteriatype' => BADGE_CRITERIA_TYPE_COURSE, 'badgeid' => $badge->id]);
            $criterion->save(['agg' => BADGE_CRITERIA_AGGREGATION_ALL, 'course_' . $courseid => $courseid]);
            return [];
        }

        $params = ['agg' => BADGE_CRITERIA_AGGREGATION_ALL];
        $missing = [];
        foreach ($criteria['activities'] ?? [] as $activityid) {
            $cmid = $context->get_cmid((string) $activityid);
            if ($cmid === null || !self::tracks_completion($cmid)) {
                $missing[] = $activityid;
                continue;
            }
            $params['module_' . $cmid] = $cmid;
        }
        $warnings = [];
        if ($missing !== []) {
            $warnings[] = get_string('buildwarnbadgeactivities', 'local_aicoursebuilder', [
                'badge' => $name,
                'activities' => implode(', ', $missing),
            ]);
        }
        if (count($params) > 1) {
            $criterion = \award_criteria::build(['criteriatype' => BADGE_CRITERIA_TYPE_ACTIVITY, 'badgeid' => $badge->id]);
            $criterion->save($params);
        }
        return $warnings;
    }

    /**
     * Tells whether an activity tracks completion, which an activity criterion needs.
     *
     * @param int $cmid Course module id.
     * @return bool
     */
    protected static function tracks_completion(int $cmid): bool {
        global $DB;

        return (int) $DB->get_field('course_modules', 'completion', ['id' => $cmid]) !== COMPLETION_TRACKING_NONE;
    }

    /**
     * Draws the image of a badge: a disc whose colour comes from the name, so that badges are told apart.
     *
     * @param string $name Name of the badge.
     * @return string Path of the PNG file.
     */
    protected static function image(string $name): string {
        $side = self::IMAGE_SIDE;
        $image = imagecreatetruecolor($side, $side);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $hash = crc32($name);
        $colour = imagecolorallocate($image, 40 + ($hash & 0x7f), 40 + (($hash >> 8) & 0x7f), 40 + (($hash >> 16) & 0x7f));
        imagefilledellipse($image, intdiv($side, 2), intdiv($side, 2), $side - 10, $side - 10, $colour);
        $path = make_request_directory() . '/badge.png';
        imagepng($image, $path);
        return $path;
    }
}
