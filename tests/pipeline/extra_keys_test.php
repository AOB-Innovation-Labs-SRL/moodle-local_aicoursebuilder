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
 * Tests of the deterministic removal of keys a schema does not allow.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\pipeline\extra_keys
 */
final class extra_keys_test extends \basic_testcase {
    /**
     * Builds an additional-properties error.
     *
     * @param string $path Path of the object that holds the keys.
     * @param string[] $keys The rejected keys.
     * @return validation_error
     */
    private function extra(string $path, array $keys): validation_error {
        return new validation_error(
            $path,
            validation_error::CODE_SCHEMA,
            'Additional object properties are not allowed',
            validation_error::KEYWORD_ADDITIONAL_PROPERTIES,
            $keys,
        );
    }

    /**
     * Only a list made entirely of rejected keys is settled by deleting them.
     */
    public function test_only_extra_keys_count(): void {
        $this->assertTrue(extra_keys::are_all_extra([$this->extra('/a', ['x']), $this->extra('/b', ['y'])]));
        $this->assertFalse(extra_keys::are_all_extra([]), 'nothing wrong, nothing to remove');
        $this->assertFalse(extra_keys::are_all_extra([
            $this->extra('/a', ['x']),
            new validation_error('/b', validation_error::CODE_SCHEMA, 'The required properties are missing', 'required'),
        ]));
        $this->assertFalse(extra_keys::are_all_extra([
            new validation_error('/a', validation_error::CODE_DUPLICATE_ID, 'Duplicate id'),
        ]));
        $this->assertFalse(extra_keys::are_all_extra([$this->extra('/a', [])]), 'an error that names no key removes nothing');
    }

    /**
     * Keys are deleted at the path the error gives, and nothing else is touched.
     */
    public function test_strip_deletes_the_named_keys_only(): void {
        $output = [
            'id' => 's1',
            'junk' => 1,
            'activities' => [
                ['id' => 's1.page1', 'content' => ['text' => 'a', 'extra' => 1, 'extra2' => 2]],
                ['id' => 's1.page2', 'content' => ['text' => 'b', 'extra' => 3]],
            ],
        ];

        [$clean, $removed] = extra_keys::strip($output, [
            $this->extra('', ['junk']),
            $this->extra('/activities/0/content', ['extra', 'extra2']),
        ]);

        $this->assertSame(3, $removed);
        $this->assertSame(['id' => 's1', 'activities' => [
            ['id' => 's1.page1', 'content' => ['text' => 'a']],
            ['id' => 's1.page2', 'content' => ['text' => 'b', 'extra' => 3]],
        ]], $clean);
    }

    /**
     * A path that leads nowhere, or a key that is not there, deletes nothing.
     */
    public function test_strip_ignores_what_is_not_there(): void {
        $output = ['a' => ['b' => 'text']];

        [$clean, $removed] = extra_keys::strip($output, [
            $this->extra('/a/zzz', ['x']),
            $this->extra('/a/b', ['x']),
            $this->extra('/a', ['missing']),
        ]);

        $this->assertSame(0, $removed);
        $this->assertSame($output, $clean);
    }

    /**
     * Escaped characters of a JSON Pointer are read back.
     */
    public function test_strip_reads_escaped_pointers(): void {
        [$clean, $removed] = extra_keys::strip(['a/b' => ['c~d' => ['x' => 1, 'y' => 2]]], [
            $this->extra('/a~1b/c~0d', ['x']),
        ]);

        $this->assertSame(1, $removed);
        $this->assertSame(['a/b' => ['c~d' => ['y' => 2]]], $clean);
    }
}
