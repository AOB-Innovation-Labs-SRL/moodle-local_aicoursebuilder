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

namespace local_aicoursebuilder\form;

use local_aicoursebuilder\ingest\source_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The fields of the course generation wizard.
 *
 * The form is a container for the fields only: it is never submitted to the server as a form. The wizard script
 * reads the values, creates the job through the web services and uploads nothing itself, so the filemanager only
 * has to give it a draft area to hand to local_aicoursebuilder_create_job. Each step of the wizard is one fieldset
 * of the form, and the script shows one at a time.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_form extends \moodleform {
    /** @var string[] Languages a course can be written in; the first is the default. */
    public const LANGUAGES = ['ro', 'en', 'fr', 'de', 'es', 'it'];

    /** @var string Name of the step: where the course goes. */
    public const STEP_DESTINATION = 'stepdestination';

    /** @var string Name of the step: what the course is about. */
    public const STEP_BRIEF = 'stepbrief';

    /** @var string Name of the step: the source material. */
    public const STEP_SOURCES = 'stepsources';

    /** @var string Name of the step: the cost and the start. */
    public const STEP_CONFIRM = 'stepconfirm';

    /**
     * Defines the fields.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $mform->setDisableShortforms(true);
        $categories = $this->_customdata['categories'] ?? [];

        $mform->addElement('header', self::STEP_DESTINATION, get_string('wizard:stepdestination', 'local_aicoursebuilder'));
        $modes = [];
        if ($categories) {
            $modes['newcourse'] = get_string('wizard:modenewcourse', 'local_aicoursebuilder');
        }
        $modes['existingcourse'] = get_string('wizard:modeexistingcourse', 'local_aicoursebuilder');
        $mform->addElement('select', 'mode', get_string('wizard:mode', 'local_aicoursebuilder'), $modes);
        $mform->setType('mode', PARAM_ALPHA);

        if ($categories) {
            $mform->addElement('select', 'categoryid', get_string('wizard:category', 'local_aicoursebuilder'), $categories);
            $mform->setType('categoryid', PARAM_INT);
            $mform->hideIf('categoryid', 'mode', 'neq', 'newcourse');
        }

        $mform->addElement('course', 'courseid', get_string('wizard:course', 'local_aicoursebuilder'), [
            'multiple' => false,
            'requiredcapabilities' => ['local/aicoursebuilder:generateincourse', 'moodle/course:manageactivities'],
        ]);
        $mform->setType('courseid', PARAM_INT);
        $mform->hideIf('courseid', 'mode', 'neq', 'existingcourse');

        $mform->addElement('select', 'sectionnum', get_string('wizard:section', 'local_aicoursebuilder'), [
            0 => get_string('wizard:sectionfirst', 'local_aicoursebuilder'),
        ]);
        $mform->setType('sectionnum', PARAM_INT);
        $mform->hideIf('sectionnum', 'mode', 'neq', 'existingcourse');

        $mform->addElement('header', self::STEP_BRIEF, get_string('wizard:stepbrief', 'local_aicoursebuilder'));
        $mform->addElement('textarea', 'prompt', get_string('wizard:prompt', 'local_aicoursebuilder'), ['rows' => 5, 'cols' => 60]);
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addHelpButton('prompt', 'wizard:prompt', 'local_aicoursebuilder');
        $mform->addElement('text', 'audience', get_string('wizard:audience', 'local_aicoursebuilder'), ['size' => 60]);
        $mform->setType('audience', PARAM_TEXT);
        $mform->addElement('text', 'level', get_string('wizard:level', 'local_aicoursebuilder'), ['size' => 30]);
        $mform->setType('level', PARAM_TEXT);
        $mform->addElement('text', 'duration', get_string('wizard:duration', 'local_aicoursebuilder'), ['size' => 6]);
        $mform->setType('duration', PARAM_INT);
        $languages = [];
        foreach (self::LANGUAGES as $code) {
            $languages[$code] = get_string('wizard:language_' . $code, 'local_aicoursebuilder');
        }
        $mform->addElement('select', 'language', get_string('wizard:language', 'local_aicoursebuilder'), $languages);
        $mform->setType('language', PARAM_ALPHANUMEXT);
        $mform->setDefault('language', self::LANGUAGES[0]);
        $mform->addElement('text', 'tone', get_string('wizard:tone', 'local_aicoursebuilder'), ['size' => 40]);
        $mform->setType('tone', PARAM_TEXT);

        $mform->addElement('header', self::STEP_SOURCES, get_string('wizard:stepsources', 'local_aicoursebuilder'));
        $mform->addElement('static', 'sourceshelp', '', get_string('wizard:sourceshelp', 'local_aicoursebuilder'));
        $mform->addElement(
            'filemanager',
            'sources',
            get_string('wizard:sources', 'local_aicoursebuilder'),
            null,
            self::filemanager_options()
        );
        $mform->addElement('advcheckbox', 'offpeak', get_string('wizard:offpeak', 'local_aicoursebuilder'));
        $mform->setDefault('offpeak', 1);
        $mform->addHelpButton('offpeak', 'wizard:offpeak', 'local_aicoursebuilder');

        $mform->addElement('header', self::STEP_CONFIRM, get_string('wizard:stepconfirm', 'local_aicoursebuilder'));
        $mform->addElement('static', 'estimate', '', '<div data-region="aicb-estimate" aria-live="polite"></div>');
        $mform->addElement('advcheckbox', 'acceptpolicy', get_string('wizard:acceptpolicy', 'local_aicoursebuilder'));
    }

    /**
     * Returns the options of the sources filemanager, from the source limits of the settings.
     *
     * @return array
     */
    public static function filemanager_options(): array {
        $manager = new source_manager();
        // The key of a source type is its file extension.
        $extensions = array_map(fn($type) => '.' . $type, $manager->get_allowed_types());
        return [
            'subdirs' => 0,
            'maxfiles' => $manager->get_max_files(),
            'maxbytes' => $manager->get_max_file_bytes(),
            'accepted_types' => $extensions ?: '*',
        ];
    }
}
