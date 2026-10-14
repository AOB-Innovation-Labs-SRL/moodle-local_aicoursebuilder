# AI Course Builder (local_aicoursebuilder)

Moodle local plugin that will generate course structure and content with AI.
Current state (version 0.1.0, alpha): the AI connector layer, the ingestion of source files (PDF, DOCX, PPTX, XLSX,
text, OCR), the generation pipeline (brief, outline, sections) and the wizard at `/local/aicoursebuilder/wizard.php`,
which creates a job, shows its estimated cost, starts it and follows it until the blueprint is ready for review.
Once a blueprint is approved the course is built from it: the course, its sections and subsections, and the
pages, labels, links, files, folders, books, quizzes, glossaries, forums, lessons, wikis, choices, feedback activities and
assignments; only the types without a builder yet (H5P and SCORM) are left for the teacher to add.
The course is built with what the blueprint says about progress: the completion of each activity, the completion of the
course, the access restrictions (an activity or a section that waits for another activity, for a grade in it, or for a
date), the competencies and the badges. Competencies are linked, never created: the administrator chooses a competency
framework in the settings, and the competencies of a blueprint are looked up in it by their idnumber. Badges are created
inactive, for the teacher to look at and switch on. Whatever cannot be done, such as a rule that waits for an activity
that does not track completion, is left out with a warning and the course is built all the same.
The entry point is the list of jobs at `/local/aicoursebuilder/`, linked as "AI Course Builder" in the primary
navigation for everybody who may use the plugin: a user sees their own jobs, with the way to a new course, and a manager
can read those of everybody.
Spending is limited per job, per user and month, and for the whole site. The usage report at
`/local/aicoursebuilder/usage.php` (capability `local/aicoursebuilder:viewusage`) shows tokens and cost per job, user,
model and month from the AI call log, and how each limit stands. The user and the managers are notified when a limit's
alert percentage is reached; a job that a limit stops is paused, not failed, and its owner resumes it from the job
page once the limit is raised, without paying again for the steps it had finished.
The report counts what this Moodle instance sent, so it matches the provider's console only for the key that instance
uses: every environment (local development, CI, the test server, production) must have its own API key, or the provider
shows one total that cannot be told apart. The automated tests run on the fake connector or on a mocked HTTP client and never call a provider.
A call that the provider billed but the plugin refused, such as an answer cut off at the output limit, is logged and
charged at what the provider billed.

## Requirements

### Required

- Moodle 5.2 or 5.3 (`$plugin->supported = [502, 503]`).
- PHP 8.3 or newer, and the database that Moodle asks for: PostgreSQL 16+ or MariaDB 10.11+ for Moodle 5.2,
  PostgreSQL 17+ or MariaDB 11.4+ for Moodle 5.3.

### Optional, on the server

The plugin installs and runs without these. They are used to read some of the source files of a course:

- **poppler-utils** (`pdftotext`, `pdftoppm`) and **tesseract-ocr with the language data of the documents**, for
  Romanian `tesseract-ocr-ron`: they read a PDF that is a scan, with no text layer, and give a fallback for PDF files
  that the PHP extractor cannot read.
- **LibreOffice** (`soffice`): a fallback for DOCX and PPTX files that the PHP extractors cannot read.
- **`proc_open` allowed** in the `disable_functions` of PHP: the programs above are run with it, so on a hosting that
  blocks it none of them can run, even if they are installed.

What is lost without them: a scanned PDF cannot be read, and the PDF, DOCX and PPTX files that the PHP extractors
cannot read have no fallback. Everything else works. The teacher is told to upload a version of the document with
text (not scanned).

The AI model only receives the text that was extracted. The provider that is chosen (DeepSeek or any other) does not
change which files can be read.

Set the paths of the programs and the OCR language in Site administration > Plugins > Local plugins > AI Course
Builder. Site administration > Reports > System status shows whether OCR is available, and the OCR section of the
settings says the same.

## Installation

1. Copy or clone this repository to `public/local/aicoursebuilder` in your Moodle.
2. Run `php admin/cli/upgrade.php --non-interactive` (or visit Site administration > Notifications).

## Deployment (test server)

`deploy.sh` pulls a branch (`main` by default) into the plugin directory of the server, runs the Moodle upgrade and
purges the caches. It needs the plugin directory to be a git clone of this repository that the web user owns, and,
for a private repository, a read-only deploy key. Set it up once:

```bash
# 1. A deploy key for the web user. Add the public key in the repository on GitHub, Settings > Deploy keys (read only).
sudo -u www-data mkdir -p -m 700 /var/www/.ssh
sudo -u www-data ssh-keygen -t ed25519 -N '' -C 'aicb deploy' -f /var/www/.ssh/aicb_deploy
sudo cat /var/www/.ssh/aicb_deploy.pub
ssh-keyscan github.com | sudo -u www-data tee -a /var/www/.ssh/known_hosts   # compare with the fingerprints GitHub publishes

# 2. Make the plugin directory a clone. This overwrites the files that are in it with the ones of main.
cd /var/www/moodle/public/local/aicoursebuilder
sudo -u www-data git init -b main
sudo -u www-data git remote add origin git@github.com:AOB-Innovation-Labs-SRL/moodle-local_aicoursebuilder.git
sudo -u www-data env GIT_SSH_COMMAND='ssh -i /var/www/.ssh/aicb_deploy -o IdentitiesOnly=yes' git fetch origin main
sudo -u www-data git reset --hard origin/main
```

After that a deployment is one command:

```bash
DEPLOY_KEY=/var/www/.ssh/aicb_deploy ./deploy.sh              # branch main
DEPLOY_KEY=/var/www/.ssh/aicb_deploy GIT_BRANCH=dev ./deploy.sh
```

## Tests

- Code style: `phpcs --standard=moodle .`
- PHPUnit: `vendor/bin/phpunit --testsuite local_aicoursebuilder_testsuite`
- CI: GitHub Actions with moodle-plugin-ci, see `.github/workflows/ci.yml`.

### Verification script

One script, `scripts/verifica`, runs the checks, locally and in CI. The independent checks run as parallel
processes; at the end it shows the log of each one and a summary, and the exit status is 0 only if all passed.

```bash
scripts/verifica stil      # after each task: php -l and phpcs on the changed files (seconds)
scripts/verifica schimbari # after a group of tasks: stil, plus PHPUnit on the tests the changes touch
scripts/verifica rapid     # before a PR: php -l, phpcs and the whole PHPUnit suite (about 4 minutes)
scripts/verifica complet   # before a PR: everything the environment can run (adds Behat; in CI also phpdoc,
                           # savepoints, mustache and grunt)
```

- `stil` and `schimbari` work on the `.php` files that changed (commits, working tree, new files) since the last green
  run of the same level: after a green run the script saves the commit in `refs/verifica/stil-ok` (`stil`) or
  `refs/verifica/ok` (`schimbari`, `rapid`, `complet`), and the next run starts from it. Without that reference, or
  after a rebase, it starts from `origin/main` (run `git fetch origin` first). `stil` runs php -l and phpcs on them.
  `schimbari` adds PHPUnit on the tests that use what changed: for a changed file of `classes/` or `tests/fixtures/` the
  test files that mention the class by name (else the tests of its directory); a changed `*_test.php` runs by itself;
  `version.php` or `db/` (except `db/install.xml`) runs `install_test` and `upgrade_test`, after initialising the test
  site again if Moodle says it is out of date (a few minutes); `lang/` needs no PHPUnit. The whole suite runs only for
  `db/install.xml`, `lib.php`, `schema/` and `prompts/`. No Behat.
- **Locally** it runs in the webserver container of the Docker stack that mounts this plugin at
  `/var/www/html/public/local/aicoursebuilder` (AGENTS.md, Local environment). The script finds that container and
  moodle-cs (`/tmp/quality` or `/quality`) by itself; if it cannot, set `VERIFICA_CONTAINER` and `VERIFICA_PHPCS`.
  moodle-cs is installed once per container with:

  ```bash
  docker exec CONTAINER sh -c 'curl -sS https://getcomposer.org/download/latest-stable/composer.phar -o /tmp/composer.phar \
    && mkdir -p /tmp/quality && cd /tmp/quality \
    && php /tmp/composer.phar config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && php /tmp/composer.phar require --no-interaction moodlehq/moodle-cs'
  ```

  Behat runs only if it was initialised in the container (`php public/admin/tool/behat/cli/init.php`); `VERIFICA_NO_BEHAT=1` skips it. phpdoc is part of the
  moodle phpcs standard; savepoints, mustache and grunt exist only in moodle-plugin-ci, so they run only in CI.
- **Speed on Windows/macOS:** PHPUnit runs from a copy of Moodle kept on the container's own disk
  (`/opt/moodle-fast`), not from the mounted tree. After every test Moodle scans its whole code tree again, and on the
  mounted file system that scan costs about 6 s per test: 768 tests took 92 minutes, and take about 4 minutes from the
  copy (same results). The first run makes the copy (one-off, 20+ minutes on Windows, lost if the container is
  recreated); each run then copies the plugin and `phpunit.xml` again, which takes seconds. `VERIFICA_NO_FAST=1` runs
  in the mounted tree instead. After adding test files, regenerate `phpunit.xml` (`php public/admin/tool/phpunit/cli/init.php --disable-composer` in the container).
  The copy of Moodle is made again by itself when `public/version.php` changes, or with `VERIFICA_RESYNC=1`.
- **In CI** (`GITHUB_ACTIONS=true`) the same script runs `moodle-plugin-ci` for all checks, in parallel, after
  `moodle-plugin-ci install`. `.github/workflows/ci.yml` only prepares the environment and calls
  `scripts/verifica complet`.
- A pull request that changes only `*.md`, `deploy.sh` or `.gitignore` skips the checks (the jobs still report, so the
  required status checks of `main` are satisfied).
