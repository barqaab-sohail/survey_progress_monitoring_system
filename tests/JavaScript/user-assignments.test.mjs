import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { JSDOM } from '../../tmp/assignment-qa/node_modules/jsdom/lib/api.js';

const source = await fs.readFile('public/js/user-assignments.js', 'utf8');
const { TaskSelection, initAssignments } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const html = await fs.readFile('tmp/assignment-qa/assignments.html', 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 10));

function setup() {
    const dom = new JSDOM(html, { url: 'http://localhost/legacy-admin/assignments', runScripts: 'outside-only' });
    const d = dom.window.document;
    globalThis.window = dom.window; globalThis.FormData = dom.window.FormData; globalThis.Option = dom.window.Option;
    dom.window.HTMLElement.prototype.scrollIntoView = () => {};
    dom.window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    dom.window.HTMLDialogElement.prototype.close = function () { this.open = false; };
    initAssignments(d);
    return { dom, d, close: () => dom.window.close() };
}

test('selection rejects disabled tasks, deduplicates, and enforces the bulk limit', () => {
    const s = new TaskSelection();
    assert.equal(s.set({id: 1, disabled: true}, true), false);
    for (let i = 1; i <= 50; i++) assert.equal(s.set({id: i}, true), true);
    assert.equal(s.set({id: 51}, true), false);
    assert.equal(s.set({id: 1}, true), true);
    assert.equal(s.items.size, 50);
    s.set({id: 1}, false); assert.equal(s.items.size, 49);
    s.clear(); assert.equal(s.items.size, 0);
});

test('user search filters names and emails while retaining the native user picker', () => {
    const { d, dom, close } = setup();
    try {
        const input = d.getElementById('user-search');
        const select = d.getElementById('assignment-user');
        input.value = select.options[1].textContent.split(' · ')[1];
        input.dispatchEvent(new dom.window.Event('input'));
        assert.equal([...select.options].filter(o => o.value && !o.hidden).length, 1);
        assert.match(d.querySelector('[data-user-search-status]').textContent, /1 matching/);
        input.value = ''; input.dispatchEvent(new dom.window.Event('input'));
        assert.equal([...select.options].some(o => o.hidden), false);
    } finally { close(); }
});

test('selections survive task searches and pages without duplicate submitted inputs', async () => {
    const { d, dom, close } = setup();
    try {
        const item = d.querySelector('[data-choice]:not(:disabled)');
        assert.ok(item, 'Generate the latest PHP fixture with an available feeder');
        item.checked = true; item.dispatchEvent(new dom.window.Event('change'));
        const originalId = item.value;
        globalThis.fetch = async () => ({ok: true, json: async () => ({items: [{id: 900, label: 'Other page feeder', owner: 0, disabled: false}], more: true})});
        d.getElementById('task-finder').dispatchEvent(new dom.window.Event('submit', {cancelable: true}));
        await tick();
        const other = d.querySelector('[data-choice]'); other.checked = true; other.dispatchEvent(new dom.window.Event('change'));
        assert.deepEqual([...d.querySelectorAll('#selected-hidden [name="task_ids[]"]')].map(i => i.value), [originalId, '900']);
        assert.equal(d.querySelectorAll('#task-results [name="task_ids[]"]').length, 0);
        d.getElementById('select-visible').click();
        assert.equal(d.querySelectorAll('#selected-hidden [name="task_ids[]"]').length, 2);
        d.getElementById('clear-selected').click(); assert.equal(d.getElementById('selected-hidden').children.length, 0);
    } finally { close(); }
});

test('late search responses cannot replace newer results and labels are rendered as text', async () => {
    const { d, dom, close } = setup();
    try {
        const pending = [];
        globalThis.fetch = () => new Promise(resolve => pending.push(resolve));
        const finder = d.getElementById('task-finder');
        finder.dispatchEvent(new dom.window.Event('submit', {cancelable: true}));
        finder.dispatchEvent(new dom.window.Event('submit', {cancelable: true}));
        pending[1]({ok: true, json: async () => ({items: [{id: 902, label: '<img src=x onerror=alert(1)>', owner: 0}], more: false})});
        await tick();
        pending[0]({ok: true, json: async () => ({items: [{id: 901, label: 'Old response', owner: 0}], more: false})});
        await tick();
        assert.equal(d.querySelector('[data-choice]').value, '902');
        assert.equal(d.querySelector('#task-results img'), null);
        assert.match(d.getElementById('task-results').textContent, /<img/);
    } finally { close(); }
});

test('confirmation precedes submission, prevents repeated saves, and team change clears selection', async () => {
    const { d, dom, close } = setup();
    try {
        const item = d.querySelector('[data-choice]:not(:disabled)'); item.checked = true; item.dispatchEvent(new dom.window.Event('change'));
        const form = d.getElementById('assign-form');
        assert.equal(form.dispatchEvent(new dom.window.Event('submit', {cancelable: true})), false);
        assert.equal(d.getElementById('assignment-review').open, true);
        assert.match(d.getElementById('review-summary').textContent, /1 selected/);
        let saves = 0;
        form.requestSubmit = () => { if (form.dispatchEvent(new dom.window.Event('submit', {cancelable: true}))) saves++; };
        d.getElementById('confirm-assignment').click(); d.getElementById('confirm-assignment').click();
        assert.equal(saves, 1);
        assert.equal(d.getElementById('review-assignments').disabled, true);
        globalThis.fetch = async () => ({ok: true, json: async () => ({items: [], more: false})});
        d.getElementById('survey-team').dispatchEvent(new dom.window.Event('change'));
        assert.equal(d.getElementById('selected-hidden').children.length, 0);
        await tick();
    } finally { close(); }
});

test('reassignment dialog only loads eligible candidates and requires a reason and confirmation', async () => {
    const { d, close } = setup();
    try {
        globalThis.fetch = async () => ({ok: true, json: async () => ({users: [{id: 77, name: 'Replacement surveyor'}]})});
        d.querySelector('[data-reassign]').click(); await tick();
        assert.equal(d.getElementById('reassign-dialog').open, true);
        assert.equal(d.getElementById('reassign-user').options[1].value, '77');
        const form = d.getElementById('reassign-form');
        assert.ok(form.elements.assignment_id.value);
        assert.equal(form.elements.reason.required, true);
        assert.equal(form.elements.confirmed.required, true);
        assert.equal(form.elements.confirmed.checked, false);
    } finally { close(); }
});
