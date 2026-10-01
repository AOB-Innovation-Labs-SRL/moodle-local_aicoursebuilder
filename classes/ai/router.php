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
 * Chooses the connector and the model of each pipeline step from the plugin settings.
 *
 * Settings: defaultconnector, route_{step}_connector (empty for the default) and
 * route_{step}_model (empty for the connector default). The fake connector is accepted
 * only in PHPUnit and Behat runs and never offered in the settings.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class router {
    /** @var string Connector used when nothing is configured. */
    public const DEFAULT_CONNECTOR = deepseek_connector::NAME;

    /** @var string[] Connectors an administrator can choose. */
    public const CONNECTORS = [
        deepseek_connector::NAME,
        coreai_connector::NAME,
        anthropic_connector::NAME,
        gemini_connector::NAME,
        openaicompat_connector::NAME,
    ];

    /** @var connector|null Connector every fake route answers with, set by tests only. */
    protected static ?connector $testconnector = null;

    /**
     * Returns the connector of a step, configured with the model of its route.
     *
     * Real connectors are wrapped for retry (respecting Retry-After, exponential backoff with
     * jitter otherwise) and for logging every attempt to local_aicb_ailog; the fake connector used
     * in tests is returned bare. Retry sits outside logging, so every attempt is logged, retried
     * ones included (spec 3.3, 3.8).
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @return connector
     * @throws connector_exception When the settings name an unknown connector.
     */
    public function for_step(string $step): connector {
        $name = $this->get_route($step)['connector'];
        $inner = $this->raw_connector($step);
        if ($name === fake_connector::NAME) {
            return $inner;
        }
        $maxattempts = (int) get_config('local_aicoursebuilder', 'retry_maxattempts');
        $basedelayms = (int) get_config('local_aicoursebuilder', 'retry_basedelayms');
        return new retrying_connector(
            new logging_connector($inner, $name),
            maxattempts: $maxattempts > 0 ? $maxattempts : retrying_connector::DEFAULT_MAXATTEMPTS,
            basedelayms: $basedelayms > 0 ? $basedelayms : retrying_connector::DEFAULT_BASEDELAYMS,
        );
    }

    /**
     * Returns the undecorated connector of a step, configured with the model of its route.
     *
     * Used by parallel_executor, which needs to see the connector's own async_connector capability
     * and applies its own per-round retry and logging around the async calls.
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @return connector
     * @throws connector_exception When the settings name an unknown connector.
     */
    public function raw_connector(string $step): connector {
        ['connector' => $name, 'model' => $model] = $this->get_route($step);
        if ($name === fake_connector::NAME && self::$testconnector !== null) {
            return self::$testconnector;
        }
        return match ($name) {
            deepseek_connector::NAME => new deepseek_connector($model),
            coreai_connector::NAME => new coreai_connector(),
            anthropic_connector::NAME => new anthropic_connector($model),
            gemini_connector::NAME => new gemini_connector($model),
            openaicompat_connector::NAME => new openaicompat_connector($model),
            fake_connector::NAME => new fake_connector(),
        };
    }

    /**
     * Makes every fake route answer with one given connector, so a test can programme it.
     *
     * The router builds a connector per call, which is right in production and useless in a test
     * that has to queue answers or count calls. Only ever honoured under PHPUnit and Behat, and
     * only for the fake route, so no real route can be redirected this way.
     *
     * @param connector|null $connector The connector every fake route returns, null to stop.
     * @throws \coding_exception When called outside a test run.
     */
    public static function set_test_connector(?connector $connector): void {
        if (!self::is_test_run()) {
            throw new \coding_exception('The test connector can only be set in a test run');
        }
        self::$testconnector = $connector;
    }

    /**
     * Returns the route of a step: connector name and effective model.
     *
     * The model is empty for the core_ai connector, whose model is set in the core AI provider.
     *
     * @param string $step Pipeline step, one of the request::STEP_* constants.
     * @return array ['connector' => string, 'model' => string]
     * @throws connector_exception When the settings name an unknown connector.
     */
    public function get_route(string $step): array {
        if (!in_array($step, request::STEPS, true)) {
            throw new \coding_exception("Unknown AI step: {$step}");
        }
        $config = get_config('local_aicoursebuilder');
        $name = trim((string) ($config->{"route_{$step}_connector"} ?? ''))
            ?: trim((string) ($config->defaultconnector ?? ''))
            ?: self::DEFAULT_CONNECTOR;
        if (!in_array($name, self::CONNECTORS, true) && !($name === fake_connector::NAME && self::is_test_run())) {
            throw new connector_exception(connector_exception::UNKNOWN, $name);
        }
        $model = trim((string) ($config->{"route_{$step}_model"} ?? ''));

        return [
            'connector' => $name,
            'model' => match ($name) {
                deepseek_connector::NAME => (new deepseek_connector($model))->get_model(),
                anthropic_connector::NAME => (new anthropic_connector($model))->get_model(),
                gemini_connector::NAME => (new gemini_connector($model))->get_model(),
                openaicompat_connector::NAME => (new openaicompat_connector($model))->get_model(),
                fake_connector::NAME => fake_connector::MODEL,
                default => '',
            },
        ];
    }

    /**
     * Tells whether the code runs under PHPUnit or Behat, where the fake connector is allowed.
     *
     * @return bool
     */
    protected static function is_test_run(): bool {
        return (defined('PHPUNIT_TEST') && PHPUNIT_TEST) || defined('BEHAT_SITE_RUNNING');
    }
}
