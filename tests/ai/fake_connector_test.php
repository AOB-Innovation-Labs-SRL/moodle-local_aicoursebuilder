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
 * Tests for the fake connector and the AI value objects.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\fake_connector
 * @covers     \local_aicoursebuilder\ai\request
 * @covers     \local_aicoursebuilder\ai\result
 */
final class fake_connector_test extends \basic_testcase {
    /** @var string[] Steps that have a fixture in tests/fixtures/ai. */
    private const FIXTURE_STEPS = [
        request::STEP_DIGEST,
        request::STEP_BRIEF,
        request::STEP_OUTLINE,
        request::STEP_SECTIONS,
        request::STEP_ACTIVITIES,
        request::STEP_QUESTIONS,
        request::STEP_REVIEW,
    ];

    /**
     * Builds a request for a step.
     *
     * @param string $step Pipeline step.
     * @return request
     */
    private function make_request(string $step): request {
        return new request(
            step: $step,
            system: 'Ești un asistent care proiectează cursuri.',
            messages: [['role' => 'user', 'content' => 'Generează pasul ' . $step]],
            schema: ['type' => 'object'],
        );
    }

    /**
     * Every step returns its own fixture, decoded, with fake usage and no cost.
     */
    public function test_complete_returns_fixture_per_step(): void {
        $connector = new fake_connector();
        foreach (self::FIXTURE_STEPS as $step) {
            $path = __DIR__ . "/../fixtures/ai/{$step}.json";
            $expected = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            $result = $connector->complete($this->make_request($step));

            $this->assertSame($expected, $result->json, "Fixture for step {$step}");
            $this->assertSame(trim(file_get_contents($path)), $result->content);
            $this->assertSame(fake_connector::MODEL, $result->model);
            $this->assertSame(fake_connector::NAME, $result->connector);
            $this->assertSame(0.0, $result->cost);
            $this->assertSame(0, $result->tokenscached);
            $this->assertSame($connector->count_tokens($result->content), $result->tokensout);
            $this->assertGreaterThan(0, $result->tokensin);
            $this->assertSame(
                ['tokensin' => $result->tokensin, 'tokensout' => $result->tokensout, 'tokenscached' => 0],
                $result->get_usage()
            );
        }
    }

    /**
     * Two identical requests give identical results.
     */
    public function test_complete_is_deterministic(): void {
        $first = (new fake_connector())->complete($this->make_request(request::STEP_DIGEST));
        $second = (new fake_connector())->complete($this->make_request(request::STEP_DIGEST));
        $this->assertEquals($first, $second);
    }

    /**
     * A step without fixture fails loudly instead of inventing an answer.
     */
    public function test_complete_without_fixture_throws(): void {
        $this->expectException(\coding_exception::class);
        (new fake_connector())->complete($this->make_request(request::STEP_REPAIR));
    }

    /**
     * A custom fixture directory is honoured.
     */
    public function test_custom_fixture_directory(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/brief.json', '{"language": "ro"}');
        $result = (new fake_connector($dir))->complete($this->make_request(request::STEP_BRIEF));
        $this->assertSame(['language' => 'ro'], $result->json);
    }

    /**
     * Only json_schema is supported.
     */
    public function test_supports(): void {
        $connector = new fake_connector();
        foreach (connector::CAPABILITIES as $capability) {
            $this->assertSame($capability === connector::CAP_JSON_SCHEMA, $connector->supports($capability), $capability);
        }
        $this->assertFalse($connector->supports('unknown'));
    }

    /**
     * Tokens are characters / 3.5 rounded up, counting multi-byte characters once.
     */
    public function test_count_tokens_and_cost(): void {
        $connector = new fake_connector();
        $this->assertSame(0, $connector->count_tokens(''));
        $this->assertSame(1, $connector->count_tokens('abc'));
        $this->assertSame(2, $connector->count_tokens('abcdefg'));
        $this->assertSame(2, $connector->count_tokens('ăîșțâ'));
        $this->assertSame(0.0, $connector->estimate_cost($this->make_request(request::STEP_OUTLINE)));
    }

    /**
     * The request rejects unknown steps and malformed messages.
     */
    public function test_request_validation(): void {
        try {
            new request('unknown', '', []);
            $this->fail('Unknown step accepted');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unknown AI step', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        new request(request::STEP_DIGEST, '', [['role' => 'system', 'content' => 'x']]);
    }

    /**
     * User, context and JSON flag default to empty, and JSON is wanted with a schema or the flag.
     */
    public function test_request_user_context_and_json(): void {
        $plain = new request(request::STEP_DIGEST, 'sys', []);
        $this->assertNull($plain->userid);
        $this->assertNull($plain->contextid);
        $this->assertFalse($plain->json);
        $this->assertFalse($plain->wants_json());

        $this->assertTrue((new request(request::STEP_DIGEST, 'sys', [], json: true))->wants_json());
        $this->assertTrue((new request(request::STEP_DIGEST, 'sys', [], schema: ['type' => 'object']))->wants_json());

        $request = new request(request::STEP_DIGEST, 'sys', [], userid: 5, contextid: 1);
        $this->assertSame(5, $request->userid);
        $this->assertSame(1, $request->contextid);

        $this->expectException(\coding_exception::class);
        new request(request::STEP_DIGEST, 'sys', [], userid: 0);
    }

    /**
     * The input text joins the system prompt and the messages.
     */
    public function test_request_input_text(): void {
        $request = new request(request::STEP_DIGEST, 'sys', [
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
        ]);
        $this->assertSame("sys\na\nb", $request->get_input_text());
    }
}
