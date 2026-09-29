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
 * Checks that the AI step fixtures are consistent with blueprint_golden.json.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\fake_connector
 */
final class ai_fixtures_test extends \basic_testcase {
    /**
     * Returns the fixture of a step, as the fake connector serves it.
     *
     * @param string $step Pipeline step.
     * @return array
     */
    private function fixture(string $step): array {
        $messages = [['role' => 'user', 'content' => $step]];
        return (new fake_connector())->complete(new request($step, '', $messages))->json;
    }

    /**
     * Returns the golden blueprint.
     *
     * @return array
     */
    private function golden(): array {
        $json = file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Returns the golden activities indexed by id, with their container id.
     *
     * @return array Activity id => ['container' => string, 'activity' => array].
     */
    private function golden_activities(): array {
        $activities = [];
        foreach ($this->golden()['sections'] as $section) {
            foreach (array_merge([$section], $section['subsections'] ?? []) as $container) {
                foreach ($container['activities'] as $activity) {
                    $activities[$activity['id']] = ['container' => $container['id'], 'activity' => $activity];
                }
            }
        }
        return $activities;
    }

    /**
     * The outline has the golden course and sections.
     */
    public function test_outline_matches_golden(): void {
        $golden = $this->golden();
        $outline = $this->fixture(request::STEP_OUTLINE);

        $this->assertSame($golden['course']['fullname'], $outline['course']['fullname']);
        $this->assertSame(array_column($golden['sections'], 'id'), array_column($outline['sections'], 'id'));
        foreach ($golden['sections'] as $i => $section) {
            $this->assertSame($section['title'], $outline['sections'][$i]['title']);
            $this->assertSame($section['objectives'], $outline['sections'][$i]['objectives']);
        }
    }

    /**
     * Sections, activities and questions fixtures together rebuild every golden activity.
     */
    public function test_generated_nodes_match_golden(): void {
        $golden = $this->golden_activities();
        $questions = [];
        foreach ($this->fixture(request::STEP_QUESTIONS)['quizzes'] as $quiz) {
            $questions[$quiz['activity']] = $quiz['questions'];
        }

        $seen = [];
        foreach ([request::STEP_SECTIONS, request::STEP_ACTIVITIES] as $step) {
            foreach ($this->fixture($step)['sections'] as $container) {
                foreach ($container['activities'] as $activity) {
                    $this->assertArrayHasKey($activity['id'], $golden, "{$step}: unknown node {$activity['id']}");
                    $this->assertSame($golden[$activity['id']]['container'], $container['id']);
                    if ($activity['type'] === 'quiz') {
                        $activity['content']['questions'] = $questions[$activity['id']];
                    }
                    $this->assertSame($golden[$activity['id']]['activity'], $activity);
                    $seen[] = $activity['id'];
                }
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($golden), $seen);
    }

    /**
     * Digest, brief and review refer to existing sources, objectives and nodes.
     */
    public function test_other_fixtures_are_consistent(): void {
        $golden = $this->golden();
        $digest = $this->fixture(request::STEP_DIGEST);
        $this->assertSame('src1', $digest['source']);
        $this->assertNotEmpty($digest['concepts']);

        $brief = $this->fixture(request::STEP_BRIEF);
        $this->assertSame($golden['language'], $brief['language']);
        $this->assertSame(array_sum(array_column($golden['sections'], 'duration_minutes')), $brief['duration_minutes']);

        $nodes = array_keys($this->golden_activities());
        foreach ($golden['sections'][0]['activities'] as $activity) {
            foreach ($activity['content']['questions'] ?? [] as $question) {
                $nodes[] = $question['id'];
            }
        }
        foreach ($this->fixture(request::STEP_REVIEW)['issues'] as $issue) {
            $this->assertContains($issue['node'], $nodes);
        }
    }
}
