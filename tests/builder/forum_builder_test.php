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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/builder_test_helpers.php');

/**
 * Tests of the forum builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\forum_builder
 */
final class forum_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * The forum of the golden blueprint is created with its discussion.
     */
    public function test_builds_the_forum_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new forum_builder())->build($this->node('s2.forum1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame([], $result->warnings);
        $this->assertSame('forum', $this->get_cm($result)->modname);
        $forum = $DB->get_record('forum', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('general', $forum->type);
        $this->assertSame('Forum de discuții', $forum->name);

        $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id]);
        $this->assertCount(1, $discussions);
        $discussion = reset($discussions);
        $this->assertSame('Ce sursă folosiți acasă?', $discussion->name);
        $this->assertEquals($this->teacher->id, $discussion->userid);
        $this->assertEquals(-1, $discussion->groupid);
        $this->assertEquals(0, $discussion->pinned);
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
        $this->assertSame('Ce sursă folosiți acasă?', $post->subject);
        $this->assertSame('<p>Spuneți-ne ce surse regenerabile folosiți.</p>', $post->message);
        $this->assertEquals(FORMAT_HTML, $post->messageformat);
        $this->assertEquals($this->teacher->id, $post->userid);
    }

    /**
     * Each discussion fires the event of a new discussion.
     */
    public function test_every_discussion_fires_its_event(): void {
        $node = $this->node('s2.forum1');
        $node['content']['discussions'][] = ['subject' => 'A doua', 'message' => '<p>Mesaj.</p>'];
        $sink = $this->redirectEvents();

        (new forum_builder())->build($node, $this->make_context());

        $created = array_filter($sink->get_events(), fn($event) => $event instanceof \mod_forum\event\discussion_created);
        $this->assertCount(2, $created);
    }

    /**
     * A question and answer forum is one, and a type that is not known is a general forum.
     */
    public function test_the_type_of_the_forum(): void {
        global $DB;
        $node = $this->node('s2.forum1');
        $node['content']['forumtype'] = 'qanda';
        $qanda = (new forum_builder())->build($node, $this->make_context());
        $node['id'] = 's2.forum2';
        $node['content']['forumtype'] = 'single';
        $other = (new forum_builder())->build($node, $this->make_context());

        $this->assertSame('qanda', $DB->get_field('forum', 'type', ['id' => $qanda->instanceid]));
        $this->assertSame('general', $DB->get_field('forum', 'type', ['id' => $other->instanceid]));
    }

    /**
     * A forum with no discussion is a forum.
     */
    public function test_a_forum_without_discussions(): void {
        global $DB;
        $node = $this->node('s2.forum1');
        unset($node['content']['discussions']);

        $result = (new forum_builder())->build($node, $this->make_context());

        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $result->instanceid]));
    }

    /**
     * The settings are the site defaults of a new forum.
     */
    public function test_settings_come_from_the_site_defaults(): void {
        global $DB;
        set_config('forum_maxattachments', 3);
        set_config('forum_trackingtype', FORUM_TRACKING_OFF);
        set_config('forum_subscription', FORUM_INITIALSUBSCRIBE);

        $result = (new forum_builder())->build($this->node('s2.forum1'), $this->make_context());

        $forum = $DB->get_record('forum', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(3, $forum->maxattachments);
        $this->assertEquals(FORUM_TRACKING_OFF, $forum->trackingtype);
        $this->assertEquals(FORUM_INITIALSUBSCRIBE, $forum->forcesubscribe);
    }

    /**
     * The text of a discussion is cleaned.
     */
    public function test_the_text_is_cleaned(): void {
        global $DB;
        $node = $this->node('s2.forum1');
        $node['content']['discussions'] = [['subject' => '<b>Subiect</b>', 'message' => '<p>Ok</p><script>x()</script>']];

        $result = (new forum_builder())->build($node, $this->make_context());

        $discussion = $DB->get_record('forum_discussions', ['forum' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('Subiect', $discussion->name);
        $this->assertStringNotContainsString('script', $DB->get_field('forum_posts', 'message', ['id' => $discussion->firstpost]));
    }

    /**
     * The completion rule of the golden blueprint, one post, is set on the forum.
     */
    public function test_the_completion_rule_is_set(): void {
        global $DB;

        $result = (new forum_builder())->build($this->node('s2.forum1'), $this->make_context());

        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $this->get_cm($result)->completion);
        $this->assertEquals(1, $DB->get_field('forum', 'completionposts', ['id' => $result->instanceid]));
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        global $DB;
        $context = $this->make_context();
        $builder = new forum_builder();
        $this->record($context, $builder->build($this->node('s2.forum1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s2.forum1'), $context)->status);
        $this->assertSame(1, $DB->count_records('forum_discussions'));
    }
}
