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

use GuzzleHttp\Psr7\Response;
use local_aicoursebuilder\blueprint\schema_store;

/**
 * Contract tests on the schemas the connectors really send, one test for each provider and each schema of schema/steps/.
 *
 * On 02.10 the first real call with a schema failed with an HTTP 400 at DeepSeek: the step schemas carried a $ref to
 * aicb:///blueprint.v1, which no provider can resolve. The tests with a mocked HTTP client did not see it, because a mock
 * accepts any schema. These tests look at the body that goes on the wire, built by the real connector from the real
 * schema file, and check it against what each provider documents:
 *
 *  - Every $ref is local, "#/$defs/{name}", and names a definition the schema has; no registered URI (aicb:) or $id is left.
 *  - Every keyword is one the provider accepts. The lists below quote the documentation they come from.
 *
 * A step schema added to schema/steps/ is tested without touching this file. The checks are also run on schemas made to
 * break them, so that a check that stops checking is itself a failing test.
 *
 * Documentation, read on 13.10.2026:
 *
 *  - Anthropic, https://platform.claude.com/docs/en/build-with-claude/structured-outputs. Supported: "enum", "const",
 *    "anyOf and allOf (with limitations - allOf with $ref not supported)", "$ref, $def, and definitions (external $ref not
 *    supported)", "default", "required and additionalProperties (must be set to false for objects)", string formats
 *    date-time, time, date, duration, email, hostname, uri, ipv4, ipv6 and uuid, "Array minItems (only values 0 and 1
 *    supported)", and a simple regex pattern. Not supported: "Recursive schemas", "Complex types within enums", "External
 *    $ref", "Numerical constraints (such as minimum, maximum, multipleOf)", "String constraints (minLength, maxLength)",
 *    "Array constraints beyond minItems of 0 or 1", "additionalProperties set to anything other than false". "If you use an
 *    unsupported feature, you'll receive a 400 error."
 *  - Gemini responseSchema, https://ai.google.dev/api/generate-content (Schema, "a select subset of an OpenAPI 3.0 schema
 *    object") and https://ai.google.dev/gemini-api/docs/structured-output: type, format, title, description, enum,
 *    minItems, maxItems, properties, required, items, minimum, maximum; the structured output page lists
 *    additionalProperties as well. Nothing in Schema is a reference, so none is sent.
 *  - Gemini responseJsonSchema, the same page: "a subset of the JSON Schema specification" and "Not all JSON Schema features
 *    are supported". It does not list the keywords, so only the references are checked.
 *  - DeepSeek, https://api-docs.deepseek.com/guides/tool_calls: a function's parameters are a JSON Schema; the strict mode
 *    (beta, not used) has the restrictions of its own, and the page names none for the default mode. $ref with $def is
 *    documented. The real runs of 02.10 and after, which sent these schemas, were answered.
 *  - OpenAI, https://platform.openai.com/docs/guides/structured-outputs: the restrictions are those of "strict": true,
 *    which the connector never sends; the schema is sent as it is, with local references, which the page documents.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\ai\schema_bundler
 * @covers     \local_aicoursebuilder\ai\anthropic_schema_transformer
 * @covers     \local_aicoursebuilder\ai\gemini_schema_transformer
 * @covers     \local_aicoursebuilder\ai\deepseek_connector
 * @covers     \local_aicoursebuilder\ai\anthropic_connector
 * @covers     \local_aicoursebuilder\ai\gemini_connector
 * @covers     \local_aicoursebuilder\ai\openaicompat_connector
 */
final class provider_schema_contract_test extends \advanced_testcase {
    /** @var string Prefix of a local reference into the definitions of the schema. */
    private const LOCAL_REF = '#/$defs/';

    /** @var string[] Keywords whose value is a map of name => schema. */
    private const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /** @var string[] Keywords whose value is one schema. */
    private const SCHEMAS = ['items', 'additionalProperties', 'additionalItems', 'unevaluatedItems', 'unevaluatedProperties',
        'contains', 'propertyNames', 'if', 'then', 'else', 'not'];

    /** @var string[] Keywords whose value is a list of schemas. */
    private const SCHEMA_LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * Anthropic: what the page lists as supported, and no more. allOf is not here, because a schema with an allOf and a $ref
     * is refused and the transformer merges the allOf away; title is left out because the page does not mention it.
     */
    private const ANTHROPIC = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const', 'anyOf',
        '$ref', '$defs', 'default', 'description', 'format', 'pattern', 'minItems'];

    /**
     * Gemini responseSchema: the subset the documentation lists, which is also what gemini_schema_transformer keeps.
     */
    private const GEMINI = ['type', 'format', 'title', 'description', 'enum', 'minItems', 'maxItems', 'properties', 'required',
        'items', 'prefixItems', 'minimum', 'maximum', 'additionalProperties'];

    /**
     * DeepSeek and the OpenAI-compatible servers, in the default mode: the vocabulary of JSON Schema that the step schemas
     * are written in. The documentation restricts nothing here, so this list is what the schema files are known to use;
     * a keyword that is not in it was added by mistake, or has to be added here with its source.
     */
    private const JSON_SCHEMA = ['$schema', '$defs', '$ref', 'type', 'properties', 'required', 'additionalProperties', 'items',
        'enum', 'const', 'allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', 'default', 'description', 'title', 'format',
        'pattern', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'minItems',
        'maxItems', 'uniqueItems', 'minProperties'];

    /** @var string[] String formats Anthropic accepts. */
    private const ANTHROPIC_FORMATS = ['date-time', 'time', 'date', 'duration', 'email', 'hostname', 'uri', 'ipv4', 'ipv6',
        'uuid'];

    /** @var array HTTP request/response history of the mocked client. */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler Mocked HTTP handler. */
    private \GuzzleHttp\Handler\MockHandler $mock;

    /** @var int User that accepted the AI policy. */
    private int $userid;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $key = \core\encryption::encrypt('sk-test-0123456789');
        set_config('deepseek_apikey', $key, 'local_aicoursebuilder');
        set_config('anthropic_apikey', $key, 'local_aicoursebuilder');
        set_config('gemini_apikey', $key, 'local_aicoursebuilder');
        set_config('gemini_model', 'gemini-test', 'local_aicoursebuilder');
        set_config('openaicompat_baseurl', 'https://api.openai.com/v1', 'local_aicoursebuilder');
        set_config('openaicompat_model', 'gpt-test', 'local_aicoursebuilder');
        set_config('openaicompat_apikey', $key, 'local_aicoursebuilder');
        set_config('openaicompat_supports_json_schema', 1, 'local_aicoursebuilder');
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        \core_ai\manager::user_policy_accepted($this->userid, \context_system::instance()->id);
        $this->history = [];
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
    }

    /**
     * Returns every provider with every step schema of schema/steps/.
     *
     * @return array
     */
    public static function provider_step_provider(): array {
        return self::cases(['deepseek', 'anthropic', 'gemini', 'gemini native', 'openaicompat']);
    }

    /**
     * Returns the providers whose keywords the documentation lists, with every step schema of schema/steps/.
     *
     * @return array
     */
    public static function keyword_provider(): array {
        return self::cases(['deepseek', 'anthropic', 'gemini', 'openaicompat']);
    }

    /**
     * Pairs each provider with each step schema.
     *
     * @param string[] $providers The providers.
     * @return array
     */
    private static function cases(array $providers): array {
        $steps = [];
        foreach (glob(dirname(__DIR__, 2) . '/schema/steps/*.json') as $file) {
            $steps[] = preg_replace('/\.v[0-9]+\.json$/', '', basename($file));
        }
        $cases = [];
        foreach ($providers as $provider) {
            foreach ($steps as $step) {
                $cases["{$provider}: {$step}"] = [$provider, $step];
            }
        }
        return $cases;
    }

    /**
     * Sends the schema of a step through the connector of a provider, and returns what went on the wire.
     *
     * @param string $provider deepseek, anthropic, gemini, gemini native or openaicompat.
     * @param string $step The step whose schema is sent.
     * @return array [array, string]: the schema in the body of the request, and the whole body as it was sent.
     */
    private function sent(string $provider, string $step): array {
        $schema = (new schema_store())->step_schema_array($step);
        $request = new request(
            step: $step,
            system: 'Scrie JSON.',
            messages: [['role' => 'user', 'content' => 'Fotosinteza transformă lumina în energie chimică.']],
            schema: $schema,
            userid: $this->userid,
        );
        $connector = match ($provider) {
            'deepseek' => new deepseek_connector(),
            'anthropic' => new anthropic_connector(),
            'gemini', 'gemini native' => new gemini_connector(),
            'openaicompat' => new openaicompat_connector(),
        };
        set_config('gemini_native_json_schema', $provider === 'gemini native' ? 1 : 0, 'local_aicoursebuilder');
        $connector = $provider === 'gemini native' || $provider === 'gemini' ? new gemini_connector() : $connector;

        // The answer is of no use here: the request is what is looked at, and a mock answers it with an empty object.
        $this->mock->append(new Response(200, [], '{}'));
        try {
            $connector->complete($request);
        } catch (connector_exception $e) {
            $this->assertNotEmpty($this->history, 'The connector sent nothing: ' . $e->getMessage());
        }
        $this->assertCount(1, $this->history);
        $raw = (string) $this->history[0]['request']->getBody();
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $schema = match ($provider) {
            'deepseek' => $body['tools'][0]['function']['parameters'] ?? null,
            'anthropic' => $body['output_config']['format']['schema'] ?? null,
            'gemini' => $body['generationConfig']['responseSchema'] ?? null,
            'gemini native' => $body['generationConfig']['responseJsonSchema'] ?? null,
            'openaicompat' => $body['response_format']['json_schema']['schema'] ?? null,
        };
        $this->assertIsArray($schema, "The schema is not where the {$provider} API reads it");
        return [$schema, $raw];
    }

    /**
     * No registered URI and no external reference reaches a provider, and every reference that is left resolves.
     *
     * @dataProvider provider_step_provider
     * @param string $provider The provider.
     * @param string $step The step.
     */
    public function test_references_are_local_and_resolve(string $provider, string $step): void {
        [$schema, $raw] = $this->sent($provider, $step);

        $this->assertStringNotContainsString('aicb:', $raw, 'a registered URI of the plugin was sent');
        $problems = self::reference_problems($schema, $provider === 'gemini');
        $this->assertSame([], $problems, "{$provider} {$step}: " . implode('; ', $problems));
    }

    /**
     * Every keyword sent is one the provider accepts. Gemini's responseJsonSchema is left out, because the documentation
     * lists no keywords for it: only its references are checked.
     *
     * @dataProvider keyword_provider
     * @param string $provider The provider.
     * @param string $step The step.
     */
    public function test_only_keywords_the_provider_accepts(string $provider, string $step): void {
        [$schema] = $this->sent($provider, $step);

        $allowed = match ($provider) {
            'anthropic' => self::ANTHROPIC,
            'gemini' => self::GEMINI,
            default => self::JSON_SCHEMA,
        };
        $problems = self::keyword_problems($schema, $allowed);
        if ($provider === 'anthropic') {
            $problems = array_merge($problems, self::anthropic_value_problems($schema));
        }
        $this->assertSame([], $problems, "{$provider} {$step}: " . implode('; ', $problems));
    }

    /**
     * The schema has no keyword that a provider does not take, and a provider that takes a schema in strict mode is not
     * asked for it: the step schemas have optional properties and open objects, which strict mode refuses.
     *
     * @dataProvider provider_step_provider
     * @param string $provider The provider.
     * @param string $step The step.
     */
    public function test_strict_mode_is_not_asked_for(string $provider, string $step): void {
        [, $raw] = $this->sent($provider, $step);

        $this->assertDoesNotMatchRegularExpression('/"strict"\s*:\s*true/', $raw);
    }

    // The checks, and the proof that they check.

    /**
     * Returns what is wrong with the references of a schema.
     *
     * @param array $schema The schema as it was sent.
     * @param bool $none True when the provider has no reference at all, so that any is a problem.
     * @return string[] One line for each problem.
     */
    private static function reference_problems(array $schema, bool $none = false): array {
        $problems = [];
        $defs = is_array($schema['$defs'] ?? null) ? $schema['$defs'] : [];
        if (array_key_exists('$id', $schema)) {
            $problems[] = 'the root has an $id, which a provider would resolve every $ref against';
        }
        $edges = [];
        self::walk($schema, '', function (string $path, string $keyword, mixed $value) use (&$problems, $defs, $none, &$edges) {
            if ($keyword === '$id' && $path !== '') {
                $problems[] = "{$path}: an \$id";
            }
            if ($keyword !== '$ref') {
                return;
            }
            if ($none) {
                $problems[] = "{$path}: a \$ref where the provider takes none";
            } else if (!is_string($value) || !str_starts_with($value, self::LOCAL_REF)) {
                $problems[] = "{$path}: the \$ref " . json_encode($value) . ' is not local';
            } else if (!array_key_exists(substr($value, strlen(self::LOCAL_REF)), $defs)) {
                $problems[] = "{$path}: the \$ref {$value} names no definition";
            } else {
                $edges[] = [self::definition_of($path), substr($value, strlen(self::LOCAL_REF))];
            }
        });
        if (self::has_cycle($edges)) {
            $problems[] = 'the definitions refer to each other in a cycle (recursive schemas are refused by Anthropic)';
        }
        return $problems;
    }

    /**
     * Returns what is wrong with the keywords of a schema.
     *
     * @param array $schema The schema as it was sent.
     * @param string[] $allowed The keywords the provider accepts.
     * @return string[] One line for each problem.
     */
    private static function keyword_problems(array $schema, array $allowed): array {
        $problems = [];
        self::walk($schema, '', function (string $path, string $keyword) use (&$problems, $allowed) {
            if (!in_array($keyword, $allowed, true)) {
                $problems[$path . '/' . $keyword] = "{$path}: the keyword {$keyword} is not accepted";
            }
        });
        return array_values($problems);
    }

    /**
     * Returns what is wrong with the values of the keywords Anthropic takes only with some values.
     *
     * @param array $schema The schema as it was sent.
     * @return string[] One line for each problem.
     */
    private static function anthropic_value_problems(array $schema): array {
        $problems = [];
        self::walk($schema, '', function (string $path, string $keyword, mixed $value) use (&$problems) {
            if ($keyword === 'additionalProperties' && $value !== false) {
                $problems[] = "{$path}: additionalProperties is not false";
            }
            if ($keyword === 'minItems' && !in_array($value, [0, 1], true)) {
                $problems[] = "{$path}: minItems is not 0 or 1";
            }
            if ($keyword === 'format' && !in_array($value, self::ANTHROPIC_FORMATS, true)) {
                $problems[] = "{$path}: the format " . json_encode($value) . ' is not one of the documented';
            }
            if ($keyword === 'pattern' && preg_match('/\(\?|\\\\[1-9bB]/', (string) $value)) {
                $problems[] = "{$path}: the pattern uses a lookaround, a backreference or a word boundary";
            }
        });
        return $problems;
    }

    /**
     * Calls a function for each keyword of a schema, which is told apart from the names of properties and definitions.
     *
     * @param mixed $node A schema, or a boolean schema.
     * @param string $path JSON Pointer of the node.
     * @param callable $visit function(string $path, string $keyword, mixed $value): void.
     */
    private static function walk(mixed $node, string $path, callable $visit): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $keyword => $value) {
            $visit($path, (string) $keyword, $value);
            if (in_array($keyword, self::SCHEMA_MAPS, true) && is_array($value)) {
                foreach ($value as $name => $child) {
                    self::walk($child, "{$path}/{$keyword}/{$name}", $visit);
                }
            } else if (in_array($keyword, self::SCHEMAS, true)) {
                self::walk($value, "{$path}/{$keyword}", $visit);
            } else if (in_array($keyword, self::SCHEMA_LISTS, true) && is_array($value)) {
                foreach ($value as $index => $child) {
                    self::walk($child, "{$path}/{$keyword}/{$index}", $visit);
                }
            }
        }
    }

    /**
     * Returns the definition a path is inside of, or the root.
     *
     * @param string $path JSON Pointer.
     * @return string Name of the definition, '' for the root.
     */
    private static function definition_of(string $path): string {
        return preg_match('#^/\$defs/([^/]+)#', $path, $matches) ? $matches[1] : '';
    }

    /**
     * Tells whether the references among definitions go round.
     *
     * @param array $edges [from, to] pairs, '' being the root.
     * @return bool
     */
    private static function has_cycle(array $edges): bool {
        $graph = [];
        foreach ($edges as [$from, $to]) {
            $graph[$from][$to] = true;
        }
        $state = [];
        $visit = function (string $node) use (&$visit, &$state, $graph): bool {
            if (($state[$node] ?? 0) === 1) {
                return true;
            }
            if (($state[$node] ?? 0) === 2) {
                return false;
            }
            $state[$node] = 1;
            foreach (array_keys($graph[$node] ?? []) as $next) {
                if ($visit((string) $next)) {
                    return true;
                }
            }
            $state[$node] = 2;
            return false;
        };
        foreach (array_keys($graph) as $node) {
            if ($visit((string) $node)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The checks find what they are for: an external reference, a registered URI, a reference to nothing, a recursive
     * definition, and a keyword or a value that a provider refuses.
     */
    public function test_the_checks_find_what_they_are_for(): void {
        $external = ['type' => 'object', 'properties' => ['course' => ['$ref' => 'aicb:///blueprint.v1#/$defs/course']]];
        $this->assertNotEmpty(self::reference_problems($external));

        $url = ['type' => 'object', 'properties' => ['a' => ['$ref' => 'https://example.com/schema.json']]];
        $this->assertNotEmpty(self::reference_problems($url));

        $withid = ['$id' => 'aicb:///steps/outline.v1', 'type' => 'object'];
        $this->assertNotEmpty(self::reference_problems($withid));

        $dangling = ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/missing']], '$defs' => []];
        $this->assertNotEmpty(self::reference_problems($dangling));

        $recursive = ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/node']], '$defs' => [
            'node' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/$defs/node']]],
        ]];
        $this->assertNotEmpty(self::reference_problems($recursive));

        $local = ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/x']], '$defs' => ['x' => ['type' => 'string']]];
        $this->assertSame([], self::reference_problems($local));
        $this->assertNotEmpty(self::reference_problems($local, true), 'a provider with no references takes none');

        $constraint = ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 100]]];
        $this->assertNotEmpty(self::keyword_problems($constraint, self::ANTHROPIC));
        $this->assertSame([], self::keyword_problems($constraint, self::JSON_SCHEMA));
        $this->assertNotEmpty(self::keyword_problems(['type' => 'object', 'x-note' => 'a'], self::JSON_SCHEMA));
        // A property that is called like a keyword is a name, not a keyword.
        $named = ['type' => 'object', 'properties' => ['maxLength' => ['type' => 'integer']]];
        $this->assertSame([], self::keyword_problems($named, self::ANTHROPIC));

        $this->assertNotEmpty(self::anthropic_value_problems(['type' => 'object', 'additionalProperties' => ['type' => 'string']]));
        $this->assertNotEmpty(self::anthropic_value_problems(['type' => 'array', 'minItems' => 2]));
        $this->assertNotEmpty(self::anthropic_value_problems(['type' => 'string', 'format' => 'hostname-ish']));
        $this->assertNotEmpty(self::anthropic_value_problems(['type' => 'string', 'pattern' => '^(?=a)b$']));
        $fine = ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']];
        $this->assertSame([], self::anthropic_value_problems($fine));
    }

    /**
     * The step schemas this test covers are the ones of schema/steps/, and there are some.
     */
    public function test_the_step_schemas_are_found(): void {
        $steps = array_unique(array_map(fn(array $case) => $case[1], self::provider_step_provider()));
        $this->assertGreaterThanOrEqual(7, count($steps));
        $this->assertContains('sections', $steps);
        $this->assertContains('questions', $steps);
    }
}
