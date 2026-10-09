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

## Tests

- Code style: `phpcs --standard=moodle .`
- PHPUnit: `vendor/bin/phpunit --testsuite local_aicoursebuilder_testsuite`
- CI: GitHub Actions with moodle-plugin-ci, see `.github/workflows/ci.yml`.
