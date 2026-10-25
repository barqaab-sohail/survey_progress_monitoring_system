import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { registeredStartingPole } from '../../public/js/mdb-entry.js';
import { paperHeader } from '../../public/js/mdb-verification-sheet.js';

test('starting-pole display accepts only the selected frozen record and valid named coordinates', () => {
    const transformer = {header: {registered_transformer_id: 77, starting_pole_coordinates: {
        version: 1, source: 'registered_transformer', registered_transformer_id: 77, latitude: 34.1234567, longitude: 73.7654321,
    }}};
    assert.equal(registeredStartingPole(transformer).latitude, 34.1234567);
    assert.equal(registeredStartingPole(transformer).longitude, 73.7654321);
    assert.equal(registeredStartingPole({header: {}}), null);
    for (const invalid of [{latitude: null}, {latitude: ''}, {latitude: 91}, {longitude: -181}, {latitude: Infinity},
        {latitude: 0, longitude: 0}, {registered_transformer_id: 99}, {source: 'first_e_gpx'}]) {
        const copy = structuredClone(transformer);
        Object.assign(copy.header.starting_pole_coordinates, invalid);
        assert.equal(registeredStartingPole(copy), null);
    }
});

test('verification sheet displays both captured axes and retains legacy layout when absent', () => {
    const fields = [{path: 'header.starting_pole_coordinates.latitude', label: 'First S latitude (registered transformer)', value: 34.1234567},
        {path: 'header.starting_pole_coordinates.longitude', label: 'First S longitude (registered transformer)', value: 73.7654321}];
    const html = paperHeader(fields, field => `<span>${field.value}</span>`);
    assert.match(html, /First S latitude.*34\.1234567/);
    assert.match(html, /First S longitude.*73\.7654321/);
    assert.doesNotMatch(paperHeader([], () => ''), /First S latitude|First S longitude/);
});

test('entry shows registered first-S coordinates, preserves later GPX hints, and identifies fallback', async () => {
    const { JSDOM } = await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html = await fs.readFile('tmp/mdb-entry-qa/registered-start.html', 'utf8');
    const dom = new JSDOM(html, {url: 'http://localhost/mdb-workflow/1', runScripts: 'outside-only'});
    try {
        dom.window.eval([...dom.window.document.scripts].find(s => s.textContent.includes('window.mdbEntryBoot')).textContent);
        const boot = dom.window.mdbEntryBoot;
        const stub = path.resolve('tmp/mdb-entry-qa/starting-pole-pdf.mjs');
        await fs.writeFile(stub, `export const GlobalWorkerOptions={};export function getDocument(){return {destroy(){},promise:Promise.resolve({numPages:14,getPage:async()=>({getViewport:()=>({width:842,height:595}),render:()=>({promise:Promise.resolve(),cancel(){}})})})};}`);
        boot.pdfjs_url = pathToFileURL(stub).href;
        dom.window.HTMLCanvasElement.prototype.getContext = () => ({});
        dom.window.Element.prototype.scrollIntoView = () => {};
        Object.assign(globalThis, {window: dom.window, document: dom.window.document, location: dom.window.location,
            history: dom.window.history, Option: dom.window.Option, localStorage: dom.window.localStorage, ResizeObserver: class {observe(){}}});
        let requests = 0;
        globalThis.fetch = async () => { requests++; return {ok: true, json: async () => structuredClone(boot)}; };
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href + '?starting-pole-dom');
        for (let i = 0; i < 18; i++) await new Promise(resolve => setImmediate(resolve));
        const d = dom.window.document, form = d.getElementById('entry-row-form');
        const enter = (name, value) => { const input = form.elements.namedItem(name); input.value = value; input.dispatchEvent(new dom.window.Event('change', {bubbles: true})); };
        assert.match(d.getElementById('entry-transformer-location').textContent, /registered transformer latitude 30\.0009876, longitude 71\.0008765/);
        enter('pair_number', '1'); enter('designation', 'S'); enter('waypoint_reference', '998');
        assert.match(d.getElementById('entry-waypoint-status').textContent, /First S uses registered transformer coordinates/);
        assert.equal(d.getElementById('entry-gpx-ambiguity').hidden, true);
        enter('pair_number', '2'); enter('waypoint_reference', '010');
        assert.doesNotMatch(d.getElementById('entry-waypoint-status').textContent, /registered transformer/);
        assert.match(d.getElementById('entry-waypoint-status').textContent, /Matched to GPX/);
        const active = boot.transformers.find(t => t.id === boot.active_transformer_id);
        active.header.starting_pole_coordinates.source = 'first_e_gpx';
        enter('pair_number', '1'); enter('waypoint_reference', '998');
        assert.match(d.getElementById('entry-waypoint-status').textContent, /First pair only.*first E/);
        assert.equal(requests, 0, 'Viewing hints must not save or refetch coordinates');
    } finally {
        dom.window.close(); delete globalThis.window; delete globalThis.document;
    }
});
