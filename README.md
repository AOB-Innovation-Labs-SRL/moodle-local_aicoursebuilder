# AI Course Builder (local_aicoursebuilder)

Moodle local plugin that will generate course structure and content with AI.
Current state (version 0.1.0, alpha): the AI connector layer, the ingestion of source files (PDF, DOCX, PPTX, XLSX,
text, OCR), the generation pipeline (brief, outline, sections) and the wizard at `/local/aicoursebuilder/wizard.php`,
which creates a job, shows its estimated cost, starts it and follows it until the blueprint is ready for review.
Once a blueprint is approved the course is built from it: the course, its sections and subsections, and the
pages, labels, links, files, folders, books, quizzes, glossaries, forums, lessons, wikis, choices, feedback activities and
assignments; only the types without a builder yet (H5P and SCORM) are left for the teacher to add.
The entry point is the list of jobs at `/local/aicoursebuilder/`, linked as "AI Course Builder" in the primary
navigation for everybody who may use the plugin: a user sees their own jobs, with the way to a new course, and a manager
can read those of everybody.
Spending is limited per job, per user and month, and for the whole site. The usage report at
`/local/aicoursebuilder/usage.php` (capability `local/aicoursebuilder:viewusage`) shows tokens and cost per job, user,
model and month from the AI call log, and how each limit stands. The user and the managers are notified when a limit's
alert percentage is reached; a job that a limit stops is paused, not failed, and its owner resumes it from the job
page once the limit is raised, without paying again for the steps it had finished.

## Requirements

- Moodle 5.2 or 5.3 (`$plugin->supported = [502, 503]`).
- PHP 8.3 or newer, PostgreSQL 16+ or MariaDB 10.11+ (per Moodle 5.2).

## Installation

1. Copy or clone this repository to `public/local/aicoursebuilder` in your Moodle.
2. Run `php admin/cli/upgrade.php --non-interactive` (or visit Site administration > Notifications).

## Tests

- Code style: `phpcs --standard=moodle .`
- PHPUnit: `vendor/bin/phpunit --testsuite local_aicoursebuilder_testsuite`
- CI: GitHub Actions with moodle-plugin-ci, see `.github/workflows/ci.yml`.
