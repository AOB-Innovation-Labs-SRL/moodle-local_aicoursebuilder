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

use local_aicoursebuilder\ai\budget_exceeded_exception;
use local_aicoursebuilder\ai\connector;
use local_aicoursebuilder\ai\connector_exception;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\result;
use local_aicoursebuilder\ai\router;

/**
 * Tests for the digest builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ingest\digest_builder
 */
final class digest_builder_test extends \advanced_testcase {
    /** @var \stdClass Owner of the job. */
    private \stdClass $user;

    /** @var \stdClass The job. */
    private \stdClass $job;

    /** @var \stdClass The source. */
    private \stdClass $source;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultconnector', 'fake', 'local_aicoursebuilder');
        $this->user = $this->getDataGenerator()->create_user();
        $now = time();
        $this->job = (object) [
            'userid' => $this->user->id,
            'status' => 'ingesting',
            'prompt' => 'Test',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $this->job->id = $DB->insert_record('local_aicb_job', $this->job);
        $this->job = $DB->get_record('local_aicb_job', ['id' => $this->job->id], '*', MUST_EXIST);
        $this->source = (object) [
            'jobid' => $this->job->id,
            'filename' => 'energie.pdf',
            'mimetype' => 'application/pdf',
            'contenthash' => sha1('energie'),
            'status' => 'extracted',
            'language' => 'ro',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $this->source->id = $DB->insert_record('local_aicb_source', $this->source);
    }

    /**
     * Makes chunks with their index as the only difference.
     *
     * @param int $count Number of chunks.
     * @param int $tokens Tokens of each chunk.
     * @return chunk[]
     */
    private function chunks(int $count, int $tokens = 1000): array {
        $chunks = [];
        for ($i = 0; $i < $count; $i++) {
            $chunks[] = new chunk($i, $i === 0 ? 'Introducere' : null, $i + 1, $i + 2, "Conținutul chunk-ului {$i}.", $tokens);
        }
        return $chunks;
    }

    /**
     * A digest that a stub connector gives, with lists that can be changed.
     *
     * @param array $changes Keys to replace.
     * @return array
     */
    private function answer(array $changes = []): array {
        return $changes + [
            'title' => 'Energie',
            'language' => 'ro',
            'concepts' => [['name' => 'Solar', 'chunks' => [0]]],
            'definitions' => [['term' => 'Fotovoltaic', 'definition' => 'Lumină în electricitate.', 'chunks' => [0]]],
            'objectives' => ['Definește energia.'],
            'procedures' => [['title' => 'Alegere', 'steps' => ['Măsoară.', 'Compară.'], 'chunks' => [0]]],
        ];
    }

    /**
     * Makes a connector that gives the answers in order (the last one again when there are more calls).
     *
     * @param array $answers What each call gives: an array (JSON), a string (text, no JSON) or an exception.
     * @param float $cost Cost of a call.
     * @param float $estimate Estimated cost of a call.
     * @return connector The connector; its public properties calls (int) and requests (request[]) tell what it was asked.
     */
    private function connector(array $answers, float $cost = 0.0, float $estimate = 0.0): connector {
        return new class ($answers, $cost, $estimate) implements connector {
            /** @var int Calls so far. */
            public int $calls = 0;

            /** @var request[] Requests so far. */
            public array $requests = [];

            /**
             * Creates the connector.
             *
             * @param array $answers Answers in order.
             * @param float $cost Cost of a call.
             * @param float $estimate Estimated cost of a call.
             */
            public function __construct(
                /** @var array Answers in order. */
                private array $answers,
                /** @var float Cost of a call. */
                private float $cost,
                /** @var float Estimated cost of a call. */
                private float $estimate,
            ) {
            }

            #[\Override]
            public function complete(request $request): result {
                $answer = $this->answers[min($this->calls, count($this->answers) - 1)];
                $this->calls++;
                $this->requests[] = $request;
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                $content = is_array($answer) ? json_encode($answer) : $answer;
                $json = is_array($answer) ? $answer : null;
                return new result($content, $json, 100, 50, 0, $this->cost, 'stub-model', 1, 'stop', 'stub');
            }

            #[\Override]
            public function supports(string $capability): bool {
                return true;
            }

            #[\Override]
            public function estimate_cost(request $request): float {
                return $this->estimate;
            }

            #[\Override]
            public function count_tokens(string $text): int {
                return (int) ceil(strlen($text) / 4);
            }
        };
    }

    /**
     * Makes a builder that uses a connector.
     *
     * @param connector $connector The connector.
     * @return digest_builder
     */
    private function builder_for(connector $connector): digest_builder {
        $router = new class ($connector) extends router {
            /**
             * Creates the router.
             *
             * @param connector $connector The connector of every step.
             */
            public function __construct(
                /** @var connector The connector of every step. */
                private connector $connector,
            ) {
            }

            #[\Override]
            public function for_step(string $step): connector {
                return $this->connector;
            }

            #[\Override]
            public function get_route(string $step): array {
                return ['connector' => 'stub', 'model' => 'stub-model'];
            }
        };
        return new digest_builder($router);
    }

    /**
     * Returns the cost of the job so far.
     *
     * @return float
     */
    private function job_cost(): float {
        global $DB;
        return (float) $DB->get_field('local_aicb_job', 'actualcost', ['id' => $this->job->id]);
    }

    /**
     * Returns the checkpoint rows of the job.
     *
     * @return \stdClass[]
     */
    private function steps(): array {
        global $DB;
        return array_values($DB->get_records('local_aicb_step', ['jobid' => $this->job->id], 'id'));
    }

    /**
     * The digest comes from the connector of the digest step, here the fake one with its fixture, and is saved as a
     * checkpoint.
     */
    public function test_digest_from_the_fake_connector(): void {
        $digest = (new digest_builder())->build($this->job, $this->source, $this->chunks(3));

        $this->assertSame('src' . $this->source->id, $digest['source']);
        $this->assertSame('Energia regenerabilă — material introductiv', $digest['title']);
        $this->assertSame('ro', $digest['language']);
        $this->assertCount(4, $digest['concepts']);
        $this->assertSame(['name' => 'Energie solară', 'chunks' => [1]], $digest['concepts'][1]);
        $this->assertCount(3, $digest['definitions']);
        $this->assertSame('Fotovoltaic', $digest['definitions'][1]['term']);
        $this->assertCount(3, $digest['objectives']);
        $steps = ['Evaluează clima și relieful.', 'Estimează consumul anual.', 'Compară costul surselor potrivite.'];
        $this->assertSame($steps, $digest['procedures'][0]['steps']);

        $steps = $this->steps();
        $this->assertCount(1, $steps);
        $this->assertSame('digest', $steps[0]->step);
        $this->assertSame('s' . $this->source->id, $steps[0]->nodekey);
        $this->assertSame('done', $steps[0]->status);
        $this->assertSame('v1', $steps[0]->promptversion);
        $this->assertSame('fake', $steps[0]->connector);
        $this->assertGreaterThan(0, $steps[0]->tokensin);
        $this->assertSame(64, strlen($steps[0]->inputhash));
    }

    /**
     * The references to chunks that were not sent are dropped.
     */
    public function test_references_to_unknown_chunks_are_dropped(): void {
        $digest = (new digest_builder())->build($this->job, $this->source, $this->chunks(1));

        $this->assertSame(['name' => 'Energie solară', 'chunks' => []], $digest['concepts'][1]);
        $this->assertSame([0], $digest['concepts'][0]['chunks']);
    }

    /**
     * The request names the language, the limits and the chunks with their pages and titles, and asks for the schema.
     */
    public function test_request(): void {
        $connector = $this->connector([$this->answer()]);

        $this->builder_for($connector)->build($this->job, $this->source, $this->chunks(2));

        $request = $connector->requests[0];
        $this->assertSame(request::STEP_DIGEST, $request->step);
        $this->assertStringContainsString('Romanian', $request->system);
        $this->assertStringContainsString(
            'at most 40 concepts, 40 definitions, 15 learning objectives and 15 procedures',
            $request->system
        );
        $this->assertStringNotContainsString('{{', $request->system);
        $text = $request->messages[0]['content'];
        $this->assertStringContainsString('Document: energie.pdf', $text);
        $this->assertStringContainsString("<<< chunk 0 | pages 1-2 | title: Introducere >>>\nConținutul chunk-ului 0.", $text);
        $this->assertStringContainsString("<<< chunk 1 | pages 2-3 >>>\nConținutul chunk-ului 1.", $text);
        $this->assertSame('object', $request->schema['type']);
        $this->assertArrayNotHasKey('$schema', $request->schema);
        $this->assertSame(digest_builder::MAX_OUTPUT_TOKENS, $request->maxtokens);
        $this->assertEquals($this->job->id, $request->jobid);
        $this->assertEquals($this->user->id, $request->userid);
    }

    /**
     * A repeated run finds the answer in the checkpoint and does not call the connector; other chunks are another input.
     */
    public function test_checkpoint_is_reused(): void {
        $connector = $this->connector([$this->answer()]);
        $builder = $this->builder_for($connector);

        $first = $builder->build($this->job, $this->source, $this->chunks(2));
        $second = $builder->build($this->job, $this->source, $this->chunks(2));

        $this->assertSame(1, $connector->calls);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->steps());

        $builder->build($this->job, $this->source, $this->chunks(3));
        $this->assertSame(2, $connector->calls);
        $this->assertCount(2, $this->steps());
    }

    /**
     * The cost of a call is added to the job, settled in the budget of the user and of the site, and kept in the step.
     */
    public function test_cost_is_recorded(): void {
        global $DB;
        $connector = $this->connector([$this->answer()], 0.10, 0.20);

        $this->builder_for($connector)->build($this->job, $this->source, $this->chunks(1));

        $this->assertEqualsWithDelta(0.10, $this->job_cost(), 0.000001);
        $step = $this->steps()[0];
        $this->assertEqualsWithDelta(0.10, (float) $step->cost, 0.000001);
        $this->assertEquals(100, $step->tokensin);
        $this->assertEquals(50, $step->tokensout);
        $this->assertSame('stub', $step->connector);
        $this->assertSame('stub-model', $step->model);
        foreach ([$this->user->id, 0] as $userid) {
            $row = $DB->get_record('local_aicb_budget', ['userid' => $userid, 'period' => gmdate('Y-m')], '*', MUST_EXIST);
            $this->assertEqualsWithDelta(0.10, (float) $row->spentusd, 0.000001);
            $this->assertEqualsWithDelta(0.0, (float) $row->reservedusd, 0.000001);
        }
    }

    /**
     * A call that would pass the limit of the job is not sent, and nothing stays reserved.
     */
    public function test_budget_exceeded_blocks_the_call(): void {
        global $DB;
        set_config('joblimitusd', '0.50', 'local_aicoursebuilder');
        $connector = $this->connector([$this->answer()], 0.0, 1.0);

        try {
            $this->builder_for($connector)->build($this->job, $this->source, $this->chunks(1));
            $this->fail('Expected a budget_exceeded_exception');
        } catch (budget_exceeded_exception $e) {
            $this->assertSame(0, $connector->calls);
        }
        $this->assertSame('failed', $this->steps()[0]->status);
        $this->assertNotEmpty($this->steps()[0]->error);
        $this->assertFalse($DB->record_exists_select('local_aicb_budget', 'reservedusd <> 0'));
    }

    /**
     * A connector that fails passes its error on, the step is failed and the reservation is released.
     */
    public function test_connector_failure(): void {
        global $DB;
        $connector = $this->connector([new connector_exception(connector_exception::NETWORK_ERROR)], 0.0, 0.3);

        try {
            $this->builder_for($connector)->build($this->job, $this->source, $this->chunks(1));
            $this->fail('Expected a connector_exception');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::NETWORK_ERROR, $e->errorcode);
        }
        $this->assertSame('failed', $this->steps()[0]->status);
        $this->assertFalse($DB->record_exists_select('local_aicb_budget', 'reservedusd <> 0'));
        $this->assertEqualsWithDelta(0.0, $this->job_cost(), 0.000001);
    }

    /**
     * An answer that is not a digest is an error, the call is still paid, and a new try makes a new call.
     *
     * @dataProvider invalid_answers_provider
     * @param mixed $answer What the connector gives.
     */
    public function test_invalid_answer(mixed $answer): void {
        global $DB;
        $connector = $this->connector([$answer, $this->answer()], 0.25);
        $builder = $this->builder_for($connector);

        try {
            $builder->build($this->job, $this->source, $this->chunks(1));
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::DIGEST_INVALID, $e->errorcode);
        }
        $this->assertSame('failed', $this->steps()[0]->status);
        $this->assertEqualsWithDelta(0.25, $this->job_cost(), 0.000001);

        $digest = $builder->build($this->job, $this->source, $this->chunks(1));
        $this->assertSame(2, $connector->calls);
        $this->assertSame('Energie', $digest['title']);
        $steps = $this->steps();
        $this->assertCount(1, $steps);
        $this->assertSame('done', $steps[0]->status);
        $this->assertEquals(2, $steps[0]->attempts);
    }

    /**
     * Data provider: answers that are not a usable digest.
     *
     * @return array
     */
    public static function invalid_answers_provider(): array {
        return [
            'text, not JSON' => ['Nu pot face asta.'],
            'empty object' => [[]],
            'all lists empty' => [['title' => 'X', 'concepts' => [], 'definitions' => [], 'objectives' => [], 'procedures' => []]],
            'items without names' => [['concepts' => [['name' => ' ', 'chunks' => [0]]], 'definitions' => [['term' => 'T']]]],
        ];
    }

    /**
     * Text is trimmed, incomplete items are dropped, and every list is cut to its limit.
     */
    public function test_items_are_cleaned_and_limited(): void {
        $concepts = [['name' => '   ', 'chunks' => [0]], ['name' => "  Cu   spații \n multe ", 'chunks' => [0, '0', 'x', 7]]];
        for ($i = 1; $i <= 60; $i++) {
            $concepts[] = ['name' => "Concept {$i}", 'chunks' => [0]];
        }
        $answer = $this->answer([
            'title' => '  Titlu   cu spații ',
            'language' => 'RO',
            'concepts' => $concepts,
            'definitions' => [['term' => 'Fără definiție'], ['term' => 'Bun', 'definition' => ' O definiție. ', 'chunks' => 'nu']],
            'objectives' => ['  Primul.  ', '', 5, ['nu'], 'Al doilea.'],
            'procedures' => [['title' => 'Fără pași', 'steps' => []], ['title' => 'Cu pași', 'steps' => [' unu ', '', 'doi']]],
        ]);

        $digest = $this->builder_for($this->connector([$answer]))->build($this->job, $this->source, $this->chunks(1));

        $this->assertSame('Titlu cu spații', $digest['title']);
        $this->assertSame('ro', $digest['language']);
        $this->assertCount(digest_builder::LIMITS['concepts'], $digest['concepts']);
        $this->assertSame(['name' => 'Cu spații multe', 'chunks' => [0]], $digest['concepts'][0]);
        $this->assertSame([['term' => 'Bun', 'definition' => 'O definiție.', 'chunks' => []]], $digest['definitions']);
        $this->assertSame(['Primul.', '5', 'Al doilea.'], $digest['objectives']);
        $this->assertSame([['title' => 'Cu pași', 'steps' => ['unu', 'doi'], 'chunks' => []]], $digest['procedures']);
    }

    /**
     * The title falls back to the file name and the language to the one of the source.
     */
    public function test_title_and_language_fall_back(): void {
        $answer = $this->answer(['title' => '', 'language' => 'nu este cod']);

        $digest = $this->builder_for($this->connector([$answer]))->build($this->job, $this->source, $this->chunks(1));

        $this->assertSame('energie', $digest['title']);
        $this->assertSame('ro', $digest['language']);
    }

    /**
     * A document that does not fit in one call is sent in windows of chunks, and the digests are merged.
     */
    public function test_long_document_is_sent_in_windows(): void {
        set_config('digest_maxinputtokens', '2500', 'local_aicoursebuilder');
        $connector = $this->connector([
            $this->answer([
                'title' => 'Primul',
                'concepts' => [['name' => 'A', 'chunks' => [0]], ['name' => 'Comun', 'chunks' => [0]]],
                'objectives' => ['Același obiectiv.', 'Obiectiv A.'],
            ]),
            $this->answer([
                'title' => 'Al doilea',
                'concepts' => [['name' => 'comun', 'chunks' => [1]], ['name' => 'B', 'chunks' => [1, 0]]],
                'objectives' => ['același obiectiv.'],
            ]),
            $this->answer(['title' => '', 'concepts' => [['name' => 'C', 'chunks' => [2]]], 'objectives' => []]),
        ]);

        $digest = $this->builder_for($connector)->build($this->job, $this->source, $this->chunks(3, 1500));

        $this->assertSame(3, $connector->calls);
        $this->assertStringContainsString('<<< chunk 1', $connector->requests[1]->messages[0]['content']);
        $this->assertStringNotContainsString('<<< chunk 0', $connector->requests[1]->messages[0]['content']);
        $this->assertSame('Primul', $digest['title']);
        $this->assertSame(['A', 'Comun', 'B', 'C'], array_column($digest['concepts'], 'name'));
        $this->assertSame([0, 1], $digest['concepts'][1]['chunks']);
        $this->assertSame([1], $digest['concepts'][2]['chunks']);
        $this->assertSame(['Același obiectiv.', 'Obiectiv A.'], $digest['objectives']);
        $nodekeys = array_map(fn($window) => 's' . $this->source->id . 'w' . $window, [0, 1, 2]);
        $this->assertSame($nodekeys, array_column($this->steps(), 'nodekey'));
    }

    /**
     * A source without chunks cannot have a digest.
     */
    public function test_no_chunks(): void {
        $connector = $this->connector([$this->answer()]);

        try {
            $this->builder_for($connector)->build($this->job, $this->source, []);
            $this->fail('Expected an ingest_exception');
        } catch (ingest_exception $e) {
            $this->assertSame(ingest_exception::DIGEST_INVALID, $e->errorcode);
        }
        $this->assertSame(0, $connector->calls);
    }
}
