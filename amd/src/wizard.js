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
 * The course generation wizard.
 *
 * The page draws the form; this module shows it one step at a time, creates the job through the web services when
 * the teacher reaches the last step, shows the estimated cost, and after the teacher confirms it starts the job and
 * follows its progress until the blueprint is ready or the job fails.
 *
 * @module     local_aicoursebuilder/wizard
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {get_strings as getStrings} from 'core/str';

const COMPONENT = 'local_aicoursebuilder';

/** Names of the steps, in order; each is the id of a fieldset of the form. */
const STEPS = ['stepdestination', 'stepbrief', 'stepsources', 'stepconfirm'];

/** Seconds between two reads of the progress of a job. */
const POLL_SECONDS = 3;

/** Statuses after which the job does not change by itself. */
const FINAL_STATUSES = ['review', 'approved', 'building', 'finished', 'failed', 'cancelled'];

/** Strings the wizard shows from script. */
const STRING_KEYS = [
    'wizard:stepof',
    'wizard:errorcategory',
    'wizard:errorcourse',
    'wizard:errorprompt',
    'wizard:errorpolicy',
    'wizard:stage_draft',
    'wizard:stage_queued',
    'wizard:stage_ingesting',
    'wizard:stage_generating',
    'wizard:stage_review',
    'wizard:stage_failed',
    'wizard:sectionfirst',
];

/**
 * Calls one web service function.
 *
 * @param {String} methodname Name of the function.
 * @param {Object} args Its arguments.
 * @returns {Promise}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

class Wizard {
    /**
     * @param {Object} config contextid, jobid, policyaccepted and courseid, from the page.
     */
    constructor(config) {
        this.config = config;
        this.root = document.querySelector('[data-region="aicb-wizard"]');
        this.form = this.root.querySelector('form');
        this.current = 0;
        this.jobid = 0;
        this.policyAccepted = Boolean(config.policyaccepted);
        this.strings = {};
        this.timer = null;
    }

    /**
     * Loads the strings, then starts on the first step, or on the progress of the job the page was opened for.
     */
    async run() {
        const requests = STRING_KEYS.map((key) => ({key, component: COMPONENT}));
        const loaded = await getStrings(requests);
        STRING_KEYS.forEach((key, index) => {
            this.strings[key] = loaded[index];
        });

        this.root.querySelector('[data-action="aicb-back"]').addEventListener('click', () => this.show(this.current - 1));
        this.root.querySelector('[data-action="aicb-next"]').addEventListener('click', () => this.next());
        this.root.querySelector('[data-action="aicb-start"]').addEventListener('click', () => this.start());
        this.field('courseid')?.addEventListener('change', () => this.loadSections());

        if (this.config.jobid) {
            this.jobid = this.config.jobid;
            this.showProgress();
            return;
        }
        if (this.config.courseid) {
            this.loadSections();
        }
        this.show(0);
    }

    /**
     * Returns a field of the form.
     *
     * @param {String} name Name of the field.
     * @returns {HTMLElement|null}
     */
    field(name) {
        return this.form.querySelector(`[name="${name}"], [name="${name}[]"]`);
    }

    /**
     * Tells whether a checkbox of the form is ticked.
     *
     * An advanced checkbox has a hidden field of the same name before the checkbox, so the box is asked for by type.
     *
     * @param {String} name Name of the field.
     * @returns {Boolean}
     */
    checked(name) {
        const box = this.form.querySelector(`input[type="checkbox"][name="${name}"]`);
        return Boolean(box && box.checked);
    }

    /**
     * Returns the value of a field of the form.
     *
     * @param {String} name Name of the field.
     * @returns {String}
     */
    value(name) {
        const element = this.field(name);
        return element ? element.value.trim() : '';
    }

    /**
     * Shows one step and hides the others.
     *
     * @param {Number} index Index in STEPS.
     */
    show(index) {
        this.current = Math.max(0, Math.min(STEPS.length - 1, index));
        STEPS.forEach((name, position) => {
            const fieldset = this.form.querySelector(`#id_${name}`);
            if (fieldset) {
                fieldset.classList.toggle('d-none', position !== this.current);
            }
        });

        const last = this.current === STEPS.length - 1;
        this.root.querySelector('[data-action="aicb-back"]').disabled = this.current === 0;
        this.root.querySelector('[data-action="aicb-next"]').classList.toggle('d-none', last);
        this.root.querySelector('[data-action="aicb-start"]').classList.toggle('d-none', !last);
        this.root.querySelector('[data-region="aicb-stepindicator"]').textContent = this.strings['wizard:stepof']
            .replace('{$a->current}', this.current + 1)
            .replace('{$a->total}', STEPS.length);
        this.hideError();
    }

    /**
     * Moves to the next step, once the fields of this one are right.
     */
    async next() {
        const problem = this.problem();
        if (problem) {
            this.showError(problem);
            return;
        }
        if (this.current === STEPS.length - 2) {
            this.show(this.current + 1);
            await this.prepareConfirm();
            return;
        }
        this.show(this.current + 1);
    }

    /**
     * Returns what is wrong with the fields of the current step, or an empty string.
     *
     * @returns {String}
     */
    problem() {
        if (STEPS[this.current] === 'stepdestination') {
            if (this.value('mode') === 'existingcourse') {
                return Number(this.value('courseid')) > 0 ? '' : this.strings['wizard:errorcourse'];
            }
            return Number(this.value('categoryid')) > 0 ? '' : this.strings['wizard:errorcategory'];
        }
        if (STEPS[this.current] === 'stepbrief') {
            return this.value('prompt') === '' ? this.strings['wizard:errorprompt'] : '';
        }
        return '';
    }

    /**
     * Fills the sections of the chosen course.
     */
    async loadSections() {
        const select = this.field('sectionnum');
        const courseid = Number(this.value('courseid'));
        if (!select || !courseid) {
            return;
        }
        try {
            const sections = await call('core_course_get_contents', {
                courseid,
                options: [{name: 'excludemodules', value: 1}, {name: 'excludecontents', value: 1}],
            });
            select.innerHTML = '';
            sections.forEach((section) => {
                const option = document.createElement('option');
                option.value = section.section;
                const fallback = section.section === 0 ? this.strings['wizard:sectionfirst'] : section.section;
                option.textContent = section.name || fallback;
                select.appendChild(option);
            });
        } catch (error) {
            this.showError(error.message);
        }
    }

    /**
     * Returns the brief fields the teacher filled in, as the JSON the job keeps.
     *
     * @returns {String} A JSON object, empty when no field was filled in.
     */
    briefJson() {
        const brief = {};
        const audience = this.value('audience');
        const level = this.value('level');
        const tone = this.value('tone');
        const duration = Number(this.value('duration'));
        if (audience) {
            brief.audience = audience;
        }
        if (level) {
            brief.level = level;
        }
        if (tone) {
            brief.tone = tone;
        }
        if (duration > 0) {
            // The name is the one the brief schema uses.
            // eslint-disable-next-line camelcase
            brief.duration_minutes = duration;
        }
        return Object.keys(brief).length ? JSON.stringify(brief) : '';
    }

    /**
     * Creates the job as a draft and shows what it will cost.
     */
    async prepareConfirm() {
        const region = this.root.querySelector('[data-region="aicb-estimate"]');
        const start = this.root.querySelector('[data-action="aicb-start"]');
        start.disabled = true;
        region.textContent = '';
        this.showPolicy();

        try {
            const existing = this.value('mode') === 'existingcourse';
            const job = await call('local_aicoursebuilder_create_job', {
                mode: existing ? 'existingcourse' : 'newcourse',
                categoryid: existing ? 0 : Number(this.value('categoryid')),
                courseid: existing ? Number(this.value('courseid')) : 0,
                sectionnum: existing ? Number(this.value('sectionnum')) : 0,
                prompt: this.value('prompt'),
                language: this.value('language') || 'ro',
                draftitemid: Number(this.value('sources')),
                brief: this.briefJson(),
                offpeak: this.checked('offpeak'),
            });
            this.jobid = job.jobid;

            const estimate = await call('local_aicoursebuilder_estimate_cost', {jobid: this.jobid});
            const {html, js} = await Templates.renderForPromise('local_aicoursebuilder/wizard_estimate', {
                cost: estimate.estimatedcost.toFixed(4),
                tokensin: estimate.tokensin.toLocaleString(),
                tokensout: estimate.tokensout.toLocaleString(),
                withinbudget: estimate.withinbudget,
                hasjoblimit: estimate.joblimit > 0,
                joblimit: estimate.joblimit.toFixed(2),
                hasuserlimit: estimate.userremaining >= 0,
                userremaining: estimate.userremaining.toFixed(2),
            });
            Templates.replaceNodeContents(region, html, js);
            start.disabled = !estimate.withinbudget;
        } catch (error) {
            this.showError(error.message);
        }
    }

    /**
     * Shows the AI policy checkbox, unless the teacher accepted the policy already.
     */
    showPolicy() {
        const checkbox = this.field('acceptpolicy');
        const row = checkbox ? checkbox.closest('.fitem') : null;
        if (row) {
            row.classList.toggle('d-none', this.policyAccepted);
        }
    }

    /**
     * Starts the job and follows it.
     */
    async start() {
        this.hideError();
        try {
            if (!this.policyAccepted) {
                if (!this.checked('acceptpolicy')) {
                    this.showError(this.strings['wizard:errorpolicy']);
                    return;
                }
                const accepted = await call('core_ai_set_policy_status', {contextid: this.config.contextid});
                this.policyAccepted = Boolean(accepted.success);
            }
            await call('local_aicoursebuilder_start_job', {jobid: this.jobid});
            this.showProgress();
        } catch (error) {
            this.showError(error.message);
        }
    }

    /**
     * Replaces the form with the progress of the job and reads it until the job ends.
     */
    showProgress() {
        this.root.querySelector('[data-region="aicb-steps"]').classList.add('d-none');
        this.root.querySelector('[data-region="aicb-progress"]').classList.remove('d-none');
        this.poll();
    }

    /**
     * Reads the status of the job, shows it, and schedules the next read while the job is working.
     */
    async poll() {
        try {
            const status = await call('local_aicoursebuilder_get_job_status', {jobid: this.jobid});
            await this.renderProgress(status);
            if (!FINAL_STATUSES.includes(status.status)) {
                this.timer = setTimeout(() => this.poll(), POLL_SECONDS * 1000);
            }
        } catch (error) {
            this.showError(error.message);
        }
    }

    /**
     * Shows the status of the job.
     *
     * @param {Object} status What local_aicoursebuilder_get_job_status returned.
     */
    async renderProgress(status) {
        const done = status.steps.filter((step) => step.status === 'done' || step.status === 'manual').length;
        let percent = 0;
        if (status.status === 'ingesting') {
            percent = Math.round(status.progress / 2);
        } else if (status.status === 'generating') {
            percent = 50 + Math.min(45, done * 5);
        } else if (status.status === 'review') {
            percent = 100;
        }

        const stage = this.strings[`wizard:stage_${status.status}`] || this.strings['wizard:stage_queued'];
        const {html, js} = await Templates.renderForPromise('local_aicoursebuilder/wizard_progress', {
            percent,
            stage,
            message: status.message,
            cost: status.actualcost.toFixed(4),
            running: !FINAL_STATUSES.includes(status.status),
            finished: status.status === 'review',
            failed: status.status === 'failed',
            error: status.error,
        });
        Templates.replaceNodeContents(this.root.querySelector('[data-region="aicb-progress"]'), html, js);
    }

    /**
     * Shows a message above the wizard.
     *
     * @param {String} message The message.
     */
    showError(message) {
        const region = this.root.querySelector('[data-region="aicb-error"]');
        region.textContent = message;
        region.classList.remove('d-none');
    }

    /**
     * Hides the message above the wizard.
     */
    hideError() {
        this.root.querySelector('[data-region="aicb-error"]').classList.add('d-none');
    }
}

/**
 * Starts the wizard.
 *
 * @param {Object} config contextid, jobid, policyaccepted and courseid, from the page.
 */
export const init = (config) => {
    new Wizard(config).run();
};
