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
 * Turns the Markdown that an extractor made into clean, structured Markdown, and detects its language.
 *
 * The extractors give the text as it is in the file: a PDF has a line for every line of the page, running
 * headers and footers on every page, hyphenated words, bullets as special characters and no headings. The
 * normaliser
 *  - cleans characters (control characters, soft hyphens, ligatures, the Romanian cedilla letters ş ţ
 *    become ș ț);
 *  - removes running headers and footers and page numbers (lines that repeat at the top or the bottom of
 *    many pages);
 *  - joins the lines of a paragraph again and the words that were hyphenated at the end of a line;
 *  - makes Markdown tables of text aligned in columns;
 *  - marks headings (H1 to H3) in text that has none: numbered titles, "Capitolul 3" and lines in capitals;
 *    headings that the extractor already marked are kept, and deeper levels become H3;
 *  - writes bullets as "- " items and removes repeated paragraphs;
 *  - detects the language.
 * Page markers ("<!-- page N -->", made by markdown::join_pages) are kept, so that the chunker knows the page.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class normalizer {
    /** @var int Pages a text needs before running headers and footers are looked for. */
    public const MIN_PAGES_FOR_RUNNING_LINES = 3;

    /** @var int Lines at the top and at the bottom of a page that can be a running header or footer. */
    public const RUNNING_LINES_PER_EDGE = 3;

    /** @var float Share of the pages on which a line must repeat to be a running header or footer. */
    public const RUNNING_LINE_SHARE = 0.25;

    /** @var int Longest line that can be a heading, in characters. */
    public const MAX_HEADING_LENGTH = 90;

    /** @var int Shortest block that counts as a duplicate, in letters and digits. */
    public const MIN_DUPLICATE_LENGTH = 80;

    /** @var int Deepest heading level kept. */
    public const MAX_HEADING_LEVEL = 3;

    /** @var int Most characters of the text that are read to detect the language. */
    public const LANGUAGE_SAMPLE_LENGTH = 30000;

    /** @var int Stop words a language needs in the sample to be chosen. */
    private const MIN_LANGUAGE_HITS = 8;

    /** @var float How many times more stop words than the second language the best one needs. */
    private const LANGUAGE_MARGIN = 1.25;

    /** @var string Page marker line made by markdown::join_pages(). */
    private const PAGE_MARKER = '/^<!-- page (\d+) -->$/';

    /** @var string Line that starts a list item: a bullet character or a dash followed by a space. */
    private const BULLET = '/^[•●▪■◦·‣⁃○◆►–—*-]\s+(\S.*)$/u';

    /** @var string[] Letters that differ between the Windows code pages and the Romanian standard. */
    private const ROMANIAN_LETTERS = ['ş' => 'ș', 'Ş' => 'Ș', 'ţ' => 'ț', 'Ţ' => 'Ț'];

    /** @var string[] Ligatures that some PDF fonts use, and the letters they stand for. */
    private const LIGATURES = ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl'];

    /** @var string[][] Frequent words of each language, for the language detection. */
    private const STOP_WORDS = [
        'ro' => ['și', 'si', 'în', 'in', 'de', 'la', 'cu', 'pe', 'care', 'este', 'sunt', 'pentru', 'din', 'un',
            'că', 'ca', 'nu', 'mai', 'sau', 'se', 'au', 'fi', 'prin', 'acest', 'această', 'acestei', 'lor', 'ale',
            'al', 'cel', 'cea', 'unei', 'unui', 'fiecare', 'foarte', 'dar', 'dacă', 'când', 'între', 'către'],
        'en' => ['the', 'and', 'of', 'to', 'in', 'is', 'that', 'for', 'it', 'with', 'as', 'was', 'on', 'are', 'by', 'this',
            'be', 'or', 'from', 'at', 'an', 'which', 'have', 'has', 'not', 'but', 'they', 'their', 'can', 'will', 'all',
            'you', 'we', 'more', 'when', 'these', 'also', 'such', 'between'],
        'fr' => ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'en', 'un', 'une', 'est', 'que', 'qui', 'dans', 'pour', 'pas',
            'sur', 'au', 'aux', 'avec', 'ce', 'il', 'elle', 'sont', 'par', 'plus', 'ou', 'mais', 'nous', 'vous', 'ont'],
        'de' => ['der', 'die', 'das', 'und', 'in', 'den', 'von', 'zu', 'mit', 'ist', 'für', 'auf', 'nicht', 'ein', 'eine',
            'dem', 'des', 'sich', 'im', 'auch', 'als', 'bei', 'oder', 'wird', 'werden', 'sind', 'an', 'nach', 'wie'],
        'es' => ['el', 'la', 'los', 'las', 'de', 'en', 'un', 'una', 'es', 'que', 'por', 'con', 'para', 'del', 'se',
            'su', 'al', 'lo', 'como', 'más', 'pero', 'sus', 'son', 'este', 'esta', 'entre', 'también', 'sin', 'sobre'],
        'it' => ['il', 'lo', 'la', 'gli', 'le', 'di', 'in', 'un', 'una', 'che', 'per', 'con', 'non', 'del',
            'della', 'dei', 'delle', 'è', 'sono', 'si', 'da', 'come', 'più', 'anche', 'nel', 'alla', 'questo', 'tra'],
        'hu' => ['az', 'és', 'hogy', 'nem', 'is', 'egy', 'meg', 'de', 'van', 'volt', 'ez', 'azt', 'még', 'már', 'csak',
            'mint', 'vagy', 'el', 'ki', 'be', 'fel', 'le', 'át', 'köztük', 'amely', 'között', 'szerint', 'kell', 'lehet'],
    ];

    /**
     * Normalises the text of one source.
     *
     * @param string $markdown Text from an extractor, with or without page markers.
     * @return normalization_result
     */
    public function normalize(string $markdown): normalization_result {
        $headers = 0;
        $duplicates = 0;
        $tables = 0;
        $headings = 0;

        $pages = $this->split_pages($this->clean_characters($markdown));
        $this->remove_running_lines($pages, $headers);

        $structured = false;
        foreach ($pages as $index => $page) {
            $pages[$index]['blocks'] = $this->to_blocks($page['lines'], $tables);
            foreach ($pages[$index]['blocks'] as $block) {
                $structured = $structured || $block['type'] === 'heading';
            }
        }
        if (!$structured) {
            foreach ($pages as $index => $page) {
                $pages[$index]['blocks'] = $this->find_headings($page['blocks'], $headings);
            }
        }
        $this->remove_duplicates($pages, $duplicates);

        $text = $this->render($pages);
        return new normalization_result($text, $this->detect_language($text), [
            'headers' => $headers,
            'duplicates' => $duplicates,
            'tables' => $tables,
            'headings' => $headings,
        ]);
    }

    /**
     * Detects the language of a text from its most frequent words.
     *
     * @param string $text The text.
     * @return string ISO 639-1 code (ro, en, fr, de, es, it, hu), or normalization_result::LANGUAGE_UNDETERMINED
     *                when no language stands out.
     */
    public function detect_language(string $text): string {
        $sample = \core_text::substr($text, 0, self::LANGUAGE_SAMPLE_LENGTH);
        $words = preg_split('/[^\p{L}]+/u', \core_text::strtolower($sample), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return normalization_result::LANGUAGE_UNDETERMINED;
        }
        $counts = array_count_values($words);

        $scores = [];
        foreach (self::STOP_WORDS as $language => $stopwords) {
            $scores[$language] = 0;
            foreach (array_unique($stopwords) as $word) {
                $scores[$language] += $counts[$word] ?? 0;
            }
        }
        arsort($scores);
        $best = array_key_first($scores);
        $second = array_values($scores)[1] ?? 0;
        if ($scores[$best] < self::MIN_LANGUAGE_HITS || $scores[$best] < $second * self::LANGUAGE_MARGIN) {
            return normalization_result::LANGUAGE_UNDETERMINED;
        }
        return $best;
    }

    /**
     * Cleans characters: valid UTF-8, no control characters, soft hyphens, zero-width characters or ligatures,
     * one kind of space, and the Romanian letters with a comma below.
     *
     * @param string $text The text.
     * @return string
     */
    private function clean_characters(string $text): string {
        $text = fix_utf8($text);
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        // A word hyphenated with a soft hyphen at the end of a line is one word again.
        $text = str_replace("\u{00AD}\n", '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $text) ?? $text;
        $text = strtr($text, self::LIGATURES);
        $text = strtr($text, self::ROMANIAN_LETTERS);
        return $text;
    }

    /**
     * Splits the text at the page markers.
     *
     * @param string $text Cleaned text.
     * @return array[] Pages, each ['number' => int|null, 'lines' => string[]]; one page without a number when
     *                 the text has no page markers.
     */
    private function split_pages(string $text): array {
        $pages = [];
        $current = ['number' => null, 'lines' => []];
        $hasmarkers = false;
        foreach (explode("\n", $text) as $line) {
            if (preg_match(self::PAGE_MARKER, trim($line), $match)) {
                if ($hasmarkers || array_filter($current['lines'], fn($l) => trim($l) !== '')) {
                    $pages[] = $current;
                }
                $hasmarkers = true;
                $current = ['number' => (int) $match[1], 'lines' => []];
                continue;
            }
            $current['lines'][] = rtrim($line);
        }
        $pages[] = $current;
        return $pages;
    }

    /**
     * Removes the running headers and footers and the page numbers.
     *
     * A line is a running header or footer when, with its numbers ignored, it is among the first or the last
     * lines of at least RUNNING_LINE_SHARE of the pages. The first time it occurs it is kept, because it is then
     * the real title of the document or of a section; the repeats are removed. A line that only holds a page
     * number is removed at the top or the bottom of any page.
     *
     * @param array[] $pages Pages from split_pages(), changed in place.
     * @param int $removed Count of the lines removed, increased.
     */
    private function remove_running_lines(array &$pages, int &$removed): void {
        $numbered = array_filter($pages, fn($page) => $page['number'] !== null);
        $pagecount = count($numbered);

        // The positions of the lines that can be running lines, by page.
        $edges = [];
        $keys = [];
        foreach ($pages as $index => $page) {
            $edges[$index] = $this->edge_positions($page['lines']);
            foreach ($edges[$index] as $position) {
                $key = $this->running_key($page['lines'][$position]);
                if ($key !== '') {
                    $keys[$key][$index] = true;
                }
            }
        }
        $limit = max(self::MIN_PAGES_FOR_RUNNING_LINES, (int) ceil(self::RUNNING_LINE_SHARE * $pagecount));

        $kept = [];
        foreach ($pages as $index => $page) {
            foreach ($edges[$index] as $position) {
                $line = $page['lines'][$position];
                $key = $this->running_key($line);
                $repeats = $pagecount >= self::MIN_PAGES_FOR_RUNNING_LINES && $key !== '' && count($keys[$key]) >= $limit;
                if ($repeats && !isset($kept[$key]) && !$this->is_page_number($line)) {
                    // The first time is the real title (of the document or of a section); only the repeats are furniture.
                    $kept[$key] = true;
                    continue;
                }
                if ($repeats || $this->is_page_number($line)) {
                    $pages[$index]['lines'][$position] = '';
                    $removed++;
                }
            }
        }
    }

    /**
     * Returns the positions of the first and the last non-empty lines of a page.
     *
     * @param string[] $lines Lines of the page.
     * @return int[]
     */
    private function edge_positions(array $lines): array {
        $filled = array_keys(array_filter($lines, fn($line) => trim($line) !== ''));
        if (!$filled) {
            return [];
        }
        $count = self::RUNNING_LINES_PER_EDGE;
        return array_values(array_unique(array_merge(array_slice($filled, 0, $count), array_slice($filled, -$count))));
    }

    /**
     * Returns the key that tells that two lines are the same running line: case, punctuation and numbers ignored.
     *
     * @param string $line The line.
     * @return string Empty for a line that cannot be a running line (structure, or too short).
     */
    private function running_key(string $line): string {
        $line = trim($line);
        if ($line === '' || preg_match('/^(#{1,6}\s|\||<!--|-\s|>)/', $line)) {
            return '';
        }
        $key = preg_replace('/\d+/u', '#', \core_text::strtolower($line)) ?? '';
        $key = trim(preg_replace('/[^\p{L}#]+/u', ' ', $key) ?? '');
        return preg_match_all('/\p{L}/u', $key) >= 4 ? $key : '';
    }

    /**
     * Tells whether a line only holds a page number, such as "12", "- 12 -" or "Pagina 12 din 40".
     *
     * @param string $line The line.
     * @return bool
     */
    private function is_page_number(string $line): bool {
        $pattern = '/^\s*[-–—]?\s*(?:(?:pagina|page|pag\.?|p\.)\s*)?\d{1,4}(?:\s*(?:din|of|\/)\s*\d{1,4})?\s*[-–—]?\s*$/iu';
        return (bool) preg_match($pattern, $line);
    }

    /**
     * Makes the blocks of a page: headings, paragraphs, list items, quotes and tables.
     *
     * @param string[] $lines Lines of the page.
     * @param int $tables Count of the tables made from aligned text, increased.
     * @return array[] Blocks, each ['type' => heading|paragraph|item|quote|table, 'text' => string, 'level' => int].
     */
    private function to_blocks(array $lines, int &$tables): array {
        $entries = $this->find_aligned_tables($lines, $tables);
        $width = $this->typical_width($entries);

        $blocks = [];
        $current = null;
        $tablelines = [];
        $flush = function () use (&$blocks, &$current, &$tablelines): void {
            if ($current !== null) {
                $blocks[] = ['type' => $current['type'], 'text' => $this->join_lines($current['lines']), 'level' => 0];
                $current = null;
            }
            if ($tablelines) {
                $blocks[] = ['type' => 'table', 'text' => implode("\n", $tablelines), 'level' => 0];
                $tablelines = [];
            }
        };

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $flush();
                $blocks[] = ['type' => 'table', 'text' => $entry['table'], 'level' => 0];
                continue;
            }
            $line = trim($entry);
            if ($line === '') {
                $flush();
                continue;
            }
            if ($line[0] === '|') {
                if ($current !== null) {
                    $flush();
                }
                $tablelines[] = $line;
                continue;
            }
            if ($tablelines) {
                $flush();
            }
            if (preg_match('/^(#{1,6})\s+(\S.*)$/u', $line, $match)) {
                $flush();
                $blocks[] = [
                    'type' => 'heading',
                    'text' => $match[2],
                    'level' => min(self::MAX_HEADING_LEVEL, strlen($match[1])),
                ];
            } else if ($line[0] === '>') {
                $flush();
                $blocks[] = ['type' => 'quote', 'text' => $line, 'level' => 0];
            } else if (preg_match(self::BULLET, $line, $match)) {
                $flush();
                $current = ['type' => 'item', 'lines' => [$match[1]]];
            } else if ($current !== null && $this->continues($current['lines'], $line, $width)) {
                $current['lines'][] = $line;
            } else {
                $flush();
                $current = ['type' => 'paragraph', 'lines' => [$line]];
            }
        }
        $flush();
        return $blocks;
    }

    /**
     * Tells whether a line goes on with the paragraph whose lines are given.
     *
     * The paragraph goes on when its last line does not end a sentence and the new line starts in lower case, or
     * when the last line is as long as the lines of the page, so the line was wrapped. A line that looks like a
     * heading never goes on with a paragraph.
     *
     * @param string[] $lines Lines of the paragraph so far.
     * @param string $line The new line.
     * @param int $width Typical width of the lines of the page, in characters.
     * @return bool
     */
    private function continues(array $lines, string $line, int $width): bool {
        $last = end($lines);
        if ($this->ends_sentence($last) || $this->heading_level($line) !== null) {
            return false;
        }
        $wrapped = $width > 0 && \core_text::strlen($last) >= 0.85 * $width;
        return $wrapped || preg_match('/^\p{Ll}/u', $line);
    }

    /**
     * Tells whether a line ends a sentence.
     *
     * @param string $line The line.
     * @return bool
     */
    private function ends_sentence(string $line): bool {
        return (bool) preg_match('/[.!?…;:»”]\s*$/u', $line);
    }

    /**
     * Joins the lines of a paragraph; a word hyphenated at the end of a line is joined without the hyphen.
     *
     * @param string[] $lines Lines of the paragraph.
     * @return string
     */
    private function join_lines(array $lines): string {
        $text = '';
        foreach ($lines as $line) {
            if ($text === '') {
                $text = $line;
            } else if (preg_match('/\p{Ll}-$/u', $text) && preg_match('/^\p{Ll}/u', $line)) {
                $text = substr($text, 0, -1) . $line;
            } else {
                $text .= ' ' . $line;
            }
        }
        return trim(preg_replace('/ {2,}/', ' ', $text) ?? $text);
    }

    /**
     * Returns the typical width of the lines of a page: the 90th percentile of the lengths of its text lines.
     *
     * @param array $entries Lines of the page, and tables (arrays) that are left out.
     * @return int Characters; 0 when the page has too few text lines to tell.
     */
    private function typical_width(array $entries): int {
        $lengths = [];
        foreach ($entries as $entry) {
            if (is_string($entry) && trim($entry) !== '' && !preg_match('/^\s*(#|\||>)/', $entry)) {
                $lengths[] = \core_text::strlen(trim($entry));
            }
        }
        if (count($lengths) < 5) {
            return 0;
        }
        sort($lengths);
        return $lengths[(int) floor(0.9 * (count($lengths) - 1))];
    }

    /**
     * Finds text that is aligned in columns (what pdftotext -layout makes of a table) and makes Markdown tables.
     *
     * At least three lines in a row must split, at gaps of two or more spaces, into the same number (2 to 12)
     * of short cells.
     *
     * @param string[] $lines Lines of the page.
     * @param int $tables Count of the tables made, increased.
     * @return array The lines, with every table replaced by ['table' => Markdown].
     */
    private function find_aligned_tables(array $lines, int &$tables): array {
        $entries = [];
        $run = [];
        $flush = function () use (&$entries, &$run, &$tables): void {
            if (count($run) >= 3) {
                $entries[] = ['table' => markdown::table($run)];
                $tables++;
            } else {
                foreach ($run as $row) {
                    $entries[] = implode('  ', $row);
                }
            }
            $run = [];
        };

        foreach ($lines as $line) {
            $cells = $this->split_columns($line);
            if ($cells !== null && (!$run || count($run[0]) === count($cells))) {
                $run[] = $cells;
                continue;
            }
            $flush();
            if ($cells !== null) {
                $run[] = $cells;
            } else {
                $entries[] = $line;
            }
        }
        $flush();
        return $entries;
    }

    /**
     * Splits a line into the cells of a table row.
     *
     * @param string $line The line.
     * @return string[]|null The cells, null when the line is not a row: it has no gap, structure markup, or long cells.
     */
    private function split_columns(string $line): ?array {
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/^(#|\||>|<!--)/', $trimmed)) {
            return null;
        }
        $cells = preg_split('/\s{2,}/u', $trimmed);
        if (count($cells) < 2 || count($cells) > 12) {
            return null;
        }
        foreach ($cells as $cell) {
            if (\core_text::strlen($cell) > 45) {
                return null;
            }
        }
        return $cells;
    }

    /**
     * Marks the headings of a text that had none.
     *
     * Only a paragraph of one short line without a full stop can be a heading. Lines in capitals that follow
     * each other are one heading.
     *
     * @param array[] $blocks Blocks of a page.
     * @param int $count Count of the headings made, increased.
     * @return array[] The blocks.
     */
    private function find_headings(array $blocks, int &$count): array {
        $result = [];
        foreach ($blocks as $block) {
            $level = $block['type'] === 'paragraph' ? $this->heading_level($block['text']) : null;
            if ($level === null) {
                $result[] = $block;
                continue;
            }
            $previous = end($result);
            $caps = $this->is_capitals($block['text']);
            if ($previous && $previous['type'] === 'heading' && $previous['caps'] && $caps && $previous['level'] === $level) {
                $result[key($result)]['text'] .= ' ' . $block['text'];
                continue;
            }
            $result[] = ['type' => 'heading', 'text' => $block['text'], 'level' => $level, 'caps' => $caps];
            $count++;
        }
        return $result;
    }

    /**
     * Returns the heading level of a line, or null when the line does not look like a heading.
     *
     * A heading is a short line that does not end like a sentence, and is one of: a numbered title (1. or 1.2
     * or 1.2.3, levels 1 to 3), a chapter or part title (Capitolul 3, Partea a II-a, Chapter 2: level 1), a
     * section or annex title (level 2), or a line in capitals (level 2).
     *
     * @param string $line The line.
     * @return int|null 1 to 3, or null.
     */
    private function heading_level(string $line): ?int {
        $line = trim($line);
        if (
            $line === '' || \core_text::strlen($line) > self::MAX_HEADING_LENGTH || preg_match('/[.!?,;:]$/u', $line)
            || preg_match('/^(#|\||>|-\s|<!--)/', $line) || $this->is_page_number($line)
        ) {
            return null;
        }
        if (preg_match('/^(\d{1,2}(?:\.\d{1,2}){0,2})\.?\s+\p{Lu}\S*(?:\s+\S+){0,11}$/u', $line, $match)) {
            return min(self::MAX_HEADING_LEVEL, substr_count($match[1], '.') + 1);
        }
        if (preg_match('/^(?:capitolul|capitol|partea|chapter|part)\s+(?:\d+|[ivxlc]+|a\s+[ivxlc]+-a)\b/iu', $line)) {
            return 1;
        }
        if (preg_match('/^(?:sec[țt]iunea|section|anexa|annex|appendix)\s+(?:\d+|[ivxlc]+|[a-z])\b/iu', $line)) {
            return 2;
        }
        return $this->is_capitals($line) ? 2 : null;
    }

    /**
     * Tells whether a line is written in capitals: at least four letters, nearly all of them upper case.
     *
     * @param string $line The line.
     * @return bool
     */
    private function is_capitals(string $line): bool {
        $letters = preg_match_all('/\p{L}/u', $line);
        if ($letters < 4) {
            return false;
        }
        $upper = preg_match_all('/\p{Lu}/u', $line);
        $words = count(preg_split('/\s+/u', trim($line)));
        return $upper / $letters >= 0.9 && ($words >= 2 || $letters >= 8);
    }

    /**
     * Removes the paragraphs and the tables that occur again later in the text, and a heading that repeats the
     * heading just before it.
     *
     * @param array[] $pages Pages with their blocks, changed in place.
     * @param int $removed Count of the blocks removed, increased.
     */
    private function remove_duplicates(array &$pages, int &$removed): void {
        $seen = [];
        $previousheading = null;
        foreach ($pages as $index => $page) {
            $kept = [];
            foreach ($page['blocks'] as $block) {
                if ($block['type'] === 'heading') {
                    $key = preg_replace('/[^\p{L}\p{N}]+/u', '', \core_text::strtolower($block['text'])) ?? '';
                    if ($key === $previousheading) {
                        $removed++;
                        continue;
                    }
                    $previousheading = $key;
                } else {
                    $previousheading = null;
                }
                if ($block['type'] === 'paragraph' || $block['type'] === 'table') {
                    $key = preg_replace('/[^\p{L}\p{N}]+/u', '', \core_text::strtolower($block['text'])) ?? '';
                    if (\core_text::strlen($key) >= self::MIN_DUPLICATE_LENGTH) {
                        if (isset($seen[$key])) {
                            $removed++;
                            continue;
                        }
                        $seen[$key] = true;
                    }
                }
                $kept[] = $block;
            }
            $pages[$index]['blocks'] = $kept;
        }
    }

    /**
     * Writes the pages back as Markdown, with their page markers.
     *
     * @param array[] $pages Pages with their blocks.
     * @return string
     */
    private function render(array $pages): string {
        $parts = [];
        foreach ($pages as $page) {
            $text = '';
            $previous = null;
            foreach ($page['blocks'] as $block) {
                $line = match ($block['type']) {
                    'heading' => str_repeat('#', $block['level']) . ' ' . $block['text'],
                    'item' => '- ' . $block['text'],
                    default => $block['text'],
                };
                $text .= $text === '' ? $line : (($previous === 'item' && $block['type'] === 'item') ? "\n" : "\n\n") . $line;
                $previous = $block['type'];
            }
            if ($text === '') {
                continue;
            }
            $parts[] = $page['number'] === null ? $text : "<!-- page {$page['number']} -->\n\n" . $text;
        }
        return markdown::tidy(implode("\n\n", $parts));
    }
}
