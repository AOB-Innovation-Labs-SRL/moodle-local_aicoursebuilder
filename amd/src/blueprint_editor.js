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
 * The blueprint editor.
 *
 * Shows the course the generation wrote as a tree, lets the teacher edit its text, delete and reorder its parts and
 * ask for a part to be written again, saves every edit as a new version of the blueprint, and approves the version
 * the course is built from. The blueprint is read and written through the web services; nothing is kept in the page
 * but the version being edited.
 *
 * @module     local_aicoursebuilder/blueprint_editor
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {get_strings as getStrings} from 'core/str';

const COMPONENT = 'local_aicoursebuilder';

/** Seconds between two reads of a job that is writing a part of the course again. */
const REGENERATION_POLL_SECONDS = 3;

/** Most seconds to wait for a part to be written again. */
const REGENERATION_TIMEOUT_SECONDS = 300;

/** The strings the editor shows from script. */
const STRING_KEYS = [
    'editor:kind_section', 'editor:kind_subsection', 'editor:kind_activity', 'editor:kind_question',
    'editor:field_title', 'editor:field_summary', 'editor:field_name', 'editor:field_intro', 'editor:field_text',
    'editor:field_chaptertitle', 'editor:field_chaptercontent', 'editor:field_questiontext', 'editor:field_feedback',
    'editor:status_saved', 'editor:status_unsaved', 'editor:status_approved', 'editor:status_version',
    'editor:saved', 'editor:savedinvalid', 'editor:errorsheading', 'editor:regenerating', 'editor:regenerated',
    'editor:regenerationfailed', 'editor:regenerationtimeout', 'editor:approving', 'editor:approvedmessage',
    'editor:approveconfirmtitle', 'editor:approveconfirm', 'editor:approve', 'editor:readonly',
    'editor:nothingselected', 'editor:unsavedwarning',
];

/**
 * Calls one web service function.
 *
 * @param {String} methodname Name of the function.
 * @param {Object} args Its arguments.
 * @returns {Promise}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Waits.
 *
 * @param {Number} seconds How long.
 * @returns {Promise}
 */
const wait = (seconds) => new Promise((resolve) => setTimeout(resolve, seconds * 1000));

class Editor {
    /**
     * @param {Object} config jobid and editable, from the page.
     */
    constructor(config) {
        this.config = config;
        this.root = document.querySelector('[data-region="aicb-editor"]');
        this.blueprint = null;
        this.version = 0;
        this.hash = '';
        this.status = 'draft';
        this.dirty = false;
        this.busy = false;
        this.selected = [];
        this.errors = [];
        this.strings = {};
    }

    /**
     * Whether the teacher can change the blueprint now.
     *
     * @returns {Boolean}
     */
    get editable() {
        return Boolean(this.config.editable) && this.status !== 'approved';
    }

    /**
     * Loads the strings and the blueprint, shows the tree, and starts listening.
     */
    async run() {
        const requests = STRING_KEYS.map((key) => ({key, component: COMPONENT}));
        const loaded = await getStrings(requests);
        STRING_KEYS.forEach((key, index) => {
            this.strings[key] = loaded[index];
        });

        try {
            await this.load(0);
        } catch (error) {
            this.message(error.message, 'danger');
            return;
        }
        if (this.status === 'approved') {
            this.message(this.strings['editor:readonly'], 'info');
        }
        this.selected = this.blueprint.sections.length ? ['sections', 0] : [];
        this.listen();
        await this.render();
    }

    /**
     * Reads a version of the blueprint from the server.
     *
     * @param {Number} version The version, 0 for the latest.
     */
    async load(version) {
        const data = await call('local_aicoursebuilder_get_blueprint', {jobid: this.config.jobid, version});
        this.blueprint = JSON.parse(data.blueprint);
        this.blueprint.sections = this.blueprint.sections || [];
        this.version = data.version;
        this.hash = data.contenthash;
        this.status = data.status;
        this.dirty = false;
        this.errors = [];
    }

    /**
     * Listens to the buttons and the fields of the editor.
     */
    listen() {
        this.root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-action]');
            if (!button || !this.root.contains(button) || button.disabled) {
                return;
            }
            this.act(button.dataset.action, button);
        });
        this.root.addEventListener('input', (event) => {
            const field = event.target.closest('[data-field]');
            if (field && this.editable) {
                this.edit(field.dataset.field, field.value);
            }
        });
        window.addEventListener('beforeunload', (event) => {
            if (this.dirty) {
                event.preventDefault();
                event.returnValue = this.strings['editor:unsavedwarning'];
            }
        });
    }

    /**
     * Does what a button asks.
     *
     * @param {String} action The data-action of the button.
     * @param {HTMLElement} button The button.
     */
    async act(action, button) {
        if (this.busy && action !== 'aicb-select') {
            return;
        }
        switch (action) {
            case 'aicb-select':
                this.selected = button.dataset.id.split('/').map((part) => (/^\d+$/.test(part) ? Number(part) : part));
                await this.render();
                break;
            case 'aicb-save':
                await this.guarded(() => this.save(true));
                break;
            case 'aicb-approve':
                await this.guarded(() => this.approve());
                break;
            case 'aicb-moveup':
                await this.move(-1);
                break;
            case 'aicb-movedown':
                await this.move(1);
                break;
            case 'aicb-delete':
                await this.remove();
                break;
            case 'aicb-clearflag':
                this.clearFlag();
                break;
            case 'aicb-regenerate':
                await this.guarded(() => this.regenerate());
                break;
            default:
                break;
        }
    }

    /**
     * Runs an action that talks to the server, showing its failure and keeping the editor from starting another.
     *
     * @param {Function} action What to do.
     */
    async guarded(action) {
        this.busy = true;
        this.setButtons();
        try {
            await action();
        } catch (error) {
            this.message(error.message, 'danger');
        } finally {
            this.busy = false;
            this.setButtons();
            await this.renderNode();
        }
    }

    /**
     * Walks the parts of the course: sections, subsections, activities and the questions of a quiz.
     *
     * @param {Function} visit Called with each part as {kind, node, path, depth}.
     */
    walk(visit) {
        this.blueprint.sections.forEach((section, si) => {
            const spath = ['sections', si];
            visit({kind: 'section', node: section, path: spath, depth: 0});
            this.walkActivities(section, spath, 1, visit);
            (section.subsections || []).forEach((subsection, ui) => {
                const upath = [...spath, 'subsections', ui];
                visit({kind: 'subsection', node: subsection, path: upath, depth: 1});
                this.walkActivities(subsection, upath, 2, visit);
            });
        });
    }

    /**
     * Walks the activities of a section or subsection, and the questions of its quizzes.
     *
     * @param {Object} container The section or subsection.
     * @param {Array} path Where it is in the blueprint.
     * @param {Number} depth How deep its activities are.
     * @param {Function} visit Called with each part.
     */
    walkActivities(container, path, depth, visit) {
        (container.activities || []).forEach((activity, ai) => {
            const apath = [...path, 'activities', ai];
            visit({kind: 'activity', node: activity, path: apath, depth});
            ((activity.content || {}).questions || []).forEach((question, qi) => {
                visit({kind: 'question', node: question, path: [...apath, 'content', 'questions', qi], depth: depth + 1});
            });
        });
    }

    /**
     * Finds a part of the course by its path.
     *
     * @param {Array} path The path.
     * @returns {Object|null} The part, as walk() gives it, or null when there is none there.
     */
    find(path) {
        let found = null;
        const key = path.join('/');
        this.walk((entry) => {
            if (entry.path.join('/') === key) {
                found = entry;
            }
        });
        return found;
    }

    /**
     * Returns the array a part is in, and its place in it.
     *
     * @param {Array} path The path of the part.
     * @returns {Object} The array and the index.
     */
    parentOf(path) {
        let array = this.blueprint;
        path.slice(0, -1).forEach((part) => {
            array = array[part];
        });
        return {array, index: path[path.length - 1]};
    }

    /**
     * Finds the parts the validator found fault with.
     *
     * @returns {Object} The path keys of those parts, and the messages for each.
     */
    errorsByNode() {
        const byNode = {};
        const known = new Set();
        this.walk((entry) => known.add(entry.path.join('/')));
        this.errors.forEach((error) => {
            const parts = error.path.split('/').filter((part) => part !== '')
                .map((part) => (/^\d+$/.test(part) ? Number(part) : part));
            for (let length = parts.length; length > 0; length--) {
                const key = parts.slice(0, length).join('/');
                if (known.has(key)) {
                    byNode[key] = (byNode[key] || []).concat(error.message);
                    break;
                }
            }
        });
        return byNode;
    }

    /**
     * Returns the text fields that can be edited in a part.
     *
     * @param {Object} entry The part.
     * @returns {Array} The fields: key (a path inside the part), label, value and multiline.
     */
    fieldsOf(entry) {
        const {kind, node} = entry;
        const s = this.strings;
        const field = (key, label, value, multiline) => ({key, label, value: value || '', multiline});

        if (kind === 'section' || kind === 'subsection') {
            return [
                field('title', s['editor:field_title'], node.title, false),
                field('summary', s['editor:field_summary'], node.summary, true),
            ];
        }
        if (kind === 'question') {
            return [
                field('name', s['editor:field_name'], node.name, false),
                field('questiontext', s['editor:field_questiontext'], node.questiontext, true),
                field('generalfeedback', s['editor:field_feedback'], node.generalfeedback, true),
            ];
        }
        const fields = [
            field('name', s['editor:field_name'], node.name, false),
            field('intro', s['editor:field_intro'], node.intro, true),
        ];
        const content = node.content || {};
        if (typeof content.text === 'string') {
            fields.push(field('content.text', s['editor:field_text'], content.text, true));
        }
        (content.chapters || []).forEach((chapter, index) => {
            const number = index + 1;
            fields.push(field(
                `content.chapters.${index}.title`,
                s['editor:field_chaptertitle'].replace('{$a}', number),
                chapter.title,
                false
            ));
            fields.push(field(
                `content.chapters.${index}.content`,
                s['editor:field_chaptercontent'].replace('{$a}', number),
                chapter.content,
                true
            ));
        });
        return fields;
    }

    /**
     * Returns the label of a part in the tree.
     *
     * @param {Object} entry The part.
     * @returns {String}
     */
    labelOf(entry) {
        const label = entry.kind === 'section' || entry.kind === 'subsection' ? entry.node.title : entry.node.name;
        return label || entry.node.id || '';
    }

    /**
     * Shows the tree, the part being edited, and the status.
     */
    async render() {
        await this.renderTree();
        await this.renderNode();
        this.renderStatus();
        this.renderErrors();
        this.setButtons();
    }

    /**
     * Shows the tree of the course.
     */
    async renderTree() {
        const errors = this.errorsByNode();
        const selectedKey = this.selected.join('/');
        const nodes = [];
        this.walk((entry) => {
            const key = entry.path.join('/');
            nodes.push({
                id: key,
                label: this.labelOf(entry),
                kind: entry.kind,
                type: entry.node.type || entry.node.qtype || '',
                indent: Math.min(5, entry.depth * 2),
                flagged: Boolean(entry.node.review_flag),
                selected: key === selectedKey,
                invalid: Boolean(errors[key]),
            });
        });
        const {html, js} = await Templates.renderForPromise('local_aicoursebuilder/blueprint_tree', {nodes});
        Templates.replaceNodeContents(this.root.querySelector('[data-region="aicb-editor-tree"]'), html, js);
    }

    /**
     * Shows the part being edited.
     */
    async renderNode() {
        const region = this.root.querySelector('[data-region="aicb-editor-node"]');
        const entry = this.find(this.selected);
        if (!entry) {
            region.textContent = this.strings['editor:nothingselected'];
            return;
        }
        const {array, index} = this.parentOf(entry.path);
        const errors = this.errorsByNode()[entry.path.join('/')] || [];
        const fields = this.fieldsOf(entry).map((field, number) => ({...field, fieldid: `aicb-field-${number}`}));
        const {html, js} = await Templates.renderForPromise('local_aicoursebuilder/node_editor', {
            id: entry.node.id || entry.path.join('/'),
            kindlabel: this.strings[`editor:kind_${entry.kind}`],
            editable: this.editable,
            flagged: Boolean(entry.node.review_flag),
            fields,
            canup: index > 0,
            candown: index < array.length - 1,
            candelete: !(entry.kind === 'section' && array.length === 1),
            canregenerate: Boolean(entry.node.id) && entry.kind !== 'subsection',
            errors: errors.map((message) => ({message})),
        });
        Templates.replaceNodeContents(region, html, js);
    }

    /**
     * Shows the version, and whether it is saved.
     */
    renderStatus() {
        let state = this.dirty ? this.strings['editor:status_unsaved'] : this.strings['editor:status_saved'];
        if (this.status === 'approved') {
            state = this.strings['editor:status_approved'];
        }
        this.root.querySelector('[data-region="aicb-editor-status"]').textContent =
            `${this.strings['editor:status_version'].replace('{$a}', this.version)} · ${state}`;
    }

    /**
     * Lists what the validator found fault with.
     */
    renderErrors() {
        const region = this.root.querySelector('[data-region="aicb-editor-errors"]');
        region.textContent = '';
        if (!this.errors.length) {
            region.classList.add('d-none');
            return;
        }
        const heading = document.createElement('strong');
        heading.textContent = this.strings['editor:errorsheading'];
        const list = document.createElement('ul');
        list.className = 'mb-0';
        this.errors.forEach((error) => {
            const item = document.createElement('li');
            item.textContent = `${error.path || '/'}: ${error.message}`;
            list.appendChild(item);
        });
        region.append(heading, list);
        region.classList.remove('d-none');
    }

    /**
     * Enables the buttons that can be used now.
     */
    setButtons() {
        const save = this.root.querySelector('[data-action="aicb-save"]');
        const approve = this.root.querySelector('[data-action="aicb-approve"]');
        if (save) {
            save.disabled = this.busy || !this.dirty;
        }
        if (approve) {
            approve.disabled = this.busy || this.status === 'approved';
        }
        if (this.busy) {
            // The buttons of the part being edited are drawn again, with the right ones disabled, when the action ends.
            this.root.querySelectorAll('[data-region="aicb-editor-node"] button').forEach((button) => {
                button.disabled = true;
            });
        }
    }

    /**
     * Shows a message above the editor.
     *
     * @param {String} text The message.
     * @param {String} type success, info, warning or danger.
     */
    message(text, type) {
        const region = this.root.querySelector('[data-region="aicb-editor-message"]');
        region.textContent = text;
        region.className = `alert alert-${type}`;
    }

    /**
     * Sets a text field of the selected part.
     *
     * @param {String} key The path of the field inside the part.
     * @param {String} value The new text.
     */
    edit(key, value) {
        const entry = this.find(this.selected);
        if (!entry) {
            return;
        }
        const parts = key.split('.').map((part) => (/^\d+$/.test(part) ? Number(part) : part));
        let target = entry.node;
        parts.slice(0, -1).forEach((part) => {
            target = target[part];
        });
        target[parts[parts.length - 1]] = value;
        this.changed();
        // The label in the tree follows the title or the name as it is typed.
        if (key === 'title' || key === 'name') {
            this.renderTree();
        }
    }

    /**
     * Notes that the blueprint was changed since it was saved.
     */
    changed() {
        this.dirty = true;
        this.renderStatus();
        this.setButtons();
    }

    /**
     * Moves the selected part up or down among its neighbours.
     *
     * @param {Number} direction -1 for up, 1 for down.
     */
    async move(direction) {
        const {array, index} = this.parentOf(this.selected);
        const target = index + direction;
        if (target < 0 || target >= array.length) {
            return;
        }
        [array[index], array[target]] = [array[target], array[index]];
        this.selected = [...this.selected.slice(0, -1), target];
        this.changed();
        await this.render();
    }

    /**
     * Deletes the selected part.
     */
    async remove() {
        const {array, index} = this.parentOf(this.selected);
        array.splice(index, 1);
        if (array.length) {
            // The part that took its place is selected.
            this.selected = [...this.selected.slice(0, -1), Math.min(index, array.length - 1)];
        } else {
            // The last one of its kind went: the part that held it is selected.
            const parent = this.selected.slice(0, -2);
            this.selected = this.find(parent) ? parent : [];
        }
        this.changed();
        await this.render();
    }

    /**
     * Takes the mark for a human to check off the selected part.
     */
    clearFlag() {
        const entry = this.find(this.selected);
        if (entry) {
            delete entry.node.review_flag;
            this.changed();
            this.render();
        }
    }

    /**
     * Saves the blueprint as a new version, when it was changed.
     *
     * @param {Boolean} announce Whether to tell the teacher it was saved.
     * @returns {Promise<Boolean>} Whether the saved blueprint is valid.
     */
    async save(announce) {
        const result = await call('local_aicoursebuilder_save_blueprint', {
            jobid: this.config.jobid,
            blueprint: JSON.stringify(this.blueprint),
            baseversion: this.version,
        });
        this.version = result.version;
        this.hash = result.contenthash;
        this.dirty = false;
        this.errors = result.errors;
        if (announce) {
            this.message(this.strings[result.valid ? 'editor:saved' : 'editor:savedinvalid'], result.valid ? 'success' : 'warning');
        }
        await this.render();
        return result.valid;
    }

    /**
     * Approves the blueprint, after the teacher confirms: the version is locked and the course is built from it.
     */
    async approve() {
        await Notification.saveCancelPromise(
            this.strings['editor:approveconfirmtitle'],
            this.strings['editor:approveconfirm'],
            this.strings['editor:approve']
        );
        if (this.dirty && !await this.save(false)) {
            this.message(this.strings['editor:savedinvalid'], 'warning');
            return;
        }
        this.message(this.strings['editor:approving'], 'info');
        await call('local_aicoursebuilder_approve_blueprint', {
            jobid: this.config.jobid,
            version: this.version,
            contenthash: this.hash,
        });
        this.status = 'approved';
        this.message(this.strings['editor:approvedmessage'], 'success');
        await this.render();
    }

    /**
     * Asks for the selected part to be written again, and shows the new version when it is ready.
     */
    async regenerate() {
        const entry = this.find(this.selected);
        if (!entry || !entry.node.id) {
            return;
        }
        const nodeid = entry.node.id;
        const instructions = this.root.querySelector('[data-region="aicb-instructions"]').value.trim();
        if (this.dirty) {
            await this.save(false);
        }

        const before = this.version;
        this.message(this.strings['editor:regenerating'], 'info');
        await call('local_aicoursebuilder_regenerate_node', {jobid: this.config.jobid, nodeid, instructions});

        for (let waited = 0; waited < REGENERATION_TIMEOUT_SECONDS; waited += REGENERATION_POLL_SECONDS) {
            await wait(REGENERATION_POLL_SECONDS);
            const status = await call('local_aicoursebuilder_get_job_status', {jobid: this.config.jobid});
            if (status.blueprintversion > before) {
                await this.load(0);
                const stillThere = this.find(this.selected);
                if (!stillThere) {
                    this.selected = this.blueprint.sections.length ? ['sections', 0] : [];
                }
                this.message(this.strings['editor:regenerated'], 'success');
                await this.render();
                return;
            }
            if (status.error) {
                this.message(`${this.strings['editor:regenerationfailed']} ${status.error}`, 'danger');
                return;
            }
        }
        this.message(this.strings['editor:regenerationtimeout'], 'warning');
    }
}

/**
 * Starts the editor.
 *
 * @param {Object} config jobid and editable, from the page.
 */
export const init = (config) => {
    new Editor(config).run();
};
