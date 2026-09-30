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

namespace local_aicoursebuilder\ai;

/**
 * Deterministic connector without network access, for tests, Behat and development.
 *
 * Answers with the fixture file tests/fixtures/ai/{step}.json for the step of the request.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_connector implements connector {
    use token_estimator;

    /** @var string Connector name. */
    public const NAME = 'fake';

    /** @var string Model name reported in results. */
    public const MODEL = 'fake';

    /** @var string Directory holding the fixture files. */
    protected string $fixturedir;

    /**
     * Creates the connector.
     *
     * @param string|null $fixturedir Directory with {step}.json files, null for tests/fixtures/ai.
     */
    public function __construct(?string $fixturedir = null) {
        $this->fixturedir = $fixturedir ?? dirname(__DIR__, 2) . '/tests/fixtures/ai';
    }

    /**
     * Returns the fixture for the request step.
     *
     * @param request $request The completion request.
     * @return result
     */
    public function complete(request $request): result {
        $path = $this->fixturedir . '/' . $request->step . '.json';
        if (!is_readable($path)) {
            throw new \coding_exception("No AI fixture for step '{$request->step}': {$path}");
        }
        $content = trim(file_get_contents($path));
        $json = json_decode($content, true);

        return new result(
            content: $content,
            json: is_array($json) ? $json : null,
            tokensin: $this->count_tokens($request->get_input_text()),
            tokensout: $this->count_tokens($content),
            tokenscached: 0,
            cost: 0.0,
            model: self::MODEL,
            durationms: 0,
            finishreason: 'stop',
            connector: self::NAME,
        );
    }

    /**
     * Only structured JSON output is supported.
     *
     * @param string $capability One of the connector::CAP_* constants.
     * @return bool
     */
    public function supports(string $capability): bool {
        return $capability === connector::CAP_JSON_SCHEMA;
    }

    /**
     * The fake connector is free.
     *
     * @param request $request The completion request.
     * @return float Always 0.0.
     */
    public function estimate_cost(request $request): float {
        return 0.0;
    }
}
