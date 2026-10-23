import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

test('transformer completion keeps the same PDF for Yes and submits before leaving on No',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/operator-workflow.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
    const d=dom.window.document,$=id=>d.getElementById('entry-'+id);
    dom.window.eval([...d.scripts].find(script=>script.textContent.includes('window.mdbEntryBoot')).textContent);
    const boot=dom.window.mdbEntryBoot;
    boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/readonly-pdf-stub.mjs')).href;
    const server=structuredClone(boot), originalId=server.transformers[0].id;
    const navigations=[],requests=[];let rejectReview=true,rejectSubmission=true;
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({});
    dom.window.Element.prototype.scrollIntoView=()=>{};
    dom.window.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
    dom.window.HTMLDialogElement.prototype.close=function(){this.removeAttribute('open');};
    Object.assign(globalThis,{window:dom.window,document:d,location:{href:dom.window.location.href,assign:url=>navigations.push(url)},history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
    globalThis.fetch=async(url,options)=>{
        requests.push({url,body:options.body?JSON.parse(options.body):null});
        if(url.endsWith('/entry-review')){
            if(rejectReview)return {ok:false,status:422,json:async()=>({message:'Incomplete pair',errors:{rows:['Complete the E row.']}})};
            server.revision++;
            return {ok:true,json:async()=>structuredClone(server)};
        }
        if(url.endsWith('/survey-decision')){
            if(rejectSubmission)return {ok:false,status:422,json:async()=>({message:'Another transformer is incomplete',errors:{rows:['Complete all transformers.']}})};
            return {ok:true,json:async()=>({redirect_url:'/mdb-workflow'})};
        }
        throw Error('Unexpected request '+url);
    };
    const settle=async()=>{for(let i=0;i<18;i++)await new Promise(resolve=>setImmediate(resolve));};
    try{
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?transformer-completion');await settle();
        $('transformer-submit').click();await settle();
        assert.equal($('next-transformer').open,false);
        assert.match($('errors').textContent,/Complete the E row/);
        rejectReview=false;
        $('page-next').click();await settle();
        const source=$('pdf-source').value,page=$('page').value;
        $('transformer-submit').click();await settle();
        assert.equal($('next-transformer').open,true);
        assert.equal(requests.at(-1).body.transformer_id,originalId);
        $('next-transformer-yes').click();await settle();
        assert.equal($('next-transformer').open,false);
        assert.equal($('pdf-source').value,source);assert.equal($('page').value,page);
        assert.equal($('transformer').value,'');
        assert.equal(d.querySelector('[name="code"]').value,'');
        assert.equal(d.querySelector('[name="designation"]').value,'S');
        assert.equal(d.querySelector('[name="waypoint_reference"]').value,'');
        assert.equal($('header-pdf').value,source);assert.equal($('header-pdf-page').value,page);
        assert.equal(navigations.length,0);
        assert.equal(requests.some(request=>request.url.endsWith('/survey-decision')),false);
        $('transformer').value=originalId;$('transformer').dispatchEvent(new dom.window.Event('change'));
        $('transformer-submit').click();await settle();
        $('next-transformer-no').click();await settle();
        assert.equal(navigations.length,0);assert.match($('errors').textContent,/Complete all transformers/);
        rejectSubmission=false;
        $('transformer-submit').click();await settle();
        $('next-transformer-no').click();await settle();
        assert.deepEqual(navigations,['/mdb-workflow']);
        assert.equal(requests.at(-1).body.revision,server.revision);
        assert.equal(requests.at(-1).body.action,'submit');
    }finally{
        dom.window.close();
        for(const name of ['window','document','location','history','Option','localStorage','ResizeObserver','fetch'])delete globalThis[name];
    }
});

test('empty PDF entry cannot submit until a transformer header is saved',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/empty-operator-workflow.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
    const d=dom.window.document,$=id=>d.getElementById('entry-'+id);
    dom.window.eval([...d.scripts].find(script=>script.textContent.includes('window.mdbEntryBoot')).textContent);
    const boot=dom.window.mdbEntryBoot;
    boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/readonly-pdf-stub.mjs')).href;
    const server=structuredClone(boot),requests=[];
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({});
    dom.window.Element.prototype.scrollIntoView=()=>{};
    Object.assign(globalThis,{window:dom.window,document:d,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
    globalThis.fetch=async(url,options)=>{
        requests.push({url,method:options.method});
        if(options.method==='POST'){
            assert.ok(url.endsWith('/entry-headers'));
            const body=JSON.parse(options.body),registered=server.registered_transformers.find(transformer=>transformer.id===body.registered_transformer_id);
            server.transformers.push({id:50,code:registered.code,capacity_kva:body.capacity_kva,header:body.header,rows:[]});
            server.active_transformer_id=50;server.revision++;
        }else assert.ok(url.endsWith('/entry-data'));
        return {ok:true,json:async()=>structuredClone(server)};
    };
    const settle=async()=>{for(let i=0;i<18;i++)await new Promise(resolve=>setImmediate(resolve));};
    try{
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?empty-transformer-completion');await settle();
        assert.equal($('transformer-submit').disabled,true);
        const submit=new dom.window.Event('submit',{bubbles:true,cancelable:true});
        $('submit-form').dispatchEvent(submit);
        assert.equal(submit.defaultPrevented,true);
        assert.match($('errors').textContent,/Save at least one transformer/);
        assert.equal(requests.length,0);
        $('refresh').click();await settle();assert.equal($('transformer-submit').disabled,true);
        $('transformer').value=`registered:${server.registered_transformers[0].id}`;
        $('transformer').dispatchEvent(new dom.window.Event('change'));
        assert.equal($('transformer-submit').disabled,true);
        const form=$('header-form');
        for(const [name,value] of Object.entries({'header[inspector]':'Surveyor','header[location]':'Survey location','header[service_category]':'General Duty'}))form.elements.namedItem(name).value=value;
        $('header-pdf').value=String(server.sources.find(source=>source.kind==='pdf').id);
        $('header-pdf-page').value='1';
        form.dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
        assert.equal(server.transformers.length,1);
        assert.equal($('transformer-submit').disabled,false);
        assert.equal(requests.filter(request=>request.method==='POST').length,1);
    }finally{
        dom.window.close();
        for(const name of ['window','document','location','history','Option','localStorage','ResizeObserver','fetch'])delete globalThis[name];
    }
});
