# AGENTS.md — local_aicoursebuilder

Moodle local plugin (`local_aicoursebuilder`). The repo root is the plugin root.
Targets Moodle 5.2 and 5.3 (`$plugin->supported = [502, 503]`), PHP 8.3+.

## Target structure

```
db/                  access.php, install.xml, upgrade.php, services.php, messages.php, tasks (added when needed)
classes/
  ai/                AI provider abstraction and requests
  ingest/            source material ingestion
  pipeline/          generation pipeline orchestration
  blueprint/         course blueprint model
  builder/           course/module/section creation from a blueprint
  task/              ad-hoc tasks
  external/          web service functions
  event/             events
  output/            renderables and renderers
  form/              moodleforms
  privacy/           Privacy API providers
amd/src              JavaScript modules (ES modules, built with grunt)
templates/           Mustache templates
lang/{en,ro}/        the only place for user-visible strings
schema/              JSON schemas
prompts/             prompt templates
cli/                 developer scripts run from the command line (golden_digest.php: the M1 check on the golden set)
tests/               PHPUnit and Behat
thirdparty/          vendored third-party code (with licence)
```

Create a directory only when it gets its first file.

## Conventions

- Coding style: `moodle-cs` (`--standard=moodle`), zero errors and zero warnings.
- Complete PHPDoc on every file, class, method, property and constant.
- Namespace: `local_aicoursebuilder\...`, one class per file, PSR-4 under `classes/`.
- User-visible strings only in `lang/en` and `lang/ro`, never in PHP, JS or templates.
- Prompts in `prompts/` are versioned: once a version has run it is never edited again; any change is
  a new `*.vN+1.md` file, and the version is part of the step hash.

## Allowed APIs

- `create_course()`
- `create_module()` / `update_module()` over `prepare_new_moduleinfo_data()`
- `formatactions::section()` for sections
- `qformat_xml` for question import
- `\core\http_client` for HTTP
- `\core\encryption` for stored secrets
- Ad-hoc tasks (`\core\task\adhoc_task`) for all long or AI work

## Forbidden

- `question_make_default_categories()`
- Calling `save_question()` directly
- Building courses through MBZ backup/restore
- Synchronous AI calls inside a web request
- API keys in the repo, in JS, or in logs
- INSMC code

## Definition of done

- moodle-cs clean: no errors, no warnings.
- PHPUnit test in the same PR as the code.
- Behat scenario for every new screen.
- Strings added in both `en` and `ro`.
- Privacy API provider for every new table holding `userid`.
- CI green (`.github/workflows/ci.yml`).

## Verification before pushing

- Before a push: PHPUnit and phpcs green locally.
- CI green on the pull request. CI runs on pull requests to `main` and on demand, never on a push:
  a pull request runs a reduced matrix of two combinations, which between them cover both PHP
  versions, both Moodle branches and both databases.
- The full matrix of eight is run by hand, from the Actions tab (`workflow_dispatch`, `full=true`),
  before every Friday integration and before every release.

## Local environment (Docker)

Variables used by the commands below (set them once per shell):

- `MOODLE_ROOT`: Moodle source checkout the stack seeds its code volume from.
- `STACK_DIR`: directory holding the compose file and `.runtime\.env` of the Docker stack.
- `ENV_DIR`: stable directory outside the repo with the stack's `config.php` (database
  and dataroot for this plugin) and an empty `emptyproj` project root.
- `DOCKER_SERVICE`: compose service that runs PHP and Apache (has the Moodle code mounted
  at `/var/www/html`, the plugin at `public/local/aicoursebuilder`, cwd `/var/www/html`).

The plugin root is bind-mounted read-only into the container, so run `phpcbf` from the
host or a copy, not in the container.

Concrete values for the `aicb45` stack (Moodle 5.2.1, PHP 8.3, PostgreSQL 16, port 8081,
database `moodle_aicb`). The stack's own `.runtime\.env` points at an older plugin, so
override with environment variables instead of editing it. PowerShell:

```powershell
$MOODLE_ROOT    = 'D:\Dev\moodle52-reference'
$STACK_DIR      = 'D:\Dev\MoodlePluginsSimavi\AI Course Builder (AT-01, AT-02, AT-16, AT-18)\tools'
$ENV_DIR        = 'D:\Dev\aicb-local-env'      # config.php + emptyproj\aiprovider_openaicompat
$DOCKER_SERVICE = 'webserver'

$env:AICB_PLUGIN_ROOT  = 'D:/Dev/moodle-local_aicoursebuilder'
$env:AICB_CONFIG_FILE  = "$ENV_DIR/config.php"
$env:AICB_PROJECT_ROOT = "$ENV_DIR/emptyproj"
$dc = { docker compose --env-file "$STACK_DIR\.runtime\.env" -f "$STACK_DIR\compose.yaml" @args }
& $dc up -d $DOCKER_SERVICE
```

`$ENV_DIR\start.ps1` does the same in one step.

Commands (inside the container):

```powershell
# upgrade (installs/updates the plugin)
& $dc exec -T $DOCKER_SERVICE php admin/cli/upgrade.php --non-interactive
# purge caches
& $dc exec -T $DOCKER_SERVICE php admin/cli/purge_caches.php
# code style (moodle-cs lives in the quality_tools volume, mounted at /quality)
& $dc exec -T $DOCKER_SERVICE sh -c 'cd public/local/aicoursebuilder && /quality/vendor/bin/phpcs --standard=moodle --extensions=php --warning-severity=1 --ignore="*/thirdparty/*" .'
# PHPUnit (init once, then run)
& $dc exec -T $DOCKER_SERVICE php public/admin/tool/phpunit/cli/init.php --disable-composer
& $dc exec -T $DOCKER_SERVICE vendor/bin/phpunit --testsuite local_aicoursebuilder_testsuite
```
