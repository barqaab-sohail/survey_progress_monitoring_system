import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from '../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js';
import {installServiceCableEntry} from '../../public/js/mdb-service-cables.js';
import {isoDate, displayDate, completeWaypoint, waypointMatches} from '../../public/js/mdb-entry.js';

function fixture() {
    const fields = ['entry_row_id','category','gpx_source_id','source_pdf_id','meter_waypoint_id'];
    const inputs = ['consumer_number','group_number','row_date','waypoint_reference','composite_identifier','consumer_reference','meter_number','recorded_phase','conductor_code','measured_length_m','length_evidence','source_page','source_row','remarks'];
    const dom = new JSDOM(`<section id="entry-service-cables"><input type="checkbox" id="service-cables-enabled"><p id="service-cable-status"></p><div id="service-cable-content"><div id="service-cable-coverage"></div><div id="service-cable-list"></div><details id="service-cable-editor"><form id="service-cable-form">${fields.map(name=>`<select name="${name}">${name==='category' ? '<option value="rs">RS</option><option value="rl">RL</option>' : ''}</select>`).join('')}${inputs.map(name=>`<input name="${name}">`).join('')}<p id="service-cable-coordinates"></p><button type="submit">Save</button><button type="button" id="service-cable-cancel">Cancel</button></form></details></div></section>`, {url:'http://localhost'});
    Object.assign(globalThis, {window:dom.window,document:dom.window.document,Option:dom.window.Option});
    let state = {revision:1,service_cables_can_enter:true,entry_settings:{two_digit_year_start:2000},
        sources:[{id:1,kind:'gpx',status:'ready',original_name:'Survey.gpx'},{id:2,kind:'pdf',status:'ready',original_name:'Survey.pdf'}],
        waypoints:[{id:26,name:'026',source_file_id:1,latitude:34.1,longitude:73.2}],
        transformers:[{id:1,service_cables_enabled:true,service_cables:[],rows:[{id:2,designation:'E',pair_number:1,group_number:'02',row_date:'2026-10-08',waypoint_reference:'025',gpx_source_id:1,source_pdf_id:2,source_page:1,consumers:{rs:5}}]}]};
    let busy = false, posted = null, reject = false;
    let feature;
    feature = installServiceCableEntry({active:()=>state.transformers[0],data:()=>state,editable:()=>true,isBusy:()=>busy,
        setBusy:value=>{busy=value;feature.setBusy(value);},isoDate,displayDate,completeWaypoint,waypointMatches,
        accept:value=>{state=value;feature.render();},api:async(path,method,body)=>{
            posted = body;
            if(reject)throw new Error('Network unavailable');
            const result=structuredClone(state);
            result.revision++;
            result.transformers[0].service_cables.push({...body,id:1,composite_identifier:completeWaypoint(body.group_number,body.row_date,body.waypoint_reference)});
            return result;
        }});
    feature.render();
    const form=dom.window.document.getElementById('service-cable-form');
    return {dom,feature,form,field:name=>form.elements.namedItem(name),state:()=>state,posted:()=>posted,reject:()=>{reject=true;},
        close:()=>{dom.window.close();delete globalThis.window;delete globalThis.document;delete globalThis.Option;}};
}
const settle = async()=>{for(let i=0;i<8;i++)await new Promise(resolve=>setImmediate(resolve));};

test('meter entry uses the E pole but does not invent its next waypoint, and preserves identity and counts on save',async()=>{
    const f=fixture();
    try {
        assert.equal(f.field('entry_row_id').value,'2');
        assert.equal(f.field('group_number').value,'02');
        assert.equal(f.field('row_date').value,'081026');
        assert.equal(f.field('waypoint_reference').value,'');
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
