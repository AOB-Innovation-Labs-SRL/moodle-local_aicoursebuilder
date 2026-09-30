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
 * Per-model USD pricing, with an optional off-peak window (spec 3.3, 8).
 *
 * Prices are read from a per-connector JSON setting (pricing_{connector}), a map of model name to
 * ['input_miss' => float, 'input_hit' => float, 'output' => float, 'offpeak' => [same 3 keys]],
 * prices per 1M tokens. The setting is validated at save time by admin_setting_configpricingjson;
 * an invalid or missing setting falls back to DEFAULT_PRICES. The peak window (spec 8: 01:00-04:00
 * and 06:00-10:00 UTC, Monday to Friday) is also a setting, so it can be adjusted without code
 * changes; outside that window, or on Saturday/Sunday, pricing is off-peak.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pricing {
    /** @var int Prices are expressed per this many tokens. */
    public const PER_TOKENS = 1_000_000;

    /** @var array Default prices per connector and model, USD per 1M tokens (spec 8, checked 2026-09-29). */
    public const DEFAULT_PRICES = [
        deepseek_connector::NAME => [
            'deepseek-flash' => [
                'input_miss' => 0.30,
                'input_hit' => 0.006,
                'output' => 1.20,
                'offpeak' => ['input_miss' => 0.15, 'input_hit' => 0.003, 'output' => 0.60],
            ],
            'deepseek-v4-pro' => [
                'input_miss' => 1.32,
                'input_hit' => 0.044,
                'output' => 3.96,
                'offpeak' => ['input_miss' => 0.66, 'input_hit' => 0.022, 'output' => 1.98],
            ],
        ],
    ];

    /** @var array Default peak windows per weekday (1 = Monday .. 7 = Sunday), UTC hour ranges [start, end). */
    public const DEFAULT_PEAK_WINDOWS = [
        1 => [[1, 4], [6, 10]],
        2 => [[1, 4], [6, 10]],
        3 => [[1, 4], [6, 10]],
        4 => [[1, 4], [6, 10]],
        5 => [[1, 4], [6, 10]],
    ];

    /**
     * Computes the cost of a call in USD.
     *
     * @param string $connector Connector name.
     * @param string $model Model name.
     * @param int $tokensin Input tokens not served from the prompt cache.
     * @param int $tokensout Output tokens.
     * @param int $tokenscached Input tokens served from the prompt cache.
     * @param int|null $timestamp Unix timestamp of the call, null for now.
     * @return float Cost in USD.
     */
    public static function cost_for(
        string $connector,
        string $model,
        int $tokensin,
        int $tokensout,
        int $tokenscached = 0,
        ?int $timestamp = null
    ): float {
        $prices = self::prices_for($connector, $model);
        if ($prices === null) {
            return 0.0;
        }
        if (self::is_offpeak($connector, $timestamp) && isset($prices['offpeak'])) {
            $prices = $prices['offpeak'];
        }
        $cost = ($tokensin / self::PER_TOKENS) * $prices['input_miss']
            + ($tokenscached / self::PER_TOKENS) * $prices['input_hit']
            + ($tokensout / self::PER_TOKENS) * $prices['output'];
        return round($cost, 6);
    }

    /**
     * Returns the prices of a model, from the setting or the defaults.
     *
     * @param string $connector Connector name.
     * @param string $model Model name.
     * @return array|null ['input_miss' => float, 'input_hit' => float, 'output' => float, 'offpeak' => array], or
     *                     null when the model has no known price.
     */
    public static function prices_for(string $connector, string $model): ?array {
        $table = self::table_for($connector);
        return $table[$model] ?? null;
    }

    /**
     * Returns the whole pricing table of a connector, from its setting or the defaults.
     *
     * @param string $connector Connector name.
     * @return array Map of model name to prices.
     */
    public static function table_for(string $connector): array {
        $default = self::DEFAULT_PRICES[$connector] ?? [];
        $raw = (string) get_config('local_aicoursebuilder', 'pricing_' . $connector);
        if (trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return self::is_valid_table($decoded) ? $decoded : $default;
    }

    /**
     * Tells whether a decoded pricing table has the expected shape.
     *
     * @param mixed $table The decoded JSON.
     * @return bool
     */
    public static function is_valid_table(mixed $table): bool {
        if (!is_array($table) || $table === []) {
            return false;
        }
        foreach ($table as $prices) {
            if (!is_array($prices) || !self::is_valid_prices($prices)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tells whether a decoded prices entry has the expected keys and non-negative numeric values.
     *
     * @param array $prices The decoded prices entry.
     * @return bool
     */
    protected static function is_valid_prices(array $prices): bool {
        foreach (['input_miss', 'input_hit', 'output'] as $key) {
            if (!isset($prices[$key]) || !is_numeric($prices[$key]) || $prices[$key] < 0) {
                return false;
            }
        }
        if (isset($prices['offpeak'])) {
            if (!is_array($prices['offpeak']) || !self::is_valid_prices_offpeak($prices['offpeak'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tells whether a decoded off-peak prices entry has the expected keys and non-negative values.
     *
     * @param array $prices The decoded off-peak prices entry.
     * @return bool
     */
    protected static function is_valid_prices_offpeak(array $prices): bool {
        foreach (['input_miss', 'input_hit', 'output'] as $key) {
            if (!isset($prices[$key]) || !is_numeric($prices[$key]) || $prices[$key] < 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tells whether a moment falls outside the peak window of a connector (spec 8).
     *
     * @param string $connector Connector name.
     * @param int|null $timestamp Unix timestamp, null for now.
     * @return bool True when the moment is off-peak.
     */
    public static function is_offpeak(string $connector, ?int $timestamp = null): bool {
        return !self::is_peak($connector, $timestamp);
    }

    /**
     * Tells whether a moment falls inside the peak window of a connector (spec 8).
     *
     * @param string $connector Connector name.
     * @param int|null $timestamp Unix timestamp, null for now.
     * @return bool
     */
    public static function is_peak(string $connector, ?int $timestamp = null): bool {
        $windows = self::peak_windows_for($connector);
        $timestamp ??= time();
        $weekday = (int) gmdate('N', $timestamp);
        $hour = (int) gmdate('G', $timestamp);
        foreach ($windows[$weekday] ?? [] as [$start, $end]) {
            if ($hour >= $start && $hour < $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the peak windows of a connector, from the setting or the default.
     *
     * @param string $connector Connector name.
     * @return array Map of ISO weekday (1-7) to a list of [starthour, endhour) UTC ranges.
     */
    public static function peak_windows_for(string $connector): array {
        $raw = (string) get_config('local_aicoursebuilder', 'peakwindows_' . $connector);
        if (trim($raw) === '') {
            return self::DEFAULT_PEAK_WINDOWS;
        }
        $decoded = json_decode($raw, true);
        return self::is_valid_peak_windows($decoded) ? self::normalise_peak_windows($decoded) : self::DEFAULT_PEAK_WINDOWS;
    }

    /**
     * Tells whether a decoded peak-windows setting has the expected shape.
     *
     * @param mixed $windows The decoded JSON.
     * @return bool
     */
    protected static function is_valid_peak_windows(mixed $windows): bool {
        if (!is_array($windows)) {
            return false;
        }
        foreach ($windows as $weekday => $ranges) {
            if (!is_numeric($weekday) || (int) $weekday < 1 || (int) $weekday > 7 || !is_array($ranges)) {
                return false;
            }
            foreach ($ranges as $range) {
                if (
                    !is_array($range) || count($range) !== 2
                    || !is_numeric($range[0] ?? null) || !is_numeric($range[1] ?? null)
                    || $range[0] < 0 || $range[0] > 24 || $range[1] < 0 || $range[1] > 24
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Normalises a decoded peak-windows setting to integer weekdays and hour bounds.
     *
     * @param array $windows The decoded, validated JSON.
     * @return array
     */
    protected static function normalise_peak_windows(array $windows): array {
        $normalised = [];
        foreach ($windows as $weekday => $ranges) {
            $normalised[(int) $weekday] = array_map(
                fn(array $range): array => [(int) $range[0], (int) $range[1]],
                $ranges
            );
        }
        return $normalised;
    }
}
