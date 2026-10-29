import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

test('individual transformer deletion confirms identity and refreshes list, selection, progress and recovery draft',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/operator-workflow.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
    const d=dom.window.document,$=id=>d.getElementById('entry-'+id);
    dom.window.eval([...d.scripts].find(script=>script.textContent.includes('window.mdbEntryBoot')).textContent);
    const boot=dom.window.mdbEntryBoot;
    boot.can_delete_transformer=true;
    boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/readonly-pdf-stub.mjs')).href;
    const original=boot.transformers[0];
    boot.transformers=[original,{...structuredClone(original),id:999,code:'KEEP-TX',rows:[]}];
    boot.pdf_rows=[{id:101,source_file_id:boot.sources.find(s=>s.kind==='pdf').id,page:1,row_number:1,
        bounds:{x:0,y:0.1,width:1,height:0.1},entry_row_id:original.rows[0]?.id ?? null,entered:true}];
    const panel=d.createElement('div');
    panel.innerHTML='<span id="entry-transformer-count"></span><div id="entry-transformer-list"></div><strong id="entry-batch-status"></strong>';
    d.querySelector('.entry-transformer').prepend(panel);
    const server=structuredClone(boot),requests=[],prompts=[];
    let accepted=false,reject=true;
    dom.window.confirm=message=>{prompts.push(message);return accepted;};
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({});
    dom.window.Element.prototype.scrollIntoView=()=>{};
    Object.assign(globalThis,{window:dom.window,document:d,location:dom.window.location,history:dom.window.history,
        Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
    globalThis.fetch=async(url,options)=>{
        const body=JSON.parse(options.body);
        requests.push({url,method:options.method,body});
        assert.equal(options.method,'DELETE');
        assert.equal(body.confirmed,true);
        assert.equal(body.revision,server.revision);
        if(reject)return {ok:false,status:422,json:async()=>({message:'Another save changed this batch.',errors:{revision:['Refresh current records.']}})};
        const id=Number(url.split('/').at(-1));
        server.transformers=server.transformers.filter(t=>t.id!==id);
        server.pdf_rows[0].entry_row_id=null;server.pdf_rows[0].entered=false;
        server.revision++;server.workflow_label='Draft';
        return {ok:true,json:async()=>structuredClone(server)};
    };
    const settle=async()=>{for(let i=0;i<20;i++)await new Promise(resolve=>setImmediate(resolve));};
    try{
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?individual-transformer-deletion');await settle();
        assert.equal($('transformer-count').textContent,'2');
        const remove=()=>d.querySelector(`[data-entry-delete-transformer="${original.id}"]`);
        remove().click();await settle();
        assert.equal(requests.length,0);
        assert.match(prompts[0],new RegExp(original.code));
        assert.ok(prompts[0].includes(`Batch #${boot.batch_id}`));
        accepted=true;remove().click();await settle();
        assert.equal($('transformer-count').textContent,'2');
        assert.match($('errors').textContent,/Refresh current records/);
        reject=false;remove().click();await settle();
        assert.equal(remove(),null);
        assert.equal($('transformer-count').textContent,'1');
        assert.equal($('transformer').value,'999');
        assert.equal($('batch-status').textContent,'Draft');
        assert.equal(JSON.parse(localStorage.getItem(boot.storage_key)).activeId,999);
        assert.match($('pdf-progress').textContent,/0\/1 entered/);
        d.querySelector('[data-entry-delete-transformer="999"]').click();await settle();
        assert.equal($('transformer-count').textContent,'0');
        assert.match($('transformer-list').textContent,/No transformers in this batch/);
        assert.equal($('transformer').value,'');
        assert.equal(JSON.parse(localStorage.getItem(boot.storage_key)).activeId,null);
        assert.equal($('transformer-submit').disabled,true);
    }finally{
        dom.window.close();
        for(const key of ['window','document','location','history','Option','localStorage','ResizeObserver','fetch'])delete globalThis[key];
    }
});
