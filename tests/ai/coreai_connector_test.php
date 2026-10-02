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

use core_ai\aiactions\generate_text;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for the core_ai connector, through a core aiprovider_deepseek instance with a mocked HTTP client.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\coreai_connector
 * @covers     \local_aicoursebuilder\ai\policy
 */
final class coreai_connector_test extends \advanced_testcase {
    /** @var int User that accepted the AI policy. */
    private int $userid;

    /** @var array HTTP request/response history of the mocked client. */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler Mocked HTTP handler. */
    private \GuzzleHttp\Handler\MockHandler $mock;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        \core_ai\manager::user_policy_accepted($this->userid, \context_system::instance()->id);
        $this->history = [];
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
    }

    /**
     * Creates an enabled core DeepSeek provider instance with generate_text enabled.
     */
    private function create_provider(): void {
        \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: '\aiprovider_deepseek\provider',
            name: 'deepseek',
            enabled: true,
            config: ['apikey' => 'core-key'],
            actionconfig: [
                generate_text::class => [
                    'enabled' => true,
                    'settings' => [
                        'model' => 'deepseek-flash',
                        'endpoint' => 'https://api.deepseek.com/chat/completions',
                        'systeminstruction' => 'Core system instruction.',
                    ],
                ],
            ],
        );
    }

    /**
     * Builds a request for the digest step.
     *
     * @param array $params Named request parameters overriding the defaults.
     * @return request
     */
    private function make_request(array $params = []): request {
        return new request(...array_merge([
            'step' => request::STEP_DIGEST,
            'system' => 'Rezumă documentul.',
            'messages' => [['role' => 'user', 'content' => 'Text sursă.']],
            'userid' => $this->userid,
        ], $params));
    }

    /**
     * Builds a DeepSeek chat completion body.
     *
     * @param string $content The generated content.
     * @return string
     */
    private function completion_body(string $content): string {
        return json_encode([
            'id' => 'cmpl-1',
            'model' => 'deepseek-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 12, 'total_tokens' => 52],
            'system_fingerprint' => 'fp_test',
        ]);
    }

    /**
     * The outline schema refers to the blueprint schema: the schema written into the prompt has to
     * carry everything, with no aicb:/// left.
     */
    public function test_a_step_schema_is_written_into_the_prompt_without_external_references(): void {
        $this->create_provider();
        $schema = (new \local_aicoursebuilder\blueprint\schema_store())->step_schema_array(request::STEP_OUTLINE);
        $rawjson = json_encode($schema, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('aicb:///', $rawjson, 'the raw schema is not self-contained');
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], $this->completion_body('{"course": {}}')));

        (new coreai_connector())->complete($this->make_request(['step' => request::STEP_OUTLINE, 'schema' => $schema]));

        $body = json_decode((string) $this->history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $prompt = $body['messages'][1]['content'];
        $this->assertStringNotContainsString('aicb:', $prompt);
        $this->assertStringContainsString('"$ref":"#/$defs/blueprint_v1__course"', $prompt);
    }

    /**
     * The request runs through core_ai; the prompt text joins system, schema and message.
     */
    public function test_complete_through_core_ai(): void {
        $this->create_provider();
        $fence = str_repeat(chr(96), 3);
        $this->mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->completion_body("{$fence}json\n{\"titlu\": \"Fotosinteza\"}\n{$fence}")
        ));

        $schema = ['type' => 'object', 'required' => ['titlu']];
        $result = (new coreai_connector())->complete($this->make_request(['schema' => $schema]));

        $this->assertCount(1, $this->history);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('deepseek-flash', $body['model']);
        $this->assertSame(['role' => 'system', 'content' => 'Core system instruction.'], $body['messages'][0]);
        $this->assertSame(
            "Rezumă documentul.\n\n" . coreai_connector::SCHEMA_INSTRUCTION . "\n"
                . '{"type":"object","required":["titlu"]}' . "\n\nText sursă.",
            $body['messages'][1]['content']
        );

        $this->assertSame(['titlu' => 'Fotosinteza'], $result->json);
        $this->assertSame(['tokensin' => 40, 'tokensout' => 12, 'tokenscached' => 0], $result->get_usage());
        $this->assertSame('deepseek-flash', $result->model);
        $this->assertSame('stop', $result->finishreason);
        $this->assertSame(coreai_connector::NAME, $result->connector);
    }

    /**
     * Free text keeps the content and has no decoded JSON; JSON that does not parse gives null.
     */
    public function test_text_and_unparsable_json(): void {
        $this->create_provider();
        $this->mock->append(new Response(200, [], $this->completion_body('Un rezumat.')));
        $this->mock->append(new Response(200, [], $this->completion_body('Nu este JSON.')));

        $text = (new coreai_connector())->complete($this->make_request());
        $json = (new coreai_connector())->complete($this->make_request(['json' => true]));

        $this->assertSame('Un rezumat.', $text->content);
        $this->assertNull($text->json);
        $this->assertSame('Nu este JSON.', $json->content);
        $this->assertNull($json->json);
    }

    /**
     * The prompt text labels the roles when the conversation has several messages.
     */
    public function test_build_prompt_text(): void {
        $request = $this->make_request([
            'system' => '',
            'json' => true,
            'messages' => [
                ['role' => 'user', 'content' => 'a'],
                ['role' => 'assistant', 'content' => 'b'],
                ['role' => 'user', 'content' => 'c'],
            ],
        ]);
        $this->assertSame(
            coreai_connector::JSON_INSTRUCTION . "\n\nUser:\na\n\nAssistant:\nb\n\nUser:\nc",
            (new coreai_connector())->build_prompt_text($request)
        );
    }

    /**
     * Without an enabled provider core_ai returns an error, raised as coreaierror.
     */
    public function test_no_provider(): void {
        try {
            (new coreai_connector())->complete($this->make_request());
            $this->fail('Missing provider accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::CORE_AI_ERROR, $e->errorcode);
            $this->assertSame(0, $e->httpstatus);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * A provider HTTP error comes back as coreaierror with the HTTP status.
     */
    public function test_provider_error(): void {
        $this->create_provider();
        $this->mock->append(new Response(429, [], '{"error": {"message": "Rate limit reached"}}'));
        try {
            (new coreai_connector())->complete($this->make_request());
            $this->fail('Provider error accepted');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::CORE_AI_ERROR, $e->errorcode);
            $this->assertSame(429, $e->httpstatus);
        }
    }

    /**
     * The AI policy is checked before core_ai is called.
     */
    public function test_policy_not_accepted(): void {
        $this->create_provider();
        $other = (int) $this->getDataGenerator()->create_user()->id;
        try {
            (new coreai_connector())->complete($this->make_request(['userid' => $other]));
            $this->fail('Policy not checked');
        } catch (connector_exception $e) {
            $this->assertSame(connector_exception::POLICY_NOT_ACCEPTED, $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    /**
     * Files are refused and no capability is advertised.
     */
    public function test_files_and_supports(): void {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_aicoursebuilder',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'doc.pdf',
        ], '%PDF-1.4');
        $connector = new coreai_connector();
        foreach (connector::CAPABILITIES as $capability) {
            $this->assertFalse($connector->supports($capability), $capability);
        }
        $this->assertSame(0.0, $connector->estimate_cost($this->make_request()));
        $this->expectExceptionObject(new connector_exception(connector_exception::UNSUPPORTED, 'files'));
        $connector->complete($this->make_request(['files' => [$file]]));
    }
}
