import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import path from 'node:path';
import {derivePhase, consumerTotal, nextDraft, completeWaypoint, parseWaypoint, displayDate, waypointMatches, convertPoleHeight, rotatedRowBounds, nextPendingPdfRow, pdfPageProgress, rowValidationErrors} from '../../public/js/mdb-entry.js';

test('row validation identifies invalid dates, identifiers and counts while allowing blank drafts',()=>{
    const empty=nextDraft();empty.identity_version=2;assert.deepEqual(rowValidationErrors(empty),{});
    const errors=rowValidationErrors({...empty,group_number:'1',waypoint_reference:'12',row_date:'31/02/2026',pole_height:201,consumers:{rs:-1,rl:1.5},pv_details:[{service_load_kw:-2,installed_capacity_kw:null}]},2000);
    for(const key of ['group_number','waypoint_reference','row_date','pole_height','consumers.rs','consumers.rl','pv_details.0.service_load_kw'])assert.ok(errors[key]);
    assert.match(errors['consumers.rl'][0],/whole number/);
    assert.deepEqual(rowValidationErrors({...empty,group_number:'01',waypoint_reference:'001',row_date:'29/02/2024',pole_height:31,consumers:{rs:0}},2000),{});
    assert.match(rowValidationErrors({...empty,row_date:'08/10/1999'},2000).row_date[0],/year window/);
});

test('readonly workflow PDF navigates and refreshes without replacing an operator recovery draft',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile(new URL('../../tmp/mdb-entry-qa/readonly.html',import.meta.url),'utf8');
    const stubPath=path.resolve('tmp/mdb-entry-qa/readonly-pdf-stub.mjs');
    await fs.writeFile(stubPath,`export const GlobalWorkerOptions={};export function getDocument(){return {destroy:async()=>{},promise:Promise.resolve({numPages:14,getPage:async()=>({getViewport:()=>({width:842,height:595}),render:()=>({promise:Promise.resolve(),cancel(){}})})})};}`);
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
    dom.window.eval([...dom.window.document.scripts].find(script=>script.textContent.includes('window.mdbEntryBoot')).textContent);
    const boot=dom.window.mdbEntryBoot;boot.pdfjs_url=pathToFileURL(stubPath).href;
    const recovery=JSON.stringify({activeId:boot.transformers[0].id,headerDirty:true,header:{header:{location:'Unsaved operator draft'}}});
    dom.window.localStorage.setItem(boot.storage_key,recovery);
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};
    Object.assign(globalThis,{window:dom.window,document:dom.window.document,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
    let gets=0;
    globalThis.fetch=async(url,options)=>{assert.equal(options.method,'GET');gets++;return {ok:true,json:async()=>structuredClone(boot)};};
    const settle=async()=>{for(let i=0;i<18;i++)await new Promise(resolve=>setImmediate(resolve));};
    await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?readonly-workflow');await settle();
    const d=dom.window.document;
    assert.equal(d.getElementById('entry-header-form'),null);assert.equal(d.getElementById('entry-row-form'),null);
    assert.doesNotMatch(d.getElementById('entry-readonly-header').textContent,/Unsaved operator draft/);
    d.getElementById('entry-page-next').click();await settle();assert.equal(d.getElementById('entry-page').value,'2');
    d.getElementById('entry-refresh').click();await settle();assert.equal(gets,1);
    assert.equal(d.getElementById('entry-errors').hidden,true);assert.equal(d.getElementById('entry-pdf-canvas').hidden,false);
    assert.equal(dom.window.localStorage.getItem(boot.storage_key),recovery);
    d.dispatchEvent(new dom.window.KeyboardEvent('keydown',{ctrlKey:true,key:'Enter',bubbles:true}));await settle();assert.equal(gets,1);
    dom.window.close();delete globalThis.window;delete globalThis.document;
});

test('workflow submission requires saved drafts and refreshing a submitted survey disables editing',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile(new URL('../../tmp/mdb-entry-qa/operator-workflow.html',import.meta.url),'utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
    dom.window.eval([...dom.window.document.scripts].find(script=>script.textContent.includes('window.mdbEntryBoot')).textContent);
    const boot=dom.window.mdbEntryBoot;boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/readonly-pdf-stub.mjs')).href;
    const server=structuredClone(boot);let posts=0;
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};dom.window.confirm=()=>true;
    Object.assign(globalThis,{window:dom.window,document:dom.window.document,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
    globalThis.fetch=async(url,options)=>{
        if(options.method!=='GET'){posts++;const body=JSON.parse(options.body);Object.assign(server.transformers[0],body);server.active_transformer_id=server.transformers[0].id;server.revision++;}
        return {ok:true,json:async()=>structuredClone(server)};
    };
    const settle=async()=>{for(let i=0;i<18;i++)await new Promise(resolve=>setImmediate(resolve));};
    await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?submission-workflow');await settle();
    const d=dom.window.document,submission=d.getElementById('entry-submit-form');
    const input=d.querySelector('[name="header[location]"]');input.value='Draft correction';input.dispatchEvent(new dom.window.Event('input',{bubbles:true}));
    const draftSubmit=new dom.window.Event('submit',{bubbles:true,cancelable:true});submission.dispatchEvent(draftSubmit);
    assert.equal(draftSubmit.defaultPrevented,true);assert.match(d.getElementById('entry-errors').textContent,/Save the header and all row changes/);assert.equal(posts,0);
    d.getElementById('entry-header-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();assert.equal(posts,1);
    const savedSubmit=new dom.window.Event('submit',{bubbles:true,cancelable:true});submission.dispatchEvent(savedSubmit);assert.equal(savedSubmit.defaultPrevented,false);
    assert.equal(submission.querySelector('[name="revision"]').value,String(server.revision));
    server.survey_status='awaiting_verification';server.status='awaiting_verification';server.revision++;
    d.getElementById('entry-refresh').click();await settle();assert.equal(input.disabled,true);assert.equal(submission.querySelector('button').disabled,true);
    d.getElementById('entry-header-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();assert.equal(posts,1);
    dom.window.close();delete globalThis.window;delete globalThis.document;
});

test('entered area grows through consecutive rows and stops at gaps or undone markers',()=>{
    const rows=Array.from({length:4},(_,index)=>({row_number:index+1,entered:false,bounds:{x:.1,y:.2+index*.1,width:.8,height:.1}}));
    const empty=pdfPageProgress([]);assert.equal(empty.area,null);assert.equal(empty.enteredArea,null);assert.equal(empty.remaining,0);
    rows[0].entered=true;
    let progress=pdfPageProgress([...rows].reverse());assert.equal(progress.enteredThrough,1);assert.equal(progress.remaining,3);
    assert.ok(Math.abs(progress.enteredArea.height-.1)<1e-10);
    rows[1].entered=true;progress=pdfPageProgress(rows);assert.equal(progress.enteredThrough,2);assert.ok(Math.abs(progress.enteredArea.height-.2)<1e-10);
    rows[3].entered=true;progress=pdfPageProgress(rows);assert.equal(progress.enteredThrough,2);assert.equal(progress.remaining,1);
    rows[2].entered=true;progress=pdfPageProgress(rows);assert.equal(progress.enteredThrough,4);assert.equal(progress.remaining,0);
    assert.deepEqual(progress.enteredArea,progress.area);
    rows[1].entered=false;progress=pdfPageProgress(rows);assert.equal(progress.enteredThrough,1);assert.equal(progress.remaining,1);
    rows[0].entered=false;progress=pdfPageProgress(rows);assert.equal(progress.enteredArea,null);assert.equal(progress.enteredThrough,0);
});

test('pending PDF rows use physical page order and remain isolated from other PDFs',()=>{
    const rows=[{id:1,source_file_id:10,page:2,row_number:1,entered:false},{id:2,source_file_id:11,page:1,row_number:1,entered:false},
        {id:3,source_file_id:10,page:1,row_number:2,entered:false},{id:4,source_file_id:10,page:1,row_number:1,entered:true}];
    assert.equal(nextPendingPdfRow(rows,'10').id,3);
    rows[3].entered=false;assert.equal(nextPendingPdfRow(rows,10).id,4);
    rows.filter(row=>row.source_file_id===10).forEach(row=>row.entered=true);assert.equal(nextPendingPdfRow(rows,10),null);
    const area={x:.1,y:.2,width:.5,height:.1};
    for(const rotation of [0,90,180,270]){
        const rotated=rotatedRowBounds(area,rotation), restored=rotatedRowBounds(rotated,360-rotation);
        for(const key of Object.keys(area))assert.ok(Math.abs(restored[key]-area[key])<1e-10);
    }
    const vertical=rotatedRowBounds(area,90);assert.ok(Math.abs(vertical.x-.7)<1e-10);assert.equal(vertical.y,.1);assert.equal(vertical.width,.1);assert.equal(vertical.height,.5);
});

test('a project unit change requires explicit conversion and never treats an unknown unit as verified',()=>{
    assert.equal(convertPoleHeight(31,'ft','m'),9.4488);assert.equal(convertPoleHeight(9.4488,'m','ft'),31);assert.equal(convertPoleHeight(null,'ft','m'),null);
    assert.throws(()=>convertPoleHeight(31,'','m'));
});

test('complete waypoints preserve both examples, zeros and explicit year interpretation',()=>{
    for(const [identity,group,date,gps] of [['11131222104','11','2022-12-13','104'],['01081026001','01','2026-10-08','001']]){
        assert.equal(completeWaypoint(group,date,gps,2000),identity);assert.deepEqual(parseWaypoint(identity,2000),{group_number:group,row_date:date,waypoint_reference:gps});
    }
    assert.equal(completeWaypoint('01','08/10/2026','002',2000),'01081026002');
    assert.equal(displayDate('2022-12-13'),'13/12/2022');
    assert.equal(parseWaypoint('01081069001',1970).row_date,'2069-10-08');
    for(const value of ['0108102600','010810260001','01310226001','01290225001'])assert.throws(()=>parseWaypoint(value,2000));
    assert.throws(()=>parseWaypoint('01081026001',null));
});

test('recording dates disambiguate repeated GPS names without substituting an unrelated point',()=>{
    const points=[{id:1,name:'104',source_file_id:1,recorded_at:'2022-12-13T08:00:00Z'},{id:2,name:'104',source_file_id:2,recorded_at:'2026-10-08T08:00:00Z'}];
    assert.equal(waypointMatches({waypoint_reference:'104',row_date:'2022-12-13'},points)[0].id,1);
    assert.equal(waypointMatches({waypoint_reference:'104',row_date:'2024-12-13'},points).length,0);
    assert.equal(waypointMatches({waypoint_reference:'104',row_date:'2022-12-13'},[points[1]]).length,0);
    assert.equal(waypointMatches({waypoint_reference:'999',row_date:'2022-12-13'},points).length,0);
});

test('all phase combinations and neutral use the same explicit absence/unknown contract', () => {
    for (const expected of ['R','Y','B','RY','RB','YB','RYB']) for (const neutral of [null,'__none__','A']) {
        const values=Object.fromEntries(['R','Y','B'].map(p=>[p,expected.includes(p) ? 'A' : '__none__']));values.N=neutral;
        assert.deepEqual(derivePhase(values),{display:expected,complete:true});
    }
    assert.equal(derivePhase({R:'A',Y:null,B:'__none__',N:'A'}).complete,false);
    assert.equal(consumerTotal({rs:3,rl:0,pv:1,int:99,intersection:true}),4);
});

test('next row keeps only group/date, offers the paired E and clears electrical/consumer/PV observations', () => {
    const next=nextDraft({pair_number:3,designation:'S',group_number:'01',row_date:'2026-10-08',waypoint_reference:'010',consumers:{rs:8},intersection:true,pv_details:[{reference:'001'}],conductors:{R:'A'}},{next_pair:4});
    assert.equal(next.designation,'E');assert.equal(next.pair_number,3);assert.equal(next.group_number,'01');assert.equal(next.row_date,'2026-10-08');
    assert.equal(next.waypoint_reference,'');assert.equal(next.intersection,false);assert.deepEqual(next.pv_details,[]);assert.equal(next.consumers.rs,null);assert.equal(next.conductors.R,null);
    const rows=[{pair_number:3,designation:'S'},{pair_number:3,designation:'E'}];
    const afterCorrection=nextDraft(rows[0],{rows,next_pair:4});assert.equal(afterCorrection.designation,'S');assert.equal(afterCorrection.pair_number,4);
    rows.push({pair_number:4,designation:'S'});
    const resume=nextDraft(rows[0],{rows,next_pair:5});assert.equal(resume.designation,'E');assert.equal(resume.pair_number,4);
});

test('rendered operator DOM retains drafts across pages/reload, saves once, copies explicit scopes and isolates transformers', async () => {
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile(new URL('../../tmp/mdb-entry-qa/operator.html',import.meta.url),'utf8');
    const stubPath=path.resolve('tmp/mdb-entry-qa/pdf-stub.mjs');
    await fs.writeFile(stubPath,`export const GlobalWorkerOptions={};export const loads=[];export function getDocument(){const task={destroyCalls:0,async destroy(){if(++this.destroyCalls>1)throw new Error('Loading task destroyed twice');},promise:Promise.resolve({numPages:14,getPage:async()=>({getViewport:({scale,rotation})=>({width:(rotation%180 ? 595 : 842)*scale,height:(rotation%180 ? 842 : 595)*scale}),render:()=>({promise:Promise.resolve(),cancel(){}})})})};loads.push(task);return task;}`);
    const server={data:null,posts:[],gets:0};
    let importCount=0;
    const settle=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));};
    async function open(recovery=null) {
        const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
        dom.window.eval([...dom.window.document.scripts].find(s=>s.textContent.includes('window.mdbEntryBoot')).textContent);
        const boot=dom.window.mdbEntryBoot;boot.pdfjs_url=pathToFileURL(stubPath).href;
        boot.entry_settings={two_digit_year_start:2000,pole_height_unit:'ft'};
        if(!server.data){boot.sources.find(s=>s.kind==='gpx').status='queued';server.data=structuredClone(boot);}else Object.assign(boot,structuredClone(server.data));
        boot.pdfjs_url=pathToFileURL(stubPath).href;dom.window.confirm=()=>true;
        dom.window.setInterval=callback=>{server.poll=callback;return 1;};dom.window.clearInterval=()=>{server.poll=null;};
        dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};
        if(recovery)dom.window.localStorage.setItem(boot.storage_key,recovery);
        Object.assign(globalThis,{window:dom.window,document:dom.window.document,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,
            ResizeObserver:class{observe(){}disconnect(){}}});
        globalThis.fetch=async(url,options)=>{
            if(options.method==='GET'){server.gets++;return {ok:true,json:async()=>structuredClone(server.data)};}
            const body=JSON.parse(options.body);server.posts.push({url,body});
            if(url.includes('/rows')) {
                const t=server.data.transformers.find(t=>url.includes(`/transformers/${t.id}/`));
                const normalized=structuredClone(body);normalized.id=t.rows.length+100;normalized.transformer_id=t.id;
                normalized.conductors=Object.fromEntries(Object.entries(body.conductors).map(([p,v])=>[p,v==='__none__' ? '' : v]));t.rows.push(normalized);
            }
            server.data.revision++;return {ok:true,json:async()=>structuredClone(server.data)};
        };
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+`?dom-test=${++importCount}`);await settle();
        return dom;
    }
    const first=await open();let d=first.window.document;
    const input=(name,value)=>{const e=d.querySelector(`[name="${name}"]`);e.value=value;e.dispatchEvent(new first.window.Event('input',{bubbles:true}));};
    input('header[location]','Unsaved header location');input('waypoint_reference','001');input('consumers[rs]','3');
    d.getElementById('entry-paste-waypoint').value='01081026001';d.getElementById('entry-paste-apply').click();
    assert.equal(d.querySelector('[name="row_date"]').value,'08/10/2026');assert.equal(d.querySelector('[name="group_number"]').value,'01');
    d.getElementById('entry-paste-waypoint').value='01310226001';d.getElementById('entry-paste-apply').click();
    assert.equal(d.querySelector('[name="row_date"]').value,'08/10/2026');assert.equal(d.getElementById('entry-composite').value,'01081026001');
    d.getElementById('entry-paste-waypoint').value='01081026001';d.getElementById('entry-paste-apply').click();
    d.querySelector('[name="intersection"]').checked=true;d.querySelector('[name="intersection"]').dispatchEvent(new first.window.Event('change',{bubbles:true}));
    d.getElementById('entry-pv-add').click();d.querySelector('[data-pv-key="reference"]').value='0000123';d.querySelector('[data-pv-key="reference"]').dispatchEvent(new first.window.Event('input',{bubbles:true}));
    d.getElementById('entry-page-next').click();await settle();
    assert.equal(d.getElementById('entry-page').value,'2');assert.equal(d.querySelector('[name="waypoint_reference"]').value,'001');assert.equal(d.querySelector('[name="source_page"]').value,'2');
    assert.equal(d.getElementById('entry-composite').value,'01081026001');
    assert.equal(d.querySelector('[name="header[location]"]').value,'Unsaved header location');assert.equal(d.getElementById('entry-transformer').value,'1');assert.equal(d.querySelector('[data-pv-key="reference"]').value,'0000123');
    const revisionBefore=server.data.revision;server.data.sources.find(s=>s.kind==='gpx').status='ready';server.data.revision++;await server.poll();await settle();
    assert.equal(d.querySelector('[name="header[location]"]').value,'Unsaved header location');assert.equal(d.querySelector('[name="waypoint_reference"]').value,'001');assert.equal(server.poll,null);
    d.getElementById('entry-rotate').click();d.getElementById('entry-zoom-in').click();d.getElementById('entry-viewer-height').value='220';d.getElementById('entry-viewer-height').dispatchEvent(new first.window.Event('input'));
    const recovery=first.window.localStorage.getItem(first.window.mdbEntryBoot.storage_key);first.window.close();
    const second=await open(recovery);d=second.window.document;
    server.data.sources.push({id:999,kind:'gpx',original_name:'Conflicting source',status:'ready',metadata:{}});server.data.waypoints.push({id:999,name:'001',source_file_id:999,recorded_at:null});
    d.getElementById('entry-refresh').click();await settle();assert.equal(d.getElementById('entry-gpx-ambiguity').hidden,false);
    const pdfStub=await import(pathToFileURL(stubPath).href);
    assert.equal(pdfStub.loads.length,3);assert.equal(pdfStub.loads[1].destroyCalls,1);assert.equal(pdfStub.loads[2].destroyCalls,0);
    assert.equal(d.getElementById('entry-pdf-message').hidden,true);assert.equal(d.getElementById('entry-pdf-canvas').hidden,false);
    assert.equal(d.getElementById('entry-errors').hidden,true);
    const gpx=d.getElementById('entry-gpx');gpx.value=String(server.data.sources.find(s=>s.kind==='gpx' && s.id!==999).id);gpx.dispatchEvent(new second.window.Event('change',{bubbles:true}));
    assert.match(d.getElementById('entry-waypoint-status').textContent,/Matched/);
    assert.equal(d.querySelector('[name="source_row"]').type,'hidden');assert.equal(d.querySelector('[name="pole_height_unit"]').type,'hidden');assert.equal(d.querySelectorAll('.entry-combobox-trigger').length,6);
    const conductor=d.querySelector('[name="conductors[R]"]'),trigger=conductor.parentElement.querySelector('.entry-combobox-trigger');
    trigger.click();const search=conductor.parentElement.querySelector('input[type="search"]');search.value='Wasp';search.dispatchEvent(new second.window.Event('input',{bubbles:true}));
    assert.match(conductor.parentElement.querySelector('[role="listbox"]').textContent,/Wasp/i);
    search.dispatchEvent(new second.window.KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));assert.equal(conductor.value,'W');assert.equal(trigger.textContent,'W');assert.equal(trigger.getAttribute('aria-expanded'),'false');
    assert.equal(d.getElementById('entry-page').value,'2');assert.equal(d.querySelector('[name="waypoint_reference"]').value,'001');assert.equal(d.querySelector('[name="header[location]"]').value,'Unsaved header location');assert.equal(d.querySelector('[name="consumers[rs]"]').value,'3');assert.equal(d.querySelector('[name="intersection"]').checked,true);assert.equal(d.querySelector('[data-pv-key="reference"]').value,'0000123');assert.equal(d.getElementById('entry-pdf-scroll').style.height,'220px');
    for(const p of ['R','Y','B','N'])d.querySelector(`[name="conductors[${p}]"]`).value=p==='R'||p==='N' ? 'A' : '__none__';
    d.querySelector('[name="conductors[R]"]').dispatchEvent(new second.window.Event('change',{bubbles:true}));
    assert.equal(d.getElementById('entry-phase').value,'R');
    d.getElementById('entry-row-form').dispatchEvent(new second.window.Event('submit',{bubbles:true,cancelable:true}));
    d.getElementById('entry-row-form').dispatchEvent(new second.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(server.posts.filter(p=>p.url.includes('/rows')).length,1);assert.equal(d.querySelector('[name="designation"]').value,'E');
    assert.equal(d.activeElement,d.querySelector('[name="waypoint_reference"]'));assert.equal(d.activeElement.disabled,false);
    assert.match(d.querySelector('#entry-saved-rows .entry-completed').textContent,/Entered/);
    assert.equal(server.posts.find(p=>p.url.includes('/rows')).body.revision,revisionBefore+1);
    assert.equal(d.querySelector('[name="consumers[rs]"]').value,'');assert.equal(d.querySelector('[name="intersection"]').checked,false);assert.equal(d.querySelectorAll('.entry-pv-row').length,0);assert.equal(d.querySelector('[name="conductors[R]"]').value,'');
    assert.match(d.getElementById('entry-group-date-status').textContent,/inherited/);
    const olderDate=d.querySelector('[name="row_date"]');olderDate.value='13/12/2022';olderDate.dispatchEvent(new second.window.Event('input',{bubbles:true}));
    const gpsNumber=d.querySelector('[name="waypoint_reference"]');gpsNumber.value='002';gpsNumber.dispatchEvent(new second.window.Event('input',{bubbles:true}));
    assert.equal(d.getElementById('entry-composite').value,'01131222002');assert.equal(d.getElementById('entry-group-date-status').textContent,'');
    assert.equal(second.window.mdbEntryBoot.transformers[0].header.survey_date,'2026-10-08');
    d.querySelector('[name="consumers[rs]"]').value='5';d.querySelector('[name="intersection"]').checked=true;
    const previous=server.data.transformers[0].rows.at(-1);previous.equipment_type='SP';previous.pole_class='S';previous.pole_height=31;previous.pole_height_unit='ft';
    d.getElementById('entry-refresh').click();await settle();
    const setValue=(name,value)=>{const element=d.querySelector(`[name="${name}"]`);element.value=value;element.dispatchEvent(new second.window.Event('input',{bubbles:true}));};
    setValue('pole_height','45');setValue('consumers[rs]','5');d.querySelector('[name="intersection"]').checked=true;
    let confirmations=0;second.window.confirm=message=>{confirmations++;assert.match(message,/Replace your entered values/);return false;};
    d.getElementById('entry-copy-both').click();assert.equal(confirmations,1);assert.equal(d.querySelector('[name="pole_height"]').value,'45');assert.equal(d.querySelector('[name="conductors[R]"]').value,'');
    second.window.confirm=()=>true;
    d.getElementById('entry-copy-both').click();assert.equal(d.querySelector('[name="conductors[R]"]').value,'A');assert.equal(d.querySelector('[name="consumers[rs]"]').value,'5');assert.equal(d.querySelector('[name="intersection"]').checked,true);assert.match(d.getElementById('entry-conductor-copy-status').textContent,/Explicitly copied/);
    assert.equal(d.querySelector('[name="pole_height"]').value,'31');assert.equal(d.querySelector('[name="pole_height_unit"]').value,'ft');assert.equal(d.querySelector('[name="pole_height"]').disabled,false);
    assert.equal(d.querySelector('[name="waypoint_reference"]').value,'002');assert.equal(d.querySelector('[name="row_date"]').value,'13/12/2022');assert.equal(d.querySelector('[name="composite_identifier"]').value,'01131222002');assert.equal(d.getElementById('entry-copy-endpoint'),null);
    setValue('pole_height','30');assert.equal(d.getElementById('entry-equipment-copy-status').textContent,'');
    setValue('consumers[rs]','-2');const beforeInvalid=server.posts.length;
    d.getElementById('entry-save-draft').click();await settle();assert.equal(server.posts.length,beforeInvalid);assert.equal(d.querySelector('[name="consumers[rs]"]').getAttribute('aria-invalid'),'true');assert.equal(d.activeElement,d.querySelector('[name="consumers[rs]"]'));assert.equal(d.querySelector('[name="pole_height"]').value,'30');
    setValue('consumers[rs]','5');
    const yTrigger=d.querySelector('[name="conductors[Y]"]').parentElement.querySelector('.entry-combobox-trigger');yTrigger.click();
    yTrigger.parentElement.querySelector('input[type="search"]').dispatchEvent(new second.window.KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));
    assert.equal(d.activeElement,d.querySelector('[name="conductors[B]"]').parentElement.querySelector('.entry-combobox-trigger'));
    d.querySelector('[data-entry-add-transformer]').click();assert.equal(d.getElementById('entry-transformer').value,'');assert.equal(d.querySelector('[name="conductors[R]"]').value,'');assert.equal(d.querySelector('[name="code"]').value,'');assert.equal(d.querySelector('[name="consumers[rs]"]').value,'');assert.equal(d.querySelector('[name="intersection"]').checked,false);
    second.window.close();delete globalThis.window;delete globalThis.document;
});

test('PDF row controls retain progress after reload, save atomically, advance, undo and reopen the linked record', async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile(new URL('../../tmp/mdb-entry-qa/operator.html',import.meta.url),'utf8');
    const stubPath=path.resolve('tmp/mdb-entry-qa/pdf-progress-stub.mjs');
    await fs.writeFile(stubPath,`export const GlobalWorkerOptions={};export function getDocument(){return {destroy:async()=>{},promise:Promise.resolve({numPages:14,getPage:async()=>({getViewport:({scale,rotation})=>({width:(rotation%180 ? 595 : 842)*scale,height:(rotation%180 ? 842 : 595)*scale}),render:()=>({promise:Promise.resolve(),cancel(){}})})})};}`);
    const server={data:null,fail:false,creates:0};let imports=0;
    const settle=async()=>{for(let i=0;i<15;i++)await new Promise(resolve=>setImmediate(resolve));};
    async function open(recovery=null){
        const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
        dom.window.eval([...dom.window.document.scripts].find(s=>s.textContent.includes('window.mdbEntryBoot')).textContent);
        const boot=dom.window.mdbEntryBoot;
        if(!server.data){
            boot.entry_settings={two_digit_year_start:2000,pole_height_unit:'ft'};
            boot.sources.forEach(s=>s.status='ready');boot.transformers[0].rows=[];
            const source=boot.sources.find(s=>s.kind==='pdf').id;
            boot.pdf_rows=[{id:51,source_file_id:source,page:1,row_number:1,bounds:{x:.1,y:.2,width:.8,height:.1},entered:false,entry_row_id:null},
                {id:52,source_file_id:source,page:1,row_number:2,bounds:{x:.1,y:.3,width:.8,height:.1},entered:false,entry_row_id:null},
                {id:53,source_file_id:source,page:2,row_number:1,bounds:{x:.1,y:.2,width:.8,height:.1},entered:false,entry_row_id:null}];
            server.data=structuredClone(boot);
        }else Object.assign(boot,structuredClone(server.data));
        boot.pdfjs_url=pathToFileURL(stubPath).href;
        dom.window.confirm=()=>true;dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};
        if(recovery)dom.window.localStorage.setItem(boot.storage_key,recovery);
        Object.assign(globalThis,{window:dom.window,document:dom.window.document,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
        globalThis.fetch=async(url,options)=>{
            if(options.method==='GET')return {ok:true,json:async()=>structuredClone(server.data)};
            if(server.fail){server.fail=false;return {ok:false,json:async()=>({message:'Save failed'})};}
            const body=JSON.parse(options.body);
            if(url.includes('/row-layout')){
                for(let i=0;i<body.row_count;i++)server.data.pdf_rows.push({id:60+i,source_file_id:server.data.sources.find(s=>s.kind==='pdf').id,page:body.page,row_number:i+1,
                    bounds:{...body.bounds,y:body.bounds.y+i*body.bounds.height/body.row_count,height:body.bounds.height/body.row_count},entered:false,entry_row_id:null});
            }else if(url.includes('/pdf-rows/'))server.data.pdf_rows.find(row=>row.id===Number(url.split('/').at(-1))).entered=body.entered;
            else if(url.includes('/rows')){
                const owner=server.data.transformers[0];
                let saved=owner.rows.find(row=>row.client_uuid===body.client_uuid);
                if(!saved){saved={...body,id:100+server.creates++};owner.rows.push(saved);}else Object.assign(saved,body);
                const marker=server.data.pdf_rows.find(row=>row.id===body.pdf_progress_row_id);marker.entry_row_id=saved.id;marker.entered=true;
            }
            server.data.revision++;return {ok:true,json:async()=>structuredClone(server.data)};
        };
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+`?pdf-progress-test=${++imports}`);await settle();
        for(let attempt=0;attempt<100 && dom.window.document.getElementById('entry-pdf-draw').disabled;attempt++)await new Promise(resolve=>setTimeout(resolve,10));
        assert.equal(dom.window.document.getElementById('entry-pdf-draw').disabled,false,'Wait for the PDF to finish loading before drawing');
        return dom;
    }
    const first=await open();let d=first.window.document;
    assert.match(d.getElementById('entry-pdf-progress').textContent,/page 1, row 1/);
    const pageInput=d.getElementById('entry-page');pageInput.value='3';pageInput.dispatchEvent(new first.window.Event('change'));await settle();
    d.getElementById('entry-pdf-row-count').value='4';d.getElementById('entry-pdf-draw').click();
    d.getElementById('entry-pdf-canvas').getBoundingClientRect=()=>({left:0,top:0,width:1000,height:500});
    const stage=d.getElementById('entry-pdf-stage');
    stage.dispatchEvent(new first.window.MouseEvent('pointerdown',{clientX:100,clientY:100,button:0,bubbles:true}));
    stage.dispatchEvent(new first.window.MouseEvent('pointermove',{clientX:900,clientY:400,bubbles:true}));
    stage.dispatchEvent(new first.window.MouseEvent('pointerup',{clientX:900,clientY:400,bubbles:true}));
    assert.equal(d.getElementById('entry-pdf-layout-save').disabled,false);d.getElementById('entry-pdf-layout-save').click();await settle();
    assert.equal(server.data.pdf_rows.filter(row=>row.page===3).length,4);assert.equal(d.querySelectorAll('[data-pdf-row]').length,4);
    assert.ok(Math.abs(server.data.pdf_rows.find(row=>row.page===3).bounds.height-.15)<1e-10);
    d.getElementById('entry-pdf-pending').click();await settle();assert.equal(d.getElementById('entry-page').value,'1');
    d.querySelector('[data-pdf-row="51"]').click();assert.equal(d.getElementById('entry-pdf-mark').disabled,true);
    assert.equal(d.getElementById('entry-pdf-row').value,'51');
    server.fail=true;d.getElementById('entry-row-form').dispatchEvent(new first.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(server.data.pdf_rows[0].entered,false);assert.equal(server.creates,0);assert.equal(d.getElementById('entry-pdf-row').value,'51');
    assert.equal(d.getElementById('entry-pdf-entered-area').hidden,true);
    d.getElementById('entry-row-form').dispatchEvent(new first.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(server.data.pdf_rows[0].entered,true);assert.equal(server.creates,1);
    assert.equal(d.querySelector('[data-pdf-row="51"]').dataset.entered,'true');assert.equal(d.getElementById('entry-pdf-row').value,'52');
    assert.equal(d.getElementById('entry-pdf-entered-area').hidden,false);assert.ok(Math.abs(parseFloat(d.getElementById('entry-pdf-entered-area').style.height)-10)<.001);
    assert.match(d.getElementById('entry-pdf-entered-area').textContent,/Entered through row 1/);assert.match(d.getElementById('entry-pdf-progress').textContent,/1 remaining/);
    assert.equal(d.querySelector('[name="designation"]').value,'E');assert.match(d.getElementById('entry-pdf-progress').textContent,/page 1, row 2/);
    d.getElementById('entry-page-next').click();await settle();
    assert.equal(d.querySelector('[name="source_page"]').value,'1'); // Navigation does not move the selected record's provenance.
    d.getElementById('entry-pdf-pending').click();await settle();assert.equal(d.getElementById('entry-page').value,'1');
    d.querySelector('[data-pdf-row="51"]').click();assert.match(d.getElementById('entry-row-title').textContent,/Edit pair/);
    d.getElementById('entry-pdf-undo').click();await settle();
    assert.equal(server.data.pdf_rows[0].entered,false);assert.equal(server.data.pdf_rows[0].entry_row_id,100);assert.equal(server.creates,1);
    assert.equal(d.getElementById('entry-pdf-entered-area').hidden,true);
    assert.match(d.getElementById('entry-pdf-progress').textContent,/page 1, row 1/);assert.equal(d.getElementById('entry-pdf-mark').disabled,false);
    const recovery=first.window.localStorage.getItem(first.window.mdbEntryBoot.storage_key);first.window.close();
    const second=await open(recovery);d=second.window.document;
    assert.equal(d.getElementById('entry-pdf-row').value,'51');assert.match(d.getElementById('entry-row-title').textContent,/Edit pair/);
    assert.equal(d.getElementById('entry-pdf-entered-area').hidden,true);
    d.getElementById('entry-pdf-mark').click();await settle();assert.equal(server.data.pdf_rows[0].entered,true);assert.equal(server.creates,1);
    d.getElementById('entry-pdf-pending').click();await settle();
    d.getElementById('entry-row-form').dispatchEvent(new second.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(server.creates,2);assert.equal(d.getElementById('entry-page').value,'2');assert.equal(d.getElementById('entry-pdf-entered-area').hidden,true);
    d.getElementById('entry-page').value='1';d.getElementById('entry-page').dispatchEvent(new second.window.Event('change'));await settle();
    assert.equal(d.getElementById('entry-pdf-entered-area').hidden,false);assert.ok(Math.abs(parseFloat(d.getElementById('entry-pdf-entered-area').style.height)-20)<.001);
    assert.match(d.getElementById('entry-pdf-entered-area').textContent,/Entered through row 2/);assert.match(d.getElementById('entry-pdf-progress').textContent,/0 remaining/);
    d.querySelector('[data-pdf-row="52"]').click();d.getElementById('entry-pdf-undo').click();await settle();
    assert.ok(Math.abs(parseFloat(d.getElementById('entry-pdf-entered-area').style.height)-10)<.001);
    d.getElementById('entry-pdf-mark').click();await settle();
    d.getElementById('entry-rotate').click();await settle();
    const overlay=d.querySelector('[data-pdf-row="51"]');assert.ok(Math.abs(parseFloat(overlay.style.left)-70)<.001);assert.ok(Math.abs(parseFloat(overlay.style.width)-10)<.001);
    assert.ok(Math.abs(parseFloat(d.getElementById('entry-pdf-entered-area').style.width)-20)<.001);
    const savedRecovery=second.window.localStorage.getItem(second.window.mdbEntryBoot.storage_key);second.window.close();
    const third=await open(savedRecovery);d=third.window.document;
    assert.equal(d.getElementById('entry-pdf-entered-area').hidden,false);assert.match(d.getElementById('entry-pdf-entered-area').textContent,/Entered through row 2/);
    assert.ok(Math.abs(parseFloat(d.getElementById('entry-pdf-entered-area').style.width)-20)<.001);
    third.window.close();delete globalThis.window;delete globalThis.document;
});

test('header failures show reasons and field errors, preserve recovery and succeed only after a confirmed retry',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile(new URL('../../tmp/mdb-entry-qa/operator.html',import.meta.url),'utf8');
    const server={data:null,response:null,calls:0};let imports=0;
    const settle=async()=>{for(let i=0;i<15;i++)await new Promise(resolve=>setImmediate(resolve));};
    async function open(recovery=null){
        const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
        dom.window.eval([...dom.window.document.scripts].find(s=>s.textContent.includes('window.mdbEntryBoot')).textContent);
        const boot=dom.window.mdbEntryBoot;
        if(!server.data){boot.transformers=[];boot.sources.forEach(source=>source.status='ready');server.data=structuredClone(boot);}else Object.assign(boot,structuredClone(server.data));
        boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/pdf-progress-stub.mjs')).href;
        dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};dom.window.confirm=()=>true;
        if(recovery)dom.window.localStorage.setItem(boot.storage_key,recovery);
        Object.assign(globalThis,{window:dom.window,document:dom.window.document,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
        globalThis.fetch=async(url,options)=>{
            if(options.method==='GET')return {ok:true,status:200,json:async()=>structuredClone(server.data)};
            server.calls++;
            if(server.response instanceof Error)throw server.response;
            if(server.response)return server.response;
            const body=JSON.parse(options.body);const saved={id:10,rows:[],legacy_sections:[],...body};server.data.transformers=[saved];server.data.active_transformer_id=10;server.data.revision++;
            return {ok:true,status:200,json:async()=>structuredClone(server.data)};
        };
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+`?header-errors-test=${++imports}`);await settle();return dom;
    }
    const first=await open();let d=first.window.document;
    const input=(name,value)=>{const element=d.querySelector(`[name="${name}"]`);element.value=value;element.dispatchEvent(new first.window.Event('input',{bubbles:true}));};
    input('code','T-keep');input('header[substation_name]','Retain substation');input('header[location]','Retain location');input('capacity_kva','100');
    const submit=async(dom)=>{dom.window.document.getElementById('entry-header-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();};
    server.response={ok:false,status:422,json:async()=>({message:'Both PDF and GPX must finish processing before entry.',errors:{}})};
    await submit(first);assert.match(d.getElementById('entry-errors').textContent,/Both PDF and GPX must finish processing/);
    assert.equal(d.getElementById('entry-save-status').hidden,true);
    assert.equal(d.getElementById('entry-errors').parentElement,d.getElementById('entry-refresh').parentElement);
    assert.equal(d.getElementById('entry-save-status').textContent,'');
    assert.equal(d.querySelector('[name="code"]').value,'T-keep');assert.equal(d.querySelector('[name="header[location]"]').value,'Retain location');assert.equal(d.getElementById('entry-header-panel').open,true);
    assert.equal(d.querySelector('[name="code"]').disabled,false);assert.equal(d.querySelector('[name="code"]').hasAttribute('aria-invalid'),false);
    server.response={ok:false,status:422,json:async()=>({message:'Correct the invalid header fields.',errors:{code:['Transformer code already exists.'],'header.location':['Check this location against the PDF.']},reference:'debug-reference'})};
    await submit(first);assert.match(d.getElementById('entry-errors').textContent,/Transformer code already exists/);assert.match(d.getElementById('entry-errors').textContent,/debug-reference/);
    for(const name of ['code','header[location]']){
        const element=d.querySelector(`[name="${name}"]`);assert.equal(element.getAttribute('aria-invalid'),'true');
        assert.ok(d.getElementById(element.getAttribute('aria-describedby'))?.textContent);assert.equal(element.disabled,false);
    }
    assert.equal(d.querySelector('[name="header[substation_name]"]').value,'Retain substation');assert.equal(d.querySelector('[name="capacity_kva"]').value,'100');
    const recovery=first.window.localStorage.getItem(first.window.mdbEntryBoot.storage_key);first.window.close();
    const second=await open(recovery);d=second.window.document;
    assert.equal(d.querySelector('[name="code"]').value,'T-keep');assert.equal(d.querySelector('[name="header[location]"]').value,'Retain location');
    server.response={ok:false,status:419,json:async()=>{throw new Error('HTML expired-session response');}};
    await submit(second);assert.match(d.getElementById('entry-errors').textContent,/session has expired/);assert.equal(d.querySelector('[name="code"]').value,'T-keep');
    server.response={ok:true,status:200,redirected:true,url:'http://localhost/login',json:async()=>({})};
    await submit(second);assert.match(d.getElementById('entry-errors').textContent,/Sign in again/);
    server.response=new Error('Network down');await submit(second);assert.match(d.getElementById('entry-errors').textContent,/Could not reach the server/);
    server.response={ok:true,status:200,json:async()=>({message:'unexpected body'})};await submit(second);
    assert.match(d.getElementById('entry-errors').textContent,/did not confirm/);assert.equal(d.getElementById('entry-save-status').hidden,true);assert.equal(d.getElementById('entry-save-status').parentElement.dataset.state,'error');
    assert.equal(d.querySelector('[name="code"]').value,'T-keep');
    server.response=null;await submit(second);assert.equal(d.getElementById('entry-save-status').textContent,'Transformer header saved.');
    assert.equal(d.getElementById('entry-save-status').hidden,false);
    assert.equal(d.getElementById('entry-errors').hidden,true);assert.equal(d.querySelectorAll('[aria-invalid]').length,0);
    assert.equal(server.data.transformers[0].header.location,'Retain location');assert.equal(d.getElementById('entry-header-panel').open,false);
    const clean=JSON.parse(second.window.localStorage.getItem(second.window.mdbEntryBoot.storage_key));assert.equal(clean.headerDirty,false);
    const callsBefore=server.calls;d.querySelector('[name="code"]').value='';await submit(second);
    assert.equal(server.calls,callsBefore);assert.equal(d.querySelector('[name="code"]').getAttribute('aria-invalid'),'true');assert.equal(d.getElementById('entry-header-panel').open,true);
    second.window.close();delete globalThis.window;delete globalThis.document;
});
