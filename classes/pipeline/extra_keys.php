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

use local_aicoursebuilder\blueprint\validation_error;

/**
 * Removes from an answer the keys its schema does not allow, without asking the model again.
 *
 * A model that adds one key too many to an otherwise good answer (`additionalProperties`) loses
 * nothing by having that key dropped: the key is not in the schema, so nothing downstream reads it.
 * Spending a repair call on it, and risking the repair rewriting something that was right, is the
 * worse trade. This only ever deletes keys the validator named, and only when those are all that
 * is wrong with the answer.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extra_keys {
    /**
     * Tells whether every error is a rejected extra key, so that deleting them settles the answer.
     *
     * @param validation_error[] $errors What the validator found.
     * @return bool False for no errors at all, and for any error of another kind.
     */
    public static function are_all_extra(array $errors): bool {
        if ($errors === []) {
            return false;
        }
        foreach ($errors as $error) {
            if ($error->keyword !== validation_error::KEYWORD_ADDITIONAL_PROPERTIES || $error->keys === []) {
                return false;
            }
        }
        return true;
    }

    /**
     * Deletes the rejected keys from an answer.
     *
     * @param array $output The decoded answer.
     * @param validation_error[] $errors Additional-properties errors, each with the path of the
     *                                   object that holds the keys and the keys themselves.
     * @return array [the answer without those keys, how many keys were deleted]
     */
    public static function strip(array $output, array $errors): array {
        $removed = 0;
        foreach ($errors as $error) {
            $target = &self::at($output, $error->path);
            if ($target === null) {
                unset($target);
                continue;
            }
            foreach ($error->keys as $key) {
                if (array_key_exists($key, $target)) {
                    unset($target[$key]);
                    $removed++;
                }
            }
            unset($target);
        }
        return [$output, $removed];
    }

    /**
     * Returns a reference to the object a JSON Pointer names, or to null when it is not there.
     *
     * @param array $document The decoded answer.
     * @param string $path JSON Pointer, empty for the document itself.
     * @return array|null Reference to the object, null when the pointer does not lead to an array.
     */
    protected static function &at(array &$document, string $path): ?array {
        $none = null;
        $node = &$document;
        if ($path === '') {
            return $node;
        }
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $none;
            }
            $node = &$node[$segment];
        }
        if (!is_array($node)) {
            return $none;
        }
        return $node;
    }
}
