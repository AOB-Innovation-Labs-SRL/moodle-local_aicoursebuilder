# User guide

AI Course Builder writes a course from your documents. You describe the course, the plugin shows what it will cost,
writes a **blueprint** (the whole course as a tree of sections, activities and questions), you correct it, and when you
approve it the course is built from it. The interface is in English and Romanian, following your language in Moodle.

## Where to start

- **"AI Course Builder"** in the main navigation opens the list of your generations, with their status, cost and date,
  and the way to a new course.
- Inside a course, the wizard can also be opened to add content to that course.

You need the right to use the plugin in the course (teachers have it by default). If the link is missing, ask an
administrator.

## 1. The wizard

The wizard has four steps; the first line says which one you are on ("Step 2 of 4"). It does not move on while a step
is incomplete, and it tells you what is missing.

1. **Where.** Choose *Create a new course* (then the category of the new course) or *Add to an existing course* (then
   the course and the section).
2. **The course.** Say what the course is about: the topic and what learners should be able to do at the end. The more
   precise you are, the better the blueprint. You can also set the audience, the level, the tone, the language of the
   course and its duration in minutes.
3. **Sources.** Upload the documents the course is written from (PDF, Word, PowerPoint, Excel, HTML, Markdown or text).
   The step states how many files and how large they may be. You can also start without documents. A scanned PDF can be
   read only if the administrator installed OCR; otherwise upload a version with text.
4. **Cost and start.** The wizard shows the **estimated cost** in USD, the tokens it is based on and what is left of
   your limits. The estimate is approximate: the real cost can be higher or lower, and it is worked out again once your
   documents have been read.
   - If you have used most of your monthly budget, a warning says so.
   - If the estimate is over a limit, the start button is disabled: use fewer or smaller sources, or ask an administrator.
   - *Run off-peak* schedules the work when the provider is cheaper; the blueprint can then take longer. The job page says when it will start.
   - Accept the **Moodle AI usage policy** and press **Start generation**. Without accepting it the job does not start.

Your documents are sent to the AI provider that the administrator chose. Do not upload personal or confidential data
unless your institution allows it for that provider.

## 2. Follow the generation

After you start, the page shows a progress bar, what the job is doing now (reading the source files, writing the
blueprint) and what it has cost so far. You can leave the page: **you are notified when the blueprint is ready**, or
if the job stops. The job also stays in the list.

If a cost limit stops the job, it is **paused, not lost**: what was already paid for is kept, and when an administrator
has raised the limit you press **Resume** on the job page.

## 3. Review and edit the blueprint

When the status is *Ready for review*, press **Review the blueprint**. The editor has two parts: the tree of the course
on the left and the selected part on the right.

- Select a part in the tree to edit its fields (title, text, and so on).
- **Move up** and **Move down** change its place; **Delete** removes it.
- A part marked **Check** is one that the generation was not sure about. Read it and press **Mark as checked**.
- **Write this part again** asks the AI to rewrite just that part. You can add instructions first. This costs a little
  and counts against your limits.
- Parts with errors are marked in the tree.
- **Save** keeps your changes as a **new version** ("Version 2"); older versions are not overwritten. The status line
  says whether there are unsaved changes.

The editor works with the keyboard alone: use Tab to move between the tree and the fields, Enter or Space to press a
button. Messages (saved, errors, unsaved changes) are announced to screen readers.

## 4. Approve

**Approve** builds the course from the version you are looking at, after you confirm. A blueprint with errors can be
saved but **cannot be approved**; the editor lists what must be fixed. An approved version is **locked**: it can no
longer be changed, because the course is built from it. If you want something else, start a new generation.

## 5. The course

After approval the course is built by the server (this needs a few minutes; it happens in the background). The job page
then says *The course is built* and offers **Open the course**. The course holds the sections, subsections and
activities of the blueprint, with their completion tracking and access restrictions. The badges are created inactive:
look at them and switch them on yourself. Types of activity that cannot be built yet (H5P and SCORM) are left for you
to add. Whatever could not be built is listed with a warning, and the course is built all the same.

## Notifications

You get a message in Moodle (pop-up) and by email, and can change this in your notification preferences, when:

- a job has finished (the blueprint is ready, or the course is built);
- a job has failed for good, with the reason;
- your monthly spending reaches the alert percentage the administrator set;
- a cost limit has paused a job.

## Problems

| What you see | What to do |
| --- | --- |
| *Accept the Moodle AI usage policy to start.* | Tick the box in the last step. |
| *The estimated cost is over your cost limits.* | Use fewer or smaller documents, or ask an administrator to raise the limit. |
| *Paused by a cost limit* | Wait until the limit is raised, then press Resume. |
| *The generation stopped.* | Read the reason on the page. Press **Try again** to go on from where it stopped, or **Cancel and delete what was built** to remove the unfinished course (in an existing course, only the activities that were added). If the reason is the sources, start a new generation with other documents. |
| *The blueprint cannot be approved while it has errors* | Fix the parts marked with errors, save, and approve again. |
