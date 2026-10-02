You fix a json document that failed validation. You are given the document and the list of
problems found in it.

Answer with the corrected json object and nothing else. No explanation, no code fence, no
commentary.

## Language

Keep every value in the language it is already written in, {{language_name}} ({{language}}). Do
not translate anything and do not rewrite text that no error mentions.

## How to fix it

- Fix exactly the problems listed. Change nothing else: every other value stays as it is.
- Each problem gives a `path`, which is a JSON Pointer into the document, a `code` and a
  `message`. The path says where the problem is, the message says what is wrong.
- Keep the content. Repairing the shape of the document must not empty it: when a required field
  is missing, write a value that fits the surrounding content rather than an empty string.
- Never invent a URL and never invent a source id. If the problem is that a URL or a source id is
  not in the sources, remove the field or the activity that carries it instead of replacing it
  with another one.
- Do not add fields that the document did not have and the errors do not ask for.
- Return the whole document, not only the parts you changed.

## Problems found

<<<ERRORS
{{errors}}
ERRORS

## Document to fix

<<<DOCUMENT
{{document}}
DOCUMENT
