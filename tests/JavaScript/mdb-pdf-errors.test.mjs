import test from 'node:test';
import assert from 'node:assert/strict';
import {pdfLoadErrorMessage} from '../../public/js/mdb-pdf-errors.js';

test('PDF failures distinguish viewer startup, authorization, missing files and unavailable storage', () => {
    assert.match(pdfLoadErrorMessage(new TypeError('Failed to fetch dynamically imported module'), 'library'), /viewer could not start/);
    assert.match(pdfLoadErrorMessage(new Error('Setting up fake worker failed')), /module and worker/);
    for (const status of [401, 403]) assert.match(pdfLoadErrorMessage({status}), /access was denied/);
    assert.match(pdfLoadErrorMessage({status:404}), /could not be found/);
    assert.match(pdfLoadErrorMessage({status:503}), /storage is temporarily unavailable/);
    assert.match(pdfLoadErrorMessage({name:'InvalidPDFException'}), /valid PDF/);
    const message = pdfLoadErrorMessage(new Error('private server details'));
    assert.match(message, /Network tab/);
    assert.doesNotMatch(message, /private server details/);
});
