import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from '../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js';
import {installEntryCorrections} from '../../public/js/mdb-entry-corrections.js';

const settle = async () => {for (let i=0;i<8;i++) await new Promise(resolve=>setImmediate(resolve));};
function fixture({initial = [], savedDraft = null, fail = false, unconfirmed = false} = {}) {
    const dom = new JSDOM(`<input id="outside-draft" value="Unsaved pole data"><details id="entry-corrections" data-admin="1"><summary>PDF corrections / comments</summary>
        <span id="entry-correction-count"></span><details id="entry-correction-editor"><form id="entry-correction-form">
        <input name="transformer_id"><input name="transformer_reference"><select name="source_file_id"><option value="3">Survey.pdf</option></select>
        <input name="source_page"><input name="source_row"><textarea name="comment"></textarea><input type="file" name="screenshot"><p id="entry-correction-screenshot-help"></p><img id="entry-correction-preview" hidden><button type="button" id="entry-correction-remove-screenshot" hidden>Remove screenshot</button><button type="submit" value="next">Save & next</button></form></details>
        <p id="entry-correction-status"></p><details id="entry-correction-history"><div id="entry-correction-list"></div></details><button id="entry-correction-refresh"></button></details>`, {url:'http://localhost'});
    const previousFormData = globalThis.FormData;
    Object.assign(globalThis, {window:dom.window, document:dom.window.document, localStorage:dom.window.localStorage, FormData:dom.window.FormData});
    if (savedDraft) localStorage.setItem('correction-draft', JSON.stringify(savedDraft));
    let busy = false, next = 0, shouldFail = fail, calls = [], notes = initial;
    installEntryCorrections({initial, storageKey:'correction-draft', isBusy:()=>busy, setBusy:value=>{busy=value;},
        context:()=>({transformer_id:12, transformer_reference:'T-12', source_file_id:3, source_page:4}),
        chooseNext:()=>{next++;}, api:async(path, method, body)=>{
            calls.push({path,method,body});
            if (shouldFail) throw new Error('Connection unavailable');
            const payload = body instanceof dom.window.FormData ? Object.fromEntries(body.entries()) : body;
            if (method === 'POST' && !unconfirmed) notes=[{...payload,id:1,status:'open',pdf_name:'Survey.pdf',pdf_version:1,reporter_name:'Operator One',created_at:'2026-11-21T08:00:00Z'}];
            if (method === 'PUT') notes=notes.map(note=>note.id === Number(path.split('/').at(-2)) ? {...note,status:'resolved',resolution:body.resolution,resolver_name:'Admin'} : note);
            return {corrections:notes};
        }});
    const form=document.getElementById('entry-correction-form'), field=name=>form.elements.namedItem(name);
    return {dom,form,field,calls,next:()=>next,busy:()=>busy,retry:()=>{shouldFail=false;},
        async open(){document.getElementById('entry-correction-editor').open=true;await settle();},
        submit(next=false){form.dispatchEvent(new dom.window.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:next ? form.querySelector('button[type=submit]') : null}));},
        close(){dom.window.close();delete globalThis.window;delete globalThis.document;delete globalThis.localStorage;globalThis.FormData=previousFormData;}};
}

test('save and choose next confirms the comment without changing the pole draft', async()=>{
    const f=fixture();
    try {
        await f.open();
        assert.equal(f.field('transformer_reference').value,'T-12');
        f.field('comment').value='The PDF has an unreadable waypoint.';
        f.field('comment').dispatchEvent(new f.dom.window.Event('input',{bubbles:true}));
        f.submit(true);await settle();
        assert.equal(f.next(),1);
        assert.equal(document.getElementById('entry-corrections').open,false);
        assert.equal(f.calls[0].body.source_page,4);
        assert.equal(document.getElementById('outside-draft').value,'Unsaved pole data');
        assert.match(document.getElementById('entry-correction-list').textContent,/unreadable waypoint/);
        assert.match(document.getElementById('entry-correction-count').textContent,/1 open/);
        assert.equal(f.field('comment').value,'');
        assert.equal(localStorage.getItem('correction-draft'),null);
        assert.equal(f.busy(),false);
    } finally {f.close();}
});

test('failed saves retain the comment and retry identity until confirmed', async()=>{
    const f=fixture({fail:true});
    try {
        await f.open();f.field('comment').value='Missing conductor information.';
        f.submit(true);await settle();
        assert.equal(f.next(),0);
        assert.equal(f.field('comment').value,'Missing conductor information.');
        const uuid=f.calls[0].body.client_uuid;
        assert.equal(JSON.parse(localStorage.getItem('correction-draft')).clientUuid,uuid);
        f.retry();f.submit(true);await settle();
        assert.equal(f.calls[1].body.client_uuid,uuid);
        assert.equal(f.next(),1);
    } finally {f.close();}
});

test('unconfirmed save does not clear a comment or move to another transformer', async()=>{
    const f=fixture({unconfirmed:true});
    try {
        await f.open();f.field('comment').value='Missing PDF row.';
        f.submit(true);await settle();
        assert.equal(f.next(),0);
        assert.equal(f.field('comment').value,'Missing PDF row.');
        assert.match(document.getElementById('entry-correction-status').textContent,/did not confirm/);
    } finally {f.close();}
});

test('a recovered comment keeps its original transformer and page', async()=>{
    const f=fixture({savedDraft:{values:{transformer_id:'8',transformer_reference:'T-8',source_file_id:'3',source_page:'9',comment:'Recover this correction.'},clientUuid:crypto.randomUUID()}});
    try {
        await settle();
        assert.equal(f.field('transformer_reference').value,'T-8');
        assert.equal(f.field('source_page').value,'9');
        assert.equal(f.field('comment').value,'Recover this correction.');
    } finally {f.close();}
});

test('comment rendering escapes HTML and resolving preserves other resolution drafts', async()=>{
    const base={status:'open',pdf_name:'Survey.pdf',pdf_version:1,source_page:4,transformer_reference:'T-12',reporter_name:'Operator One',created_at:'2026-11-21T08:00:00Z'};
    const f=fixture({initial:[{...base,id:1,comment:'<img src=x onerror=alert(1)>'},{...base,id:2,comment:'Another missing waypoint.'}]});
    try {
        assert.equal(document.querySelector('#entry-correction-list img'),null);
        const forms=document.querySelectorAll('[data-resolve-correction]');
        forms[0].elements.namedItem('resolution').value='The PDF has now been corrected.';
        forms[1].elements.namedItem('resolution').value='Keep this unsaved resolution.';
        forms[0].dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
        assert.equal(f.calls[0].method,'PUT');
        assert.match(document.getElementById('entry-correction-list').textContent,/Resolution:/);
        assert.equal(document.querySelector('[data-resolve-correction] [name=resolution]').value,'Keep this unsaved resolution.');
        assert.equal(document.getElementById('outside-draft').value,'Unsaved pole data');
    } finally {f.close();}
});


test('screenshot upload uses multipart data and retains the selected file after failure', async()=>{
    const f=fixture({fail:true});
    try {
        await f.open();f.field('comment').value='See the attached unreadable PDF row.';
        const screenshot=new f.dom.window.File(['image-bytes'],'error.png',{type:'image/png'});
        Object.defineProperty(f.field('screenshot'),'files',{value:[screenshot],configurable:true});
        f.field('screenshot').dispatchEvent(new f.dom.window.Event('change',{bubbles:true}));
        f.submit();await settle();
        assert.ok(f.calls[0].body instanceof f.dom.window.FormData);
        assert.equal(f.calls[0].body.get('screenshot').name,'error.png');
        assert.equal(f.calls[0].body.get('comment'),'See the attached unreadable PDF row.');
        assert.equal(f.field('screenshot').files[0],screenshot);
        assert.equal(JSON.parse(localStorage.getItem('correction-draft')).screenshotName,'error.png');
        f.retry();f.submit();await settle();
        assert.equal(f.field('comment').value,'');
        assert.equal(f.field('screenshot').value,'');
    } finally {f.close();}
});

test('recovered screenshot drafts request reattachment and cannot silently save without the image', async()=>{
    const f=fixture({savedDraft:{values:{transformer_id:'8',transformer_reference:'T-8',source_file_id:'3',source_page:'9',comment:'See attached image.'},clientUuid:crypto.randomUUID(),screenshotName:'error.png'}});
    try {
        await settle();
        assert.equal(document.getElementById('entry-corrections').open,false);
        assert.match(document.getElementById('entry-correction-screenshot-help').textContent,/reattach error.png/);
        f.submit();await settle();assert.equal(f.calls.length,0);
        document.getElementById('entry-correction-remove-screenshot').click();
        f.submit();await settle();assert.equal(f.calls.length,1);
    } finally {f.close();}
});
