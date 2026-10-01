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

use local_aicoursebuilder\ai\budget_exceeded_exception;
use local_aicoursebuilder\ai\budget_guard;
use local_aicoursebuilder\ai\fake_connector;
use local_aicoursebuilder\ai\fake_lock_factory;
use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\router;
use local_aicoursebuilder\blueprint\node_tree;
use local_aicoursebuilder\blueprint\schema_store;
use local_aicoursebuilder\blueprint\validator;
use local_aicoursebuilder\blueprint\version_store;
use local_aicoursebuilder\task\regenerate_node as regenerate_task;

/**
 * Verifies isolated regeneration, external references, immutable versions and budget refusal.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\pipeline\regenerator
 * @covers     \local_aicoursebuilder\pipeline\step_regenerate
 * @covers     \local_aicoursebuilder\blueprint\node_tree
 * @covers     \local_aicoursebuilder\blueprint\version_store
 */
final class regenerator_test extends \advanced_testcase {
    /** @var int Owner id. */
    private int $userid;

    /** @var int Job id. */
    private int $jobid;

    /** @var \stdClass Source blueprint row. */
    private \stdClass $source;

    /** @var array Decoded source blueprint. */
    private array $blueprint;

    /** @var fake_connector Programmed connector. */
    private fake_connector $connector;

    /**
     * Creates a valid source blueprint and a fake AI route.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $DB;

        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->jobid = (int) $DB->insert_record('local_aicb_job', (object) [
            'userid' => $this->userid,
            'mode' => 'newcourse',
            'status' => 'review',
            'prompt' => 'Curs de probă',
            'language' => 'ro',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->blueprint = json_decode(
            file_get_contents(dirname(__DIR__) . '/fixtures/blueprint_golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->source = (new version_store())->save(
            $this->jobid,
            $this->userid,
            $this->blueprint,
            new validator()
        );
        foreach (request::STEPS as $step) {
            set_config("route_{$step}_connector", fake_connector::NAME, 'local_aicoursebuilder');
        }
        set_config('defaultconnector', fake_connector::NAME, 'local_aicoursebuilder');
        set_config('joblimitusd', '0', 'local_aicoursebuilder');
        set_config('userlimitusd', '0', 'local_aicoursebuilder');
        set_config('sitelimitusd', '0', 'local_aicoursebuilder');
        $this->connector = new fake_connector();
        router::set_test_connector($this->connector);
    }

    /**
     * Removes the test connector.
     */
    protected function tearDown(): void {
        router::set_test_connector(null);
        parent::tearDown();
    }

    /**
     * Creates a context with the fake budget guard.
     *
     * @return pipeline_context
     */
    private function context(): pipeline_context {
        return new pipeline_context(
            jobid: $this->jobid,
            userid: $this->userid,
            language: 'ro',
            router: new router(),
            budget: new budget_guard(new fake_lock_factory()),
            steps: new step_store(),
            validator: new validator(),
            schemas: new schema_store(),
        );
    }

    /**
     * Asserts the serialized draft differs only in the exact bytes of the target subtree.
     *
     * @param string $source Serialized source blueprint.
     * @param string $draft Serialized draft blueprint.
     * @param array $oldnode Original target subtree.
     * @param array $newnode Replacement target subtree.
     */
    private function assert_only_subtree_bytes_changed(string $source, string $draft, array $oldnode, array $newnode): void {
        $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        $oldjson = json_encode($oldnode, $options);
        $newjson = json_encode($newnode, $options);
        $this->assertSame(1, substr_count($source, $oldjson));
        $this->assertSame(str_replace($oldjson, $newjson, $source), $draft);
    }

    /**
     * A section referenced from another section keeps its required ids and all outside bytes.
     */
    public function test_section_preserves_external_references_and_approved_source(): void {
        global $DB;

        $DB->set_field('local_aicb_blueprint', 'status', 'approved', ['id' => $this->source->id]);
        $path = node_tree::locate($this->blueprint, 's1');
        $node = node_tree::get($this->blueprint, $path);
        $node['activities'][1]['content']['text'] = '<p>Explicație simplificată.</p>';
        $this->connector->push(request::STEP_SECTIONS, ['node' => $node], 's1');

        $draft = (new regenerator())->run(
            $this->jobid,
            $this->source->id,
            's1',
            'Simplifică explicația',
            $this->context()
        );
        $result = json_decode($draft->content, true);

        $this->assertSame(2, (int) $draft->version);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('approved', $DB->get_field('local_aicb_blueprint', 'status', ['id' => $this->source->id]));
        $this->assertSame(
            $this->source->content,
            $DB->get_field('local_aicb_blueprint', 'content', ['id' => $this->source->id])
        );
        $this->assertSame(json_encode($this->blueprint['sections'][1]), json_encode($result['sections'][1]));
        $this->assertSame(json_encode($this->blueprint['sections'][2]), json_encode($result['sections'][2]));
        $this->assert_only_subtree_bytes_changed(
            $this->source->content,
            $draft->content,
            $this->blueprint['sections'][0],
            $result['sections'][0]
        );
        $this->assertSame($this->blueprint['sections'][1]['availability'], $result['sections'][1]['availability']);
        $this->assertContains('s1.quiz1', node_tree::required_ids($this->blueprint, $path));
        $this->assertStringContainsString('s1.quiz1', $this->connector->requests(request::STEP_SECTIONS)[0]->system);
        $this->assertStringContainsString(
            'Simplifică explicația',
            $this->connector->requests(request::STEP_SECTIONS)[0]->system
        );
    }

    /**
     * Regenerating an activity or quiz does not change any sibling activity.
     */
    public function test_activity_and_quiz_replace_only_the_target(): void {
        $activity = $this->blueprint['sections'][1]['activities'][1];
        $activity['name'] = 'Sarcină clarificată';
        $this->connector->push(request::STEP_ACTIVITIES, ['node' => $activity], 's2.assign1');
        $draft = (new regenerator())->run(
            $this->jobid,
            $this->source->id,
            's2.assign1',
            'Clarifică',
            $this->context()
        );
        $result = json_decode($draft->content, true);
        $this->assert_only_subtree_bytes_changed(
            $this->source->content,
            $draft->content,
            $this->blueprint['sections'][1]['activities'][1],
            $result['sections'][1]['activities'][1]
        );
        foreach ($this->blueprint['sections'] as $index => $section) {
            foreach ($section['activities'] as $activityindex => $oldactivity) {
                if ($oldactivity['id'] === 's2.assign1') {
                    continue;
                }
                $this->assertSame(
                    json_encode($oldactivity),
                    json_encode($result['sections'][$index]['activities'][$activityindex])
                );
            }
        }

        $quiz = $this->blueprint['sections'][0]['activities'][6];
        $quiz['name'] = 'Test clarificat';
        $this->connector->push(request::STEP_QUESTIONS, ['node' => $quiz], 's1.quiz1');
        $quizdraft = (new regenerator())->run(
            $this->jobid,
            $this->source->id,
            's1.quiz1',
            'Clarifică quiz-ul',
            $this->context()
        );
        $quizresult = json_decode($quizdraft->content, true);
        $this->assert_only_subtree_bytes_changed(
            $this->source->content,
            $quizdraft->content,
            $this->blueprint['sections'][0]['activities'][6],
            $quizresult['sections'][0]['activities'][6]
        );
        $this->assertSame('Test clarificat', $quizresult['sections'][0]['activities'][6]['name']);
        $this->assertSame(json_encode($this->blueprint['sections'][1]), json_encode($quizresult['sections'][1]));
    }

    /**
     * A missing externally referenced id fails after repair without publishing a draft.
     */
    public function test_broken_external_reference_does_not_save(): void {
        global $DB;

        $node = $this->blueprint['sections'][0];
        $node['activities'] = array_values(array_filter(
            $node['activities'],
            fn(array $activity) => $activity['id'] !== 's1.quiz1'
        ));
        $answer = ['node' => $node];
        $this->connector->push(request::STEP_SECTIONS, $answer, 's1');
        $this->connector->push(request::STEP_REPAIR, $answer);
        $this->connector->push(request::STEP_REPAIR, $answer);

        try {
            (new regenerator())->run($this->jobid, $this->source->id, 's1', 'Schimbă', $this->context());
            $this->fail('A broken external reference must reject regeneration.');
        } catch (\moodle_exception $e) {
            $this->assertSame('regenerationinvalid', $e->errorcode);
            $this->assertStringContainsString('Preserve mandatory id s1.quiz1', $e->getMessage());
        } finally {
            $this->assertSame(1, $DB->count_records('local_aicb_blueprint', ['jobid' => $this->jobid]));
        }
    }

    /**
     * Budget refusal leaves the source version and version count intact.
     */
    public function test_budget_refusal_does_not_save(): void {
        global $DB;

        $this->connector->set_cost_per_call(1.0);
        set_config('joblimitusd', '0.5', 'local_aicoursebuilder');
        $DB->set_field('local_aicb_job', 'estimatedcost', 0.5, ['id' => $this->jobid]);
        $this->expectException(budget_exceeded_exception::class);
        try {
            (new regenerator())->run($this->jobid, $this->source->id, 's2.assign1', 'Schimbă', $this->context());
        } finally {
            $this->assertSame(1, $DB->count_records('local_aicb_blueprint', ['jobid' => $this->jobid]));
            $this->assertSame(0, $this->connector->call_count());
        }
    }

    /**
     * The queued task executes regeneration and reports the new draft to the job.
     */
    public function test_adhoc_task_saves_new_draft(): void {
        global $DB;

        $activity = $this->blueprint['sections'][1]['activities'][1];
        $activity['name'] = 'Sarcină refăcută';
        $this->connector->push(request::STEP_ACTIVITIES, ['node' => $activity], 's2.assign1');
        $task = regenerate_task::instance(
            $this->jobid,
            $this->userid,
            (int) $this->source->id,
            's2.assign1',
            'Rescrie sarcina'
        );

        $task->execute();

        $this->assertSame(2, $DB->count_records('local_aicb_blueprint', ['jobid' => $this->jobid]));
        $this->assertSame('review', $DB->get_field('local_aicb_job', 'status', ['id' => $this->jobid]));
        $this->assertNull($DB->get_field('local_aicb_job', 'error', ['id' => $this->jobid]));
    }

    /**
     * Identical instructions resume a paid call; new instructions force a new AI answer.
     */
    public function test_instructions_are_part_of_regeneration_hash(): void {
        global $DB;

        $activity = $this->blueprint['sections'][1]['activities'][1];
        $activity['name'] = 'Varianta A';
        $this->connector->push(request::STEP_ACTIVITIES, ['node' => $activity], 's2.assign1');
        $first = (new regenerator())->run($this->jobid, $this->source->id, 's2.assign1', 'A', $this->context());
        $this->assertSame(1, $this->connector->call_count(request::STEP_ACTIVITIES));

        $same = (new regenerator())->run($this->jobid, $this->source->id, 's2.assign1', 'A', $this->context());
        $this->assertSame((int) $first->version + 1, (int) $same->version);
        $this->assertSame($first->content, $same->content);
        $this->assertSame(1, $this->connector->call_count(request::STEP_ACTIVITIES));

        $activity['name'] = 'Varianta B';
        $this->connector->push(request::STEP_ACTIVITIES, ['node' => $activity], 's2.assign1');
        $second = (new regenerator())->run($this->jobid, $this->source->id, 's2.assign1', 'B', $this->context());
        $this->assertSame(2, $this->connector->call_count(request::STEP_ACTIVITIES));
        $this->assertSame(4, (int) $second->version);
        $this->assertSame(4, $DB->count_records('local_aicb_blueprint', ['jobid' => $this->jobid]));
    }
}
