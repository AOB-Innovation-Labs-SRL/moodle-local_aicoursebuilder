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

/**
 * Records the real answer of a connector to one request as a test fixture, in tests/fixtures/ai/recorded/.
 *
 * The request is read from two files, the system prompt and the user message, and sent to the connector of the step
 * as the plugin settings route it. What is written is the answer only (content, usage, model, finish reason): never
 * the prompt, the API key or any header. The call goes to the AI provider and costs money, so it asks for --yes.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_aicoursebuilder\ai\request;
use local_aicoursebuilder\ai\router;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'user' => 'admin',
    'step' => request::STEP_SECTIONS,
    'system' => '',
    'message' => '',
    'name' => '',
    'json' => false,
    'yes' => false,
], ['h' => 'help']);

if ($unrecognized || $options['help']) {
    cli_writeln(
        "Records the answer of the connector of a step as a fixture in tests/fixtures/ai/recorded/.\n\n" .
        "Options:\n" .
        "  --user=USERNAME   User the call runs for (default: admin); the user must have accepted the AI policy.\n" .
        "  --step=STEP       Pipeline step, which decides the connector and the model (default: sections).\n" .
        "  --system=FILE     File with the system prompt.\n" .
        "  --message=FILE    File with the user message.\n" .
        "  --name=NAME       Name of the fixture, a-z, 0-9 and _ (default: the connector and the step).\n" .
        "  --json            Ask for a JSON object.\n" .
        "  --yes             Go on: the call goes to the AI provider and costs money.\n" .
        "  -h, --help        This help.\n\n" .
        "Needs: the API key of the connector in the plugin settings, never in the repo."
    );
    exit($unrecognized ? 1 : 0);
}

if (!in_array($options['step'], request::STEPS, true)) {
    cli_error('Unknown step. Choose one of: ' . implode(', ', request::STEPS) . '.');
}
foreach (['system', 'message'] as $option) {
    if ($options[$option] === '' || !is_readable($options[$option])) {
        cli_error("--{$option} must name a readable file.");
    }
}
if (!$options['yes']) {
    cli_error('The call goes to the AI provider and costs money. Run again with --yes.');
}
$user = \core_user::get_user_by_username($options['user'], '*', null, MUST_EXIST);
\core\session\manager::set_user($user);

$router = new router();
$route = $router->get_route($options['step']);
$connector = $router->raw_connector($options['step']);
$result = $connector->complete(new request(
    $options['step'],
    (string) file_get_contents($options['system']),
    [['role' => 'user', 'content' => (string) file_get_contents($options['message'])]],
    userid: (int) $user->id,
    json: (bool) $options['json'],
));

$name = $options['name'] !== '' ? $options['name'] : $route['connector'] . '_' . $options['step'];
if (!preg_match('/^[a-z0-9_]+$/', $name)) {
    cli_error('--name may only hold a-z, 0-9 and _.');
}
$directory = dirname(__DIR__) . '/tests/fixtures/ai/recorded';
if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
    cli_error("Cannot create {$directory}.");
}
$fixture = [
    'connector' => $route['connector'],
    'step' => $options['step'],
    'model' => $result->model,
    'finishreason' => $result->finishreason,
    'usage' => $result->get_usage(),
    'content' => $result->content,
];
$file = "{$directory}/{$name}.json";
file_put_contents($file, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
cli_writeln("Recorded {$file}");
