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
 * The idnumber that marks what one job built for one blueprint node.
 *
 * A builder creates Moodle objects before the build map is written: the quiz module exists before its
 * questions are imported, but the checkpoint is only recorded once the whole node succeeds. A run that
 * dies in between leaves an orphan behind, which a second run would otherwise duplicate. The key is how
 * the orphan is found again: the quiz course module and the question subcategory of a node both carry it
 * in their idnumber, so a re-run deletes exactly what the previous run left and never touches anything
 * a teacher made.
 *
 * The key is derived only from the job id and the node id, so it is the same on every run of the same
 * node. Both idnumber columns are char(100): a key that would not fit keeps a readable prefix and ends
 * in a hash of the whole key, which stays deterministic and keeps distinct nodes distinct.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class build_key {
    /** @var string Prefix of every key, so that what the plugin built is recognisable in the course. */
    public const PREFIX = 'aicb';

    /** @var int Length of question_categories.idnumber and course_modules.idnumber. */
    public const MAXLENGTH = 100;

    /** @var int Characters of the hash appended to a key that has to be shortened. */
    protected const HASHLENGTH = 12;

    /** @var string Job part of the key when the build has no job, which happens in tests. */
    protected const NOJOB = 'nojob';

    /**
     * Returns the idnumber of a node, the same on every run of that node of that job.
     *
     * @param int|null $jobid Job that builds the node, null for a build that has no job.
     * @param string $nodeid Blueprint node id, such as s1.quiz1.
     * @return string Key of at most MAXLENGTH characters, made of [a-z0-9-] only.
     */
    public static function for_node(?int $jobid, string $nodeid): string {
        $key = self::PREFIX . '-' . ($jobid === null ? self::NOJOB : $jobid) . '-' . self::normalise($nodeid);
        if (\core_text::strlen($key) <= self::MAXLENGTH) {
            return $key;
        }
        // Too long to keep whole: a readable prefix plus a hash of the whole key, so that it stays
        // deterministic and two nodes that share a prefix still get different keys.
        $hash = substr(hash('sha256', $key), 0, self::HASHLENGTH);
        $prefix = \core_text::substr($key, 0, self::MAXLENGTH - self::HASHLENGTH - 1);
        return rtrim($prefix, '-') . '-' . $hash;
    }

    /**
     * Reduces a node id to the characters an idnumber may safely carry.
     *
     * @param string $value The node id.
     * @return string Lower case, with every run of other characters turned into one dash.
     */
    protected static function normalise(string $value): string {
        $value = \core_text::strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim((string) $value, '-');
    }
}
