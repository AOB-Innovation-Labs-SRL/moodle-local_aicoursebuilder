## Task: the teaching material of section {{section_id}}

Write the teaching material of this one section, from the brief, outline and source material
above. Answer with a single json object and nothing else, in {{language_name}} ({{language}}).

A json object with `id` and `activities`:

- `id`: `{{section_id}}`, exactly as written.
- `activities`: 2 to 4 activities that teach this section's objectives, in the order the learner
  meets them. Fewer, fuller activities are better than many thin ones.

Each activity has `id`, `type` and `name` (at most 255 characters), and the content its type
requires. The id is the section id, a dot, the type and a number: `{{section_id}}.page1`,
`{{section_id}}.book1`, `{{section_id}}.label1`. Numbering restarts at 1 per type.

Use only these types:

- `page`: one topic explained, at most about 600 words. `content` is `{"text": "<p>...</p>"}`.
- `book`: a longer topic split into 2 to 5 chapters. `content` is `{"chapters": [...]}`, each
  chapter an object with `title` and `content`, and `subchapter: true` on a chapter that belongs
  under the one before it.
- `label`: a short piece of text shown directly on the course page, to introduce or separate what
  follows. `content` is `{"text": "<p>...</p>"}`.
- `url`: a link to a page outside the course. `content` is `{"externalurl": "https://..."}`.
- `resource`: one of the uploaded documents, offered for download. `content` is
  `{"source": "src1"}`, with a source id from the list above.
- `folder`: several uploaded documents together. `content` is `{"sources": ["src1", "src2"]}`.

Do not use any other type: lessons, quizzes, assignments and the rest are written in later steps.

Optional fields on any activity:

- `intro`: one sentence of HTML saying what the activity is for.
- `completion`: `{"mode": "auto", "view": true}` for material the learner reads, or
  `{"mode": "manual"}` for anything else.
- `source_refs`: which source material the activity was written from. Each entry is an object with
  `source` and, when known, `chunk` and `page`.

Rules:

- Every activity that teaches content carries `source_refs`. Use only the source ids listed above;
  never invent one.
- URLs: use only URLs that appear literally in the source material. Never write a URL from memory,
  never guess one, and never repair a broken one. A `url` activity always carries `source_refs`
  pointing at where its address was found. If the sources contain no URL, add no `url` activity.
- HTML: use only `<p>`, `<ul>`, `<ol>`, `<li>`, `<strong>`, `<em>`, `<h3>`, `<h4>`, `<table>`,
  `<tr>`, `<th>`, `<td>`, `<code>` and `<pre>`. No scripts, no styles, no iframes, no images.
- Cover this section's objectives and stay inside them: the other sections cover the rest.

<<<SECTION
{{section}}
SECTION
