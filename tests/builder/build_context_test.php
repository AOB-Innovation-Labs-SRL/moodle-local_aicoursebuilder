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
 * Tests for the builder contract: a builder runs on the golden blueprint and the build map persists.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\build_context
 * @covers     \local_aicoursebuilder\builder\build_result
 */
final class build_context_test extends \advanced_testcase {
    /**
     * Returns a builder that records fake ids, the way a real builder records Moodle ids.
     *
     * @return builder_interface
     */
    private function make_builder(): builder_interface {
        return new class implements builder_interface {
            /** @var int Next fake id. */
            public int $nextid = 1000;

            /**
             * Builds a node without touching Moodle.
             *
             * @param array|\stdClass $node Blueprint node.
             * @param build_context $context Build context.
             * @return build_result
             */
            public function build(array|\stdClass $node, build_context $context): build_result {
                $node = (array) $node;
                if ($context->is_built($node['id'])) {
                    return new build_result($node['id'], build_result::STATUS_SKIPPED);
                }
                if (preg_match('/^s[0-9]+$/', $node['id'])) {
                    return new build_result($node['id'], build_result::STATUS_CREATED, sectionnum: (int) substr($node['id'], 1));
                }
                $id = $this->nextid++;
                $sectionnum = isset($node['activities']) ? 100 + $id : null;
                return new build_result($node['id'], build_result::STATUS_CREATED, $id, $id, $sectionnum);
            }
        };
    }

    /**
     * Runs a builder over every node of the golden blueprint.
     *
     * @param builder_interface $builder The builder.
     * @param build_context $context The context.
     */
    private function build_golden(builder_interface $builder, build_context $context): void {
        $golden = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/blueprint_golden.json'),
            false,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($golden->sections as $section) {
            $context->record($builder->build($section, $context));
            foreach ($section->subsections ?? [] as $subsection) {
                $context->record($builder->build($subsection, $context));
                foreach ($subsection->activities as $activity) {
                    $context->record($builder->build($activity, $context));
                }
            }
            foreach ($section->activities as $activity) {
                $context->record($builder->build($activity, $context));
            }
        }
    }

    /**
     * A builder runs on the fixture, the build map persists in the job, and a re-run skips every node.
     */
    public function test_builder_on_golden_fixture(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $jobid = $DB->insert_record('local_aicb_job', (object) [
            'userid' => $user->id,
            'prompt' => 'Test',
            'courseid' => $course->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $job = $DB->get_record('local_aicb_job', ['id' => $jobid]);
        $context = build_context::from_job($job, $course, \context_course::instance($course->id));
        $this->build_golden($this->make_builder(), $context);

        $this->assertSame(['s1' => 1, 's1-1' => 1100, 's2' => 2, 's3' => 3], $context->get_sectionmap());
        $this->assertCount(15, $context->get_cmidmap(), '14 activities and one subsection module');
        $this->assertSame(1, $context->get_sectionnum('s1'));
        $this->assertSame(1000, $context->get_cmid('s1-1'));
        $this->assertNull($context->get_cmid('s1'));
        $this->assertTrue($context->is_built('s1.quiz1'));

        // The job holds the same map, so a new run restores it and skips everything.
        $job = $DB->get_record('local_aicb_job', ['id' => $jobid]);
        $this->assertSame($context->get_buildmap(), json_decode($job->buildmap, true));
        $restored = build_context::from_job($job, $course);
        $builder = $this->make_builder();
        $this->build_golden($builder, $restored);
        $this->assertSame(1000, $builder->nextid, 'Nothing is built twice');
        $this->assertSame($context->get_buildmap(), $restored->get_buildmap());
        $this->assertSame(
            ['cmid' => 1000, 'instanceid' => 1000, 'sectionnum' => 1100, 'status' => 'created'],
            $restored->get_entry('s1-1')
        );
    }

    /**
     * Failed nodes are recorded without ids and are not considered built.
     */
    public function test_failed_node(): void {
        $this->resetAfterTest();
        $context = new build_context((object) ['id' => 1]);
        $context->record(new build_result('s1.page1', build_result::STATUS_FAILED, error: 'boom'));

        $this->assertFalse($context->is_built('s1.page1'));
        $this->assertSame([], $context->get_cmidmap());
        $this->assertSame('{"s1.page1":{"cmid":null,"instanceid":null,"sectionnum":null,"status":"failed"}}', $context->to_json());
        $this->assertSame('{}', (new build_context((object) ['id' => 1]))->to_json());
    }

    /**
     * Unknown build statuses are rejected.
     */
    public function test_invalid_status(): void {
        $this->expectException(\coding_exception::class);
        new build_result('s1', 'done');
    }

    /**
     * In a new course the context starts without a course, and the course node fixes it exactly once.
     */
    public function test_course_is_set_once(): void {
        $this->resetAfterTest();
        $context = new build_context();

        $this->assertFalse($context->has_course());
        $context->set_course((object) ['id' => 42]);
        $this->assertTrue($context->has_course());
        $this->assertSame(42, (int) $context->get_course()->id);

        $this->expectException(\coding_exception::class);
        $context->set_course((object) ['id' => 43]);
    }

    /**
     * Reading the course before the course node has built it is a programming error, not a null.
     */
    public function test_course_before_the_course_node(): void {
        $this->resetAfterTest();
        $context = new build_context();

        $this->assertNull($context->get_qbankcontext());
        $this->expectException(\coding_exception::class);
        $context->get_course();
    }

    /**
     * The question bank context is set once too, the same way as the course.
     */
    public function test_qbankcontext_is_set_once(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = new build_context($course);
        $qbankcontext = \context_course::instance($course->id);

        $context->set_qbankcontext($qbankcontext);
        $this->assertSame($qbankcontext, $context->get_qbankcontext());

        $this->expectException(\coding_exception::class);
        $context->set_qbankcontext($qbankcontext);
    }
}
