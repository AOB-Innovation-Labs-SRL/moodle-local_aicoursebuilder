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
$string['messageprovider:jobfailed'] = 'AI Course Builder job failed';
$string['messageprovider:jobfinished'] = 'AI Course Builder job finished';
$string['notimplemented'] = 'This feature is not implemented yet.';
$string['notjobowner'] = 'Only the owner of this job can change it.';
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
$string['privacy:path:jobs'] = 'Jobs';
$string['settings:alertpercent'] = 'Budget alert threshold (%)';
$string['settings:alertpercent_desc'] = 'Managers are notified when a monthly budget reaches this percentage of its limit.';
$string['settings:joblimitusd'] = 'Limit per job (USD)';
$string['settings:joblimitusd_desc'] = 'A job whose estimated cost is higher than this is not started. 0 means no limit.';
$string['settings:jobretentiondays'] = 'Job retention (days)';
$string['settings:jobretentiondays_desc'] = 'Finished, failed and cancelled jobs are deleted with their sources and intermediate data after this number of days. 0 keeps them.';
$string['settings:limitsheading'] = 'Cost limits';
$string['settings:limitsheading_desc'] = 'Amounts are in USD. 0 means no limit.';
$string['settings:maxuploadmb'] = 'Maximum size of a source file (MB)';
$string['settings:maxuploadmb_desc'] = 'Largest file a teacher can upload as source material.';
$string['settings:sitelimitusd'] = 'Monthly limit for the whole site (USD)';
$string['settings:sitelimitusd_desc'] = 'Total AI spending allowed per month on this site.';
$string['settings:sourcesheading'] = 'Sources and retention';
$string['settings:userlimitusd'] = 'Monthly limit per user (USD)';
$string['settings:userlimitusd_desc'] = 'AI spending allowed per user and month. A limit set for a single user overrides it.';
