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
 * Builds a forum: the module, then the discussions the blueprint starts it with.
 *
 * The forum is a general one or a question and answer one, with the site defaults of a new forum for subscription,
 * read tracking and attachments. A discussion is started with forum_add_discussion(), as the user of the build,
 * for everybody (no group), immediately and not pinned, and the event of a new discussion is fired, which
 * forum_add_discussion() leaves to its caller.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_builder extends module_builder {
    /** @var string[] Forum types the blueprint can ask for. */
    public const TYPES = ['general', 'qanda'];

    /**
     * Returns the Moodle module this builder creates.
     *
     * @return string
     */
    #[\Override]
    protected function get_modulename(): string {
        return 'forum';
    }

    /**
     * Adds the type of the forum and the site defaults of a new one.
     *
     * @param \stdClass $info Module info.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function add_fields(\stdClass $info, array $node, build_context $context): void {
        $type = (string) ($node['content']['forumtype'] ?? 'general');
        $info->type = in_array($type, self::TYPES, true) ? $type : 'general';

        $info->maxbytes = (int) $this->get_core_default('forum_maxbytes', 512000);
        $info->maxattachments = (int) $this->get_core_default('forum_maxattachments', 9);
        $info->forcesubscribe = (int) $this->get_core_default('forum_subscription', 0);
        $info->trackingtype = (int) $this->get_core_default('forum_trackingtype', 1);
        $info->rsstype = 0;
        $info->rssarticles = 0;
        $info->displaywordcount = 0;
        $info->lockdiscussionafter = 0;
        $info->assessed = 0;
        $info->scale = 0;
        $info->grade_forum = 0;
        $info->grade_forum_notify = 0;
        $info->duedate = 0;
        $info->cutoffdate = 0;
        $info->warnafter = 0;
        $info->blockafter = 0;
        $info->blockperiod = 0;
    }

    /**
     * Starts the discussions of the forum.
     *
     * @param \stdClass $created The module info create_module() returned.
     * @param array $node The blueprint node.
     * @param build_context $context The build context.
     */
    #[\Override]
    protected function after_created(\stdClass $created, array $node, build_context $context): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $forum = $DB->get_record('forum', ['id' => $created->instance], '*', MUST_EXIST);
        $modulecontext = \context_module::instance($created->coursemodule);
        foreach ($node['content']['discussions'] ?? [] as $data) {
            $discussion = (object) [
                'course' => (int) $created->course,
                'forum' => (int) $forum->id,
                'name' => self::clean_name((string) ($data['subject'] ?? '')),
                'message' => self::clean_html((string) ($data['message'] ?? '')),
                'messageformat' => FORMAT_HTML,
                'messagetrust' => 0,
                'itemid' => 0,
                'groupid' => -1,
                'mailnow' => 0,
                'timestart' => 0,
                'timeend' => 0,
                'pinned' => FORUM_DISCUSSION_UNPINNED,
            ];
            $discussionid = forum_add_discussion($discussion, null, null, (int) $USER->id);

            $event = \mod_forum\event\discussion_created::create([
                'context' => $modulecontext,
                'objectid' => $discussionid,
                'other' => ['forumid' => $forum->id],
            ]);
            $event->add_record_snapshot('forum_discussions', $DB->get_record('forum_discussions', ['id' => $discussionid]));
            $event->trigger();
        }
    }
}
