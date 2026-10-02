## Task: the learning activities of section {{section_id}}

Write the interactive learning activities of this one section, from the brief, outline and source
material above. Answer with a single json object and nothing else, in {{language_name}}
({{language}}), preserving all ids.

Return `{"id":"{{section_id}}","activities":[...]}`.

Choose the activities: use 1 to 3 activity types, never more, and only the ones that fit what this
section teaches and what the sources support. One or two well made activities are better than one
of every kind. A short or introductory section may need a single activity. Do not repeat a type
the existing teaching material below already covers in the same way.

Activity ids start with `{{section_id}}.` followed by the type and a number, such as
`{{section_id}}.assign1`. Each has `id`, `type`, `name` (at most 255 characters), `content`, and
`source_refs` from the available sources. Use only these types and the blueprint v1 content shape:

- `lesson`: `pages` with `id` (`p1`, `p2`), `type` (content, multichoice, truefalse,
  shortanswer), `title`, `contents` HTML, and `answers` with `text`, `jumpto` (next, end,
  this, or another page id), optional `response` and `score`. Every jump must resolve to a page
  of the same lesson. At most 6 pages.
- `assign`: `submission_types` from onlinetext and file, optional `grade`, `duedate`,
  `maxfiles` (1–100), `filetypes`.
- `forum`: `forumtype` general or qanda and optional `discussions` with `subject`, `message`.
- `glossary`: nonempty `entries` with `concept`, `definition`, optional `aliases`. At most 12
  entries.
- `wiki`: nonempty `pages` with `title`, `content`.
- `choice`: at least two distinct nonempty `options`, each at most 255 characters; optional
  `allowupdate`.
- `feedback`: nonempty `items` with `type` from multichoice, numeric, textarea, textfield,
  info; `name` at most 255 characters, optional `label`, `required`; multichoice needs at least
  two `options`; numeric may have `min` and `max` with min no greater than max.

Keep all source-based claims traceable in `source_refs`. Use only source ids listed above.

## Existing teaching material of this section

<<<ACTIVITIES
{{activities}}
ACTIVITIES

## Additional teacher instructions, if any

<<<INSTRUCTIONS
{{instructions}}
INSTRUCTIONS

## This section

<<<SECTION
{{section}}
SECTION
