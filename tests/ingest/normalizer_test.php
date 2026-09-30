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
 * Tests for the normaliser.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\normalizer
 * @covers     \local_aicoursebuilder\ingest\normalization_result
 */
final class normalizer_test extends \basic_testcase {
    /**
     * Normalises a text.
     *
     * @param string $text The text.
     * @return normalization_result
     */
    private function normalize(string $text): normalization_result {
        return (new normalizer())->normalize($text);
    }

    /**
     * Builds a text of pages, each with the same running header and footer and a page number.
     *
     * @param int $pages Number of pages.
     * @return string Text with page markers.
     */
    private function paged_text(int $pages): string {
        $topics = ['elevii', 'profesorii', 'părinții', 'curriculumul', 'evaluarea', 'resursele', 'comunitatea', 'siguranța'];
        $parts = [];
        for ($page = 1; $page <= $pages; $page++) {
            $topic = $topics[($page - 1) % count($topics)] . ' (partea ' . chr(96 + $page) . ')';
            $parts[] = "<!-- page {$page} -->\n\nRaportul anual al școlii 2024   {$page}\n\n"
                . "Acest fragment vorbește despre {$topic} și explică, pe larg, cum se lucrează cu ei.\n"
                . "A doua propoziție, tot despre {$topic}, continuă ideea începută mai sus, "
                . "cu alte cuvinte decât pagina {$page}.\n\n"
                . "Școala Gimnazială Exemplu\n{$page}";
        }
        return implode("\n\n", $parts);
    }

    /**
     * Control characters, soft hyphens, zero-width and no-break spaces and ligatures are cleaned, and the
     * Romanian letters with a cedilla become the ones with a comma below.
     */
    public function test_clean_characters(): void {
        $result = $this->normalize("Ştefan şi Ţicu\x1F citesc\u{00A0}despre\u{200B} e\u{FB01}cien\u{021B}\u{0103}.\n");

        $this->assertSame('Ștefan și Țicu citesc despre eficiență.', $result->markdown);
    }

    /**
     * A word hyphenated at the end of a line, with a hyphen or a soft hyphen, is joined again.
     */
    public function test_hyphenation_and_soft_hyphen(): void {
        $text = "Educația incluzivă presupune o abordare educa-\nțională sensibilă și o colaborare strânsă cu fami\u{00AD}\nlia "
            . "elevului.\n";

        $markdown = $this->normalize($text)->markdown;

        $this->assertStringContainsString('abordare educațională sensibilă', $markdown);
        $this->assertStringContainsString('cu familia elevului', $markdown);
    }

    /**
     * The lines of a paragraph that were wrapped are joined; a new sentence or a short line starts a new paragraph.
     */
    public function test_reflows_paragraphs(): void {
        $lines = [
            'Înainte de a aborda în detaliu modelele și practicile',
            'în domeniul incluziunii în educație, este important să',
            'vorbim despre diversitate, o realitate naturală a lumii',
            'contemporane. Este o realitate imposibil de ignorat.',
            'Un paragraf nou începe aici și se termină repede.',
            '',
            'Alt paragraf, după o linie goală.',
        ];

        $markdown = $this->normalize(implode("\n", $lines))->markdown;

        $this->assertSame([
            'Înainte de a aborda în detaliu modelele și practicile în domeniul incluziunii în educație, este important '
                . 'să vorbim despre diversitate, o realitate naturală a lumii contemporane. Este o realitate imposibil de ignorat.',
            'Un paragraf nou începe aici și se termină repede.',
            'Alt paragraf, după o linie goală.',
        ], explode("\n\n", $markdown));
    }

    /**
     * Bullets of every kind become "- " items; a line that goes on with an item joins it.
     */
    public function test_bullets_become_list_items(): void {
        $text = "Drepturile copilului:\n• Dreptul la educație, alături de o\nabordare sistematică.\n"
            . "● Dreptul la o educație de calitate.\n– Dreptul la participare.\n";

        $markdown = $this->normalize($text)->markdown;

        $this->assertSame(
            "Drepturile copilului:\n\n- Dreptul la educație, alături de o abordare sistematică.\n"
                . "- Dreptul la o educație de calitate.\n- Dreptul la participare.",
            $markdown
        );
    }

    /**
     * The header, the footer and the page number that repeat on every page are removed; the real content stays.
     */
    public function test_running_headers_and_footers_are_removed(): void {
        $result = $this->normalize($this->paged_text(8));

        $this->assertSame(8 * 3 - 2, $result->stats['headers']);
        $this->assertSame(1, substr_count($result->markdown, 'Raportul anual al școlii'));
        $this->assertSame(1, substr_count($result->markdown, 'Școala Gimnazială Exemplu'));
        for ($page = 1; $page <= 8; $page++) {
            $this->assertStringContainsString("cu alte cuvinte decât pagina {$page}.", $result->markdown);
            $this->assertStringContainsString("<!-- page {$page} -->", $result->markdown);
        }
        $this->assertDoesNotMatchRegularExpression('/^\d{1,2}$/m', $result->markdown);
    }

    /**
     * A text with fewer than three pages keeps its first and last lines, except a bare page number.
     */
    public function test_short_texts_keep_their_edges(): void {
        $text = "<!-- page 1 -->\n\nTitlul documentului scurt\n\nUn text.\n\n2\n\n"
            . "<!-- page 2 -->\n\nTitlul documentului scurt\n\nAlt text.";

        $result = $this->normalize($text);

        $this->assertSame(2, substr_count($result->markdown, 'Titlul documentului scurt'));
        $this->assertStringNotContainsString("\n2\n", "\n" . $result->markdown . "\n");
    }

    /**
     * Numbered titles, chapter titles and lines in capitals become headings of level 1 to 3 in a text that had none.
     */
    public function test_headings_are_found_in_unstructured_text(): void {
        $text = "CAPITOLUL 1\n\n1. Introducere\n\nUn paragraf despre introducere care se termină cu punct.\n\n"
            . "1.1 Scopul lucrării\n\nUn alt paragraf, tot cu punct la final.\n\n"
            . "1.1.1 Obiective specifice\n\nText despre obiective.\n\n"
            . "2. Metodologie\n\nText despre metodologie.\n\nSECȚIUNEA 3 – REZULTATE\n\nText despre rezultate.";

        $result = $this->normalize($text);

        $headings = array_values(array_filter(explode("\n", $result->markdown), fn($line) => str_starts_with($line, '#')));
        $this->assertSame([
            '# CAPITOLUL 1',
            '# 1. Introducere',
            '## 1.1 Scopul lucrării',
            '### 1.1.1 Obiective specifice',
            '# 2. Metodologie',
            '## SECȚIUNEA 3 – REZULTATE',
        ], $headings);
        $this->assertSame(6, $result->stats['headings']);
    }

    /**
     * A sentence, a long line or a line that ends with punctuation is not a heading.
     */
    public function test_sentences_are_not_headings(): void {
        $text = "Aceasta este o propoziție obișnuită care se termină cu punct.\n\n"
            . "2. Un element al unei liste care se termină cu punct.\n\n"
            . "Text.\n\nUNICEF\n\nDar ce este aceasta?";

        $result = $this->normalize($text);

        $this->assertStringNotContainsString('#', $result->markdown);
        $this->assertSame(0, $result->stats['headings']);
    }

    /**
     * Lines in capitals that follow each other are one heading.
     */
    public function test_capital_lines_in_a_row_are_one_heading(): void {
        $result = $this->normalize("SET DE INSTRUMENTE PENTRU\nVERIFICAREA COMPETENȚEI\n\nUn text despre set.");

        $this->assertSame("## SET DE INSTRUMENTE PENTRU VERIFICAREA COMPETENȚEI\n\nUn text despre set.", $result->markdown);
    }

    /**
     * The headings that an extractor made are kept, nothing else is turned into a heading, and deep levels become H3.
     */
    public function test_existing_structure_is_kept(): void {
        $body = "O linie scurtă fără punct\n\n1. Element de listă\n\n- punct\n- alt punct\n\n> citat";

        $result = $this->normalize("# Titlu\n\n#### Subsubsubtitlu adânc\n\n" . $body);

        $this->assertSame("# Titlu\n\n### Subsubsubtitlu adânc\n\n" . $body, $result->markdown);
        $this->assertSame(0, $result->stats['headings']);
    }

    /**
     * Markdown tables from the extractors are kept as they are.
     */
    public function test_markdown_tables_are_kept(): void {
        $table = "| Rol | Drepturi |\n| --- | --- |\n| Cursant | Citire |\n| Profesor | Editare |";

        $text = "Text înainte.\n\n{$table}\n\nText după.";

        $this->assertSame($text, $this->normalize($text)->markdown);
    }

    /**
     * Text aligned in columns (what pdftotext -layout makes of a table) becomes a Markdown table.
     */
    public function test_aligned_text_becomes_a_table(): void {
        $text = "Tabelul competențelor.\n\nCompetența        Nivel      Verificat\nAlăptarea         Avansat    Da\n"
            . "Igiena mâinilor   Mediu      Nu\nProfilaxia        Avansat    Da\n\nUn text după tabel.";

        $result = $this->normalize($text);

        $this->assertStringContainsString(
            "| Competența | Nivel | Verificat |\n| --- | --- | --- |\n| Alăptarea | Avansat | Da |\n"
                . "| Igiena mâinilor | Mediu | Nu |\n| Profilaxia | Avansat | Da |",
            $result->markdown
        );
        $this->assertSame(1, $result->stats['tables']);
        $this->assertStringContainsString("\n\nUn text după tabel.", $result->markdown);
    }

    /**
     * Two aligned lines are not a table.
     */
    public function test_two_aligned_lines_are_not_a_table(): void {
        $result = $this->normalize("Nume        Valoare\nAlfa        10\n\nAlt text.");

        $this->assertStringNotContainsString('|', $result->markdown);
        $this->assertSame(0, $result->stats['tables']);
    }

    /**
     * A long paragraph that occurs again is removed, and so is a heading that repeats the one before it; short
     * paragraphs that repeat stay.
     */
    public function test_duplicates_are_removed(): void {
        $long = 'Acest paragraf lung apare de două ori în document, cu exact aceleași cuvinte, și doar prima apariție '
            . 'trebuie să rămână în textul normalizat pentru că a doua nu aduce nimic nou.';
        $text = "# Titlu\n\n{$long}\n\nDa.\n\n## Secțiune\n\n## Secțiune\n\nAltceva.\n\nDa.\n\n{$long}";

        $result = $this->normalize($text);

        $this->assertSame(1, substr_count($result->markdown, 'Acest paragraf lung'));
        $this->assertSame(1, substr_count($result->markdown, '## Secțiune'));
        $this->assertSame(2, substr_count($result->markdown, "\n\nDa."));
        $this->assertSame(2, $result->stats['duplicates']);
    }

    /**
     * Page markers are kept when the source had them, and none is made when it had not.
     */
    public function test_page_markers(): void {
        $paged = $this->normalize("<!-- page 4 -->\n\nPrima pagină.\n\n<!-- page 5 -->\n\n\n\n<!-- page 6 -->\n\nA treia pagină.");
        $this->assertSame("<!-- page 4 -->\n\nPrima pagină.\n\n<!-- page 6 -->\n\nA treia pagină.", $paged->markdown);

        $this->assertStringNotContainsString('<!--', $this->normalize("Un text fără pagini.\n\nAl doilea paragraf.")->markdown);
    }

    /**
     * Normalising the output again changes nothing.
     */
    public function test_normalisation_is_stable(): void {
        $first = $this->normalize($this->paged_text(6) . "\n\n• un punct\n• alt punct\n\n1. Titlu nou\n\nText.");

        $this->assertSame($first->markdown, $this->normalize($first->markdown)->markdown);
    }

    /**
     * The language is found from the stop words; a text that is too short or too mixed is undetermined.
     */
    public function test_language_detection(): void {
        $samples = [
            'ro' => 'Educația este o realitate importantă pentru toți copiii care învață în școlile din România și care au '
                . 'nevoie de sprijin din partea profesorilor, dar și a părinților lor, sau a altor persoane din comunitate.',
            'en' => 'The education of children is an important reality for all of them that learn in the schools of the '
                . 'country, and they need the support of their teachers, but also of their parents and of the community.',
            'fr' => 'Les enfants qui sont dans les écoles de la ville ont besoin du soutien de leurs professeurs et des '
                . 'parents, mais aussi de la communauté dans laquelle ils vivent, pour que tous puissent apprendre.',
            'de' => 'Die Kinder in den Schulen der Stadt brauchen die Unterstützung von ihren Lehrern und den Eltern, '
                . 'aber auch von der Gemeinschaft, in der sie leben, damit alle lernen können und nicht allein sind.',
        ];
        foreach ($samples as $language => $text) {
            $this->assertSame($language, $this->normalize($text)->language, $language);
        }

        $this->assertSame(normalization_result::LANGUAGE_UNDETERMINED, $this->normalize('Scurt.')->language);
        $this->assertSame(normalization_result::LANGUAGE_UNDETERMINED, $this->normalize('')->language);
    }
}
