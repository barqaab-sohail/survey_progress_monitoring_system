import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {initializeVerification} from '../../public/js/mdb-verification.js';

test('entry operator can check a draft and the save-review form remains visible', async () => {
    const {JSDOM} = await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html = await fs.readFile('tmp/mdb-entry-qa/operator-review.html', 'utf8');
    const dom = new JSDOM(html, {url: 'http://localhost/mdb-workflow/1/review', runScripts: 'outside-only'});
    const d = dom.window.document;
    dom.window.eval([...d.scripts].find(script => script.textContent.includes('window.mdbVerificationBoot')).textContent);
    const boot = dom.window.mdbVerificationBoot;
    assert.equal(boot.can_correct, true);
    assert.match(boot.header_url, /\/entry-headers\//);
    assert.match(boot.row_url, /\/transformers\/\d+\/rows\/$/);
    Object.assign(globalThis, {window: dom.window, document: d});
    let posted;
    globalThis.fetch = async (url, options) => {
        posted = JSON.parse(options.body);
        return {ok: true, json: async () => ({revision: boot.revision, checked: posted.fields.map(field => field.key)})};
    };
    initializeVerification(boot);
    const confirmation = d.getElementById('operator-review-confirmation');
    assert.ok(confirmation.querySelector('[name="confirmed"]'));
    assert.equal(confirmation.closest('[hidden]'), null);
    assert.equal(d.getElementById('verification-legacy-data').hidden, true);
    const check = d.querySelector('[data-review-key]');
    assert.equal(check.disabled, false);
    check.checked = true;
    check.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
    for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
    assert.equal(posted.checked, true);
    assert.equal(d.querySelector('[data-review-key]').checked, true);
    assert.equal(d.getElementById('verification-edit-header').disabled, false);
    dom.window.close(); delete globalThis.window; delete globalThis.document;
});

test('verifier checks values, collapses the checked header, opens row PDF pages and corrects saved data',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/verifier-workspace.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1/review',runScripts:'outside-only'});
    const d=dom.window.document, $=name=>d.getElementById('verification-'+name);
    dom.window.eval([...d.scripts].find(script=>script.textContent.includes('window.mdbVerificationBoot')).textContent);
    const boot=dom.window.mdbVerificationBoot;
    assert.equal(boot.can_correct,true);
    dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
    dom.window.HTMLDialogElement.prototype.close=function(){this.open=false;};
    Object.assign(globalThis,{window:dom.window,document:d});
    let server=structuredClone(boot), lastCorrection, rejectCorrection=false, checkedPosts=0;
    const settle=async()=>{for(let i=0;i<15;i++)await new Promise(resolve=>setImmediate(resolve));};
    globalThis.fetch=async(url,options)=>{
        if(options.method==='POST'){
            checkedPosts++;const body=JSON.parse(options.body);
            for(const field of body.fields){server.checked=server.checked.filter(key=>key!==field.key);if(body.checked)server.checked.push(field.key);}
        }
        if(options.method==='PUT'){
            lastCorrection=JSON.parse(options.body);
            if(rejectCorrection)return {ok:false,json:async()=>({message:'Correction rejected',errors:{pole_height:['Enter a valid height.']}})};
            server.revision++;
            const row=server.pairs[0].rows.S;
            row.data.pole_height=lastCorrection.pole_height;
            row.fields.find(field=>field.path==='pole_height').value=lastCorrection.pole_height;
            row.fields.forEach(field=>field.hash='changed-height-hash');
            server.checked=server.checked.filter(key=>!key.startsWith('row.'+row.id+'.'));
        }
        return {ok:true,json:async()=>options.method==='POST' ? {revision:server.revision,checked:[...server.checked]} : structuredClone(server)};
    };
    initializeVerification(boot);
    assert.equal($('workspace').hidden,false);assert.equal(d.getElementById('verification-legacy-data').hidden,true);
    assert.equal(d.querySelector('[data-review-layout]').dataset.reviewLayout,'paper');
    assert.equal(d.querySelectorAll('[data-review-layout-button]').length,0);
    assert.equal($('values').querySelectorAll('tr[data-sheet-row]').length,2);
    assert.deepEqual([...$('values').querySelectorAll('tr')].map(row=>row.dataset.designation),['S','E']);
    const renderedKeys=[...$('workspace').querySelectorAll('[data-review-key]')].map(input=>input.dataset.reviewKey);
    assert.equal(renderedKeys.length,boot.total);
    assert.equal(new Set(renderedKeys).size,boot.total);
    assert.equal($('values').querySelector('[data-paper-column="row_date"] .paper-digits').textContent,'081026');
    d.dispatchEvent(new dom.window.CustomEvent('verification:pdf-current',{detail:{url:boot.source_urls[boot.pairs[0].rows.S.data.source_pdf_id],page:2}}));
    assert.equal($('values').querySelectorAll('tr').length,0);
    assert.equal($('empty').hidden,false);
    d.dispatchEvent(new dom.window.CustomEvent('verification:pdf-current',{detail:{url:boot.source_urls[boot.pairs[0].rows.S.data.source_pdf_id],page:1}}));
    assert.equal($('values').querySelectorAll('tr').length,2);
    $('header-values').querySelector('[data-correct-key="header.capacity_kva"]').click();
    assert.equal($('editor').open,true);
    assert.equal(d.activeElement.dataset.editPath,'capacity_kva');
    assert.equal(checkedPosts,0);
    $('cancel-correction').click();
    assert.match(d.querySelector('.paper-values').textContent,/Pole/);assert.match($('row-tools').textContent,/Correct S/);
    const first=$('header-values').querySelector('input');first.checked=true;first.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await settle();
    assert.equal(checkedPosts,1);assert.match($('progress').textContent,/1 \//);
    $('check-header').click();await settle();assert.equal($('header').open,false);
    assert.equal($('header-values').querySelectorAll('input:checked').length,server.header.fields.length);
    let pageEvent;d.addEventListener('verification:pdf-page',event=>pageEvent=event.detail);
    $('row-tools').querySelector('[data-review-page="E"]').click();
    assert.equal(pageEvent.page,server.pairs[0].rows.E.data.source_page);
    $('row-tools').querySelector('[data-check-row="S"]').click();await settle();
    assert.ok(server.checked.some(key=>key.startsWith('row.'+server.pairs[0].rows.S.id+'.')));
    $('values').querySelector(`[data-correct-key="row.${server.pairs[0].rows.S.id}.pole_height"]`).click();assert.equal($('editor').open,true);
    assert.equal(d.activeElement.dataset.editPath,'pole_height');
    assert.equal($('editor-fields').querySelectorAll('.verification-edit-target').length,1);
    $('editor-fields').querySelector('[data-edit-path="pole_height"]').value='37';
    $('correction-reason').value='Correct height from PDF';
    rejectCorrection=true;
    $('edit-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal($('editor').open,true);assert.match($('editor-errors').textContent,/valid height/);
    assert.equal($('editor-fields').querySelector('[data-edit-path="pole_height"]').value,'37');
    rejectCorrection=false;
    $('edit-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal($('editor').open,false);assert.equal(lastCorrection.pole_height,37);
    assert.equal(lastCorrection.correction_reason,'Correct height from PDF');
    assert.deepEqual(lastCorrection.inheritance,{});assert.equal(lastCorrection.composite_identifier,undefined);
    assert.equal(lastCorrection.conductors.Y,'__none__');
    assert.ok(!server.checked.some(key=>key.startsWith('row.'+server.pairs[0].rows.S.id+'.')));
    assert.match($('message').textContent,/Check the updated values again/);
    assert.equal(d.querySelector('input[name="revision"]').value,String(server.revision));
    // Reloading the panel uses saved per-verifier checks, rather than checking every value again.
    initializeVerification({...server,...{can_correct:false,state_url:boot.state_url,source_urls:boot.source_urls}});
    assert.equal($('header').open,false);
    assert.ok([...$('header-values').querySelectorAll('input')].every(input=>input.disabled));
    assert.equal($('header-values').querySelectorAll('[data-correct-key]').length,0);
    dom.window.close();delete globalThis.window;delete globalThis.document;
});
