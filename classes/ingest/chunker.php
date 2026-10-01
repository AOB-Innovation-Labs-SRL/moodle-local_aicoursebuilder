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

namespace local_aicoursebuilder\ingest;

/**
 * Cuts the normalised text of a source into chunks that follow its structure.
 *
 * A chunk holds between MIN_TOKENS and MAX_TOKENS tokens (a short text, or the end of one, can be smaller).
 * Chunks end at a block boundary: a heading of level 1 or 2 starts a new chunk once the current one has at
 * least MIN_TOKENS, and a chunk is closed before it would pass MAX_TOKENS. A block that is bigger than
 * UNIT_LIMIT (half of MAX_TOKENS) on its own is cut at line ends, at sentence ends or, last, between words;
 * a cut table repeats its header row. A heading is never left alone at the end of a chunk. When a chunk is closed because of its
 * size (not at a heading), the next one starts with the last OVERLAP_RATIO of it, so that a sentence or a
 * list that goes over the boundary is whole in one of the two. Each chunk has the nearest heading and the
 * pages it comes from.
 *
 * The page markers ("<!-- page N -->") give the pages; they are not part of the chunk text.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chunker {
    /** @var int Tokens a chunk should have at least before a heading starts a new one. */
    public const MIN_TOKENS = 1500;

    /** @var int Most tokens of the new text of a chunk. */
    public const MAX_TOKENS = 3000;

    /**
     * @var int Most tokens of a block that is packed as it is; a bigger block is cut. Half of MAX_TOKENS, so that a
     * chunk that is closed because the next block does not fit always has at least MIN_TOKENS.
     */
    public const UNIT_LIMIT = 1500;

    /** @var float Share of the previous chunk repeated at the start of the next one. */
    public const OVERLAP_RATIO = 0.10;

    /** @var float Characters per token when the settings give no factor, as in the connectors. */
    public const DEFAULT_CHARS_PER_TOKEN = 3.5;

    /** @var int Longest title kept, in characters (the column of local_aicb_chunk). */
    private const MAX_TITLE_LENGTH = 255;

    /** @var int Deepest heading level that starts a new chunk. */
    private const BOUNDARY_LEVEL = 2;

    /** @var string Page marker line. */
    private const PAGE_MARKER = '/^<!-- page (\d+) -->$/';

    /** @var float Characters per token used to estimate the size of the text. */
    private float $charspertoken;

    /**
     * Creates the chunker.
     *
     * @param float|null $charspertoken Characters per token; null for the factor of the default connector
     *                                  (setting token_estimator_charsperfactor_{connector}), else 3.5.
     */
    public function __construct(?float $charspertoken = null) {
        $this->charspertoken = $charspertoken !== null && $charspertoken > 0 ? $charspertoken : $this->read_factor();
    }

    /**
     * Estimates the tokens of a text.
     *
     * @param string $text The text.
     * @return int
     */
    public function count_tokens(string $text): int {
        return (int) ceil(\core_text::strlen($text) / $this->charspertoken);
    }

    /**
     * Cuts a text into chunks.
     *
     * @param string $markdown Normalised text, with or without page markers.
     * @return chunk[] The chunks in order; empty when the text is empty.
     */
    public function chunk(string $markdown): array {
        $units = $this->split_oversized($this->parse($markdown));
        $packed = $this->pack($units);

        $chunks = [];
        foreach ($packed as $index => $item) {
            $own = implode("\n\n", array_column($item['units'], 'text'));
            $overlap = ($index > 0 && $item['overlap']) ? $this->overlap_text($packed[$index - 1]) : '';
            $content = $overlap === '' ? $own : $overlap . "\n\n" . $own;

            $pages = array_filter(array_column($item['units'], 'page'), fn($page) => $page !== null);
            $title = $item['title'];
            $chunks[] = new chunk(
                $index,
                $title === null ? null : \core_text::substr($title, 0, self::MAX_TITLE_LENGTH),
                $pages ? min($pages) : null,
                $pages ? max($pages) : null,
                $content,
                $this->count_tokens($content)
            );
        }
        return $chunks;
    }

    /**
     * Reads the characters-per-token factor of the default connector from the settings.
     *
     * @return float
     */
    private function read_factor(): float {
        $connector = trim((string) get_config('local_aicoursebuilder', 'defaultconnector'))
            ?: \local_aicoursebuilder\ai\router::DEFAULT_CONNECTOR;
        $factor = (float) get_config('local_aicoursebuilder', 'token_estimator_charsperfactor_' . $connector);
        return $factor > 0 ? $factor : self::DEFAULT_CHARS_PER_TOKEN;
    }

    /**
     * Splits the text into units: one heading line, or one block of lines between empty lines.
     *
     * @param string $markdown The text.
     * @return array[] Units ['type' => heading|text, 'level' => int, 'title' => string|null, 'text' => string,
     *                 'page' => int|null, 'tokens' => int].
     */
    private function parse(string $markdown): array {
        $units = [];
        $page = null;
        $block = [];
        $flush = function () use (&$units, &$block, &$page): void {
            $text = trim(implode("\n", $block));
            if ($text !== '') {
                $units[] = ['type' => 'text', 'level' => 0, 'title' => null, 'text' => $text, 'page' => $page,
                    'tokens' => $this->count_tokens($text)];
            }
            $block = [];
        };

        foreach (explode("\n", $markdown) as $line) {
            $trimmed = trim($line);
            if (preg_match(self::PAGE_MARKER, $trimmed, $match)) {
                $flush();
                $page = (int) $match[1];
            } else if ($trimmed === '') {
                $flush();
            } else if (preg_match('/^(#{1,6})\s+(\S.*)$/u', $trimmed, $match)) {
                $flush();
                $units[] = ['type' => 'heading', 'level' => min(3, strlen($match[1])), 'title' => $match[2],
                    'text' => $trimmed, 'page' => $page, 'tokens' => $this->count_tokens($trimmed)];
            } else {
                $block[] = rtrim($line);
            }
        }
        $flush();
        return $units;
    }

    /**
     * Cuts the units that are bigger than UNIT_LIMIT.
     *
     * @param array[] $units Units from parse().
     * @return array[] Units, none bigger than UNIT_LIMIT (a unit can be a little over when one word is).
     */
    private function split_oversized(array $units): array {
        $result = [];
        foreach ($units as $unit) {
            if ($unit['tokens'] <= self::UNIT_LIMIT) {
                $result[] = $unit;
                continue;
            }
            foreach ($this->split_text($unit['text']) as $text) {
                $result[] = ['type' => 'text', 'level' => 0, 'title' => null, 'text' => $text, 'page' => $unit['page'],
                    'tokens' => $this->count_tokens($text)];
            }
        }
        return $result;
    }

    /**
     * Cuts a text that is too big, at line ends, then sentence ends, then between words.
     *
     * A table is cut between rows and every part repeats the header row.
     *
     * @param string $text The text.
     * @return string[] Parts of at most UNIT_LIMIT tokens.
     */
    private function split_text(string $text): array {
        $lines = explode("\n", $text);
        $header = [];
        if (str_starts_with($lines[0], '|') && isset($lines[1]) && str_starts_with($lines[1], '|')) {
            $header = array_splice($lines, 0, 2);
        }
        $headertokens = $this->count_tokens(implode("\n", $header));

        // Pieces: a line, or a part of a line that is too long; each knows what joins it to the piece before.
        $pieces = [];
        foreach ($lines as $line) {
            foreach ($this->split_line($line, self::UNIT_LIMIT - $headertokens) as $position => $piece) {
                $pieces[] = ['text' => $piece, 'separator' => $position === 0 ? "\n" : ' '];
            }
        }

        $parts = [];
        $current = '';
        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece['text'] : $current . $piece['separator'] . $piece['text'];
            if ($current !== '' && $this->count_tokens($candidate) + $headertokens > self::UNIT_LIMIT) {
                $parts[] = $current;
                $current = $piece['text'];
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        return array_map(fn($part) => $header ? implode("\n", $header) . "\n" . $part : $part, $parts);
    }

    /**
     * Cuts a line that has more than a given number of tokens into sentences and, if needed, into groups of words.
     *
     * @param string $line The line.
     * @param int $limit Most tokens of a piece.
     * @return string[] One piece when the line fits.
     */
    private function split_line(string $line, int $limit): array {
        if ($this->count_tokens($line) <= $limit) {
            return [$line];
        }
        $pieces = [];
        $sentences = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\d"„«(])/u', $line) ?: [$line];
        foreach ($sentences as $sentence) {
            if ($this->count_tokens($sentence) <= $limit) {
                $pieces[] = $sentence;
                continue;
            }
            $maxchars = max(1, (int) floor($limit * $this->charspertoken));
            $group = '';
            foreach (preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                foreach (\core_text::strlen($word) > $maxchars ? mb_str_split($word, $maxchars) : [$word] as $part) {
                    if ($group !== '' && \core_text::strlen($group) + 1 + \core_text::strlen($part) > $maxchars) {
                        $pieces[] = $group;
                        $group = $part;
                    } else {
                        $group = $group === '' ? $part : $group . ' ' . $part;
                    }
                }
            }
            if ($group !== '') {
                $pieces[] = $group;
            }
        }
        return $pieces;
    }

    /**
     * Packs the units into chunks.
     *
     * @param array[] $units Units, none bigger than UNIT_LIMIT.
     * @return array[] Chunks ['units' => array[], 'tokens' => int, 'title' => string|null, 'overlap' => bool]; overlap
     *                 tells that the chunk starts after a cut made by size, so it gets the end of the previous one.
     */
    private function pack(array $units): array {
        $chunks = [];
        $current = $this->new_chunk([], null, false);
        $activetitle = null;

        foreach ($units as $unit) {
            $isheading = $unit['type'] === 'heading';
            if ($isheading) {
                $activetitle = $unit['title'];
                if ($unit['level'] <= self::BOUNDARY_LEVEL && $current['tokens'] >= self::MIN_TOKENS) {
                    $chunks[] = $current;
                    $current = $this->new_chunk([], $activetitle, false);
                }
            }
            if ($current['units'] && $current['tokens'] + $unit['tokens'] > self::MAX_TOKENS) {
                // A heading that ends the chunk goes to the next one, with the text it introduces.
                $carried = [];
                while (count($current['units']) > 1 && end($current['units'])['type'] === 'heading') {
                    array_unshift($carried, array_pop($current['units']));
                }
                $current['tokens'] = array_sum(array_column($current['units'], 'tokens'));
                $chunks[] = $current;
                $current = $this->new_chunk($carried, $carried ? $carried[0]['title'] : $activetitle, true);
                if ($isheading && !$carried) {
                    $current['title'] = $unit['title'];
                }
            }
            if (!$current['units']) {
                $current['title'] = $isheading ? $unit['title'] : $current['title'];
            }
            $current['units'][] = $unit;
            $current['tokens'] += $unit['tokens'];
        }
        if ($current['units']) {
            $chunks[] = $current;
        }

        // The last chunk is joined to the one before when both fit in one chunk.
        $count = count($chunks);
        $fits = $count > 1 && $chunks[$count - 1]['tokens'] < self::MIN_TOKENS
            && $chunks[$count - 2]['tokens'] + $chunks[$count - 1]['tokens'] <= self::MAX_TOKENS;
        if ($fits) {
            $last = array_pop($chunks);
            $chunks[$count - 2]['units'] = array_merge($chunks[$count - 2]['units'], $last['units']);
            $chunks[$count - 2]['tokens'] += $last['tokens'];
        }
        return $chunks;
    }

    /**
     * Makes an empty chunk, or one that starts with some units.
     *
     * @param array[] $units Units it starts with.
     * @param string|null $title Nearest heading at its start.
     * @param bool $overlap Whether it starts after a cut made by size.
     * @return array
     */
    private function new_chunk(array $units, ?string $title, bool $overlap): array {
        return [
            'units' => $units,
            'tokens' => array_sum(array_column($units, 'tokens')),
            'title' => $title,
            'overlap' => $overlap,
        ];
    }

    /**
     * Returns the end of a chunk that starts the next one: about OVERLAP_RATIO of it, in whole lines or sentences.
     *
     * @param array $chunk The chunk that was closed.
     * @return string Empty when the chunk has no text to repeat.
     */
    private function overlap_text(array $chunk): string {
        $target = (int) ceil(self::OVERLAP_RATIO * $chunk['tokens']);
        $taken = [];
        $tokens = 0;
        foreach (array_reverse($chunk['units']) as $unit) {
            if ($unit['type'] === 'heading') {
                break;
            }
            if ($tokens + $unit['tokens'] <= $target) {
                array_unshift($taken, $unit['text']);
                $tokens += $unit['tokens'];
                continue;
            }
            // Only the last sentences of a unit that is too big for what is left; a table is never cut here.
            if (str_starts_with($unit['text'], '|')) {
                break;
            }
            $sentences = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\d"„«(])|\n/u', $unit['text']) ?: [];
            $part = [];
            foreach (array_reverse($sentences) as $sentence) {
                if ($tokens + $this->count_tokens(implode(' ', $part) . ' ' . $sentence) > $target) {
                    break;
                }
                array_unshift($part, $sentence);
            }
            if ($part) {
                array_unshift($taken, implode(' ', $part));
            }
            break;
        }
        return implode("\n\n", $taken);
    }
}
