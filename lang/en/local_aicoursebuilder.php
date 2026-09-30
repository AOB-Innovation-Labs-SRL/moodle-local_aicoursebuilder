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
$string['privacy:metadata:aiprovider:anthropic'] = 'Anthropic receives the teacher prompt and the source text to generate the course content.';
$string['privacy:metadata:aiprovider:deepseek'] = 'DeepSeek receives the teacher prompt and the source text to generate the course content.';
$string['privacy:metadata:aiprovider:gemini'] = 'Google Gemini receives the teacher prompt and the source text to generate the course content.';
$string['privacy:metadata:aiprovider:language'] = 'The language of the course to generate.';
$string['privacy:metadata:aiprovider:openaicompat'] = 'An OpenAI-compatible provider receives the teacher prompt and the source text to generate the course content.';
$string['privacy:metadata:aiprovider:prompt'] = 'The prompt written by the teacher.';
$string['privacy:metadata:aiprovider:sourcecontent'] = 'Text extracted from the source files uploaded by the teacher.';
$string['privacy:metadata:core_ai'] = 'Requests sent through the Moodle AI subsystem are handled by the configured AI provider plugin.';
$string['privacy:metadata:core_files'] = 'The source files uploaded for a job are stored in the Moodle file storage.';
$string['privacy:metadata:local_aicb_ailog'] = 'Log of AI calls: tokens, cost and model, never the prompt or the answer.';
$string['privacy:metadata:local_aicb_ailog:connector'] = 'The connector used for the call.';
$string['privacy:metadata:local_aicb_ailog:cost'] = 'The cost of the call in USD.';
$string['privacy:metadata:local_aicb_ailog:model'] = 'The model used for the call.';
$string['privacy:metadata:local_aicb_ailog:timecreated'] = 'The time of the call.';
$string['privacy:metadata:local_aicb_ailog:tokens'] = 'The number of tokens sent and received.';
$string['privacy:metadata:local_aicb_ailog:userid'] = 'The user who started the call.';
$string['privacy:metadata:local_aicb_blueprint'] = 'Versions of the course blueprint generated and edited for a job.';
$string['privacy:metadata:local_aicb_blueprint:approvedby'] = 'The user who approved the blueprint.';
$string['privacy:metadata:local_aicb_blueprint:content'] = 'The blueprint, including the generated course content.';
$string['privacy:metadata:local_aicb_blueprint:timeapproved'] = 'The time the blueprint was approved.';
$string['privacy:metadata:local_aicb_blueprint:usermodified'] = 'The user who last modified the blueprint.';
$string['privacy:metadata:local_aicb_budget'] = 'Monthly AI spending of a user.';
$string['privacy:metadata:local_aicb_budget:limitusd'] = 'The monthly limit set for the user, in USD.';
$string['privacy:metadata:local_aicb_budget:period'] = 'The month of the spending.';
$string['privacy:metadata:local_aicb_budget:spentusd'] = 'The amount spent in the month, in USD.';
$string['privacy:metadata:local_aicb_budget:userid'] = 'The user who spent the amount.';
$string['privacy:metadata:local_aicb_chunk'] = 'Pieces of the text extracted from the source files of a job.';
$string['privacy:metadata:local_aicb_chunk:content'] = 'The text of the chunk.';
$string['privacy:metadata:local_aicb_chunk:title'] = 'The nearest heading of the chunk.';
$string['privacy:metadata:local_aicb_job'] = 'Course generation jobs started by teachers.';
$string['privacy:metadata:local_aicb_job:brief'] = 'The brief of the course, confirmed by the teacher.';
$string['privacy:metadata:local_aicb_job:courseid'] = 'The course the job generates content for.';
$string['privacy:metadata:local_aicb_job:prompt'] = 'The prompt written by the teacher.';
$string['privacy:metadata:local_aicb_job:timecreated'] = 'The time the job was created.';
$string['privacy:metadata:local_aicb_job:userid'] = 'The user who owns the job.';
$string['privacy:metadata:local_aicb_source'] = 'Source files uploaded for a job.';
$string['privacy:metadata:local_aicb_source:digest'] = 'The digest of the source text.';
$string['privacy:metadata:local_aicb_source:filename'] = 'The name of the uploaded file.';
$string['privacy:metadata:local_aicb_source:filesize'] = 'The size of the uploaded file.';
$string['privacy:metadata:local_aicb_source:mimetype'] = 'The type of the uploaded file.';
$string['privacy:metadata:local_aicb_step'] = 'Checkpoints of the AI generation steps of a job.';
$string['privacy:metadata:local_aicb_step:cost'] = 'The cost of the step in USD.';
$string['privacy:metadata:local_aicb_step:output'] = 'The content generated by the step.';
$string['privacy:metadata:local_aicb_step:step'] = 'The name of the step.';
$string['privacy:path:ailog'] = 'AI calls';
$string['privacy:path:budget'] = 'AI budget';
$string['privacy:path:files'] = 'Source files';
$string['privacy:path:jobs'] = 'Jobs';
$string['route_connector'] = 'Connector: {$a}';
$string['route_model'] = 'Model: {$a}';
$string['route_model_desc'] = 'Leave empty for the default model of the connector. Ignored for the Moodle AI subsystem.';
$string['routesheading'] = 'Routes per step';
$string['routesheading_desc'] = 'Connector and model for each generation step. Empty values fall back to the defaults above. The Moodle AI subsystem uses the provider and model configured in the site AI settings.';
$string['settings:alertpercent'] = 'Budget alert threshold (%)';
$string['settings:alertpercent_desc'] = 'Managers are notified when a monthly budget reaches this percentage of its limit.';
$string['settings:joblimitusd'] = 'Limit per job (USD)';
$string['settings:joblimitusd_desc'] = 'A job whose estimated cost is higher than this is not started. 0 means no limit.';
$string['settings:jobretentiondays'] = 'Job retention (days)';
$string['settings:jobretentiondays_desc'] = 'Finished, failed and cancelled jobs are deleted with their sources and intermediate data after this number of days. 0 keeps them.';
$string['settings:limitsheading'] = 'Cost limits';
$string['settings:limitsheading_desc'] = 'Amounts are in USD. 0 means no limit.';
$string['settings:sitelimitusd'] = 'Monthly limit for the whole site (USD)';
$string['settings:sitelimitusd_desc'] = 'Total AI spending allowed per month on this site.';
$string['settings:userlimitusd'] = 'Monthly limit per user (USD)';
$string['settings:userlimitusd_desc'] = 'AI spending allowed per user and month. A limit set for a single user overrides it.';
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
