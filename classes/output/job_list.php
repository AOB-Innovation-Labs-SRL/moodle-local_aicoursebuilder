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

        // Only the jobs the viewer may open now are listed, and the page and the count are of those.
        $visible = $this->visible_ids();
        $total = count($visible);
        $pageids = array_slice($visible, $this->page * self::PERPAGE, self::PERPAGE);
        $jobs = [];
        if ($pageids) {
            $records = $DB->get_records_list(
                'local_aicb_job',
                'id',
                $pageids,
                '',
                'id, userid, prompt, status, progress, courseid, actualcost, timecreated'
            );
            foreach ($pageids as $id) {
                $jobs[$id] = $records[$id];
            }
        }
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
     * Returns the ids of the jobs the viewer may open, the newest first.
     *
     * The job page and the review page ask for local/aicoursebuilder:use in the context of the job, so that is what
     * decides here, at this moment: a teacher who has lost a course does not see its jobs any more, whatever else they
     * can still use. A job whose course or category is gone has no context to ask in, and is left out.
     *
     * @return int[] Job ids.
     */
    private function visible_ids(): array {
        global $DB;

        $select = $this->showall ? '1 = 1' : 'userid = :userid';
        $params = $this->showall ? [] : ['userid' => $this->viewerid];
        $rows = $DB->get_records_select(
            'local_aicb_job',
            $select,
            $params,
            'timecreated DESC, id DESC',
            'id, courseid, categoryid'
        );

        // Many jobs share a context, which is asked about once.
        $allowed = [];
        $ids = [];
        foreach ($rows as $row) {
            $key = !empty($row->courseid)
                ? 'course' . $row->courseid
                : (!empty($row->categoryid) ? 'category' . $row->categoryid : 'system');
            if (!isset($allowed[$key])) {
                $allowed[$key] = $this->may_use_in($row);
            }
            if ($allowed[$key]) {
                $ids[] = (int) $row->id;
            }
        }
        return $ids;
    }

    /**
     * Tells whether the viewer holds the capability to use the plugin in the context of a job.
     *
     * The context is the one job_manager::get_context() gives: the course, else the category, else the system.
     *
     * @param \stdClass $job A local_aicb_job row with at least courseid and categoryid.
     * @return bool
     */
    private function may_use_in(\stdClass $job): bool {
        if (!empty($job->courseid)) {
            $context = \context_course::instance((int) $job->courseid, IGNORE_MISSING);
        } else if (!empty($job->categoryid)) {
            $context = \context_coursecat::instance((int) $job->categoryid, IGNORE_MISSING);
        } else {
            $context = \context_system::instance();
        }
        return $context && has_capability('local/aicoursebuilder:use', $context, $this->viewerid);
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
