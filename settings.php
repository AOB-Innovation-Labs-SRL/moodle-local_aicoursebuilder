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

/**
 * Admin settings for local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_aicoursebuilder',
        new lang_string('pluginname', 'local_aicoursebuilder')
    );
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_aicoursebuilder/sourcesheading',
            new lang_string('settings:sourcesheading', 'local_aicoursebuilder'),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/maxuploadmb',
            new lang_string('settings:maxuploadmb', 'local_aicoursebuilder'),
            new lang_string('settings:maxuploadmb_desc', 'local_aicoursebuilder'),
            50,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/jobretentiondays',
            new lang_string('settings:jobretentiondays', 'local_aicoursebuilder'),
            new lang_string('settings:jobretentiondays_desc', 'local_aicoursebuilder'),
            90,
            PARAM_INT
        ));

        $settings->add(new admin_setting_heading(
            'local_aicoursebuilder/limitsheading',
            new lang_string('settings:limitsheading', 'local_aicoursebuilder'),
            new lang_string('settings:limitsheading_desc', 'local_aicoursebuilder')
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/joblimitusd',
            new lang_string('settings:joblimitusd', 'local_aicoursebuilder'),
            new lang_string('settings:joblimitusd_desc', 'local_aicoursebuilder'),
            '5',
            PARAM_FLOAT
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/userlimitusd',
            new lang_string('settings:userlimitusd', 'local_aicoursebuilder'),
            new lang_string('settings:userlimitusd_desc', 'local_aicoursebuilder'),
            '20',
            PARAM_FLOAT
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/sitelimitusd',
            new lang_string('settings:sitelimitusd', 'local_aicoursebuilder'),
            new lang_string('settings:sitelimitusd_desc', 'local_aicoursebuilder'),
            '200',
            PARAM_FLOAT
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/alertpercent',
            new lang_string('settings:alertpercent', 'local_aicoursebuilder'),
            new lang_string('settings:alertpercent_desc', 'local_aicoursebuilder'),
            80,
            PARAM_INT
        ));
    }
}
