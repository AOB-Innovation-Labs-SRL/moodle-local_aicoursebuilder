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
 * Shows the estimated cost of a job before the teacher starts it.
 *
 * @module     local_aicoursebuilder/cost_estimator
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';

/**
 * Asks for the estimate of a job and shows it in a region.
 *
 * @param {HTMLElement} region Where to show the estimate.
 * @param {Number} jobid The job.
 * @returns {Promise<Object>} What local_aicoursebuilder_estimate_cost returned; withinbudget says whether the job may start.
 */
export const show = async(region, jobid) => {
    const estimate = await Ajax.call([{methodname: 'local_aicoursebuilder_estimate_cost', args: {jobid}}])[0];
    const {html, js} = await Templates.renderForPromise('local_aicoursebuilder/wizard_estimate', {
        cost: estimate.estimatedcost.toFixed(4),
        tokensin: estimate.tokensin.toLocaleString(),
        tokensout: estimate.tokensout.toLocaleString(),
        withinbudget: estimate.withinbudget,
        hasjoblimit: estimate.joblimit > 0,
        joblimit: estimate.joblimit.toFixed(2),
        hasuserlimit: estimate.userremaining >= 0,
        userremaining: estimate.userremaining.toFixed(2),
        useralert: estimate.useralert,
    });
    Templates.replaceNodeContents(region, html, js);
    return estimate;
};
