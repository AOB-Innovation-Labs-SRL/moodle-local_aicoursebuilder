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
 * English language strings for local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aicoursebuilder:generateincourse'] = 'Generate content inside a course with AI Course Builder';
$string['aicoursebuilder:manage'] = 'Manage AI Course Builder';
$string['aicoursebuilder:use'] = 'Use AI Course Builder';
$string['aicoursebuilder:usedirectconnectors'] = 'Use direct AI provider connectors';
$string['aicoursebuilder:viewusage'] = 'View AI Course Builder usage';
$string['aipolicynotaccepted'] = 'The user has not accepted the Moodle AI policy.';
$string['allowedtypes'] = 'Allowed source types';
$string['allowedtypes_desc'] = 'File types that teachers may upload as source material.';
$string['commandfailed'] = 'The external program {$a} failed.';
$string['commandtimeout'] = 'The external program {$a} took too long and was stopped.';
$string['connector_coreai'] = 'Moodle AI subsystem (core_ai)';
$string['connector_deepseek'] = 'DeepSeek (direct API)';
$string['connector_usedefault'] = 'Use the default connector';
$string['connectorhttperror'] = 'The AI provider returned HTTP error {$a}.';
$string['connectorinvalidjson'] = 'The AI provider returned a response that is not valid JSON.';
$string['connectornetworkerror'] = 'The AI provider could not be reached.';
$string['connectornotconfigured'] = 'The AI connector {$a} is not configured.';
$string['connectorratelimited'] = 'The AI provider rate limit was reached. Try again later.';
$string['connectorssettings'] = 'AI connectors';
$string['connectorunknown'] = 'Unknown AI connector: {$a}.';
$string['connectorunsupported'] = 'The AI connector does not support: {$a}.';
$string['coreaierror'] = 'The Moodle AI subsystem returned an error: {$a}';
$string['deepseek_apikey'] = 'DeepSeek API key';
$string['deepseek_apikey_desc'] = 'Stored encrypted. It is not shown again after saving.';
$string['deepseek_baseurl'] = 'DeepSeek base URL';
$string['deepseek_baseurl_desc'] = 'Base URL of the OpenAI-compatible API, without /v1, for example https://api.deepseek.com.';
$string['deepseek_model'] = 'DeepSeek default model';
$string['deepseek_model_desc'] = 'Model used by the steps that have no model of their own, for example deepseek-flash.';
$string['deepseek_thinking'] = 'DeepSeek thinking mode';
$string['deepseek_thinking_desc'] = 'Enable thinking mode. It uses more output tokens and the temperature is ignored.';
$string['deepseekheading'] = 'DeepSeek';
$string['deepseekheading_desc'] = 'Direct connection to the DeepSeek API. DeepSeek processes data in China: send only public, non-personal content.';
$string['defaultconnector'] = 'Default connector';
$string['defaultconnector_desc'] = 'Connector used by every step that has no connector of its own.';
$string['extractionfailed'] = 'The text could not be extracted from the file: {$a}';
$string['extractionheading'] = 'Text extraction';
$string['extractionheading_desc'] = 'Optional external programs used when the built-in PHP extractors fail. Leave a path empty to disable that fallback.';
$string['extractionnotext'] = 'The file has no text that can be extracted. A scanned document needs OCR, which is not available yet.';
$string['extractorunsupported'] = 'Files of type {$a} are not supported.';
$string['ingestallfailed'] = 'The text could not be extracted from any source file.';
$string['ingestnosources'] = 'The job has no source files.';
$string['ingestprogress'] = 'Processed {$a->done} of {$a->total} source files.';
$string['maxfiles'] = 'Maximum files per job';
$string['maxfiles_desc'] = 'The most source files that a single job may have.';
$string['maxfilesize'] = 'Maximum file size (MB)';
$string['maxfilesize_desc'] = 'The largest source file accepted, in megabytes.';
$string['messageprovider:jobfailed'] = 'AI Course Builder job failed';
$string['messageprovider:jobfinished'] = 'AI Course Builder job finished';
$string['notimplemented'] = 'This feature is not implemented yet.';
$string['notjobowner'] = 'Only the owner of this job can change it.';
$string['pdfencrypted'] = 'The PDF is encrypted. Remove the protection and upload the file again.';
$string['pdftotextpath'] = 'Path to pdftotext';
$string['pdftotextpath_desc'] = 'Full path of the pdftotext program (Poppler), for example /usr/bin/pdftotext. It is the fallback for PDF files and is also needed by the LibreOffice fallback. Leave empty to disable.';
$string['pluginname'] = 'AI Course Builder';
$string['route_connector'] = 'Connector: {$a}';
$string['route_model'] = 'Model: {$a}';
$string['route_model_desc'] = 'Leave empty for the default model of the connector. Ignored for the Moodle AI subsystem.';
$string['routesheading'] = 'Routes per step';
$string['routesheading_desc'] = 'Connector and model for each generation step. Empty values fall back to the defaults above. The Moodle AI subsystem uses the provider and model configured in the site AI settings.';
$string['sofficepath'] = 'Path to LibreOffice (soffice)';
$string['sofficepath_desc'] = 'Full path of the soffice program, for example /usr/bin/soffice. It is the fallback for DOCX files: the file is converted to PDF and read with pdftotext, so the path of pdftotext must be set too. Leave empty to disable.';
$string['sourcefilemissing'] = 'The stored source file was not found.';
$string['sourceinfected'] = 'The antivirus refused the file {$a}.';
$string['sourcenofiles'] = 'No file was uploaded.';
$string['sourcesheading'] = 'Source files';
$string['sourcesheading_desc'] = 'Limits for the files that teachers upload as source material.';
$string['sourcetoolarge'] = 'The file {$a->filename} is bigger than the maximum of {$a->maxsize}.';
$string['sourcetoomany'] = 'A job can have at most {$a} source files.';
$string['sourcetype_docx'] = 'Word document (.docx)';
$string['sourcetype_pdf'] = 'PDF (.pdf)';
$string['sourcetypenotallowed'] = 'The file {$a} is not of an allowed type.';
$string['step_activities'] = 'Activities';
$string['step_brief'] = 'Course brief';
$string['step_digest'] = 'Document digest';
$string['step_outline'] = 'Course outline';
$string['step_questions'] = 'Quiz questions';
$string['step_repair'] = 'JSON repair';
$string['step_review'] = 'Review';
$string['step_sections'] = 'Section content';
$string['task_ingestsources'] = 'Extract text from source files';
