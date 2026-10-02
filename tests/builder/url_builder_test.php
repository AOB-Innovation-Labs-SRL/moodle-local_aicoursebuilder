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
 * Tests of the URL builder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\url_builder
 */
final class url_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job();
    }

    /**
     * The link of the golden blueprint is created with its address.
     */
    public function test_builds_the_url_of_the_golden_blueprint(): void {
        global $DB;

        $result = (new url_builder())->build($this->node('s1.url1'), $this->make_context());

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $url = $DB->get_record('url', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertSame('https://ro.wikipedia.org/wiki/Energie_regenerabil%C4%83', $url->externalurl);
        $this->assertSame('Articol de referință', $url->name);
        $this->assertNotEmpty($url->displayoptions);
        $this->assertSame('url', $this->get_cm($result)->modname);
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->get_cm($result)->completion);
    }

    /**
     * The display options are the site defaults of the module.
     */
    public function test_display_options_come_from_the_site_defaults(): void {
        global $DB;
        set_config('display', RESOURCELIB_DISPLAY_POPUP, 'url');
        set_config('popupwidth', 800, 'url');
        set_config('popupheight', 600, 'url');

        $result = (new url_builder())->build($this->node('s1.url1'), $this->make_context());

        $url = $DB->get_record('url', ['id' => $result->instanceid], '*', MUST_EXIST);
        $this->assertEquals(RESOURCELIB_DISPLAY_POPUP, $url->display);
        $options = unserialize($url->displayoptions);
        $this->assertEquals(800, $options['popupwidth']);
        $this->assertEquals(600, $options['popupheight']);
    }

    /**
     * An address that is not http or https is refused, whatever else it looks like.
     *
     * @dataProvider invalid_addresses
     * @param string $address The address.
     */
    public function test_an_address_that_is_not_http_is_refused(string $address): void {
        global $DB;
        $node = $this->node('s1.url1');
        $node['content']['externalurl'] = $address;

        try {
            (new url_builder())->build($node, $this->make_context());
            $this->fail('Expected the address to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('builderurlinvalid', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('url'));
    }

    /**
     * Addresses that must not become a link.
     *
     * @return array
     */
    public static function invalid_addresses(): array {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>x()</script>'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/file'],
            'empty' => [''],
            'relative' => ['/course/view.php?id=1'],
        ];
    }

    /**
     * A node already built is skipped.
     */
    public function test_a_built_node_is_skipped(): void {
        $context = $this->make_context();
        $builder = new url_builder();
        $this->record($context, $builder->build($this->node('s1.url1'), $context));

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->node('s1.url1'), $context)->status);
    }
}
