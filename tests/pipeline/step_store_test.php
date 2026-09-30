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

use local_aicoursebuilder\ai\request;

/**
 * Tests of the step hash and of what the store will and will not hand back.
 *
 * The hash is the whole resume mechanism: what it includes decides what a job pays for twice.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\pipeline\step_store
 * @covers     \local_aicoursebuilder\pipeline\step_result
 */
final class step_store_test extends \advanced_testcase {
    /** @var array The context of a hash: prompt version, connector, model and schema version. */
    private const CONTEXT = [
        'promptversion' => 'v1',
        'connector' => 'fake',
        'model' => 'fake',
        'schemaversion' => '1.0',
    ];

    /** @var int Test job id. */
    private int $jobid;

    /** @var step_store The store under test. */
    private step_store $store;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $DB;

        $this->store = new step_store();
        $this->jobid = (int) $DB->insert_record('local_aicb_job', (object) [
            'userid' => (int) $this->getDataGenerator()->create_user()->id,
            'mode' => 'newcourse',
            'status' => 'generating',
            'prompt' => 'x',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the hash of a step, with the standard context unless one is given.
     *
     * @param array $input The input of the step.
     * @param array $context Overrides of the standard context.
     * @param string $nodekey Sub-call key.
     * @return string
     */
    private function hash(array $input, array $context = [], string $nodekey = ''): string {
        return step_store::hash(
            $this->jobid,
            request::STEP_OUTLINE,
            $nodekey,
            $input,
            $context + self::CONTEXT,
        );
    }

    /**
     * The same input hashes the same however its keys happen to be ordered.
     */
    public function test_the_hash_ignores_key_order(): void {
        $this->assertSame(
            $this->hash(['a' => 1, 'b' => ['c' => 2, 'd' => 3]]),
            $this->hash(['b' => ['d' => 3, 'c' => 2], 'a' => 1]),
        );
    }

    /**
     * The order of a list is content, not incidental, so it does change the hash.
     */
    public function test_the_hash_respects_list_order(): void {
        $this->assertNotSame($this->hash(['a' => [1, 2]]), $this->hash(['a' => [2, 1]]));
    }

    /**
     * Everything that could change the answer changes the hash.
     *
     * @dataProvider hash_input_provider
     * @param array $context Overrides of the standard context.
     * @param array $input The input.
     * @param string $nodekey Sub-call key.
     */
    public function test_the_hash_covers_everything_that_changes_the_answer(
        array $context,
        array $input,
        string $nodekey,
    ): void {
        $baseline = $this->hash(['prompt' => 'x']);

        $this->assertNotSame($baseline, $this->hash($input, $context, $nodekey));
    }

    /**
     * The things that must each produce a different hash.
     *
     * @return array[]
     */
    public static function hash_input_provider(): array {
        return [
            'a different input' => [[], ['prompt' => 'y'], ''],
            'a different prompt version' => [['promptversion' => 'v2'], ['prompt' => 'x'], ''],
            'a different model' => [['model' => 'deepseek-v4'], ['prompt' => 'x'], ''],
            'a different connector' => [['connector' => 'deepseek'], ['prompt' => 'x'], ''],
            'a different schema version' => [['schemaversion' => '2.0'], ['prompt' => 'x'], ''],
            'a different sub-call' => [[], ['prompt' => 'x'], 's2'],
        ];
    }

    /**
     * A job of its own: two jobs never share a step, even given identical input.
     */
    public function test_the_hash_is_per_job(): void {
        global $DB;

        $other = (int) $DB->insert_record('local_aicb_job', (object) [
            'userid' => (int) $this->getDataGenerator()->create_user()->id,
            'mode' => 'newcourse',
            'status' => 'generating',
            'prompt' => 'x',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->assertNotSame(
            step_store::hash($this->jobid, request::STEP_OUTLINE, '', ['a' => 1], self::CONTEXT),
            step_store::hash($other, request::STEP_OUTLINE, '', ['a' => 1], self::CONTEXT),
        );
    }

    /**
     * A finished step is handed back; one that never finished is not.
     */
    public function test_only_a_finished_step_is_handed_back(): void {
        $hash = $this->hash(['prompt' => 'x']);
        $id = $this->store->start($this->jobid, request::STEP_OUTLINE, '', $hash, self::CONTEXT);

        $this->assertNull($this->store->find_output($this->jobid, $hash), 'a running step is not reused');

        $this->store->finish($id, new step_result(output: ['sections' => []], tokensin: 10, tokensout: 5, cost: 0.5));

        $this->assertSame(['sections' => []], $this->store->find_output($this->jobid, $hash));
    }

    /**
     * A failed step is not handed back either: it has nothing worth reusing.
     */
    public function test_a_failed_step_is_not_handed_back(): void {
        $hash = $this->hash(['prompt' => 'x']);
        $id = $this->store->start($this->jobid, request::STEP_OUTLINE, '', $hash, self::CONTEXT);
        $this->store->finish($id, step_result::failed('boom'));

        $this->assertNull($this->store->find_output($this->jobid, $hash));
        $this->assertSame(step_store::STATUS_ERROR, $this->store->find($this->jobid, $hash)->status);
    }

    /**
     * A node left for a human is handed back: its placeholder is what the pipeline goes on with.
     */
    public function test_a_manual_node_is_handed_back(): void {
        $hash = $this->hash(['prompt' => 'x']);
        $id = $this->store->start($this->jobid, request::STEP_SECTIONS, 's1', $hash, self::CONTEXT);
        $this->store->finish($id, step_result::needs_manual(['id' => 's1', 'activities' => []], []));

        $this->assertSame(['id' => 's1', 'activities' => []], $this->store->find_output($this->jobid, $hash));
        $this->assertSame(step_store::STATUS_MANUAL, $this->store->find($this->jobid, $hash)->status);
    }

    /**
     * Starting a step again counts the attempt instead of adding a second row.
     */
    public function test_restarting_a_step_counts_the_attempt(): void {
        global $DB;

        $hash = $this->hash(['prompt' => 'x']);
        $first = $this->store->start($this->jobid, request::STEP_OUTLINE, '', $hash, self::CONTEXT);
        $second = $this->store->start($this->jobid, request::STEP_OUTLINE, '', $hash, self::CONTEXT);

        $this->assertSame($first, $second);
        $this->assertSame(1, $DB->count_records('local_aicb_step', ['jobid' => $this->jobid]));
        $this->assertSame(2, (int) $this->store->find($this->jobid, $hash)->attempts);
    }

    /**
     * The totals add up what every step of the job spent.
     */
    public function test_totals(): void {
        foreach ([[10, 5, 0.25], [20, 8, 0.5]] as $index => [$in, $out, $cost]) {
            $hash = $this->hash(['prompt' => 'x' . $index]);
            $id = $this->store->start($this->jobid, request::STEP_SECTIONS, "s{$index}", $hash, self::CONTEXT);
            $this->store->finish($id, new step_result(output: ['a' => 1], tokensin: $in, tokensout: $out, cost: $cost));
        }

        $totals = $this->store->totals($this->jobid);

        $this->assertSame(30, $totals['tokensin']);
        $this->assertSame(13, $totals['tokensout']);
        $this->assertEqualsWithDelta(0.75, $totals['cost'], 0.0001);
    }

    /**
     * The totals of a job with no steps are zero, not an error.
     */
    public function test_totals_of_an_untouched_job(): void {
        $this->assertSame(
            ['tokensin' => 0, 'tokensout' => 0, 'tokenscached' => 0, 'cost' => 0.0],
            $this->store->totals($this->jobid),
        );
    }

    /**
     * A resumed result carries no cost: the step was paid for when it first ran.
     */
    public function test_a_resumed_result_costs_nothing(): void {
        $result = step_result::resumed(['sections' => []]);

        $this->assertTrue($result->resumed);
        $this->assertTrue($result->is_success());
        $this->assertSame(0.0, $result->cost);
        $this->assertSame(0, $result->calls);
    }
}
