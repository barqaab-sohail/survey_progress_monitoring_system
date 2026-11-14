import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from '../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js';
import {installServiceCableEntry, serviceCableDistance} from '../../public/js/mdb-service-cables.js';
import {isoDate, displayDate, completeWaypoint, waypointMatches} from '../../public/js/mdb-entry.js';

function fixture() {
    const fields = ['entry_row_id','category','gpx_source_id','source_pdf_id','meter_waypoint_id'];
    const inputs = ['consumer_number','group_number','row_date','waypoint_reference','composite_identifier','consumer_reference','meter_number','recorded_phase','conductor_code','measured_length_m','length_evidence','source_page','source_row','remarks'];
    const dom = new JSDOM(`<section id="entry-service-cables"><input type="checkbox" id="service-cables-enabled"><p id="service-cable-status"></p><div id="service-cable-content"><div id="service-cable-coverage"></div><input id="service-cable-search"><select id="service-cable-filter-pole"></select><select id="service-cable-filter-status"><option value=""></option><option value="incomplete">Incomplete</option></select><span id="service-cable-count"></span><button id="service-cable-prev"></button><span id="service-cable-page"></span><button id="service-cable-next"></button><div id="service-cable-list"></div><details id="service-cable-editor"><form id="service-cable-form">${fields.map(name=>`<select name="${name}">${name==='category' ? '<option value="rs">RS</option><option value="rl">RL</option>' : ''}</select>`).join('')}${inputs.map(name=>`<input name="${name}">`).join('')}<span id="service-cable-save-status"></span><button id="service-cable-save-stay" type="submit" value="stay">Save</button><p id="service-cable-coordinates"></p><button type="button" id="service-cable-pole-location">At E</button><button type="submit">Save</button><button type="button" id="service-cable-cancel">Cancel</button></form></details></div></section>`, {url:'http://localhost'});
    Object.assign(globalThis, {window:dom.window,document:dom.window.document,Option:dom.window.Option});
    let state = {revision:1,service_cables_can_enter:true,entry_settings:{two_digit_year_start:2000},
        sources:[{id:1,kind:'gpx',status:'ready',original_name:'Survey.gpx'},{id:2,kind:'pdf',status:'ready',original_name:'Survey.pdf'}],
        waypoints:[{id:26,name:'026',source_file_id:1,latitude:34.1,longitude:73.2}],
        transformers:[{id:1,service_cables_enabled:true,service_cables:[],rows:[{id:2,designation:'E',pair_number:1,group_number:'02',row_date:'2026-10-08',waypoint_reference:'025',gpx_source_id:1,source_pdf_id:2,source_page:1,consumers:{rs:5}}]}]};
    let busy = false, posted = null, reject = false, unconfirmed = false;
    let feature;
    feature = installServiceCableEntry({active:()=>state.transformers[0],data:()=>state,editable:()=>true,isBusy:()=>busy,
        setBusy:value=>{busy=value;feature.setBusy(value);},isoDate,displayDate,completeWaypoint,waypointMatches,
        accept:value=>{state=value;feature.render();},api:async(path,method,body)=>{
            posted = body;
            if(reject)throw new Error('Network unavailable');
            const result=structuredClone(state);
            result.revision++;
            if (!unconfirmed) result.transformers[0].service_cables.push({...body,id:1,composite_identifier:completeWaypoint(body.group_number,body.row_date,body.waypoint_reference)});
            return result;
        }});
    feature.render();
    const form=dom.window.document.getElementById('service-cable-form');
    return {dom,feature,form,field:name=>form.elements.namedItem(name),state:()=>state,posted:()=>posted,reject:()=>{reject=true;},unconfirm:()=>{unconfirmed=true;},
        close:()=>{dom.window.close();delete globalThis.window;delete globalThis.document;delete globalThis.Option;}};
}
const settle = async()=>{for(let i=0;i<8;i++)await new Promise(resolve=>setImmediate(resolve));};

test('meter entry suggests consecutive GPX waypoints and preserves identity and counts on save',async()=>{
    const f=fixture();
    try {
        assert.equal(f.field('entry_row_id').value,'2');
        assert.equal(f.field('group_number').value,'02');
        assert.equal(f.field('row_date').value,'081026');
        assert.equal(f.field('waypoint_reference').value,'026');
        assert.equal(f.field('conductor_code').value,'PVC 7/0.052');
        f.field('waypoint_reference').value='026';
        f.field('waypoint_reference').dispatchEvent(new f.dom.window.Event('input',{bubbles:true}));
        assert.equal(f.field('composite_identifier').value,'02081026026');
        assert.equal(f.field('meter_waypoint_id').value,'26');
        assert.match(f.dom.window.document.getElementById('service-cable-coordinates').textContent,/34.1, 73.2/);
        f.form.dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));
        await settle();
        assert.equal(f.posted().row_date,'2026-10-08');
        assert.equal(f.posted().waypoint_reference,'026');
        assert.equal(f.state().transformers[0].rows[0].consumers.rs,5);
        assert.equal(f.field('consumer_number').value,'2');
        assert.equal(f.field('waypoint_reference').value,'027');
        assert.equal(f.field('meter_waypoint_id').value,'');
        assert.equal(f.field('entry_row_id').value,'2');
        assert.match(f.dom.window.document.getElementById('service-cable-coverage').textContent,/5 consumers; 1 meter waypoints recorded; 4 without/);
    } finally {f.close();}
});

test('a failed save retains the meter draft and a refresh during a busy state retains disabled field values',async()=>{
    const f=fixture();
    try {
        f.field('waypoint_reference').value='026';
        f.field('waypoint_reference').dispatchEvent(new f.dom.window.Event('input',{bubbles:true}));
        f.field('meter_number').value='M-001';
        f.feature.setBusy(true);f.feature.render();
        assert.equal(f.field('meter_number').value,'M-001');
        f.feature.setBusy(false);f.reject();
        f.form.dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));await settle();
        assert.equal(f.field('meter_number').value,'M-001');
        assert.equal(f.field('waypoint_reference').value,'026');
        assert.match(f.dom.window.document.getElementById('service-cable-status').textContent,/Network unavailable/);
    } finally {f.close();}
});

test('revoked access locks controls while keeping existing counts intact',()=>{
    const f=fixture();
    try {
        f.state().service_cables_can_enter=false;f.feature.render();
        assert.equal(f.field('waypoint_reference').disabled,true);
        assert.equal(f.dom.window.document.getElementById('service-cables-enabled').disabled,true);
        assert.equal(f.state().transformers[0].rows[0].consumers.rs,5);
    } finally {f.close();}
});


test('category changes preserve surveyed identity and choose the cable size',()=>{
    const f=fixture();
    try {
        f.field('waypoint_reference').value='030';
        f.field('category').value='rl';
        f.field('category').dispatchEvent(new f.dom.window.Event('change',{bubbles:true}));
        assert.equal(f.field('waypoint_reference').value,'030');
        assert.equal(f.field('conductor_code').value,'PVC 19/0.052');
        assert.equal(f.field('recorded_phase').value,'RYB');
    } finally {f.close();}
});

test('distinct meter waypoints at E coordinates are identified as the same location',()=>{
    const f=fixture();
    try {
        f.state().waypoints.push({id:25,name:'025',source_file_id:1,latitude:34.1,longitude:73.2});
        f.feature.render();
        assert.equal(f.field('meter_waypoint_id').value,'26');
        assert.match(f.dom.window.document.getElementById('service-cable-coordinates').textContent,/Same location as E/);
        f.field('meter_waypoint_id').value='';
        f.dom.window.document.getElementById('service-cable-pole-location').click();
        assert.equal(f.field('meter_waypoint_id').value,'26');
    } finally {f.close();}
});


test('saving under a later E pole keeps that E selected and clears meter-specific details',async()=>{
    const f=fixture();
    try {
        f.state().transformers[0].rows.push({...f.state().transformers[0].rows[0],id:3,waypoint_reference:'214',pair_number:2});
        f.state().waypoints.push({id:215,name:'215',source_file_id:1,latitude:34.2,longitude:73.3});
        f.feature.render();
        f.field('entry_row_id').value='3';
        f.field('entry_row_id').dispatchEvent(new f.dom.window.Event('change',{bubbles:true}));
        assert.equal(f.field('waypoint_reference').value,'215');
        f.field('meter_number').value='M-215';
        f.form.dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));
        await settle();
        assert.equal(f.posted().entry_row_id,3);
        assert.equal(f.field('entry_row_id').value,'3');
        assert.equal(f.field('waypoint_reference').value,'216');
        assert.equal(f.field('meter_number').value,'');
    } finally {f.close();}
});


test('finishing all consumers at an E pole collapses the meter form',async()=>{
    const f=fixture();
    try {
        f.state().transformers[0].rows[0].consumers = {rs:1,rl:0,pv:3};
        const editor = f.dom.window.document.getElementById('service-cable-editor');
        editor.open=true;
        f.form.dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));
        await settle();
        assert.equal(editor.open,false);
        assert.match(f.dom.window.document.getElementById('service-cable-status').textContent,/All consumer meters/);
    } finally {f.close();}
});

test('meter form remains open while the E pole has consumers still to enter',async()=>{
    const f=fixture();
    try {
        const editor = f.dom.window.document.getElementById('service-cable-editor');
        editor.open=true;
        f.form.dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));
        await settle();
        assert.equal(editor.open,true);
        assert.equal(f.field('waypoint_reference').value,'027');
    } finally {f.close();}
});


test('service length preview and saved table show E-to-meter metres and measured overrides',async()=>{
    const f=fixture();
    try {
        f.state().waypoints[0].service_coordinates={x:500030,y:3700040};
        f.state().waypoints.push({id:25,name:'025',source_file_id:1,latitude:34.1,longitude:73.2,service_coordinates:{x:500000,y:3700000}});
        f.feature.render();
        assert.match(f.dom.window.document.getElementById('service-cable-coordinates').textContent,/50.00 m \(coordinate estimate\)/);
        f.form.dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));
        await settle();
        assert.match(f.dom.window.document.getElementById('service-cable-list').textContent,/50.00 m \(coordinate estimate\)/);
        f.state().transformers[0].service_cables[0].measured_length_m=65;
        f.feature.render();
        assert.match(f.dom.window.document.getElementById('service-cable-list').textContent,/65.00 m \(measured\)/);
    } finally {f.close();}
});

test('distance handles coincident points and missing coordinates without guessing',()=>{
    const p={service_coordinates:{x:500000,y:3700000}};
    assert.equal(serviceCableDistance(p,p),0);
    assert.equal(serviceCableDistance(p,null),null);
    assert.equal(serviceCableDistance(p,{service_coordinates:{x:null,y:0}}),null);
});


test('large cable lists paginate and search by meter or E pole without losing draft fields',()=>{
    const f=fixture();
    try {
        f.state().transformers[0].service_cables=Array.from({length:61},(_,i)=>({id:i+1,entry_row_id:2,category:'rs',consumer_number:i+1,composite_identifier:'meter-'+i,meter_number:'M-'+i,phase:i===60 ? '' : 'R'}));
        f.feature.render();
        const doc=f.dom.window.document;
        assert.equal(doc.querySelectorAll('#service-cable-list tbody tr').length,25);
        assert.match(doc.getElementById('service-cable-page').textContent,/Page 1 of 3/);
        f.field('meter_number').value='draft';
        doc.getElementById('service-cable-next').click();
        assert.match(doc.getElementById('service-cable-page').textContent,/Page 2 of 3/);
        assert.equal(f.field('meter_number').value,'draft');
        const search=doc.getElementById('service-cable-search');
        search.value='M-60';search.dispatchEvent(new f.dom.window.Event('input'));
        assert.equal(doc.querySelectorAll('#service-cable-list tbody tr').length,1);
        search.value='';search.dispatchEvent(new f.dom.window.Event('input'));
        doc.getElementById('service-cable-filter-status').value='incomplete';
        doc.getElementById('service-cable-filter-status').dispatchEvent(new f.dom.window.Event('change'));
        assert.equal(doc.querySelectorAll('#service-cable-list tbody tr').length,1);
    } finally {f.close();}
});

test('Save stays on the confirmed cable and shows its identity while the toast does not take focus',async()=>{
    const f=fixture();
    try {
        f.field('meter_number').value='M-026';
        const button=f.dom.window.document.getElementById('service-cable-save-stay');
        f.form.dispatchEvent(new f.dom.window.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:button}));
        await settle();
        assert.equal(f.field('meter_number').value,'M-026');
        assert.equal(f.field('waypoint_reference').value,'026');
        assert.match(f.dom.window.document.getElementById('service-cable-save-status').textContent,/02081026026 saved at/);
        assert.equal(f.dom.window.document.getElementById('entry-save-toast').getAttribute('role'),'status');
        f.field('meter_number').dispatchEvent(new f.dom.window.Event('input',{bubbles:true}));
        assert.equal(f.dom.window.document.getElementById('service-cable-save-status').textContent,'Unsaved changes');
    } finally {f.close();}
});


test('an unconfirmed server response preserves cable values and reports failure instead of success',async()=>{
    const f=fixture();
    try {
        f.unconfirm();f.field('meter_number').value='KEEP-ME';
        f.form.dispatchEvent(new f.dom.window.Event('submit',{bubbles:true,cancelable:true}));
        await settle();
        assert.equal(f.field('meter_number').value,'KEEP-ME');
        assert.equal(f.field('waypoint_reference').value,'026');
        assert.match(f.dom.window.document.getElementById('service-cable-status').textContent,/did not confirm/);
        assert.equal(f.dom.window.document.getElementById('entry-save-toast').dataset.state,'error');
    } finally {f.close();}
});
