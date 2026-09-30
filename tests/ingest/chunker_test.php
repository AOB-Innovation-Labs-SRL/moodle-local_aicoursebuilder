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
 * Tests for the chunker.
 *
 * The chunker is made with one character per token, so that the size of a text in tokens is its length.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\chunker
 * @covers     \local_aicoursebuilder\ingest\chunk
 */
final class chunker_test extends \basic_testcase {
    /** @var int Longest chunk: the new text and the overlap. */
    private const LONGEST = 3300;

    /**
     * Cuts a text into chunks, with one character per token.
     *
     * @param string $text The text.
     * @return chunk[]
     */
    private function chunk(string $text): array {
        return (new chunker(1.0))->chunk($text);
    }

    /**
     * Makes a text of sentences of about a given length that ends with a full stop.
     *
     * @param string $label Word that makes the text different from the others.
     * @param int $chars Least length in characters.
     * @return string
     */
    private function prose(string $label, int $chars): string {
        $text = '';
        $number = 1;
        while (mb_strlen($text) < $chars) {
            $text .= "Propoziția {$number} din {$label} spune ceva util despre tema discutată. ";
            $number++;
        }
        return rtrim($text);
    }

    /**
     * Makes paragraphs separated by empty lines.
     *
     * @param string $label Word that makes the paragraphs different from other texts.
     * @param int $count Number of paragraphs.
     * @param int $chars Length of a paragraph.
     * @return string
     */
    private function paragraphs(string $label, int $count, int $chars): string {
        $paragraphs = [];
        for ($i = 1; $i <= $count; $i++) {
            $paragraphs[] = $this->prose("{$label}{$i}", $chars);
        }
        return implode("\n\n", $paragraphs);
    }

    /**
     * An empty text has no chunks.
     */
    public function test_empty_text(): void {
        $this->assertSame([], $this->chunk(''));
        $this->assertSame([], $this->chunk("  \n\n  "));
        $this->assertSame([], $this->chunk("<!-- page 1 -->\n\n<!-- page 2 -->"));
    }

    /**
     * A short text is one chunk with its title, its pages and its size.
     */
    public function test_short_text_is_one_chunk(): void {
        $text = "<!-- page 3 -->\n\n# Introducere\n\nUn text scurt.\n\n<!-- page 4 -->\n\nAl doilea paragraf.";

        $chunks = $this->chunk($text);

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]->index);
        $this->assertSame('Introducere', $chunks[0]->title);
        $this->assertSame(3, $chunks[0]->pagefrom);
        $this->assertSame(4, $chunks[0]->pageto);
        $this->assertSame("# Introducere\n\nUn text scurt.\n\nAl doilea paragraf.", $chunks[0]->content);
        $this->assertSame(mb_strlen($chunks[0]->content), $chunks[0]->tokencount);
    }

    /**
     * A text without pages or headings has no pages and no title.
     */
    public function test_no_pages_and_no_title(): void {
        $chunks = $this->chunk("Un paragraf.\n\nAl doilea paragraf.");

        $this->assertNull($chunks[0]->title);
        $this->assertNull($chunks[0]->pagefrom);
        $this->assertNull($chunks[0]->pageto);
    }

    /**
     * The estimate is the length divided by the characters per token, rounded up.
     */
    public function test_token_estimate(): void {
        $this->assertSame(2, (new chunker(3.5))->count_tokens('abcdefg'));
        $this->assertSame(3, (new chunker(3.5))->count_tokens('abcdefgh'));
        $this->assertSame(0, (new chunker(3.5))->count_tokens(''));
    }

    /**
     * Chunks stay within the maximum, all but the last reach the minimum, and no text is lost.
     */
    public function test_sizes(): void {
        $text = $this->paragraphs('p', 30, 400);

        $chunks = $this->chunk($text);

        $this->assertGreaterThan(3, count($chunks));
        foreach ($chunks as $position => $chunk) {
            $this->assertSame($position, $chunk->index);
            $this->assertLessThanOrEqual(self::LONGEST, $chunk->tokencount, "chunk {$position}");
            if ($position < count($chunks) - 1) {
                $this->assertGreaterThanOrEqual(chunker::MIN_TOKENS, $chunk->tokencount, "chunk {$position}");
            }
        }
        $all = implode("\n", array_map(fn($chunk) => $chunk->content, $chunks));
        foreach (explode("\n\n", $text) as $paragraph) {
            $this->assertStringContainsString($paragraph, $all);
        }
    }

    /**
     * A chunk that was closed because of its size starts with the end of the one before it, about a tenth of it.
     */
    public function test_overlap_after_a_cut_by_size(): void {
        $chunks = $this->chunk($this->paragraphs('p', 30, 400));

        for ($i = 1; $i < count($chunks) - 1; $i++) {
            $first = explode("\n\n", $chunks[$i]->content)[0];
            $this->assertStringContainsString($first, $chunks[$i - 1]->content, "chunk {$i}");
            $this->assertLessThanOrEqual(0.12 * $chunks[$i - 1]->tokencount, mb_strlen($first), "chunk {$i}");
            $this->assertGreaterThan(0.04 * $chunks[$i - 1]->tokencount, mb_strlen($first), "chunk {$i}");
        }
    }

    /**
     * A heading of level 1 starts a new chunk once the current one has the minimum, and there is no overlap there.
     */
    public function test_heading_starts_a_chunk(): void {
        $text = "# Partea A\n\n" . $this->paragraphs('a', 6, 300) . "\n\n# Partea B\n\n" . $this->paragraphs('b', 6, 300);

        $chunks = $this->chunk($text);

        $this->assertCount(2, $chunks);
        $this->assertSame('Partea A', $chunks[0]->title);
        $this->assertStringStartsWith('# Partea B', $chunks[1]->content);
        $this->assertSame('Partea B', $chunks[1]->title);
        $this->assertStringNotContainsString('Partea B', $chunks[0]->content);
    }

    /**
     * A section that is smaller than the minimum is not cut from the next one.
     */
    public function test_small_sections_stay_together(): void {
        $text = "# A\n\n" . $this->prose('a', 600) . "\n\n# B\n\n" . $this->prose('b', 600);

        $chunks = $this->chunk($text);

        $this->assertCount(1, $chunks);
        $this->assertSame('A', $chunks[0]->title);
        $this->assertStringContainsString('# B', $chunks[0]->content);
    }

    /**
     * A last chunk that is too small goes back to the one before when both fit in one chunk.
     */
    public function test_small_last_chunk_is_joined(): void {
        $text = "# A\n\n" . $this->prose('a', 1600) . "\n\n# B\n\n" . $this->prose('b', 300);

        $chunks = $this->chunk($text);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('# B', $chunks[0]->content);
    }

    /**
     * A heading is not left alone at the end of a chunk: it goes to the next one with the text it introduces.
     */
    public function test_heading_is_not_left_at_the_end(): void {
        $text = $this->paragraphs('a', 7, 400) . "\n\n### Subsecțiune\n\n" . $this->prose('b', 400);

        $chunks = $this->chunk($text);

        $this->assertCount(2, $chunks);
        $this->assertStringNotContainsString('### Subsecțiune', $chunks[0]->content);
        $this->assertStringContainsString("### Subsecțiune\n\nPropoziția 1 din b", $chunks[1]->content);
        $this->assertSame('Subsecțiune', $chunks[1]->title);
    }

    /**
     * A paragraph that is too big is cut at the end of a sentence.
     */
    public function test_big_paragraph_is_cut_at_sentences(): void {
        $paragraph = $this->prose('mare', 5000);

        $chunks = $this->chunk($paragraph);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertStringEndsWith('.', $chunk->content);
            $this->assertLessThanOrEqual(self::LONGEST, $chunk->tokencount);
        }
        $all = implode("\n", array_map(fn($chunk) => $chunk->content, $chunks));
        preg_match_all('/Propoziția (\d+) din mare/', $paragraph, $expected);
        foreach ($expected[0] as $sentence) {
            $this->assertStringContainsString($sentence, $all);
        }
    }

    /**
     * A table that is too big is cut between rows, and every part starts with the header.
     */
    public function test_big_table_repeats_its_header(): void {
        $rows = ["| Nume | Valoare |", "| --- | --- |"];
        for ($i = 1; $i <= 140; $i++) {
            $rows[] = "| rândul {$i} | valoarea {$i} |";
        }

        $chunks = $this->chunk(implode("\n", $rows));

        $all = implode("\n\n", array_map(fn($chunk) => $chunk->content, $chunks));
        $this->assertGreaterThanOrEqual(2, substr_count($all, "| Nume | Valoare |\n| --- | --- |"));
        for ($i = 1; $i <= 140; $i++) {
            $this->assertStringContainsString("| rândul {$i} | valoarea {$i} |", $all);
        }
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(self::LONGEST, $chunk->tokencount);
        }
    }

    /**
     * Text without any punctuation is cut between words, and nothing is lost.
     */
    public function test_text_without_punctuation_is_cut_between_words(): void {
        $chunks = $this->chunk(trim(str_repeat('abcde ', 1500)));

        $this->assertGreaterThan(2, count($chunks));
        $words = 0;
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(self::LONGEST, $chunk->tokencount);
            $words += substr_count($chunk->content, 'abcde');
        }
        $this->assertGreaterThanOrEqual(1500, $words);
    }

    /**
     * The pages of a chunk are the pages of its own text, and the markers are not in the text.
     */
    public function test_pages(): void {
        $text = '';
        for ($page = 1; $page <= 12; $page++) {
            $text .= "<!-- page {$page} -->\n\n" . $this->prose("pagina{$page}", 700) . "\n\n";
        }

        $chunks = $this->chunk($text);

        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame(1, $chunks[0]->pagefrom);
        $this->assertSame(12, end($chunks)->pageto);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(12, $chunk->pageto);
            $this->assertGreaterThanOrEqual(1, $chunk->pagefrom);
            $this->assertLessThanOrEqual($chunk->pagefrom + 6, $chunk->pageto);
            $this->assertStringNotContainsString('<!--', $chunk->content);
        }
        for ($i = 1; $i < count($chunks); $i++) {
            $this->assertGreaterThanOrEqual($chunks[$i - 1]->pagefrom, $chunks[$i]->pagefrom);
        }
    }

    /**
     * A title is cut to the length of the column.
     */
    public function test_long_title_is_cut(): void {
        $chunks = $this->chunk('# ' . str_repeat('Titlu lung ', 60) . "\n\nText.");

        $this->assertSame(255, mb_strlen($chunks[0]->title));
    }
}
