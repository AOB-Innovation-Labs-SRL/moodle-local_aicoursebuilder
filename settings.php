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
        $typechoices = [];
        foreach (array_keys(\local_aicoursebuilder\ingest\extractor_factory::MIMETYPES) as $type) {
            $typechoices[$type] = new lang_string('sourcetype_' . $type, 'local_aicoursebuilder');
        }

        $settings->add(new admin_setting_heading(
            'local_aicoursebuilder/sourcesheading',
            new lang_string('sourcesheading', 'local_aicoursebuilder'),
            new lang_string('sourcesheading_desc', 'local_aicoursebuilder')
        ));
        $settings->add(new admin_setting_configmultiselect(
            'local_aicoursebuilder/allowedtypes',
            new lang_string('allowedtypes', 'local_aicoursebuilder'),
            new lang_string('allowedtypes_desc', 'local_aicoursebuilder'),
            array_keys($typechoices),
            $typechoices
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/maxfilesize',
            new lang_string('maxfilesize', 'local_aicoursebuilder'),
            new lang_string('maxfilesize_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ingest\source_manager::DEFAULT_MAXFILESIZE,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/maxfiles',
            new lang_string('maxfiles', 'local_aicoursebuilder'),
            new lang_string('maxfiles_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ingest\source_manager::DEFAULT_MAXFILES,
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
            'local_aicoursebuilder/extractionheading',
            new lang_string('extractionheading', 'local_aicoursebuilder'),
            new lang_string('extractionheading_desc', 'local_aicoursebuilder')
        ));
        $settings->add(new admin_setting_configexecutable(
            'local_aicoursebuilder/pdftotextpath',
            new lang_string('pdftotextpath', 'local_aicoursebuilder'),
            new lang_string('pdftotextpath_desc', 'local_aicoursebuilder'),
            ''
        ));
        $settings->add(new admin_setting_configexecutable(
            'local_aicoursebuilder/sofficepath',
            new lang_string('sofficepath', 'local_aicoursebuilder'),
            new lang_string('sofficepath_desc', 'local_aicoursebuilder'),
            ''
        ));
        $settings->add(new admin_setting_configexecutable(
            'local_aicoursebuilder/pdftoppmpath',
            new lang_string('pdftoppmpath', 'local_aicoursebuilder'),
            new lang_string('pdftoppmpath_desc', 'local_aicoursebuilder'),
            ''
        ));
        $settings->add(new admin_setting_configexecutable(
            'local_aicoursebuilder/tesseractpath',
            new lang_string('tesseractpath', 'local_aicoursebuilder'),
            new lang_string('tesseractpath_desc', 'local_aicoursebuilder'),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/ocrlanguage',
            new lang_string('ocrlanguage', 'local_aicoursebuilder'),
            new lang_string('ocrlanguage_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ingest\extractor_ocr::DEFAULT_LANGUAGE,
            PARAM_RAW_TRIMMED
        ));

        $settings->add(new admin_setting_configtext(
            'local_aicoursebuilder/digest_maxinputtokens',
            new lang_string('settings:digestmaxinputtokens', 'local_aicoursebuilder'),
            new lang_string('settings:digestmaxinputtokens_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ingest\digest_builder::DEFAULT_MAX_INPUT_TOKENS,
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
            'local_aicoursebuilder/anthropicheading',
            new lang_string('anthropicheading', 'local_aicoursebuilder'),
            new lang_string('anthropicheading_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_encryptedpassword(
            'local_aicoursebuilder/anthropic_apikey',
            new lang_string('anthropic_apikey', 'local_aicoursebuilder'),
            new lang_string('anthropic_apikey_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/anthropic_model',
            new lang_string('anthropic_model', 'local_aicoursebuilder'),
            new lang_string('anthropic_model_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\anthropic_connector::DEFAULT_MODEL,
            PARAM_TEXT
        ));
        $connectors->add(new admin_setting_configcheckbox(
            'local_aicoursebuilder/anthropic_thinking',
            new lang_string('anthropic_thinking', 'local_aicoursebuilder'),
            new lang_string('anthropic_thinking_desc', 'local_aicoursebuilder'),
            0
        ));

        $connectors->add(new admin_setting_heading(
            'local_aicoursebuilder/geminiheading',
            new lang_string('geminiheading', 'local_aicoursebuilder'),
            new lang_string('geminiheading_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_encryptedpassword(
            'local_aicoursebuilder/gemini_apikey',
            new lang_string('gemini_apikey', 'local_aicoursebuilder'),
            new lang_string('gemini_apikey_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/gemini_model',
            new lang_string('gemini_model', 'local_aicoursebuilder'),
            new lang_string('gemini_model_desc', 'local_aicoursebuilder'),
            '',
            PARAM_TEXT
        ));
        $connectors->add(new admin_setting_configcheckbox(
            'local_aicoursebuilder/gemini_thinking',
            new lang_string('gemini_thinking', 'local_aicoursebuilder'),
            new lang_string('gemini_thinking_desc', 'local_aicoursebuilder'),
            0
        ));
        $connectors->add(new admin_setting_configcheckbox(
            'local_aicoursebuilder/gemini_native_json_schema',
            new lang_string('gemini_native_json_schema', 'local_aicoursebuilder'),
            new lang_string('gemini_native_json_schema_desc', 'local_aicoursebuilder'),
            0
        ));

        $connectors->add(new admin_setting_heading(
            'local_aicoursebuilder/openaicompatheading',
            new lang_string('openaicompatheading', 'local_aicoursebuilder'),
            new lang_string('openaicompatheading_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/openaicompat_baseurl',
            new lang_string('openaicompat_baseurl', 'local_aicoursebuilder'),
            new lang_string('openaicompat_baseurl_desc', 'local_aicoursebuilder'),
            '',
            PARAM_URL
        ));
        $connectors->add(new admin_setting_encryptedpassword(
            'local_aicoursebuilder/openaicompat_apikey',
            new lang_string('openaicompat_apikey', 'local_aicoursebuilder'),
            new lang_string('openaicompat_apikey_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_configselect(
            'local_aicoursebuilder/openaicompat_authtype',
            new lang_string('openaicompat_authtype', 'local_aicoursebuilder'),
            new lang_string('openaicompat_authtype_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\openaicompat_connector::AUTHTYPE_BEARER,
            [
                \local_aicoursebuilder\ai\openaicompat_connector::AUTHTYPE_BEARER =>
                    new lang_string('openaicompat_authtype_bearer', 'local_aicoursebuilder'),
                \local_aicoursebuilder\ai\openaicompat_connector::AUTHTYPE_APIKEY =>
                    new lang_string('openaicompat_authtype_apikey', 'local_aicoursebuilder'),
            ]
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/openaicompat_apiversion',
            new lang_string('openaicompat_apiversion', 'local_aicoursebuilder'),
            new lang_string('openaicompat_apiversion_desc', 'local_aicoursebuilder'),
            '',
            PARAM_TEXT
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/openaicompat_model',
            new lang_string('openaicompat_model', 'local_aicoursebuilder'),
            new lang_string('openaicompat_model_desc', 'local_aicoursebuilder'),
            '',
            PARAM_TEXT
        ));
        $connectors->add(new admin_setting_configcheckbox(
            'local_aicoursebuilder/openaicompat_supports_json_schema',
            new lang_string('openaicompat_supports_json_schema', 'local_aicoursebuilder'),
            new lang_string('openaicompat_supports_json_schema_desc', 'local_aicoursebuilder'),
            0
        ));
        $connectors->add(new admin_setting_configcheckbox(
            'local_aicoursebuilder/openaicompat_supports_vision',
            new lang_string('openaicompat_supports_vision', 'local_aicoursebuilder'),
            new lang_string('openaicompat_supports_vision_desc', 'local_aicoursebuilder'),
            0
        ));

        $connectors->add(new admin_setting_heading(
            'local_aicoursebuilder/parallelismheading',
            new lang_string('parallelismheading', 'local_aicoursebuilder'),
            new lang_string('parallelismheading_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/pool_concurrency',
            new lang_string('pool_concurrency', 'local_aicoursebuilder'),
            new lang_string('pool_concurrency_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\parallel_executor::DEFAULT_CONCURRENCY,
            PARAM_INT
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/retry_maxattempts',
            new lang_string('retry_maxattempts', 'local_aicoursebuilder'),
            new lang_string('retry_maxattempts_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\retrying_connector::DEFAULT_MAXATTEMPTS,
            PARAM_INT
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/retry_basedelayms',
            new lang_string('retry_basedelayms', 'local_aicoursebuilder'),
            new lang_string('retry_basedelayms_desc', 'local_aicoursebuilder'),
            \local_aicoursebuilder\ai\retrying_connector::DEFAULT_BASEDELAYMS,
            PARAM_INT
        ));

        $connectors->add(new admin_setting_heading(
            'local_aicoursebuilder/pricingheading',
            new lang_string('pricingheading', 'local_aicoursebuilder'),
            new lang_string('pricingheading_desc', 'local_aicoursebuilder')
        ));
        foreach (\local_aicoursebuilder\ai\router::CONNECTORS as $name) {
            $connectors->add(new admin_setting_configtext(
                "local_aicoursebuilder/token_estimator_charsperfactor_{$name}",
                new lang_string('token_estimator_charsperfactor', 'local_aicoursebuilder', $choices[$name]),
                new lang_string('token_estimator_charsperfactor_desc', 'local_aicoursebuilder'),
                \local_aicoursebuilder\ai\deepseek_connector::DEFAULT_CHARS_PER_TOKEN,
                PARAM_FLOAT
            ));
            $connectors->add(new \local_aicoursebuilder\ai\admin_setting_pricing(
                "local_aicoursebuilder/pricing_{$name}",
                new lang_string('pricing_json', 'local_aicoursebuilder', $choices[$name]),
                new lang_string('pricing_json_desc', 'local_aicoursebuilder', json_encode(
                    \local_aicoursebuilder\ai\pricing::DEFAULT_PRICES[$name] ?? new \stdClass(),
                    JSON_PRETTY_PRINT
                )),
                '',
                PARAM_RAW
            ));
            $connectors->add(new \local_aicoursebuilder\ai\admin_setting_pricing(
                "local_aicoursebuilder/peakwindows_{$name}",
                new lang_string('peakwindows_json', 'local_aicoursebuilder', $choices[$name]),
                new lang_string('peakwindows_json_desc', 'local_aicoursebuilder'),
                '',
                PARAM_RAW
            ));
        }

        $connectors->add(new admin_setting_heading(
            'local_aicoursebuilder/routesheading',
            new lang_string('routesheading', 'local_aicoursebuilder'),
            new lang_string('routesheading_desc', 'local_aicoursebuilder')
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/questions_min',
            new lang_string('questions_min', 'local_aicoursebuilder'),
            new lang_string('questions_min_desc', 'local_aicoursebuilder'),
            5,
            PARAM_INT
        ));
        $connectors->add(new admin_setting_configtext(
            'local_aicoursebuilder/questions_max',
            new lang_string('questions_max', 'local_aicoursebuilder'),
            new lang_string('questions_max_desc', 'local_aicoursebuilder'),
            15,
            PARAM_INT
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
