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
 * Follows the progress of a generation job: reads its status every few seconds and shows it, until it ends.
 *
 * The wizard uses it right after the teacher starts a job, and the job page uses it for every later visit, so the
 * two show the same thing the same way.
 *
 * @module     local_aicoursebuilder/progress
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {get_strings as getStrings} from 'core/str';

const COMPONENT = 'local_aicoursebuilder';

/** Seconds between two reads of the status of a job. */
const POLL_SECONDS = 4;

/** Statuses after which the job does not change by itself. */
const FINAL_STATUSES = ['review', 'approved', 'building', 'finished', 'failed', 'cancelled'];

/** Statuses of a job the teacher can open in the editor. */
const EDITABLE_STATUSES = ['review', 'approved', 'building', 'finished'];

/** What the page says about each status. */
const STAGES = ['draft', 'queued', 'ingesting', 'generating', 'review', 'approved', 'building', 'finished', 'failed', 'cancelled'];

/**
 * Tells whether a job has stopped changing by itself.
 *
 * @param {String} status The status of the job.
 * @returns {Boolean}
 */
export const isFinal = (status) => FINAL_STATUSES.includes(status);

/**
 * Returns how far along a job is, as one number from 0 to 100 over the whole generation.
 *
 * The job reports the progress of the stage it is in, so the ingestion counts for the first half and the steps the
 * pipeline has finished for the second.
 *
 * @param {Object} status What local_aicoursebuilder_get_job_status returned.
 * @returns {Number}
 */
export const percentOf = (status) => {
    if (status.status === 'ingesting') {
        return Math.round(status.progress / 2);
    }
    if (status.status === 'generating') {
        const done = status.steps.filter((step) => step.status === 'done' || step.status === 'manual').length;
        return 50 + Math.min(45, done * 5);
    }
    return ['review', 'approved', 'building', 'finished'].includes(status.status) ? 100 : 0;
};

/**
 * Shows the status of a job in a region.
 *
 * @param {HTMLElement} region Where to show it.
 * @param {Object} status What local_aicoursebuilder_get_job_status returned.
 * @param {Object} strings The stage names, keyed by status.
 * @param {Object} links Where the teacher can go from here: reviewurl and courseurl, either may be empty.
 */
export const render = async(region, status, strings, links = {}) => {
    const {html, js} = await Templates.renderForPromise('local_aicoursebuilder/wizard_progress', {
        percent: percentOf(status),
        stage: strings[status.status] || strings.queued,
        message: status.message,
        cost: status.actualcost.toFixed(4),
        running: !isFinal(status.status),
        finished: status.status === 'review',
        failed: status.status === 'failed',
        error: status.error,
        reviewurl: EDITABLE_STATUSES.includes(status.status) ? links.reviewurl : '',
        courseurl: status.status === 'finished' ? links.courseurl : '',
    });
    Templates.replaceNodeContents(region, html, js);
};

/**
 * Loads the stage names.
 *
 * @returns {Promise<Object>} The names, keyed by status.
 */
export const loadStages = async() => {
    const loaded = await getStrings(STAGES.map((stage) => ({key: `wizard:stage_${stage}`, component: COMPONENT})));
    const strings = {};
    STAGES.forEach((stage, index) => {
        strings[stage] = loaded[index];
    });
    return strings;
};

/**
 * Reads the status of a job now and then until it ends, showing it in a region.
 *
 * @param {HTMLElement} region Where to show the status.
 * @param {Number} jobid The job.
 * @param {Object} links reviewurl and courseurl, for the buttons the status offers.
 * @param {Function} onError Called with the error when the status cannot be read.
 * @returns {Object} A handle with stop(), to stop reading.
 */
export const watch = (region, jobid, links = {}, onError = () => null) => {
    let timer = null;
    let stopped = false;
    let strings = null;

    const poll = async() => {
        try {
            strings = strings || await loadStages();
            const status = await Ajax.call([{methodname: 'local_aicoursebuilder_get_job_status', args: {jobid}}])[0];
            await render(region, status, strings, links);
            if (!stopped && !isFinal(status.status)) {
                timer = setTimeout(poll, POLL_SECONDS * 1000);
            }
        } catch (error) {
            onError(error);
        }
    };
    poll();

    return {
        stop: () => {
            stopped = true;
            clearTimeout(timer);
        },
    };
};

/**
 * Starts the job page: follows the job it was opened for.
 *
 * @param {Object} config jobid, reviewurl and courseurl, from the page.
 */
export const init = (config) => {
    const region = document.querySelector('[data-region="aicb-job-progress"]');
    const error = document.querySelector('[data-region="aicb-job-error"]');
    watch(region, config.jobid, config, (failure) => {
        error.textContent = failure.message;
        error.classList.remove('d-none');
    });
};
