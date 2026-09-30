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
 * Gets a JSON object out of a model's answer without asking the model again (spec 3.6, stage 2).
 *
 * This is the cheap half of the JSON robustness ladder: pull the JSON out of whatever wrapping the
 * model put around it, then fix the mistakes that recur and can be fixed without guessing at
 * meaning. Anything beyond that is left to a repair call, because a wrong guess here would be
 * silently wrong content rather than an honest failure.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_repair {
    /**
     * Returns the decoded JSON object of a model answer, or null when nothing usable is in it.
     *
     * @param string $content Raw text returned by the model.
     * @return array|null Decoded object, or null when the content holds no repairable JSON.
     */
    public static function decode(string $content): ?array {
        $candidate = self::extract($content);
        if ($candidate === null) {
            return null;
        }
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $decoded = json_decode(self::repair($candidate), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Returns the JSON part of a model answer: the fenced block, or the outermost braces.
     *
     * @param string $content Raw text returned by the model.
     * @return string|null The JSON candidate, or null when the content has no object in it.
     */
    public static function extract(string $content): ?string {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // A fenced block wins: models often explain themselves around it. The fence is three
        // backticks, written as an escape so this file holds none of its own.
        $fence = str_repeat("\x60", 3);
        if (preg_match('/' . $fence . '(?:json|JSON)?\s*\R?(.*?)(?:' . $fence . '|\z)/s', $content, $matches)) {
            $fenced = trim($matches[1]);
            if ($fenced !== '' && str_starts_with($fenced, '{')) {
                return self::outermost_object($fenced) ?? $fenced;
            }
        }
        return self::outermost_object($content);
    }

    /**
     * Fixes the JSON mistakes that can be fixed without guessing what was meant.
     *
     * Runs on a candidate that failed to decode: trailing commas, Python literals, single-quoted
     * keys and strings, comments, and brackets the model never closed because it ran out of output.
     *
     * @param string $json The JSON candidate.
     * @return string The repaired candidate, which may still not decode.
     */
    public static function repair(string $json): string {
        $json = self::strip_comments($json);
        $json = self::quote_normalise($json);
        $json = self::fix_literals($json);
        $json = self::drop_trailing_commas($json);
        return self::close_brackets($json);
    }

    /**
     * Returns the outermost {...} of a text, balanced, ignoring braces inside strings.
     *
     * When the closing brace is missing, which is what a truncated answer looks like, everything
     * from the first brace on is returned and close_brackets() finishes the job.
     *
     * @param string $content The text.
     * @return string|null The object, or null when the text has no opening brace.
     */
    protected static function outermost_object(string $content): ?string {
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $instring = false;
        $escaped = false;
        $length = strlen($content);
        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && $instring) {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $instring = !$instring;
                continue;
            }
            if ($instring) {
                continue;
            }
            if ($char === '{') {
                $depth++;
            } else if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }
        return substr($content, $start);
    }

    /**
     * Removes // and block comments, which JSON does not allow but models sometimes add.
     *
     * @param string $json The JSON candidate.
     * @return string
     */
    protected static function strip_comments(string $json): string {
        return self::outside_strings($json, function (string $chunk): string {
            $chunk = preg_replace('#/\*.*?\*/#s', '', $chunk);
            return preg_replace('#//[^\n\r]*#', '', $chunk);
        });
    }

    /**
     * Turns single-quoted keys and values into double-quoted ones.
     *
     * Only runs where no double-quoted string is open, so an apostrophe inside a Romanian sentence
     * is never touched.
     *
     * @param string $json The JSON candidate.
     * @return string
     */
    protected static function quote_normalise(string $json): string {
        return self::outside_strings($json, function (string $chunk): string {
            return preg_replace_callback(
                "/'((?:[^'\\\\]|\\\\.)*)'/",
                fn(array $m) => '"' . str_replace(['\\\'', '"'], ["'", '\\"'], $m[1]) . '"',
                $chunk,
            );
        });
    }

    /**
     * Replaces the Python and JavaScript literals models slip in with their JSON spellings.
     *
     * @param string $json The JSON candidate.
     * @return string
     */
    protected static function fix_literals(string $json): string {
        return self::outside_strings($json, function (string $chunk): string {
            return preg_replace(
                ['/\bTrue\b/', '/\bFalse\b/', '/\bNone\b/', '/\bNULL\b/', '/\bundefined\b/', '/\bNaN\b/'],
                ['true', 'false', 'null', 'null', 'null', '0'],
                $chunk,
            );
        });
    }

    /**
     * Removes the comma before a closing bracket, the most common JSON mistake of all.
     *
     * @param string $json The JSON candidate.
     * @return string
     */
    protected static function drop_trailing_commas(string $json): string {
        return self::outside_strings($json, fn(string $chunk) => preg_replace('/,(\s*[}\]])/', '$1', $chunk));
    }

    /**
     * Closes the brackets a truncated answer left open, in the right order.
     *
     * An unterminated string is closed first, then any dangling comma or key is dropped, so the
     * half-written last element does not turn into a broken one.
     *
     * @param string $json The JSON candidate.
     * @return string
     */
    protected static function close_brackets(string $json): string {
        $stack = [];
        $instring = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && $instring) {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $instring = !$instring;
                continue;
            }
            if ($instring) {
                continue;
            }
            if ($char === '{' || $char === '[') {
                $stack[] = $char;
            } else if (($char === '}' || $char === ']') && $stack !== []) {
                array_pop($stack);
            }
        }
        if (!$instring && $stack === []) {
            return $json;
        }
        if ($instring) {
            $json .= '"';
        }

        // Drop whatever was half-written after the last complete value.
        $json = preg_replace('/,\s*$/', '', rtrim($json));
        $json = preg_replace('/,?\s*"[^"]*"\s*:\s*$/', '', $json);
        $json = preg_replace('/,\s*$/', '', rtrim($json));

        foreach (array_reverse($stack) as $open) {
            $json .= $open === '{' ? '}' : ']';
        }
        return $json;
    }

    /**
     * Applies a callback to the parts of a JSON candidate that are outside double-quoted strings.
     *
     * Every repair has to leave string contents alone: a course about JSON could quote a trailing
     * comma, and Romanian prose is full of apostrophes.
     *
     * @param string $json The JSON candidate.
     * @param callable $callback function(string): string, applied to each chunk outside a string.
     * @return string
     */
    protected static function outside_strings(string $json, callable $callback): string {
        $out = '';
        $chunk = '';
        $instring = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($instring) {
                $out .= $char;
                if ($escaped) {
                    $escaped = false;
                } else if ($char === '\\') {
                    $escaped = true;
                } else if ($char === '"') {
                    $instring = false;
                }
                continue;
            }
            if ($char === '"') {
                $out .= $callback($chunk) . $char;
                $chunk = '';
                $instring = true;
                continue;
            }
            $chunk .= $char;
        }
        return $out . $callback($chunk);
    }
}
