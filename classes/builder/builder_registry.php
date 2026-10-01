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
 * The builders available to one build, by node type.
 *
 * The registry is passed to \local_aicoursebuilder\task\build_course, never read from global state, so a
 * test registers its own builders and a build is reproducible. A type without a builder is not an error:
 * the node is marked manual with a warning and the build goes on (spec 3.7), which is also how competencies
 * and badges behave until their builders land in phase 5.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class builder_registry {
    /** @var string Node type of the course itself. */
    public const TYPE_COURSE = 'course';

    /** @var string Node type of a top level section. */
    public const TYPE_SECTION = 'section';

    /** @var string Node type of a subsection. */
    public const TYPE_SUBSECTION = 'subsection';

    /** @var string[] Activity types of schema/blueprint.v1.json, in schema order. */
    public const ACTIVITY_TYPES = [
        'page', 'book', 'label', 'lesson', 'quiz', 'assign', 'glossary', 'forum', 'wiki',
        'choice', 'feedback', 'url', 'resource', 'folder', 'h5pactivity', 'scorm',
    ];

    /** @var builder_interface[] Node type => builder. */
    protected array $builders = [];

    /**
     * Creates the registry.
     *
     * @param builder_interface[] $builders Node type => builder, registered in order.
     */
    public function __construct(array $builders = []) {
        foreach ($builders as $type => $builder) {
            $this->register((string) $type, $builder);
        }
    }

    /**
     * Registers the builder of a node type, replacing any builder registered for it before.
     *
     * @param string $type Node type: course, section, subsection or one of ACTIVITY_TYPES.
     * @param builder_interface $builder The builder.
     * @return self This registry, so that registrations can be chained.
     * @throws \coding_exception When the type is not a known node type.
     */
    public function register(string $type, builder_interface $builder): self {
        if (!self::is_known_type($type)) {
            throw new \coding_exception("Unknown blueprint node type: {$type}");
        }
        $this->builders[$type] = $builder;
        return $this;
    }

    /**
     * Returns the builder of a node type.
     *
     * @param string $type Node type.
     * @return builder_interface|null Null when no builder is registered for the type.
     */
    public function get(string $type): ?builder_interface {
        return $this->builders[$type] ?? null;
    }

    /**
     * Tells whether a node type has a builder.
     *
     * @param string $type Node type.
     * @return bool
     */
    public function has(string $type): bool {
        return isset($this->builders[$type]);
    }

    /**
     * Tells whether a type is a node type of the blueprint schema.
     *
     * @param string $type Node type.
     * @return bool
     */
    public static function is_known_type(string $type): bool {
        return in_array($type, self::all_types(), true);
    }

    /**
     * Returns every node type a builder can be registered for.
     *
     * @return string[]
     */
    public static function all_types(): array {
        return [self::TYPE_COURSE, self::TYPE_SECTION, self::TYPE_SUBSECTION, ...self::ACTIVITY_TYPES];
    }
}
