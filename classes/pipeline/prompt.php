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

/**
 * Loads a versioned prompt template from prompts/ and fills its placeholders.
 *
 * The version is part of the file name, so prompts/outline.v1.md is version v1 of the outline
 * prompt. The version goes into the step hash, which is why a prompt can never be edited in place
 * once it has run: a new wording is a new file, and the steps that used the old one stay valid.
 *
 * Source material is data, not instruction (spec 3.9, risk R10). Every value is placed between the
 * markers the template draws around it, and any line that could close one of those markers early
 * is neutralised on the way in, so a document cannot break out of its block and be read as part of
 * the prompt.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt {
    /** @var string Version of the prompts this release ships. */
    public const VERSION = 'v1';

    /** @var string[] Marker words a value must never be able to write at the start of a line. */
    protected const MARKERS = ['REQUEST', 'SOURCES', 'BRIEF', 'OUTLINE', 'SECTION', 'ERRORS', 'DOCUMENT'];

    /** @var string Name of the prompt, such as brief, outline, sections or repair. */
    protected string $name;

    /** @var string Version of the prompt, such as v1. */
    protected string $version;

    /** @var string|null Directory holding the prompt files, null for the plugin's prompts/. */
    protected ?string $promptdir;

    /**
     * Creates the prompt.
     *
     * @param string $name Name of the prompt: brief, outline, sections or repair.
     * @param string|null $version Version, null for the version this release ships.
     * @param string|null $promptdir Directory with the prompt files, null for the plugin's prompts/.
     */
    public function __construct(string $name, ?string $version = null, ?string $promptdir = null) {
        $this->name = $name;
        $this->version = $version ?? self::VERSION;
        $this->promptdir = $promptdir;
    }

    /**
     * Returns the version of this prompt, which is part of the step hash.
     *
     * @return string
     */
    public function get_version(): string {
        return $this->version;
    }

    /**
     * Returns the template as it is on disk, placeholders unfilled.
     *
     * @return string
     * @throws \coding_exception When the prompt file does not exist.
     */
    public function get_template(): string {
        $dir = $this->promptdir ?? dirname(__DIR__, 2) . '/prompts';
        $path = "{$dir}/{$this->name}.{$this->version}.md";
        if (!is_readable($path)) {
            throw new \coding_exception("Missing prompt template: {$path}");
        }
        return file_get_contents($path);
    }

    /**
     * Returns the prompt with its placeholders replaced.
     *
     * Placeholders the caller does not supply are left in place rather than emptied, so a template
     * that gains a field fails loudly in a test instead of quietly sending an empty section.
     *
     * @param array $values Placeholder name => value, scalars or arrays, which are JSON encoded.
     * @return string
     * @throws \coding_exception When a placeholder of the template has no value.
     */
    public function render(array $values): string {
        $template = $this->get_template();
        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{{' . $key . '}}'] = $this->fence($this->stringify($value));
        }
        $rendered = strtr($template, $replacements);

        if (preg_match('/\{\{([a-z_]+)\}\}/', $rendered, $matches)) {
            throw new \coding_exception(
                "Prompt {$this->name}.{$this->version} has no value for the placeholder {$matches[1]}",
            );
        }
        return $rendered;
    }

    /**
     * Returns a value as the text that goes into the prompt.
     *
     * @param mixed $value Scalar, or an array which is JSON encoded.
     * @return string
     */
    protected function stringify(mixed $value): string {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Stops a value from closing the block it is placed in.
     *
     * A source document that holds a line reading SOURCES would otherwise end the source block, and
     * everything after it would be read as prompt rather than as data. Such a line keeps its text
     * but is indented by one space, so it can no longer match the marker at the start of a line.
     *
     * @param string $value The value.
     * @return string
     */
    protected function fence(string $value): string {
        $pattern = '/^(<<<)?(' . implode('|', self::MARKERS) . ')\s*$/m';
        return preg_replace($pattern, ' $1$2', $value);
    }
}
