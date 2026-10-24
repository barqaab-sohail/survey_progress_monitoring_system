import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeReviewPdf} from '../../public/js/mdb-review-pdf.js';

test('verification reveals the SVG overlay and draws horizontal red guides', async () => {
    class Element extends EventTarget {
        constructor() { super(); this.attributes = new Set(); this.style = {}; this.value = ''; this.innerHTML = ''; }
        toggleAttribute(name, force) { force ? this.attributes.add(name) : this.attributes.delete(name); }
        setAttribute(name) { this.attributes.add(name); }
        getContext() { return {}; }
        getBoundingClientRect() { return {left:0, top:0, width:1000, height:750}; }
        querySelectorAll() { return []; }
        querySelector() { return null; }
    }
    const elements = new Map();
    const root = new Element();
    root.getElementById = id => {
        if (!elements.has(id)) elements.set(id, new Element());
        return elements.get(id);
    };
    const $ = name => root.getElementById('review-pdf-' + name);
    $('source').value = '/source.pdf';
    $('lines').attributes.add('hidden');
    $('scroll').clientWidth = 1000;
    const previousWindow = globalThis.window, previousObserver = globalThis.ResizeObserver;
    globalThis.window = {localStorage:{getItem:()=>null, setItem(){}}, CustomEvent:class extends Event {}};
    globalThis.ResizeObserver = class { observe() {} };
    const stub = `export const GlobalWorkerOptions = {};
        export const getDocument = () => ({promise:Promise.resolve({numPages:1,
            getPage:async()=>({getViewport:({scale})=>({width:800*scale,height:600*scale}),
                render:()=>({promise:Promise.resolve(),cancel(){}})})})});`;
    try {
        initializeReviewPdf({pdfjs_url:'data:text/javascript,' + encodeURIComponent(stub)}, root);
        assert.equal($('lines').attributes.has('hidden'), true);
        for (let i = 0; i < 15; i++) await new Promise(resolve => setImmediate(resolve));
        assert.equal($('draw').disabled, false);
        assert.equal($('lines').attributes.has('hidden'), false, 'SVG hidden attribute must be removed after rendering');
        $('draw').dispatchEvent(new Event('click'));
        const pointer = (type, x, y) => {
            const event = new Event(type, {cancelable:true});
            Object.assign(event, {clientX:x, clientY:y, button:0});
            $('stage').dispatchEvent(event);
        };
        pointer('pointerdown', 100, 300);
        pointer('pointerup', 900, 350);
        assert.match($('lines').innerHTML, /x1="0.1" y1="0.4" x2="0.9" y2="0.4"/);
        assert.match($('lines').innerHTML, /stroke="#dc2626"/);
        $('undo').dispatchEvent(new Event('click'));
        assert.equal($('lines').innerHTML, '');
    } finally {
        globalThis.window = previousWindow;
        globalThis.ResizeObserver = previousObserver;
    }
});
