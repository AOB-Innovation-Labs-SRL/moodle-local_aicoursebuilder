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
 * Tests for per-model pricing and the peak/off-peak window.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\pricing
 * @covers     \local_aicoursebuilder\ai\admin_setting_pricing
 */
final class pricing_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A Monday at 02:00 UTC is inside the first peak window; 05:00 is between the two windows.
     */
    public function test_is_peak_default_windows(): void {
        $monday0200 = strtotime('2026-10-05 02:00:00 UTC');
        $monday0500 = strtotime('2026-10-05 05:00:00 UTC');
        $monday0700 = strtotime('2026-10-05 07:00:00 UTC');
        $saturday0200 = strtotime('2026-10-10 02:00:00 UTC');

        $this->assertTrue(pricing::is_peak(deepseek_connector::NAME, $monday0200));
        $this->assertFalse(pricing::is_peak(deepseek_connector::NAME, $monday0500));
        $this->assertTrue(pricing::is_peak(deepseek_connector::NAME, $monday0700));
        $this->assertFalse(pricing::is_peak(deepseek_connector::NAME, $saturday0200));

        $this->assertFalse(pricing::is_offpeak(deepseek_connector::NAME, $monday0200));
        $this->assertTrue(pricing::is_offpeak(deepseek_connector::NAME, $monday0500));
        $this->assertTrue(pricing::is_offpeak(deepseek_connector::NAME, $saturday0200));
    }

    /**
     * cost_for() uses peak prices inside the window and off-peak prices outside it.
     */
    public function test_cost_for_peak_and_offpeak(): void {
        $peak = strtotime('2026-10-05 02:00:00 UTC');
        $offpeak = strtotime('2026-10-05 05:00:00 UTC');

        $peakcost = pricing::cost_for(deepseek_connector::NAME, 'deepseek-flash', 1_000_000, 1_000_000, 1_000_000, $peak);
        $offpeakcost = pricing::cost_for(deepseek_connector::NAME, 'deepseek-flash', 1_000_000, 1_000_000, 1_000_000, $offpeak);

        $this->assertSame(round(0.30 + 0.006 + 1.20, 6), $peakcost);
        $this->assertSame(round(0.15 + 0.003 + 0.60, 6), $offpeakcost);
        $this->assertGreaterThan($offpeakcost, $peakcost);
    }

    /**
     * An unknown model has no known price and costs 0.
     */
    public function test_cost_for_unknown_model(): void {
        $this->assertSame(0.0, pricing::cost_for(deepseek_connector::NAME, 'unknown-model', 1000, 1000));
        $this->assertNull(pricing::prices_for(deepseek_connector::NAME, 'unknown-model'));
    }

    /**
     * A valid pricing setting overrides the defaults.
     */
    public function test_setting_overrides_defaults(): void {
        set_config('pricing_deepseek', json_encode([
            'deepseek-flash' => ['input_miss' => 1.0, 'input_hit' => 0.1, 'output' => 2.0],
        ]), 'local_aicoursebuilder');

        $this->assertSame(3.01, pricing::cost_for(deepseek_connector::NAME, 'deepseek-flash', 1_000_000, 1_000_000, 100_000));
    }

    /**
     * Invalid JSON in the pricing setting falls back to the defaults.
     */
    public function test_invalid_setting_falls_back_to_defaults(): void {
        set_config('pricing_deepseek', '{not valid json', 'local_aicoursebuilder');
        $this->assertSame(
            pricing::DEFAULT_PRICES[deepseek_connector::NAME],
            pricing::table_for(deepseek_connector::NAME)
        );

        $invalid = ['deepseek-flash' => ['input_miss' => -1, 'input_hit' => 0, 'output' => 0]];
        set_config('pricing_deepseek', json_encode($invalid), 'local_aicoursebuilder');
        $this->assertSame(
            pricing::DEFAULT_PRICES[deepseek_connector::NAME],
            pricing::table_for(deepseek_connector::NAME)
        );
    }

    /**
     * A valid peak-windows setting overrides the default schedule.
     */
    public function test_setting_overrides_peak_windows(): void {
        set_config('peakwindows_deepseek', json_encode(['6' => [[10, 12]]]), 'local_aicoursebuilder');
        $saturday1100 = strtotime('2026-10-10 11:00:00 UTC');
        $this->assertTrue(pricing::is_peak(deepseek_connector::NAME, $saturday1100));

        $monday0200 = strtotime('2026-10-05 02:00:00 UTC');
        $this->assertFalse(pricing::is_peak(deepseek_connector::NAME, $monday0200));
    }

    /**
     * Invalid JSON in the peak-windows setting falls back to the default schedule.
     */
    public function test_invalid_peak_windows_falls_back_to_default(): void {
        set_config('peakwindows_deepseek', '{"8": [[1, 2]]}', 'local_aicoursebuilder');
        $this->assertSame(pricing::DEFAULT_PEAK_WINDOWS, pricing::peak_windows_for(deepseek_connector::NAME));
    }

    /**
     * admin_setting_pricing accepts empty and valid JSON, and refuses invalid JSON with an error.
     */
    public function test_admin_setting_validates_json(): void {
        $setting = new admin_setting_pricing('local_aicoursebuilder/pricing_deepseek', 'Prices', '', '');
        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame('', $setting->write_setting(json_encode(
            ['deepseek-flash' => ['input_miss' => 1, 'input_hit' => 1, 'output' => 1]]
        )));
        $this->assertNotSame('', $setting->write_setting('{not valid'));
    }
}
