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

namespace local_aicoursebuilder\output;

/**
 * The list of the generation jobs: the user's own, and for managers those of everybody else, to read.
 *
 * It is the entry point of the plugin: from here a teacher starts a new course, follows a job that is running, opens
 * the blueprint of one that is ready for review, or goes to the course that was built.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_list implements \renderable, \templatable {
    /** @var int How many jobs a page lists. */
    public const PERPAGE = 25;

    /** @var string[] Job statuses of which the blueprint can be opened in the editor. */
    private const REVIEWABLE = ['review', 'approved', 'building', 'finished'];

    /** @var bool Whether the jobs of everybody are listed, which only a manager may ask for. */
    private bool $showall;

    /** @var bool Whether the viewer may ask for the jobs of everybody. */
    private bool $canshowall;

    /** @var bool[] Whether the viewer may use the plugin in a context, by context, as far as it was asked. */
    private array $mayuse = [];

    /**
     * Creates the list.
     *
     * @param int $viewerid The user who looks at the list.
     * @param bool $showall Whether the jobs of everybody are asked for; it is refused to those who may not read them.
     * @param int $page The page to list, from 0.
     */
    public function __construct(
        /** @var int The user who looks at the list. */
        private int $viewerid,
        bool $showall = false,
        /** @var int The page to list, from 0. */
        private int $page = 0,
    ) {
        $this->canshowall = has_capability('local/aicoursebuilder:manage', \context_system::instance(), $viewerid);
        $this->showall = $showall && $this->canshowall;
        $this->page = max(0, $page);
    }

    /**
     * Tells whether the jobs of everybody are listed.
     *
     * @return bool
     */
    public function is_showing_all(): bool {
        return $this->showall;
    }

    /**
     * Exports the list for its template.
     *
     * @param \renderer_base $output The renderer.
     * @return \stdClass
     */
    #[\Override]
    public function export_for_template(\renderer_base $output): \stdClass {
        global $DB;

        // Only the jobs the viewer may open now are listed, and the page and the count are of those. The count is
        // worked out per context and the page is read with the filter in the query and a limit, so what is loaded
        // does not grow with the history of the jobs.
        $select = $this->showall ? '1 = 1' : 'userid = :userid';
        $params = $this->showall ? [] : ['userid' => $this->viewerid];
        [$total, $visiblesql, $visibleparams] = $this->visibility($select, $params);
        $records = $DB->get_records_select(
            'local_aicb_job',
            "($select) AND ($visiblesql)",
            $params + $visibleparams,
            'timecreated DESC, id DESC',
            'id, userid, prompt, status, progress, courseid, categoryid, actualcost, timecreated',
            $this->page * self::PERPAGE,
            self::PERPAGE
        );
        // A job that came into a context after the count was made was not asked about; it is, before it is shown.
        $jobs = array_filter($records, fn($job) => $this->may_use_in($job));
        $owners = $this->showall ? $this->owners($jobs) : [];

        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = $this->export_job($job, $owners[(int) $job->userid] ?? '');
        }

        $base = new \moodle_url('/local/aicoursebuilder/index.php', $this->showall ? ['all' => 1] : []);
        return (object) [
            'newcourseurl' => (new \moodle_url('/local/aicoursebuilder/wizard.php'))->out(false),
            'canshowall' => $this->canshowall,
            'showall' => $this->showall,
            'toggleurl' => (new \moodle_url('/local/aicoursebuilder/index.php', $this->showall ? [] : ['all' => 1]))->out(false),
            'hasjobs' => $rows !== [],
            'jobs' => $rows,
            'pagingbar' => $output->render(new \paging_bar($total, $this->page, self::PERPAGE, $base)),
        ];
    }

    /**
     * Works out how many jobs the viewer may open, and the condition that keeps the others out of a query.
     *
     * The job page and the review page ask for local/aicoursebuilder:use in the context of the job, so that is what
     * decides here, at this moment: a teacher who has lost a course does not see its jobs any more, whatever else they
     * can still use. A job whose course or category is gone has no context to ask in, and is left out.
     *
     * The jobs are counted per context, so what is read from the database is as many rows as there are contexts that
     * hold jobs, and each context is asked about once. The condition names whichever are fewer, the contexts the
     * viewer may use or those they may not, so it stays short for a teacher, who uses few, and for a manager, who is
     * denied almost none.
     *
     * @param string $select The condition that says whose jobs are listed.
     * @param array $params Its parameters.
     * @return array [how many jobs the viewer may open, the condition, its parameters]
     */
    private function visibility(string $select, array $params): array {
        global $DB;

        $groups = $DB->get_recordset_sql(
            "SELECT courseid, categoryid, COUNT(1) AS jobs FROM {local_aicb_job} WHERE $select GROUP BY courseid, categoryid",
            $params
        );
        $contexts = [];
        foreach ($groups as $group) {
            [$type, $id] = $this->context_of($group);
            $key = $type . $id;
            if (!isset($contexts[$key])) {
                $contexts[$key] = ['type' => $type, 'id' => $id, 'jobs' => 0, 'allowed' => $this->may_use_in($group)];
            }
            $contexts[$key]['jobs'] += (int) $group->jobs;
        }
        $groups->close();

        $allowed = array_filter($contexts, fn($context) => $context['allowed']);
        $denied = array_filter($contexts, fn($context) => !$context['allowed']);
        $total = array_sum(array_column($allowed, 'jobs'));
        if (count($allowed) <= count($denied)) {
            return [$total, ...$this->condition($allowed, 'jla')];
        }
        if (!$denied) {
            return [$total, '1 = 1', []];
        }
        [$sql, $conditionparams] = $this->condition($denied, 'jld');
        return [$total, 'NOT ' . $sql, $conditionparams];
    }

    /**
     * Returns the condition that holds for the jobs in some contexts.
     *
     * Every part of it is true or false, never null, so that it can be negated.
     *
     * @param array[] $contexts Contexts as visibility() lists them: type, id.
     * @param string $prefix Prefix of the names of the parameters.
     * @return array [condition, parameters]
     */
    private function condition(array $contexts, string $prefix): array {
        global $DB;

        $ids = ['course' => [], 'category' => []];
        $system = false;
        foreach ($contexts as $context) {
            if ($context['type'] === 'system') {
                $system = true;
            } else {
                $ids[$context['type']][] = $context['id'];
            }
        }

        $parts = [];
        $params = [];
        if ($ids['course']) {
            [$in, $inparams] = $DB->get_in_or_equal($ids['course'], SQL_PARAMS_NAMED, $prefix . 'c');
            $parts[] = "(courseid IS NOT NULL AND courseid $in)";
            $params += $inparams;
        }
        if ($ids['category']) {
            [$in, $inparams] = $DB->get_in_or_equal($ids['category'], SQL_PARAMS_NAMED, $prefix . 'k');
            $parts[] = "((courseid IS NULL OR courseid = 0) AND categoryid IS NOT NULL AND categoryid $in)";
            $params += $inparams;
        }
        if ($system) {
            $parts[] = '((courseid IS NULL OR courseid = 0) AND (categoryid IS NULL OR categoryid = 0))';
        }
        return [$parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0', $params];
    }

    /**
     * Returns the context a job belongs to, the way job_manager::get_context() has it: the course, else the category,
     * else the system.
     *
     * @param \stdClass $job A row with at least courseid and categoryid.
     * @return array [type (course, category or system), id (0 for the system)]
     */
    private function context_of(\stdClass $job): array {
        if (!empty($job->courseid)) {
            return ['course', (int) $job->courseid];
        }
        return !empty($job->categoryid) ? ['category', (int) $job->categoryid] : ['system', 0];
    }

    /**
     * Tells whether the viewer holds the capability to use the plugin in the context of a job.
     *
     * The answer is kept for the rest of the request, because many jobs share a context.
     *
     * @param \stdClass $job A row with at least courseid and categoryid.
     * @return bool
     */
    private function may_use_in(\stdClass $job): bool {
        [$type, $id] = $this->context_of($job);
        $key = $type . $id;
        if (!isset($this->mayuse[$key])) {
            $context = match ($type) {
                'course' => \context_course::instance($id, IGNORE_MISSING),
                'category' => \context_coursecat::instance($id, IGNORE_MISSING),
                default => \context_system::instance(),
            };
            $this->mayuse[$key] = $context && has_capability('local/aicoursebuilder:use', $context, $this->viewerid);
        }
        return $this->mayuse[$key];
    }

    /**
     * Returns the full names of the owners of some jobs.
     *
     * @param \stdClass[] $jobs The local_aicb_job rows.
     * @return string[] Full name per user id.
     */
    private function owners(array $jobs): array {
        global $DB;

        $userids = array_unique(array_map(fn($job) => (int) $job->userid, $jobs));
        $names = [];
        if ($userids) {
            foreach ($DB->get_records_list('user', 'id', $userids) as $user) {
                $names[(int) $user->id] = fullname($user);
            }
        }
        return $names;
    }

    /**
     * Formats a job for a row of the list.
     *
     * @param \stdClass $job The local_aicb_job row.
     * @param string $owner The full name of its owner, shown when the jobs of everybody are listed.
     * @return array
     */
    private function export_job(\stdClass $job, string $owner): array {
        $reviewable = in_array($job->status, self::REVIEWABLE, true);
        $finished = $job->status === 'finished' && !empty($job->courseid);
        return [
            'title' => shorten_text(format_string($job->prompt), 90),
            'url' => (new \moodle_url('/local/aicoursebuilder/job.php', ['id' => $job->id]))->out(false),
            'owner' => $owner,
            'status' => get_string('wizard:stage_' . $job->status, 'local_aicoursebuilder'),
            'progress' => (int) $job->progress,
            'cost' => format_float((float) $job->actualcost, 4),
            'date' => userdate((int) $job->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'hasreview' => $reviewable,
            'reviewurl' => (new \moodle_url('/local/aicoursebuilder/review.php', ['id' => $job->id]))->out(false),
            'hascourse' => $finished,
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $job->courseid]))->out(false),
        ];
    }
}
