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
 * Tests of the course builder and of the course image.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicoursebuilder\builder\course_builder
 * @covers     \local_aicoursebuilder\builder\course_image
 */
final class course_builder_test extends \advanced_testcase {
    use builder_test_helpers;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_up_job('newcourse');
        set_config('courseoverviewfileslimit', 1);
    }

    /**
     * Creates the course of a node in the category of the job and returns its row.
     *
     * @param array|null $node The course object, the golden one by default.
     * @return array [the result, the course row]
     */
    private function build_course(?array $node = null): array {
        global $DB;
        $result = (new course_builder())->build($node ?? $this->golden()['course'], $this->make_context(false));
        return [$result, $DB->get_record('course', ['id' => $result->instanceid], '*', MUST_EXIST)];
    }

    /**
     * Makes an Office file with the pictures given.
     *
     * @param string $folder The media folder.
     * @param string[] $images Content of each picture, by file name.
     * @param string $extension Extension of the Office file.
     * @return string The bytes of the file.
     */
    private function office_file(string $folder, array $images, string $extension = 'docx'): string {
        $path = make_request_directory() . '/file.' . $extension;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        foreach ($images as $name => $content) {
            $zip->addFromString($folder . $name, $content);
        }
        $zip->close();
        return file_get_contents($path);
    }

    /**
     * Makes a PNG that is big enough to be a picture.
     *
     * @param int $width Width in pixels.
     * @param int $height Height in pixels.
     * @return string The bytes.
     */
    private function png(int $width = 320, int $height = 240): string {
        $image = imagecreatetruecolor($width, $height);
        mt_srand($width * 1000 + $height);
        // Noise does not compress, so the picture is as big as a photograph.
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }

    /**
     * Returns the image files of a course.
     *
     * @param \stdClass $course The course.
     * @return \stored_file[]
     */
    private function overview_files(\stdClass $course): array {
        $context = \context_course::instance($course->id);
        return get_file_storage()->get_area_files($context->id, 'course', 'overviewfiles', 0, 'id', false);
    }

    /**
     * The course of the golden blueprint is created in the category of the job.
     */
    public function test_builds_the_course_of_the_golden_blueprint(): void {
        global $DB;

        [$result, $course] = $this->build_course();

        $this->assertSame(build_result::STATUS_CREATED, $result->status);
        $this->assertSame('course', $result->nodeid);
        $this->assertSame([], $result->warnings);
        $this->assertSame('Introducere în energia regenerabilă', $course->fullname);
        $this->assertSame('ENREG-INTRO', $course->shortname);
        $this->assertEquals($this->course->category, $course->category);
        $this->assertSame('topics', $course->format);
        $this->assertEquals(1, $course->enablecompletion);
        $this->assertEquals(make_timestamp(2026, 10, 19), $course->startdate);
        $this->assertStringContainsString('Curs introductiv despre sursele', $course->summary);
        $this->assertEquals(FORMAT_HTML, $course->summaryformat);
        // The language of the job is set when the site has its language pack.
        $this->assertSame(get_string_manager()->translation_exists('ro') ? 'ro' : '', $course->lang);
        $this->assertTrue($DB->record_exists('course_sections', ['course' => $course->id, 'section' => 0]));
    }

    /**
     * The settings the form would send are the site defaults for a new course.
     */
    public function test_the_defaults_of_a_new_course_come_from_the_site(): void {
        set_config('newsitems', 7, 'moodlecourse');
        set_config('showgrades', 0, 'moodlecourse');
        set_config('maxbytes', 1048576, 'moodlecourse');

        [, $course] = $this->build_course();

        $this->assertEquals(7, $course->newsitems);
        $this->assertEquals(0, $course->showgrades);
        $this->assertEquals(1048576, $course->maxbytes);
    }

    /**
     * A short name that is taken gets a number instead of stopping the build.
     */
    public function test_a_taken_shortname_gets_a_number(): void {
        [, $first] = $this->build_course();
        $node = $this->golden()['course'];
        $node['shortname'] = $first->shortname;

        [, $second] = $this->build_course($node);
        [, $third] = $this->build_course($node);

        $this->assertSame('ENREG-INTRO', $first->shortname);
        $this->assertSame('ENREG-INTRO-2', $second->shortname);
        $this->assertSame('ENREG-INTRO-3', $third->shortname);
    }

    /**
     * A long short name is cut before the number is added, so it still fits.
     */
    public function test_a_long_shortname_still_fits_with_its_number(): void {
        $node = $this->golden()['course'];
        $node['shortname'] = str_repeat('a', 100);
        $this->build_course($node);

        [, $second] = $this->build_course($node);

        $this->assertSame(100, strlen($second->shortname));
        $this->assertStringEndsWith('-2', $second->shortname);
    }

    /**
     * The text of the course is cleaned and its names are plain.
     */
    public function test_the_text_is_cleaned(): void {
        $node = $this->golden()['course'];
        $node['summary'] = '<p>Ok</p><script>x()</script>';
        $node['fullname'] = '<b>Curs</b> nou';

        [, $course] = $this->build_course($node);

        $this->assertStringNotContainsString('script', $course->summary);
        $this->assertSame('Curs nou', $course->fullname);
    }

    /**
     * The format weeks is allowed, any other is replaced by the site default.
     */
    public function test_only_known_formats_are_used(): void {
        $node = $this->golden()['course'];
        $node['format'] = 'weeks';
        $node['shortname'] = 'W1';
        [, $weeks] = $this->build_course($node);
        $node['format'] = 'nonexistent';
        $node['shortname'] = 'W2';
        [, $other] = $this->build_course($node);

        $this->assertSame('weeks', $weeks->format);
        $this->assertContains($other->format, ['topics', get_config('moodlecourse', 'format')]);
    }

    /**
     * Course completion follows the blueprint when the site tracks completion.
     */
    public function test_completion_follows_the_blueprint(): void {
        $node = $this->golden()['course'];
        $node['enablecompletion'] = false;
        [, $course] = $this->build_course($node);
        $this->assertEquals(0, $course->enablecompletion);

        set_config('enablecompletion', 0);
        $node['enablecompletion'] = true;
        $node['shortname'] = 'C2';
        [, $off] = $this->build_course($node);
        $this->assertEquals(0, $off->enablecompletion, 'A site that does not track completion cannot have a course that does');
    }

    /**
     * A language the site does not have is left to the site.
     */
    public function test_a_language_the_site_lacks_is_not_set(): void {
        global $DB;
        $DB->set_field('local_aicb_job', 'language', 'xx', ['id' => $this->jobid]);

        [, $course] = $this->build_course();

        $this->assertSame('', $course->lang);
    }

    /**
     * A job with no category cannot create a course.
     */
    public function test_a_job_without_a_category_is_refused(): void {
        global $DB;
        $DB->set_field('local_aicb_job', 'categoryid', null, ['id' => $this->jobid]);

        try {
            $this->build_course();
            $this->fail('Expected the missing category to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('buildernocategory', $e->errorcode);
        }
    }

    /**
     * A course with no short name cannot be created.
     */
    public function test_a_course_without_a_shortname_is_refused(): void {
        $node = $this->golden()['course'];
        $node['shortname'] = ' ';

        $this->expectException(\moodle_exception::class);
        $this->build_course($node);
    }

    /**
     * The course node is built once.
     */
    public function test_a_built_course_is_skipped(): void {
        global $DB;
        $context = $this->make_context(false);
        $builder = new course_builder();
        $this->record($context, $builder->build($this->golden()['course'], $context));
        $courses = $DB->count_records('course');

        $this->assertSame(build_result::STATUS_SKIPPED, $builder->build($this->golden()['course'], $context)->status);
        $this->assertSame($courses, $DB->count_records('course'));
    }

    /**
     * The image of the course is the first relevant picture of the sources.
     */
    public function test_the_course_image_comes_from_the_sources(): void {
        $this->add_source('raport.docx', $this->office_file('word/media/', [
            'image1.png' => 'tiny',
            'image2.png' => $this->png(),
            'image3.png' => $this->png(400, 300),
        ]));

        [$result, $course] = $this->build_course();

        $this->assertSame([], $result->warnings);
        $files = get_file_storage()->get_area_files(
            \context_course::instance($course->id)->id,
            'course',
            'overviewfiles',
            0,
            'filename',
            false
        );
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('image2.png', $file->get_filename());
        $this->assertSame(320, $file->get_imageinfo()['width']);
    }

    /**
     * The sources are looked at in the order they were uploaded.
     */
    public function test_the_first_source_with_a_picture_wins(): void {
        $this->add_source('text.docx', $this->office_file('word/media/', ['icon.png' => 'tiny']));
        $this->add_source('deck.pptx', $this->office_file('ppt/media/', ['slide.png' => $this->png(500, 300)], 'pptx'));
        $this->add_source('sheet.xlsx', $this->office_file('xl/media/', ['chart.png' => $this->png(300, 300)], 'xlsx'));

        $image = course_image::find($this->jobid);

        $this->assertSame('slide.png', $image['filename']);
    }

    /**
     * A job whose sources have no picture has no course image, and that is not a problem.
     */
    public function test_sources_without_a_picture_leave_the_course_without_an_image(): void {
        $this->add_source('notes.txt', 'text only');
        $this->add_source('empty.docx', $this->office_file('word/media/', []));

        [$result, $course] = $this->build_course();

        $this->assertSame([], $result->warnings);
        $files = $this->overview_files($course);
        $this->assertCount(0, $files);
    }

    /**
     * A site that allows no course image gets none, and the build goes on.
     */
    public function test_no_image_is_set_when_the_site_allows_none(): void {
        set_config('courseoverviewfileslimit', 0);
        $this->add_source('raport.docx', $this->office_file('word/media/', ['image2.png' => $this->png()]));

        [$result, $course] = $this->build_course();

        $this->assertSame([], $result->warnings);
        $files = $this->overview_files($course);
        $this->assertCount(0, $files);
    }

    /**
     * What makes a picture relevant: big enough, in file size and in pixels, and not a strip.
     */
    public function test_which_pictures_are_relevant(): void {
        $this->assertTrue(course_image::is_relevant($this->png(320, 240)));
        $this->assertFalse(course_image::is_relevant('small'), 'Too small a file');
        $this->assertFalse(course_image::is_relevant(str_repeat('x', 20000)), 'Not a picture');
        $this->assertFalse(course_image::is_relevant($this->png(100, 400)), 'Too narrow');
        $this->assertFalse(course_image::is_relevant($this->png(1200, 220)), 'A strip');
    }
}
