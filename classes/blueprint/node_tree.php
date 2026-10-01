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

namespace local_aicoursebuilder\blueprint;

/**
 * Locates and replaces one blueprint subtree without touching its siblings.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class node_tree {
    /**
     * Returns the array path of a section, subsection, activity or question id.
     *
     * @param array $blueprint Complete blueprint.
     * @param string $nodeid Requested id.
     * @return array|null Path parts, or null when absent.
     */
    public static function locate(array $blueprint, string $nodeid): ?array {
        foreach ($blueprint['sections'] ?? [] as $si => $section) {
            $spath = ['sections', $si];
            if (($section['id'] ?? null) === $nodeid) {
                return $spath;
            }
            $found = self::find_activities($section, $spath, $nodeid);
            if ($found !== null) {
                return $found;
            }
            foreach ($section['subsections'] ?? [] as $ui => $subsection) {
                $upath = [...$spath, 'subsections', $ui];
                if (($subsection['id'] ?? null) === $nodeid) {
                    return $upath;
                }
                $found = self::find_activities($subsection, $upath, $nodeid);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Finds an activity or question within one section container.
     *
     * @param array $container Section or subsection.
     * @param array $path Path to the container.
     * @param string $nodeid Requested id.
     * @return array|null Path parts.
     */
    protected static function find_activities(array $container, array $path, string $nodeid): ?array {
        foreach ($container['activities'] ?? [] as $ai => $activity) {
            $apath = [...$path, 'activities', $ai];
            if (($activity['id'] ?? null) === $nodeid) {
                return $apath;
            }
            foreach ($activity['content']['questions'] ?? [] as $qi => $question) {
                if (($question['id'] ?? null) === $nodeid) {
                    return [...$apath, 'content', 'questions', $qi];
                }
            }
        }
        return null;
    }

    /**
     * Gets a node from a known path.
     *
     * @param array $blueprint Complete blueprint.
     * @param array $path Path returned by locate().
     * @return array Node.
     */
    public static function get(array $blueprint, array $path): array {
        $node = $blueprint;
        foreach ($path as $part) {
            $node = $node[$part];
        }
        return $node;
    }

    /**
     * Replaces exactly one subtree, preserving every other value and array order.
     *
     * @param array $blueprint Complete blueprint.
     * @param array $path Target path.
     * @param array $node Replacement node.
     * @return array New blueprint.
     */
    public static function replace(array $blueprint, array $path, array $node): array {
        $target = &$blueprint;
        foreach ($path as $part) {
            $target = &$target[$part];
        }
        $target = $node;
        unset($target);
        return $blueprint;
    }

    /**
     * Lists target and subtree ids that another node refers to through activity dependencies.
     *
     * @param array $blueprint Source blueprint.
     * @param array $path Target path.
     * @return string[] Required ids.
     */
    public static function required_ids(array $blueprint, array $path): array {
        $node = self::get($blueprint, $path);
        $inside = [];
        self::collect_ids($node, $inside);
        $outside = self::replace($blueprint, $path, []);
        $references = [];
        self::collect_references($outside, $references);
        $required = [$node['id']];
        foreach (array_keys($inside) as $id) {
            if (isset($references[$id])) {
                $required[] = $id;
            }
        }
        return array_values(array_unique($required));
    }

    /**
     * Collects ids recursively within a subtree.
     *
     * @param array $value Subtree.
     * @param array $ids Id set to fill.
     */
    protected static function collect_ids(array $value, array &$ids): void {
        if (isset($value['id']) && is_string($value['id'])) {
            $ids[$value['id']] = true;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                self::collect_ids($child, $ids);
            }
        }
    }

    /**
     * Collects the external reference fields that point at activities.
     *
     * @param array $value Value outside the target subtree.
     * @param array $references Id set to fill.
     */
    protected static function collect_references(array $value, array &$references): void {
        foreach ($value as $key => $child) {
            if (
                in_array($key, ['require_completion_of', 'completion_activities', 'activities'], true)
                && is_array($child)
            ) {
                foreach ($child as $id) {
                    if (is_string($id)) {
                        $references[$id] = true;
                    }
                }
            }
            if ($key === 'min_grade' && is_array($child) && isset($child['activity'])) {
                $references[$child['activity']] = true;
            }
            if (is_array($child)) {
                self::collect_references($child, $references);
            }
        }
    }
}
