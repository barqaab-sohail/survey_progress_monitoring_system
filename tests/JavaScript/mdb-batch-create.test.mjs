import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {JSDOM} from '../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js';

test('Drive links, PDF previews and switching back to local uploads stay consistent', async () => {
    const dom = new JSDOM(`<form data-batch-create data-drive-preview="/preview">
        <input name="_token" value="csrf"><p data-create-status></p>
        <iframe data-create-pdf hidden></iframe><a data-preview-open hidden></a><p data-preview-placeholder></p>
        <div data-source-field="survey_pdf"><input type="file" data-source-local required>
        <input data-drive-link><input data-drive-id><button type="button" data-preview-drive>Preview</button></div>
        <div data-source-field="gps_gpx"><input type="file" data-source-local required>
        <input data-drive-link><input data-drive-id></div></form>`, {runScripts: 'outside-only'});
    const {window} = dom;
    const doc = window.document;
    const calls = [], revoked = [];
    window.URL.createObjectURL = () => 'blob:test-pdf';
    window.URL.revokeObjectURL = url => revoked.push(url);
    window.fetch = async (url, options) => {
        calls.push({url, options});
        return {ok: true, blob: async () => new window.Blob(['%PDF-1.4'], {type: 'application/pdf'})};
    };
    window.eval(await fs.readFile('public/js/mdb-batch-create.js', 'utf8'));
    const group = doc.querySelector('[data-source-field="survey_pdf"]');
    const link = group.querySelector('[data-drive-link]');
    const local = group.querySelector('[data-source-local]');
    const id = group.querySelector('[data-drive-id]');
    const input = value => { link.value = value; link.dispatchEvent(new window.Event('input')); };
    input('https://drive.google.com/file/d/pdf-file-12345/view?usp=sharing');
    assert.equal(id.value, 'pdf-file-12345');
    assert.equal(local.required, false);
    group.querySelector('button').click();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(doc.querySelector('iframe').hidden, false);
    assert.equal(JSON.parse(calls[0].options.body).drive_file_id, 'pdf-file-12345');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    input('https://evil.example/file/d/pdf-file-12345/view');
    assert.equal(id.value, '');
    assert.equal(link.checkValidity(), false);
    assert.equal(local.required, true);
    assert.equal(doc.querySelector('iframe').hidden, true);
    assert.deepEqual(revoked, ['blob:test-pdf']);
    input('https://drive.google.com/open?id=pdf-file-12345');
    assert.equal(id.value, 'pdf-file-12345');
    Object.defineProperty(local, 'files', {value: [new window.File(['%PDF-1.4'], 'local.pdf')], configurable: true});
    local.dispatchEvent(new window.Event('change'));
    assert.equal(link.value, '');
    assert.equal(id.value, '');
    assert.equal(link.checkValidity(), true);
    assert.equal(doc.querySelector('iframe').hidden, false);
    dom.window.close();
});
