# Administrator guide

This guide covers every setting of AI Course Builder, the AI providers, keys, cost limits, job retention and the
Moodle setting `curlsecurityblockedhosts` that decides whether a local model can be reached. The settings are in
Site administration > Plugins > Local plugins > AI Course Builder (source files, text extraction, limits, building the
course) and AI Course Builder: connectors (providers, prices, routes, output limits). A test
(`tests/settings_documented_test.php`) fails when a setting of `settings.php` is missing from this page.

## Who may do what

| Capability | Default roles | Meaning |
| --- | --- | --- |
| `local/aicoursebuilder:use` | Teacher, Manager | Use the wizard, the job list and the editor in a course. |
| `local/aicoursebuilder:generateincourse` | Teacher, Manager | Add generated content to an existing course. |
| `local/aicoursebuilder:viewusage` | Manager | Open the usage report (Site administration > Plugins > Local plugins, or `/local/aicoursebuilder/usage.php`). |
| `local/aicoursebuilder:manage` | Manager | Read the jobs of every user and receive the cost alerts. |
| `local/aicoursebuilder:usedirectconnectors` | Manager | Use the direct provider connectors (carries a configuration risk). |

A teacher sees only their own jobs, and only in a course where they have the capability.

## Source files

| Setting | Default | What it does |
| --- | --- | --- |
| `allowedtypes` | all types | The file types that teachers may upload: PDF, DOCX, PPTX, XLSX, HTML, Markdown and plain text. |
| `maxfilesize` | 50 | The largest source file, in megabytes. |
| `maxfiles` | 10 | The most source files a single job may have. |
| `jobretentiondays` | 90 | Retention, see [Retention](#retention). |
| `digest_maxinputtokens` | 60000 | A document longer than this is read in several calls and the results are merged. Keep it well under the context size of the model of the digest step. |

The wizard shows the teacher the same limits, also for somebody the file picker does not limit.

## Text extraction (optional programs)

The plugin installs and runs without these programs. They only widen what can be read: a scanned PDF needs OCR, and
the DOCX and PPTX files that the PHP extractors cannot read need LibreOffice. Leave a path empty to disable that
fallback.

| Setting | What it does |
| --- | --- |
| `pdftotextpath` | Full path of `pdftotext` (Poppler), the fallback for PDF files and a part of the LibreOffice fallback. |
| `sofficepath` | Full path of `soffice` (LibreOffice): DOCX and PPTX are converted to PDF and read with `pdftotext`, so that path must be set too. |
| `pdftoppmpath` | Full path of `pdftoppm` (Poppler), which makes the page images that OCR reads. |
| `tesseractpath` | Full path of `tesseract`, which reads the page images (OCR) together with `pdftoppm`. |
| `ocrlanguage` | Tesseract language code, `ron` by default; join languages with `+` (`ron+eng`). The language data (`tesseract-ocr-ron` for Romanian) must be installed. |
| `ocrstatus` | Not an input: it shows whether OCR works on this server, the same state as Site administration > Reports > System status. |

PHP must be allowed to run programs: if `proc_open` is in `disable_functions`, none of the programs can run.

## Providers and keys

`defaultconnector` is the connector used by every step that has no route of its own (see [Routes](#routes-per-step)).
The default is DeepSeek. The options are:

| Connector | Notes |
| --- | --- |
| DeepSeek | Base URL without `/v1`. DeepSeek processes data in China: send only public, non-personal content. |
| Anthropic | The model must support forced tool use or native structured outputs. |
| Google Gemini | An AI Studio key, not Vertex AI. There is no default model: set one before using the connector. Enable `gemini_native_json_schema` only for Gemini 2.5 and later. |
| OpenAI-compatible endpoint | One endpoint: OpenAI, Azure OpenAI, Ollama, vLLM, LiteLLM or OpenRouter. The base URL goes up to, not including, `/chat/completions`. `openaicompat_authtype` is Bearer or `api-key` (Azure). `openaicompat_apiversion` is for Azure only. Leave the key empty for a server that has none. |
| Moodle AI subsystem (`core_ai`) | No settings here: it uses the provider and model of the site AI settings. |

The settings of the connectors:

| Setting | What it does |
| --- | --- |
| `defaultconnector` | The connector of every step without a route of its own. DeepSeek by default. |
| `deepseek_apikey` | The DeepSeek key, stored encrypted. |
| `deepseek_baseurl` | Base URL of the API, without `/v1`: `https://api.deepseek.com`. |
| `deepseek_model` | The model of the steps that have none of their own, for example `deepseek-flash`. |
| `deepseek_thinking` | Thinking mode: more output tokens, and the temperature is ignored. |
| `anthropic_apikey` | The Anthropic key, stored encrypted. |
| `anthropic_model` | The model of the steps that have none of their own. |
| `anthropic_thinking` | Extended thinking, which uses more output tokens. |
| `gemini_apikey` | The AI Studio key, stored encrypted. |
| `gemini_model` | The model of the steps that have none of their own. It has no default: set it. |
| `gemini_thinking` | Thinking, which uses more output tokens. |
| `gemini_native_json_schema` | Sends the JSON Schema unconverted (Gemini 2.5 and later); leave off otherwise. |
| `openaicompat_baseurl` | The URL up to, not including, `/chat/completions`, for example `https://api.openai.com/v1`. Only one instance can be set. |
| `openaicompat_apikey` | The key, stored encrypted. Leave empty for a server that enforces none. |
| `openaicompat_authtype` | `Authorization: Bearer` (OpenAI, Ollama, vLLM, LiteLLM, OpenRouter) or `api-key` (Azure OpenAI). |
| `openaicompat_apiversion` | Sent as the `api-version` parameter, for Azure OpenAI only. |
| `openaicompat_model` | The model name sent in every request. |
| `openaicompat_supports_json_schema` | Enable only if the model accepts `response_format: json_schema`; if unsure leave off. |
| `openaicompat_supports_vision` | Enable only if the model accepts images. |

- **Keys.** The keys (`*_apikey`) are stored encrypted with `\core\encryption` and are never shown again after saving.
  They are not in the repository, in JavaScript or in logs.
- **One key for each environment.** The usage report counts what this Moodle instance sent. Give local development, CI,
  the test server and production each their own key, or the provider console shows one total that cannot be told apart.
- **Thinking.** `*_thinking` switches on the extended reasoning of the model. It uses more output tokens, so it costs more.
- **Model names.** `*_model` is the model of the steps that have no model of their own.

### `curlsecurityblockedhosts`

All HTTP calls go through the Moodle HTTP client, which obeys the security settings of Moodle (Site administration >
General > Security > HTTP security): `curlsecurityblockedhosts` (hosts and IP ranges that are refused) and
`curlsecurityallowedport` (the ports that are allowed). The defaults refuse `localhost`, private network ranges and
ports other than 80 and 443. The cloud providers are public hosts on port 443 and need nothing. A local model, such as
Ollama at `http://localhost:11434/v1` or a vLLM server on the internal network, is **refused until an administrator
allows it explicitly**: remove its host from `curlsecurityblockedhosts` (or the range that holds it) and add its port to
`curlsecurityallowedport`. This opens that host to every Moodle feature that makes HTTP calls, so allow only the host
that is needed.

## Cost limits

All amounts are in USD; 0 means no limit.

| Setting | Default | What it does |
| --- | --- | --- |
| `joblimitusd` | 5 | A job whose estimated cost is higher is not started: the wizard says so and disables the start button. |
| `userlimitusd` | 20 | The AI spending allowed per user and month. A limit set for a single user overrides it. |
| `sitelimitusd` | 200 | The spending allowed per month on the whole site. |
| `alertpercent` | 80 | The user and the managers are notified when a monthly budget reaches this percentage of its limit. |

A job that a limit stops is paused, not failed: what was already paid for is kept, and its owner resumes it from the job
page once the limit is raised. The limit is checked before every call against the reserved cost, which is the estimated
tokens priced at the output limit of the step, so a call that would break a limit is not sent. A call that the provider
billed but the plugin refused, such as an answer cut off at the output limit, is logged and charged at the billed cost.

The usage report shows tokens and cost per job, user, model and month, and how each limit stands.

## Cost estimate: prices and peak windows

The prices compute the real cost of a call and estimate it before it is sent. For each connector `{name}`
(`deepseek`, `coreai`, `anthropic`, `gemini`, `openaicompat`) there are three settings:

| Setting | What it does |
| --- | --- |
| `token_estimator_charsperfactor_{name}` | The characters per token (default 3.5) used to estimate the tokens of a request before it is sent. |
| `pricing_{name}` | A JSON object that maps a model name to prices per 1,000,000 tokens: `input_miss`, `input_hit`, `output`, an optional `cache_write` (falls back to `input_miss`) and an optional `offpeak` object with the same keys. Empty or invalid uses the defaults, which the setting shows. |
| `peakwindows_{name}` | A JSON object that maps an ISO weekday (1 = Monday ... 7 = Sunday) to a list of `[start, end)` UTC hour ranges, for example `{"1": [[1, 4], [6, 10]]}`. Empty or invalid uses the DeepSeek pilot schedule: 01:00-04:00 and 06:00-10:00 UTC, Monday to Friday. |

A teacher can choose **Run off-peak** in the wizard: the AI work is then scheduled when the provider is cheaper, so
the blueprint can take longer to be ready.

## Routes per step

The pipeline has these steps: document digest, course brief, course outline, section content, activities, quiz
questions, review and JSON repair. For each step `{step}` (`digest`, `brief`, `outline`, `sections`, `activities`,
`questions`, `review`, `repair`):

| Setting | What it does |
| --- | --- |
| `route_{step}_connector` | The connector of the step. Empty uses `defaultconnector`. |
| `route_{step}_model` | The model of the step. Empty uses the default model of the connector (ignored for the Moodle AI subsystem). |
| `maxtokens_{step}` | The most tokens the model may write in one answer of the step. An answer that reaches it is cut off and its part is marked truncated. Empty or 0 uses the default; the provider accepts at most 393216. |

Two more settings tune the quiz:

| Setting | What it does |
| --- | --- |
| `questions_min` | The fewest quiz questions for each objective, from 5 to 15 (default 5). |
| `questions_max` | The most quiz questions for each objective, from 5 to 15 (default 15). |

Without an explicit output limit DeepSeek writes at most 8K tokens, which cuts long sections and quizzes off, so the
defaults are larger (from 4096 for the brief up to 32768 for sections, questions and repair). The output priced at the
limit is also what the budget reserves for each call.

## Parallelism and retry

| Setting | Default | What it does |
| --- | --- | --- |
| `pool_concurrency` | 4 | The independent sub-calls (sections, questions of a section) run at the same time within one task. Clamped to 1-6. |
| `retry_maxattempts` | 3 | The attempts per call, the first included. Only 429, 500, 502, 503, 504, 529 and network errors are retried. |
| `retry_basedelayms` | 500 | The delay before the first retry, doubled each time, with random jitter. Ignored when the provider sends `Retry-After`. |

## Building the course

| Setting | What it does |
| --- | --- |
| `competencyframework` | The framework in which the competencies of a blueprint are looked up by their idnumber. Competencies are linked, never created. Without a framework they are not linked. |

Badges are created inactive, for the teacher to look at and switch on. A rule that cannot be built, such as a condition
that waits for an activity that does not track completion, is left out with a warning, and the course is built all the same.

## Retention

`jobretentiondays` (default 90) is the number of days after which finished, failed and cancelled jobs are meant to be
deleted with their sources and intermediate data; 0 keeps them. **The cleanup itself is not implemented yet**: the
setting exists, but no scheduled task deletes anything, so jobs are kept until it is added. Until then, delete the jobs
and their files by hand if the privacy policy of your site needs it. The Moodle privacy API (export and deletion of the
personal data of a user) is implemented for every table that holds a user id.

## Notifications

The users are notified (pop-up and email, which each user can change in their preferences) when: a job has finished,
a job has failed for good, a spending limit has reached the alert percentage, and a cost limit has paused a job.

## Environment

- Moodle 5.2 or 5.3, PHP 8.3 or newer, PostgreSQL 16+ or MariaDB 10.11+ (Moodle 5.2) and PostgreSQL 17+ or MariaDB 11.4+
  (Moodle 5.3).
- The generation and the build run as ad hoc tasks: **cron must run**, or nothing happens after the teacher presses
  Start. Nothing calls an AI provider inside a web request.
- Languages of the interface: English and Romanian.
