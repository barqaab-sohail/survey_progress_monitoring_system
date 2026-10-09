import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import path from 'node:path';
import {derivePhase, consumerTotal, nextDraft, completeWaypoint, parseWaypoint, displayDate, waypointMatches, convertPoleHeight} from '../../public/js/mdb-entry.js';

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
});

test('rendered operator DOM retains drafts across pages/reload, saves once, copies explicit scopes and isolates transformers', async () => {
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile(new URL('../../tmp/mdb-entry-qa/operator.html',import.meta.url),'utf8');
    const stubPath=path.resolve('tmp/mdb-entry-qa/pdf-stub.mjs');
    await fs.writeFile(stubPath,`export const GlobalWorkerOptions={};export function getDocument(){return {destroy(){},promise:Promise.resolve({numPages:14,destroy:async()=>{},getPage:async()=>({getViewport:({scale,rotation})=>({width:(rotation%180 ? 595 : 842)*scale,height:(rotation%180 ? 842 : 595)*scale}),render:()=>({promise:Promise.resolve(),cancel(){}})})})};}`);
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
    assert.equal(server.posts.find(p=>p.url.includes('/rows')).body.revision,revisionBefore+1);
    assert.equal(d.querySelector('[name="consumers[rs]"]').value,'');assert.equal(d.querySelector('[name="intersection"]').checked,false);assert.equal(d.querySelectorAll('.entry-pv-row').length,0);assert.equal(d.querySelector('[name="conductors[R]"]').value,'');
    assert.match(d.getElementById('entry-group-date-status').textContent,/inherited/);
    const olderDate=d.querySelector('[name="row_date"]');olderDate.value='13/12/2022';olderDate.dispatchEvent(new second.window.Event('input',{bubbles:true}));
    const gpsNumber=d.querySelector('[name="waypoint_reference"]');gpsNumber.value='002';gpsNumber.dispatchEvent(new second.window.Event('input',{bubbles:true}));
    assert.equal(d.getElementById('entry-composite').value,'01131222002');assert.equal(d.getElementById('entry-group-date-status').textContent,'');
    assert.equal(second.window.mdbEntryBoot.transformers[0].header.survey_date,'2026-10-08');
    d.querySelector('[name="consumers[rs]"]').value='5';d.querySelector('[name="intersection"]').checked=true;
    d.getElementById('entry-copy-both').click();assert.equal(d.querySelector('[name="conductors[R]"]').value,'A');assert.equal(d.querySelector('[name="consumers[rs]"]').value,'5');assert.equal(d.querySelector('[name="intersection"]').checked,true);assert.match(d.getElementById('entry-conductor-copy-status').textContent,/Explicitly copied/);
    d.querySelector('[data-entry-add-transformer]').click();assert.equal(d.getElementById('entry-transformer').value,'');assert.equal(d.querySelector('[name="conductors[R]"]').value,'');assert.equal(d.querySelector('[name="code"]').value,'');assert.equal(d.querySelector('[name="consumers[rs]"]').value,'');assert.equal(d.querySelector('[name="intersection"]').checked,false);
    second.window.close();delete globalThis.window;delete globalThis.document;
});
