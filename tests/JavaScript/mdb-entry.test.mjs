import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import path from 'node:path';
import {derivePhase, consumerTotal, nextDraft, completeWaypoint, parseWaypoint, isoDate, displayDate, waypointMatches, convertPoleHeight, rotatedRowBounds, nextPendingPdfRow, pdfPageProgress, rowValidationErrors, rotatedPdfPoint, transformerCodeKey} from '../../public/js/mdb-entry.js';

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
    boot.can_configure=false;boot.entry_settings={...boot.entry_settings,two_digit_year_start:null};
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
    assert.equal(d.getElementById('entry-project-settings').querySelector('a'),null);
    assert.match(d.getElementById('entry-project-settings').textContent,/2000/);
    assert.doesNotMatch(d.getElementById('entry-project-settings').textContent,/unconfigured/);
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
    assert.equal(displayDate('2022-12-13'),'131222');
    assert.equal(parseWaypoint('01081069001',1970).row_date,'2069-10-08');
    for(const value of ['0108102600','010810260001','01310226001','01290225001'])assert.throws(()=>parseWaypoint(value,2000));
    assert.equal(parseWaypoint('01081026001',null).row_date,'2026-10-08');
});

test('recording dates disambiguate repeated GPS names without substituting an unrelated point',()=>{
    const points=[{id:1,name:'104',source_file_id:1,recorded_at:'2022-12-13T08:00:00Z'},{id:2,name:'104',source_file_id:2,recorded_at:'2026-10-08T08:00:00Z'}];
    assert.equal(waypointMatches({waypoint_reference:'104',row_date:'2022-12-13'},points)[0].id,1);
    assert.equal(waypointMatches({waypoint_reference:'104',row_date:'2024-12-13'},points).length,2);
    assert.equal(waypointMatches({waypoint_reference:'104',row_date:'2022-12-13'},[points[1]])[0].id,2);
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
    assert.equal(d.getElementById('header-waypoint'),null);
    assert.equal(d.querySelector('[name="header[source_survey_identifier]"]'),null);
    const input=(name,value)=>{const e=d.querySelector(`[name="${name}"]`);e.value=value;e.dispatchEvent(new first.window.Event('input',{bubbles:true}));};
    input('header[location]','Unsaved header location');input('waypoint_reference','001');input('consumers[rs]','3');
    d.getElementById('entry-paste-waypoint').value='01081026001';d.getElementById('entry-paste-apply').click();
    assert.equal(d.querySelector('[name="row_date"]').value,'081026');assert.equal(d.querySelector('[name="group_number"]').value,'01');
    d.getElementById('entry-paste-waypoint').value='01310226001';d.getElementById('entry-paste-apply').click();
    assert.equal(d.querySelector('[name="row_date"]').value,'081026');assert.equal(d.getElementById('entry-composite').value,'01081026001');
    d.getElementById('entry-paste-waypoint').value='01081026001';d.getElementById('entry-paste-apply').click();
    d.querySelector('[name="intersection"]').checked=true;d.querySelector('[name="intersection"]').dispatchEvent(new first.window.Event('change',{bubbles:true}));
    d.getElementById('entry-pv-add').click();d.querySelector('[data-pv-key="reference"]').value='0000123';d.querySelector('[data-pv-key="reference"]').dispatchEvent(new first.window.Event('input',{bubbles:true}));
    const meter=d.querySelector('[data-pv-key="meter_number"]');meter.value='001-AB/09';meter.dispatchEvent(new first.window.Event('input',{bubbles:true}));
    assert.equal(meter.closest('label').nextElementSibling.querySelector('input').dataset.pvKey,'remarks');
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
    d.getElementById('entry-refresh').click();await settle();assert.equal(d.getElementById('entry-gpx-ambiguity').hidden,true); // First S remains a KMZ reference.
    const pdfStub=await import(pathToFileURL(stubPath).href);
    assert.equal(pdfStub.loads.length,3);assert.equal(pdfStub.loads[1].destroyCalls,1);assert.equal(pdfStub.loads[2].destroyCalls,0);
    assert.equal(d.getElementById('entry-pdf-message').hidden,true);assert.equal(d.getElementById('entry-pdf-canvas').hidden,false);
    assert.equal(d.getElementById('entry-errors').hidden,true);
    const gpx=d.getElementById('entry-gpx');gpx.value=String(server.data.sources.find(s=>s.kind==='gpx' && s.id!==999).id);gpx.dispatchEvent(new second.window.Event('change',{bubbles:true}));
    assert.match(d.getElementById('entry-waypoint-status').textContent,/Matched/);
    assert.equal(d.querySelector('[name="source_row"]').type,'hidden');assert.equal(d.querySelector('[name="pole_height_unit"]').tagName,'SELECT');assert.equal(d.querySelectorAll('.entry-combobox-trigger').length,7);
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
    assert.equal(server.posts.find(p=>p.url.includes('/rows')).body.pv_details[0].meter_number,'001-AB/09');
    assert.equal(d.querySelector('[name="consumers[rs]"]').value,'');assert.equal(d.querySelector('[name="intersection"]').checked,false);assert.equal(d.querySelectorAll('.entry-pv-row').length,0);assert.equal(d.querySelector('[name="conductors[R]"]').value,'');
    assert.match(d.getElementById('entry-group-date-status').textContent,/inherited/);
    const olderDate=d.querySelector('[name="row_date"]');olderDate.value='131222';olderDate.dispatchEvent(new second.window.Event('input',{bubbles:true}));
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
    assert.equal(d.querySelector('[name="waypoint_reference"]').value,'002');assert.equal(d.querySelector('[name="row_date"]').value,'131222');assert.equal(d.querySelector('[name="composite_identifier"]').value,'01131222002');assert.equal(d.getElementById('entry-copy-endpoint'),null);
    const heightChoice=d.getElementById('entry-height-choice');heightChoice.value='36';heightChoice.dispatchEvent(new second.window.Event('change',{bubbles:true}));
    assert.equal(d.querySelector('[name="pole_height"]').value,'36');assert.equal(d.getElementById('entry-equipment-copy-status').textContent,'');
    setValue('pole_height','30');assert.equal(d.getElementById('entry-equipment-copy-status').textContent,'');
    setValue('consumers[rs]','-2');const beforeInvalid=server.posts.length;
    d.getElementById('entry-save-draft').click();await settle();assert.equal(server.posts.length,beforeInvalid);assert.equal(d.querySelector('[name="consumers[rs]"]').getAttribute('aria-invalid'),'true');assert.equal(d.activeElement,d.querySelector('[name="consumers[rs]"]'));assert.equal(d.querySelector('[name="pole_height"]').value,'30');
    setValue('consumers[rs]','5');
    const yTrigger=d.querySelector('[name="conductors[Y]"]').parentElement.querySelector('.entry-combobox-trigger');yTrigger.click();
    yTrigger.parentElement.querySelector('input[type="search"]').dispatchEvent(new second.window.KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));
    assert.equal(d.activeElement,d.querySelector('[name="conductors[B]"]').parentElement.querySelector('.entry-combobox-trigger'));
    d.querySelector('[data-entry-add-transformer]').click();assert.equal(d.getElementById('entry-transformer').value,'__manual__');assert.equal(d.querySelector('[name="conductors[R]"]').value,'');assert.equal(d.querySelector('[name="code"]').value,'');assert.equal(d.querySelector('[name="consumers[rs]"]').value,'');assert.equal(d.querySelector('[name="intersection"]').checked,false);
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
            if(url.endsWith('/lines')){
                server.data.pdf_lines ||= [];
                server.data.pdf_lines.push({...body,id:80+server.data.pdf_lines.length,source_file_id:server.data.sources.find(s=>s.kind==='pdf').id});
            }else if(url.includes('/pdf-lines/')){
                server.data.pdf_lines=server.data.pdf_lines.filter(line=>line.id!==Number(url.split('/').at(-1)));
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
    d.getElementById('entry-pdf-draw').click();
    d.getElementById('entry-pdf-canvas').getBoundingClientRect=()=>({left:0,top:0,width:1000,height:500});
    const stage=d.getElementById('entry-pdf-stage');
    const draw=(x1,y1,x2,y2)=>{
        stage.dispatchEvent(new first.window.MouseEvent('pointerdown',{clientX:x1,clientY:y1,button:0,bubbles:true}));
        stage.dispatchEvent(new first.window.MouseEvent('pointermove',{clientX:x2,clientY:y2,bubbles:true}));
        stage.dispatchEvent(new first.window.MouseEvent('pointerup',{clientX:x2,clientY:y2,bubbles:true}));
    };
    draw(100,100,900,400);await settle();
    assert.equal(server.data.pdf_lines.length,1);assert.equal(server.data.pdf_lines[0].page,3);
    assert.deepEqual(server.data.pdf_lines[0].start,{x:.1,y:.2});assert.deepEqual(server.data.pdf_lines[0].end,{x:.9,y:.2});
    assert.equal(d.getElementById('entry-pdf-draw').getAttribute('aria-pressed'),'true');
    draw(500,300,500,300);await settle();
    assert.equal(server.data.pdf_lines.length,2);assert.deepEqual(server.data.pdf_lines[1].start,{x:.01,y:.6});assert.deepEqual(server.data.pdf_lines[1].end,{x:.99,y:.6});
    assert.equal(d.querySelectorAll('[data-pdf-line]').length,2);
    d.getElementById('entry-line-undo').click();await settle();assert.equal(server.data.pdf_lines.length,1);
    server.fail=true;draw(100,200,900,200);await settle();assert.equal(server.data.pdf_lines.length,1);assert.equal(d.querySelector('[data-pdf-line="preview"]'),null);
    d.getElementById('entry-pdf-pending').click();await settle();assert.equal(d.getElementById('entry-page').value,'1');
    d.querySelector('[data-pdf-row="51"]').click();assert.equal(d.getElementById('entry-pdf-mark').disabled,true);
    assert.equal(d.getElementById('entry-pdf-row').value,'51');
    server.fail=true;d.getElementById('entry-row-form').dispatchEvent(new first.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(server.data.pdf_rows[0].entered,false);assert.equal(server.creates,0);assert.equal(d.getElementById('entry-pdf-row').value,'51');
    assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,0);
    d.getElementById('entry-row-form').dispatchEvent(new first.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(server.data.pdf_rows[0].entered,true);assert.equal(server.creates,1);
    assert.equal(d.querySelector('[data-pdf-row="51"]').dataset.entered,'true');assert.equal(d.getElementById('entry-pdf-row').value,'52');
    assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,1);
    assert.match(d.getElementById('entry-pdf-progress').textContent,/1 remaining/);
    assert.equal(d.querySelector('[name="designation"]').value,'E');assert.match(d.getElementById('entry-pdf-progress').textContent,/page 1, row 2/);
    d.getElementById('entry-page-next').click();await settle();
    assert.equal(d.querySelector('[name="source_page"]').value,'1'); // Navigation does not move the selected record's provenance.
    d.getElementById('entry-pdf-pending').click();await settle();assert.equal(d.getElementById('entry-page').value,'1');
    d.querySelector('[data-pdf-row="51"]').click();assert.match(d.getElementById('entry-row-title').textContent,/Edit pair/);
    d.getElementById('entry-pdf-undo').click();await settle();
    assert.equal(server.data.pdf_rows[0].entered,false);assert.equal(server.data.pdf_rows[0].entry_row_id,100);assert.equal(server.creates,1);
    assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,0);
    assert.match(d.getElementById('entry-pdf-progress').textContent,/page 1, row 1/);assert.equal(d.getElementById('entry-pdf-mark').disabled,false);
    const recovery=first.window.localStorage.getItem(first.window.mdbEntryBoot.storage_key);first.window.close();
    const second=await open(recovery);d=second.window.document;
    assert.equal(d.getElementById('entry-pdf-row').value,'51');assert.match(d.getElementById('entry-row-title').textContent,/Edit pair/);
    assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,0);
    d.getElementById('entry-pdf-mark').click();await settle();assert.equal(server.data.pdf_rows[0].entered,true);assert.equal(server.creates,1);
    d.getElementById('entry-pdf-pending').click();await settle();
    d.getElementById('entry-row-form').dispatchEvent(new second.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
    assert.equal(server.creates,2);assert.equal(d.getElementById('entry-page').value,'2');assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,0);
    d.getElementById('entry-page').value='1';d.getElementById('entry-page').dispatchEvent(new second.window.Event('change'));await settle();
    assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,2);
    assert.match(d.getElementById('entry-pdf-progress').textContent,/0 remaining/);
    d.querySelector('[data-pdf-row="52"]').click();d.getElementById('entry-pdf-undo').click();await settle();
    assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,1);
    d.getElementById('entry-pdf-mark').click();await settle();
    d.getElementById('entry-rotate').click();await settle();
    const overlay=d.querySelector('[data-pdf-row="51"]');assert.ok(Math.abs(parseFloat(overlay.style.left)-70)<.001);assert.ok(Math.abs(parseFloat(overlay.style.width)-10)<.001);
    assert.ok(Math.abs(Number(d.querySelector('[data-pdf-line="row-51"]').getAttribute('x1'))-.7)<.001);
    const savedRecovery=second.window.localStorage.getItem(second.window.mdbEntryBoot.storage_key);second.window.close();
    const third=await open(savedRecovery);d=third.window.document;
    assert.equal(d.querySelectorAll('[data-pdf-line^=\"row-\"]').length,2);
    assert.ok(Math.abs(Number(d.querySelector('[data-pdf-line="row-51"]').getAttribute('x1'))-.7)<.001);
    d.getElementById('entry-page').value='3';d.getElementById('entry-page').dispatchEvent(new third.window.Event('change'));await settle();
    assert.equal(d.querySelectorAll('[data-pdf-line]').length,1);
    assert.ok(Math.abs(Number(d.querySelector('[data-pdf-line="80"]').getAttribute('x1'))-.8)<.001);
    d.getElementById('entry-line-undo').click();await settle();assert.equal(server.data.pdf_lines.length,0);
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
        if(!server.data){boot.transformers=[];boot.registered_transformers=[{id:901,code:'T-keep'},{id:902,code:'T-next'}];boot.sources.forEach(source=>source.status='ready');server.data=structuredClone(boot);}else Object.assign(boot,structuredClone(server.data));
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
    d.getElementById('entry-transformer').value='registered:901';d.getElementById('entry-transformer').dispatchEvent(new first.window.Event('change'));
    assert.equal(d.querySelector('[name="code"]').value,'T-keep');assert.equal(d.querySelector('[name="code"]').readOnly,true);
    input('header[substation_name]','Retain substation');input('header[location]','Retain location');input('capacity_kva','100');
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
    assert.equal(d.getElementById('entry-transformer').value,'registered:901');
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
    assert.equal(d.querySelector('[name="code"]').readOnly,true);
    d.querySelector('[name="code"]').value='Unexpected replacement';await submit(second);
    assert.equal(server.data.transformers[0].code,'T-keep');assert.equal(d.querySelector('[name="code"]').value,'T-keep');
    d.querySelector('[data-entry-add-transformer]').click();assert.equal(d.querySelector('[name="code"]').readOnly,false);
    const callsBefore=server.calls;d.querySelector('[name="code"]').value='';await submit(second);
    assert.equal(server.calls,callsBefore);assert.equal(d.querySelector('[name="code"]').getAttribute('aria-invalid'),'true');assert.equal(d.getElementById('entry-header-panel').open,true);
    assert.equal([...d.getElementById('entry-transformer').options].some(option=>option.value==='registered:901'),false);
    d.getElementById('entry-transformer').value='registered:902';d.getElementById('entry-transformer').dispatchEvent(new second.window.Event('change'));
    assert.equal(d.querySelector('[name="code"]').value,'T-next');assert.equal(d.getElementById('entry-transformer').value,'registered:902');
    d.getElementById('entry-refresh').click();await settle();assert.equal(d.querySelector('[name="code"]').value,'T-next');
    assert.equal(JSON.parse(second.window.localStorage.getItem(second.window.mdbEntryBoot.storage_key)).header.registered_transformer_id,902);
    server.data.registered_transformers=[];d.getElementById('entry-refresh').click();await settle();
    assert.equal(d.querySelector('[name="code"]').value,'');assert.match(d.getElementById('entry-transformer-help').textContent,/enter it manually/);
    second.window.close();delete globalThis.window;delete globalThis.document;
});


test('red line page coordinates round-trip through every PDF rotation',()=>{
    const start={x:.1,y:.4},end={x:.9,y:.4};
    for(const rotation of [0,90,180,270]){
        for(const point of [start,end]){
            const restored=rotatedPdfPoint(rotatedPdfPoint(point,rotation),-rotation);
            assert.ok(Math.abs(restored.x-point.x)<1e-10);assert.ok(Math.abs(restored.y-point.y)<1e-10);
        }
    }
});


test('transformer dropdown searches codes and manual drafts recover, reject duplicates and save',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile(new URL('../../tmp/mdb-entry-qa/operator.html',import.meta.url),'utf8');
    let posts=0, lastBody, server;
    const settle=async()=>{for(let i=0;i<18;i++)await new Promise(resolve=>setImmediate(resolve));};
    async function open(recovery){
        const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
        dom.window.eval([...dom.window.document.scripts].find(s=>s.textContent.includes('window.mdbEntryBoot')).textContent);
        const boot=dom.window.mdbEntryBoot;
        boot.registered_transformers=[{id:901,code:'SEARCH-901'},{id:902,code:'SEARCH-902'}];
        boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/pdf-progress-stub.mjs')).href;
        server=structuredClone(boot);
        dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};dom.window.confirm=()=>true;
        if(recovery)dom.window.localStorage.setItem(boot.storage_key,recovery);
        Object.assign(globalThis,{window:dom.window,document:dom.window.document,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
        globalThis.fetch=async(url,options)=>{
            if(options.method!=='GET'){
                posts++;lastBody=JSON.parse(options.body);
                server.transformers.push({id:999,rows:[],legacy_sections:[],...lastBody});server.active_transformer_id=999;server.revision++;
            }
            return {ok:true,json:async()=>structuredClone(server)};
        };
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?manual-search='+Boolean(recovery));await settle();return dom;
    }
    let dom=await open(), d=dom.window.document;
    const combo=d.getElementById('entry-transformer').closest('.entry-combobox');
    combo.querySelector('button').click();
    const search=combo.querySelector('input');search.value='902';search.dispatchEvent(new dom.window.Event('input'));
    assert.deepEqual([...combo.querySelectorAll('[role="option"]')].map(el=>el.textContent),['SEARCH-902 \u2014 registered; new survey header']);
    search.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));
    assert.equal(d.querySelector('[name="code"]').value,'SEARCH-902');
    d.querySelector('[data-entry-add-transformer]').click();
    let code=d.querySelector('[name="code"]');assert.equal(code.readOnly,false);assert.equal(code.required,true);
    code.value='NEW-MANUAL';code.dispatchEvent(new dom.window.Event('input',{bubbles:true}));
    const headerPage=d.getElementById('entry-header-pdf-page');
    assert.equal(headerPage.required,true);assert.equal(d.getElementById('entry-header-pdf').required,true);
    headerPage.value='7';headerPage.dispatchEvent(new dom.window.Event('input',{bubbles:true}));
    const recovery=dom.window.localStorage.getItem(dom.window.mdbEntryBoot.storage_key);dom.window.close();
    dom=await open(recovery);d=dom.window.document;code=d.querySelector('[name="code"]');
    assert.equal(code.value,'NEW-MANUAL');assert.equal(code.readOnly,false);assert.equal(d.getElementById('entry-transformer').value,'__manual__');
    const submit=async()=>{d.getElementById('entry-header-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();};
    const page=d.getElementById('entry-header-pdf-page');assert.equal(page.value,'7');
    const selectedPdf=d.getElementById('entry-header-pdf').value;
    page.value='';await submit();assert.equal(posts,0);assert.equal(page.getAttribute('aria-invalid'),'true');
    page.value='999';await submit();assert.equal(posts,0);
    page.value='7';
    d.getElementById('entry-page-next').click();await settle();
    assert.equal(page.value,'7'); // Viewer navigation must not replace the transformer's saved starting page.
    code.value=' '+server.transformers[0].code.toLowerCase()+' ';await submit();
    assert.equal(posts,0);assert.match(d.getElementById('entry-errors').textContent,/already added/);
    code.value=' NEW-MANUAL ';await submit();
    assert.equal(posts,1);assert.equal(lastBody.code,'NEW-MANUAL');assert.equal(lastBody.manual_transformer,true);assert.equal(lastBody.registered_transformer_id,null);
    assert.equal(lastBody.source_page,7);assert.equal(lastBody.source_pdf_id,Number(selectedPdf));
    assert.equal(code.readOnly,true);assert.equal(d.getElementById('entry-errors').hidden,true);
    dom.window.close();delete globalThis.window;delete globalThis.document;
});


test('six-digit PDF dates resolve through the project year window and reject invalid dates',()=>{
    assert.equal(isoDate('131222',2000),'2022-12-13');
    assert.equal(displayDate('2022-12-13'),'131222');
    assert.equal(completeWaypoint('11','131222','104',2000),'11131222104');
    assert.equal(isoDate('010169',1970),'2069-01-01');
    assert.equal(isoDate('010170',1970),'1970-01-01');
    assert.throws(()=>completeWaypoint('11','310222','104',2000),/valid calendar date/);
    assert.throws(()=>completeWaypoint('11','290223','104',2000),/valid calendar date/);
    assert.equal(completeWaypoint('11','290224','104',2000),'11290224104');
    assert.equal(completeWaypoint('11','131222','104'),'11131222104');
    assert.equal(isoDate('131222',null),'2022-12-13');
    assert.equal(parseWaypoint('11131222104',null).row_date,'2022-12-13');
    assert.throws(()=>completeWaypoint('11','13122','104',2000),/six date digits/);
    assert.equal(isoDate('13/12/2022',2000),'2022-12-13'); // Older recovery drafts still load.
});

test('lookup typing selects Ant, TR and pole classes immediately and preserves search and Tab',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const {installCompactSelects}=await import('../../public/js/mdb-entry-select.js?v=3');
    const dom=new JSDOM(`<div>
        <select id="r" data-compact-select><option value="">Not Entered</option><option value="A">A - Ant</option><option value="W">W - Wasp</option><option value="ABC">ABC cable</option></select>
        <select id="type" data-compact-select><option value="">Not Entered</option><option value="TR">TR</option><option value="SP">SP</option></select>
        <select id="pole" data-compact-select><option value="">Not Entered</option><option value="S">S - Steel</option><option value="PCO">PCO</option><option value="PCS">PCS</option></select>
        <input id="next">
    </div>`);
    Object.assign(globalThis,{window:dom.window,document:dom.window.document});
    dom.window.Element.prototype.scrollIntoView=()=>{};
    installCompactSelects();
    const d=dom.window.document, select=id=>d.getElementById(id), trigger=id=>select(id).parentElement.querySelector('button');
    let changes=0;select('r').addEventListener('change',()=>changes++);
    const type=(id,text)=>{trigger(id).focus();for(const key of text)trigger(id).dispatchEvent(new dom.window.KeyboardEvent('keydown',{key,bubbles:true,cancelable:true}));};
    type('r','a');assert.equal(select('r').value,'A');assert.equal(changes,1);
    assert.equal(trigger('r').getAttribute('aria-expanded'),'false');
    type('type','t');assert.equal(select('type').value,'TR');
    type('pole','pcs');assert.equal(select('pole').value,'PCS');
    trigger('r').click();const search=select('r').parentElement.querySelector('input');
    search.value='Wasp';search.dispatchEvent(new dom.window.Event('input'));
    assert.equal(select('r').value,'W');assert.equal(changes,2);
    search.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));
    assert.equal(d.activeElement,trigger('type'));
    select('type').disabled=true;type('type','s');assert.equal(select('type').value,'TR');
    dom.window.close();delete globalThis.window;delete globalThis.document;
});


test('operators confirm a height unit without project configuration and retain it through reload and saving',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/operator.html','utf8');
    let server, body, count=0;
    const settle=async()=>{for(let i=0;i<18;i++)await new Promise(resolve=>setImmediate(resolve));};
    async function open(recovery){
        const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
        dom.window.eval([...dom.window.document.scripts].find(s=>s.textContent.includes('window.mdbEntryBoot')).textContent);
        const boot=dom.window.mdbEntryBoot;
        boot.can_configure=false;boot.entry_settings={...boot.entry_settings,pole_height_unit:null};
        boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/pdf-progress-stub.mjs')).href;
        server=structuredClone(boot);
        if(recovery)dom.window.localStorage.setItem(boot.storage_key,recovery);
        dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};
        Object.assign(globalThis,{window:dom.window,document:dom.window.document,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
        globalThis.fetch=async(url,options)=>{
            if(options.method!=='GET'){
                body=JSON.parse(options.body);server.transformers[0].rows.push({id:999,...body});server.revision++;
            }
            return {ok:true,json:async()=>structuredClone(server)};
        };
        await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?height-unit='+count++);await settle();return dom;
    }
    let dom=await open(),d=dom.window.document;
    const height=d.querySelector('[name="pole_height"]'),unit=d.getElementById('entry-height-unit');
    assert.equal(unit.value,'');assert.equal(d.getElementById('entry-project-settings').querySelector('a'),null);
    height.value='36';height.dispatchEvent(new dom.window.Event('input',{bubbles:true}));
    assert.equal(d.getElementById('entry-height-unit-note').hidden,false);
    unit.value='ft';unit.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    assert.equal(height.value,'36');assert.equal(d.getElementById('entry-height-unit-note').hidden,true);
    const recovery=dom.window.localStorage.getItem(dom.window.mdbEntryBoot.storage_key);dom.window.close();
    dom=await open(recovery);d=dom.window.document;
    assert.equal(d.getElementById('entry-height-unit').value,'ft');assert.equal(d.querySelector('[name="pole_height"]').value,'36');
    d.getElementById('entry-save-draft').click();await settle();
    assert.equal(body.pole_height_unit,'ft');assert.equal(body.pole_height,36);
    assert.equal(d.getElementById('entry-errors').hidden,true);
    dom.window.close();delete globalThis.window;delete globalThis.document;
});


test('a unique GPS number matches automatically while a date difference remains a warning',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/operator.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1',runScripts:'outside-only'});
    const d=dom.window.document;
    dom.window.eval([...d.scripts].find(script=>script.textContent.includes('window.mdbEntryBoot')).textContent);
    const boot=dom.window.mdbEntryBoot;
    boot.waypoints.forEach(point=>point.recorded_at='2026-10-08T07:46:12Z');
    boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/pdf-progress-stub.mjs')).href;
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({});dom.window.Element.prototype.scrollIntoView=()=>{};
    Object.assign(globalThis,{window:dom.window,document:d,location:dom.window.location,history:dom.window.history,Option:dom.window.Option,localStorage:dom.window.localStorage,ResizeObserver:class{observe(){}}});
    globalThis.fetch=async()=>({ok:true,json:async()=>structuredClone(boot)});
    const settle=async()=>{for(let i=0;i<18;i++)await new Promise(resolve=>setImmediate(resolve));};
    await import(pathToFileURL(path.resolve('public/js/mdb-entry.js')).href+'?gpx-date-confirmation');await settle();
    const enter=(name,value)=>{const element=d.querySelector(`[name="${name}"]`);element.value=value;element.dispatchEvent(new dom.window.Event('change',{bubbles:true}));};
    enter('designation','E');enter('row_date','131222');enter('waypoint_reference','001');
    assert.equal(d.getElementById('entry-gpx-ambiguity').hidden,true);
    assert.match(d.getElementById('entry-waypoint-status').textContent,/Matched to GPX by GPS number/);
    assert.match(d.getElementById('entry-waypoint-status').textContent,/PDF date stays unchanged/);
    boot.waypoints.push({id:999,name:'001',source_file_id:999,recorded_at:'2026-10-08T07:46:12Z'});
    enter('waypoint_reference','001');
    assert.equal(d.getElementById('entry-gpx-ambiguity').hidden,false); // Only unresolved duplicate points need a source choice.
    const source=boot.waypoints.find(point=>point.name==='001').source_file_id;
    enter('gpx_source_id',String(source));
    assert.match(d.getElementById('entry-waypoint-status').textContent,/Matched to GPX by GPS number/);
    assert.equal(d.querySelector('[name="row_date"]').value,'131222');
    const recovery=JSON.parse(dom.window.localStorage.getItem(boot.storage_key));
    assert.equal(recovery.row.gpx_source_id,source);assert.equal(recovery.row.row_date,'2022-12-13');
    dom.window.close();delete globalThis.window;delete globalThis.document;
});


test('transformer identity ignores case, whitespace and alternate dash characters',()=>{
    for(const code of ['T-111', ' t-111 ', 'T - 111', 'T\u2013111'])assert.equal(transformerCodeKey(code),'t-111');
    assert.notEqual(transformerCodeKey('T-0111'),transformerCodeKey('T-111'));
});
