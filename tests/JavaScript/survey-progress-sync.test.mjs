// HTML comes from an isolated SQLite test; every request is mocked.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { JSDOM } from '../../tmp/assignment-qa/node_modules/jsdom/lib/api.js';

const source = await fs.readFile('public/js/survey-progress-sync.js', 'utf8');
const {initializeSync, syncMessage} = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const html = await fs.readFile('tmp/assignment-qa/survey-sync.html', 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 5));
const run = (status, metrics = {}) => ({id: 41, type: 'sync', status, active: ['queued','running'].includes(status), metrics, issues: []});

function setup(onComplete = () => {}) {
    const dom = new JSDOM(html, {url:'https://survey-sync.test/survey-progress'}), d = dom.window.document;
    globalThis.window = dom.window; globalThis.FormData = dom.window.FormData;
    const state = JSON.parse(d.getElementById('survey-sync-boot').textContent);
    state.run = null;
    state.health.runner_recent = true;
    d.getElementById('survey-sync-boot').textContent = JSON.stringify(state);
    const control = initializeSync(d, onComplete);
    return {d, dom, state, control, submit: () => d.querySelector('[data-survey-sync-form]').dispatchEvent(new dom.window.Event('submit', {cancelable:true})),
        close: () => {control.stop(); dom.window.close();}};
}

test('manual request immediately shows loading and blocks repeated clicks before the response', async () => {
    const {d, state, submit, close} = setup();
    let resolve, calls = 0;
    globalThis.fetch = () => {calls++; return new Promise(done => {resolve = done;});};
    try {
        submit(); submit();
        assert.equal(calls, 1);
        assert.equal(d.querySelector('[data-sync-button]').disabled, true);
        assert.equal(d.querySelector('[data-sync-spinner]').hidden, false);
        assert.match(d.querySelector('[data-sync-message]').textContent, /Requesting/);
        assert.equal(d.querySelector('[data-survey-sync]').getAttribute('aria-busy'), 'true');
        resolve({ok:true, json:async () => ({...state, run:run('queued')})}); await tick();
        assert.match(d.querySelector('[data-sync-message]').textContent, /queued/);
        assert.equal(d.querySelector('[data-sync-progress]').hasAttribute('value'), false);
    } finally {close();}
});

test('live polling displays counts, failures as safe text, last successful time and a terminal result', async () => {
    let completions = 0;
    const {d, state, control, submit, close} = setup(() => completions++);
    const statuses = [run('queued'), {...run('running', {files_scanned:20, reported_transformers:50, feeders_updated:2, folders_total:4, folders_processed:2}),
        phase:'Scanning 407-NAWAN', issues:[{code:'listing_failed', message:'<img src=x onerror=alert(1)>'}]} , run('completed', {files_scanned:40, folders_processed:4, folders_total:4})];
    const requests = [];
    globalThis.fetch = async (url, options) => {requests.push({url:String(url), options}); return {ok:true, json:async () => ({...state, last_successful_sync:'2026-10-25T07:00:00+00:00', run:statuses.shift()})};};
    try {
        submit(); await tick(); await control.poll();
        assert.equal(d.querySelector('[data-sync-metric="files_scanned"]').textContent, '20');
        assert.equal(d.querySelector('[data-sync-metric="reported_transformers"]').textContent, '50');
        assert.equal(d.querySelector('[data-sync-metric="feeders_updated"]').textContent, '2');
        assert.equal(d.querySelector('[data-sync-progress]').value, 50);
        assert.match(d.querySelector('[data-sync-message]').textContent, /Scanning 407/);
        assert.equal(d.querySelector('[data-sync-issues] img'), null);
        assert.match(d.querySelector('[data-sync-issues]').textContent, /<img/);
        assert.match(d.querySelector('[data-sync-last-success]').textContent, /12:00:00/);
        assert.match(requests[1].url, /run_id=41/);
        assert.equal(requests[0].options.body.has('_token'), true);
        await control.poll();
        assert.equal(d.querySelector('[data-sync-spinner]').hidden, true);
        assert.equal(d.querySelector('[data-sync-button]').disabled, false);
        assert.equal(d.querySelector('[data-sync-progress]').value, 100);
        assert.match(d.querySelector('[data-sync-message]').textContent, /completed successfully/);
        await new Promise(resolve => setTimeout(resolve, 1250));
        assert.equal(completions, 1);
    } finally {close();}
});

test('validation and session errors are visible and release the Sync button', async () => {
    const {d, submit, close} = setup();
    globalThis.fetch = async () => ({ok:false, json:async () => ({message:'Could not queue synchronization.', errors:{sync:['Check the queue configuration.']}})});
    try {
        submit(); await tick();
        assert.match(d.querySelector('[data-sync-message]').textContent, /Check the queue configuration/);
        assert.equal(d.querySelector('[data-sync-button]').disabled, false);
        globalThis.fetch = async () => ({ok:false, json:async () => {throw new Error('HTML login page');}});
        submit(); await tick();
        assert.match(d.querySelector('[data-sync-message]').textContent, /session may have expired/);
        assert.equal(d.querySelector('[data-sync-spinner]').hidden, true);
    } finally {close();}
});

test('a polling failure leaves the existing task active and page exit stops polling', async () => {
    const {d, state, submit, control, dom, close} = setup();
    globalThis.fetch = async () => ({ok:true, json:async () => ({...state, run:run('queued')})});
    try {
        submit(); await tick();
        globalThis.fetch = async () => ({ok:false});
        await control.poll();
        assert.match(d.querySelector('[data-sync-message]').textContent, /task may still be running/);
        assert.equal(d.querySelector('[data-sync-button]').disabled, true);
        dom.window.dispatchEvent(new dom.window.Event('pagehide'));
        globalThis.fetch = () => {throw new Error('Should not poll after leaving the page');};
        await control.poll();
    } finally {close();}
});

test('failed and partial results clearly describe retained updates and required review', () => {
    assert.match(syncMessage({...run('failed'), error:'Reconnect Drive.'}), /Reconnect Drive/);
    assert.match(syncMessage(run('partial')), /successful feeder updates were saved/);
});
