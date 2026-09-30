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
 * Tests for the connector router.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\router
 * @covers     \local_aicoursebuilder\ai\retrying_connector
 * @covers     \local_aicoursebuilder\ai\logging_connector
 */
final class router_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Without settings every step goes to DeepSeek with the default model.
     */
    public function test_defaults(): void {
        $router = new router();
        foreach (request::STEPS as $step) {
            $this->assertSame(
                ['connector' => deepseek_connector::NAME, 'model' => deepseek_connector::DEFAULT_MODEL],
                $router->get_route($step),
                $step
            );
            $this->assertInstanceOf(deepseek_connector::class, $router->raw_connector($step));
            $this->assertInstanceOf(retrying_connector::class, $router->for_step($step));
        }
    }

    /**
     * A step route overrides the default; the other steps fall back to the default connector and model.
     */
    public function test_route_per_step_and_fallback(): void {
        set_config('defaultconnector', coreai_connector::NAME, 'local_aicoursebuilder');
        set_config('deepseek_model', 'deepseek-flash', 'local_aicoursebuilder');
        set_config('route_review_connector', deepseek_connector::NAME, 'local_aicoursebuilder');
        set_config('route_review_model', 'deepseek-v4-pro', 'local_aicoursebuilder');
        set_config('route_questions_connector', deepseek_connector::NAME, 'local_aicoursebuilder');

        $router = new router();
        $this->assertSame(['connector' => 'deepseek', 'model' => 'deepseek-v4-pro'], $router->get_route('review'));
        $this->assertSame(['connector' => 'deepseek', 'model' => 'deepseek-flash'], $router->get_route('questions'));
        $this->assertSame(['connector' => 'coreai', 'model' => ''], $router->get_route('digest'));

        $review = $router->raw_connector(request::STEP_REVIEW);
        $this->assertInstanceOf(deepseek_connector::class, $review);
        $this->assertSame('deepseek-v4-pro', $review->get_model());
        $this->assertInstanceOf(coreai_connector::class, $router->raw_connector(request::STEP_DIGEST));
    }

    /**
     * Switching a step with set_config changes its connector without code changes.
     */
    public function test_switch_with_set_config(): void {
        $router = new router();
        $this->assertInstanceOf(deepseek_connector::class, $router->raw_connector(request::STEP_DIGEST));

        set_config('route_digest_connector', coreai_connector::NAME, 'local_aicoursebuilder');
        $this->assertInstanceOf(coreai_connector::class, $router->raw_connector(request::STEP_DIGEST));

        set_config('route_digest_connector', '', 'local_aicoursebuilder');
        $this->assertInstanceOf(deepseek_connector::class, $router->raw_connector(request::STEP_DIGEST));
    }

    /**
     * The fake connector is accepted in PHPUnit runs, but never offered to administrators; for_step()
     * returns it bare, without retry or logging, since tests must not depend on their side effects.
     */
    public function test_fake_connector_in_tests(): void {
        set_config('route_outline_connector', fake_connector::NAME, 'local_aicoursebuilder');
        $router = new router();
        $this->assertSame(['connector' => 'fake', 'model' => fake_connector::MODEL], $router->get_route('outline'));
        $this->assertInstanceOf(fake_connector::class, $router->raw_connector(request::STEP_OUTLINE));
        $this->assertInstanceOf(fake_connector::class, $router->for_step(request::STEP_OUTLINE));
        $this->assertNotContains(fake_connector::NAME, router::CONNECTORS);
    }

    /**
     * for_step() applies the configured retry settings to the decorator.
     */
    public function test_for_step_applies_retry_settings(): void {
        set_config('retry_maxattempts', 5, 'local_aicoursebuilder');
        set_config('retry_basedelayms', 10, 'local_aicoursebuilder');
        $connector = (new router())->for_step(request::STEP_DIGEST);
        $this->assertInstanceOf(retrying_connector::class, $connector);

        $property = new \ReflectionProperty($connector, 'maxattempts');
        $this->assertSame(5, $property->getValue($connector));
        $property = new \ReflectionProperty($connector, 'basedelayms');
        $this->assertSame(10, $property->getValue($connector));
    }

    /**
     * An unknown connector name in the settings is refused.
     */
    public function test_unknown_connector(): void {
        set_config('defaultconnector', 'anthropic', 'local_aicoursebuilder');
        try {
            (new router())->for_step(request::STEP_BRIEF);
            $this->fail('Unknown connector accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::UNKNOWN, $e->errorcode);
            $this->assertStringContainsString('anthropic', $e->getMessage());
        }
    }

    /**
     * An unknown step is a coding error.
     */
    public function test_unknown_step(): void {
        $this->expectException(\coding_exception::class);
        (new router())->get_route('translate');
    }
}
