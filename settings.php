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

    $connectors = new admin_settingpage(
        'local_aicoursebuilder_connectors',
        new lang_string('connectorssettings', 'local_aicoursebuilder')
    );
    if ($ADMIN->fulltree) {
        $choices = [];
        foreach (\local_aicoursebuilder\ai\router::CONNECTORS as $name) {
            $choices[$name] = new lang_string('connector_' . $name, 'local_aicoursebuilder');
        }

        $connectors->add(new admin_setting_configselect(
            'local_aicoursebuilder/defaultconnector',
            new lang_string('defaultconnector', 'local_aicoursebuilder'),
            new lang_string('defaultconnector_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\router::DEFAULT_CONNECTOR,
            $choices
        ));

        $connectors->add(new admin_setting_heading(
            'local_aicoursebuilder/deepseekheading',
            new lang_string('deepseekheading', 'local_aicoursebuilder'),
            new lang_string('deepseekheading_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_encryptedpassword(
            'local_aicoursebuilder/deepseek_apikey',
            new lang_string('deepseek_apikey', 'local_aicoursebuilder'),
            new lang_string('deepseek_apikey_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/deepseek_baseurl',
            new lang_string('deepseek_baseurl', 'local_aicoursebuilder'),
            new lang_string('deepseek_baseurl_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\deepseek_connector::DEFAULT_BASEURL,
            PARAM_URL
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/deepseek_model',
            new lang_string('deepseek_model', 'local_aicoursebuilder'),
            new lang_string('deepseek_model_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\deepseek_connector::DEFAULT_MODEL,
            PARAM_TEXT
        ));
        $connectors->add(new admin_setting_configcheckbox(
            'local_aicoursebuilder/deepseek_thinking',
            new lang_string('deepseek_thinking', 'local_aicoursebuilder'),
            new lang_string('deepseek_thinking_desc', 'local_aicoursebuilder'),
            0
        ));

        $connectors->add(new admin_setting_heading(
            'local_aicoursebuilder/routesheading',
            new lang_string('routesheading', 'local_aicoursebuilder'),
            new lang_string('routesheading_desc', 'local_aicoursebuilder')
        ));
        $routechoices = ['' => new lang_string('connector_usedefault', 'local_aicoursebuilder')] + $choices;
        foreach (\local_aicoursebuilder\ai\request::STEPS as $step) {
            $stepname = new lang_string('step_' . $step, 'local_aicoursebuilder');
            $connectors->add(new admin_setting_configselect(
                "local_aicoursebuilder/route_{$step}_connector",
                new lang_string('route_connector', 'local_aicoursebuilder', $stepname),
                '',
                '',
                $routechoices
            ));
            $connectors->add(new admin_setting_configtext(
                "local_aicoursebuilder/route_{$step}_model",
                new lang_string('route_model', 'local_aicoursebuilder', $stepname),
                new lang_string('route_model_desc', 'local_aicoursebuilder'),
                '',
                PARAM_TEXT
            ));
        }
    }
    $ADMIN->add('localplugins', $connectors);
}
