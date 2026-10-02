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
 * Tests for the output token limit of each step.
 *
 * @package    local_aicoursebuilder
 * @category   test
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\output_limits
 */
final class output_limits_test extends \advanced_testcase {
    /**
     * Every step has a default above DeepSeek's implicit 8K for the long ones, and within the maximum.
     */
    public function test_every_step_has_a_default_within_the_provider_maximum(): void {
        $this->resetAfterTest();
        foreach (request::STEPS as $step) {
            $limit = output_limits::for_step($step);
            $this->assertSame(output_limits::DEFAULTS[$step], $limit, "{$step} has a default of its own");
            $this->assertGreaterThan(0, $limit);
            $this->assertLessThanOrEqual(output_limits::MAX, $limit);
        }
        foreach ([request::STEP_SECTIONS, request::STEP_QUESTIONS, request::STEP_REPAIR] as $step) {
            $this->assertGreaterThan(8192, output_limits::for_step($step), "{$step} answers can be longer than 8K");
        }
        $this->assertSame(393216, output_limits::MAX, 'deepseek-flash documents 384K of output at most');
        $this->assertSame(output_limits::FALLBACK, output_limits::for_step('unknown'));
    }

    /**
     * Each provider's "stopped at the limit" reason makes an undecodable answer a truncation; any
     * other reason keeps it an invalid answer.
     *
     * @covers \local_aicoursebuilder\ai\connector_exception::for_undecodable_json
     */
    public function test_each_provider_truncation_reason_is_recognised(): void {
        foreach (['length' => 'DeepSeek, OpenAI', 'MAX_TOKENS' => 'Gemini', 'max_tokens' => 'Anthropic'] as $reason => $who) {
            $e = connector_exception::for_undecodable_json($reason, 16384, "reason={$reason}");
            $this->assertSame(connector_exception::TRUNCATED, $e->errorcode, $who);
            $this->assertSame(16384, $e->a);
        }
        foreach (['stop', 'STOP', 'end_turn', 'content_filter', ''] as $reason) {
            $e = connector_exception::for_undecodable_json($reason, 16384, "reason={$reason}");
            $this->assertSame(connector_exception::INVALID_JSON, $e->errorcode, "reason '{$reason}'");
        }
    }

    /**
     * A setting replaces the default; zero or empty restores it; nothing goes above the maximum.
     */
    public function test_the_setting_is_used_and_bounded(): void {
        $this->resetAfterTest();

        set_config('maxtokens_questions', '50000', 'local_aicoursebuilder');
        $this->assertSame(50000, output_limits::for_step(request::STEP_QUESTIONS));

        set_config('maxtokens_questions', '0', 'local_aicoursebuilder');
        $this->assertSame(output_limits::DEFAULTS[request::STEP_QUESTIONS], output_limits::for_step(request::STEP_QUESTIONS));

        set_config('maxtokens_questions', '', 'local_aicoursebuilder');
        $this->assertSame(output_limits::DEFAULTS[request::STEP_QUESTIONS], output_limits::for_step(request::STEP_QUESTIONS));

        set_config('maxtokens_questions', '9999999', 'local_aicoursebuilder');
        $this->assertSame(output_limits::MAX, output_limits::for_step(request::STEP_QUESTIONS));
    }
}
