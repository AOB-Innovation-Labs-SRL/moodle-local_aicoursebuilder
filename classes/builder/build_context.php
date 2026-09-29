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

namespace local_aicoursebuilder\builder;

/**
 * State shared by the builders while one course is built.
 *
 * The build map ({nodeid: {cmid, instanceid, sectionnum, status}}) is the source of truth: it is
 * persisted in local_aicb_job.buildmap after every recorded node, so a re-run skips built nodes and
 * a rollback deletes exactly the cmids it holds. The section map and the cm map are views of it.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_context {
    /** @var string Pattern of section and subsection node ids. */
    public const SECTION_PATTERN = '/^s[0-9]+(-[0-9]+)?$/';

    /** @var array Build map, node id => ['cmid', 'instanceid', 'sectionnum', 'status']. */
    protected array $buildmap = [];

    /**
     * Creates the context.
     *
     * @param \stdClass $course The course being built.
     * @param \context|null $qbankcontext Context of the mod_qbank instance that receives the questions.
     * @param array $buildmap Build map restored from a previous run.
     * @param int|null $jobid Job whose buildmap field is updated on every record(), null to keep it in memory.
     */
    public function __construct(
        /** @var \stdClass The course being built. */
        public readonly \stdClass $course,
        /** @var \context|null Question bank context. */
        public readonly ?\context $qbankcontext = null,
        array $buildmap = [],
        /** @var int|null Job id. */
        public readonly ?int $jobid = null,
    ) {
        foreach ($buildmap as $nodeid => $entry) {
            $this->buildmap[(string) $nodeid] = self::normalise_entry((array) $entry);
        }
    }

    /**
     * Creates the context of a job, restoring its persisted build map.
     *
     * @param \stdClass $job Record from local_aicb_job.
     * @param \stdClass $course The course being built.
     * @param \context|null $qbankcontext Question bank context.
     * @return self
     */
    public static function from_job(\stdClass $job, \stdClass $course, ?\context $qbankcontext = null): self {
        $buildmap = [];
        if (!empty($job->buildmap)) {
            $buildmap = json_decode($job->buildmap, true, 512, JSON_THROW_ON_ERROR);
        }
        return new self($course, $qbankcontext, $buildmap, (int) $job->id);
    }

    /**
     * Records the result of a builder and persists the build map.
     *
     * A skipped result keeps the ids already known for the node.
     *
     * @param build_result $result The builder result.
     */
    public function record(build_result $result): void {
        $entry = $result->to_buildmap_entry();
        $previous = $this->buildmap[$result->nodeid] ?? null;
        if ($previous !== null && $result->status === build_result::STATUS_SKIPPED) {
            foreach (['cmid', 'instanceid', 'sectionnum'] as $field) {
                $entry[$field] = $entry[$field] ?? $previous[$field];
            }
            $entry['status'] = $previous['status'];
        }
        $this->buildmap[$result->nodeid] = $entry;
        $this->save();
    }

    /**
     * Writes the build map to local_aicb_job.buildmap, when the context belongs to a job.
     */
    public function save(): void {
        global $DB;

        if ($this->jobid === null) {
            return;
        }
        $DB->update_record('local_aicb_job', (object) [
            'id' => $this->jobid,
            'buildmap' => $this->to_json(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Tells whether a node already exists in the course.
     *
     * @param string $nodeid Blueprint node id.
     * @return bool
     */
    public function is_built(string $nodeid): bool {
        $status = $this->buildmap[$nodeid]['status'] ?? null;
        return $status === build_result::STATUS_CREATED || $status === build_result::STATUS_SKIPPED;
    }

    /**
     * Returns the build map entry of a node.
     *
     * @param string $nodeid Blueprint node id.
     * @return array|null ['cmid', 'instanceid', 'sectionnum', 'status'], null when the node was never recorded.
     */
    public function get_entry(string $nodeid): ?array {
        return $this->buildmap[$nodeid] ?? null;
    }

    /**
     * Returns the course module id of a node.
     *
     * @param string $nodeid Blueprint node id (s1.quiz1, or s1-1 for the subsection module).
     * @return int|null
     */
    public function get_cmid(string $nodeid): ?int {
        return $this->buildmap[$nodeid]['cmid'] ?? null;
    }

    /**
     * Returns the section number created for a section or subsection node.
     *
     * @param string $sectionid Blueprint section id (s1 or s1-1).
     * @return int|null
     */
    public function get_sectionnum(string $sectionid): ?int {
        return $this->get_sectionmap()[$sectionid] ?? null;
    }

    /**
     * Returns the section map.
     *
     * @return int[] Section or subsection node id => section number.
     */
    public function get_sectionmap(): array {
        $map = [];
        foreach ($this->buildmap as $nodeid => $entry) {
            if ($entry['sectionnum'] !== null && preg_match(self::SECTION_PATTERN, $nodeid)) {
                $map[$nodeid] = $entry['sectionnum'];
            }
        }
        return $map;
    }

    /**
     * Returns the cm map. Rollback in an existing course deletes exactly these cmids.
     *
     * @return int[] Node id => course module id.
     */
    public function get_cmidmap(): array {
        $map = [];
        foreach ($this->buildmap as $nodeid => $entry) {
            if ($entry['cmid'] !== null) {
                $map[$nodeid] = $entry['cmid'];
            }
        }
        return $map;
    }

    /**
     * Returns the whole build map.
     *
     * @return array Node id => ['cmid', 'instanceid', 'sectionnum', 'status'].
     */
    public function get_buildmap(): array {
        return $this->buildmap;
    }

    /**
     * Encodes the build map as stored in local_aicb_job.buildmap.
     *
     * @return string JSON object.
     */
    public function to_json(): string {
        return json_encode((object) $this->buildmap, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Normalises one build map entry read from storage.
     *
     * @param array $entry Raw entry.
     * @return array
     */
    protected static function normalise_entry(array $entry): array {
        $toint = fn($value) => $value === null ? null : (int) $value;
        return [
            'cmid' => $toint($entry['cmid'] ?? null),
            'instanceid' => $toint($entry['instanceid'] ?? null),
            'sectionnum' => $toint($entry['sectionnum'] ?? null),
            'status' => (string) ($entry['status'] ?? build_result::STATUS_FAILED),
        ];
    }
}
