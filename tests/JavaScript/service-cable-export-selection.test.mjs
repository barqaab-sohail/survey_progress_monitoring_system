import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from '../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js';

const script = readFileSync(new URL('../../public/js/service-cable-export-selection.js', import.meta.url), 'utf8');
function fixture(keys = ['TR-001', 'TR-002', 'TR-003']) {
    const dom = new JSDOM(`<form id="service-cable-export-selection"><input type="checkbox" id="cable-export-select-all">
        ${keys.map(key => `<input type="checkbox" name="transformers[]" value="${key}">`).join('')}
        <span id="cable-export-selection-count"></span><button id="cable-export-download">Download</button></form>`, {runScripts:'outside-only'});
    dom.window.eval(script);
    return dom;
}

test('checkbox selection submits only checked transformers and select-all tracks mixed state', () => {
    const dom = fixture();
    try {
        const doc = dom.window.document;
        const form = doc.querySelector('form');
        const inputs = [...form.querySelectorAll('[name="transformers[]"]')];
        const all = doc.getElementById('cable-export-select-all');
        const button = doc.getElementById('cable-export-download');
        assert.equal(button.disabled, true);
        inputs[1].click();
        assert.equal(button.disabled, false);
        assert.equal(all.indeterminate, true);
        assert.equal(doc.getElementById('cable-export-selection-count').textContent, '1 transformer selected');
        assert.deepEqual(new dom.window.FormData(form).getAll('transformers[]'), ['TR-002']);
        all.click();
        assert.equal(all.checked, true);
        assert.equal(all.indeterminate, false);
        assert.deepEqual(new dom.window.FormData(form).getAll('transformers[]'), ['TR-001', 'TR-002', 'TR-003']);
        all.click();
        assert.equal(button.disabled, true);
        assert.deepEqual(new dom.window.FormData(form).getAll('transformers[]'), []);
        const submit = new dom.window.Event('submit', {cancelable:true});
        form.dispatchEvent(submit);
        assert.equal(submit.defaultPrevented, true);
    } finally {dom.window.close();}
});

test('an empty feeder cannot submit an export', () => {
    const dom = fixture([]);
    try {
        assert.equal(dom.window.document.getElementById('cable-export-download').disabled, true);
    } finally {dom.window.close();}
});
