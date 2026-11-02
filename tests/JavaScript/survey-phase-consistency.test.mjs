import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {conductorAvailability, phaseConsistencyIssues, phaseEntryState, updatePhaseFields} from '../../public/js/survey-phase-consistency.js';
import {initializeVerification} from '../../public/js/mdb-verification.js';

const start = {pair_number:1, designation:'S', waypoint_reference:'020', conductors:{R:'',Y:'A',B:'W',N:'A'}};
const end = {pair_number:1, designation:'E', waypoint_reference:'021', conductors:{R:'A'}, consumer_phases:{rs:{R:2,Y:1,B:0}}};

test('source S, not E or another pair, controls phase-wise customer consistency',()=>{
    const rows=[structuredClone(start),structuredClone(end),{pair_number:2,designation:'S',conductors:{R:'A'}}];
    const original=structuredClone(rows), issues=phaseConsistencyIssues(rows,'T-11230617182');
    assert.equal(issues.length,1);
    assert.match(issues[0].message,/T-11230617182, pair 1 E 021.*RS R-phase customer count 2/);
    assert.deepEqual(rows,original);
    assert.ok(phaseEntryState(end,[start],'T-test').errors['consumer_phases.rs.R']);
});

test('incomplete drafts and zero counts remain valid; removing an existing conductor is blocked',()=>{
    assert.equal(conductorAvailability(null),null);
    for(const value of ['', '__none__', '-', 'x'])assert.equal(conductorAvailability(value),false);
    const unknown={...start,conductors:{R:null}};
    assert.equal(phaseConsistencyIssues([unknown,end]).length,0);
    assert.equal(phaseConsistencyIssues([end]).length,0);
    assert.deepEqual(phaseEntryState({...end,consumer_phases:{rs:{R:0,Y:null,B:null}}},[start]).errors,{});
    const previous={...start,conductors:{R:'A'}};
    assert.ok(phaseEntryState(unknown,[previous,end]).errors['conductors.R']);
    assert.ok(phaseEntryState(start,[previous,end]).errors['conductors.R']);
});

test('available fields enable; unavailable existing values remain visible and clear only by explicit action',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const dom=new JSDOM(`<form><label>R<input name="consumer_phases[rs][R]" value="2"></label><label>Y<input name="consumer_phases[rs][Y]" value="1"></label><label>B<input name="consumer_phases[rs][B]"></label></form>`);
    const form=dom.window.document.querySelector('form'), input=phase=>form.elements.namedItem(`consumer_phases[rs][${phase}]`);
    let inputEvents=0; form.addEventListener('input',()=>inputEvents++);
    updatePhaseFields(form,end,[start],'T-test');
    assert.equal(input('R').value,'2');assert.equal(input('R').readOnly,true);assert.equal(input('Y').readOnly,false);assert.equal(input('B').disabled,false);
    assert.match(form.querySelector('[role=alert]').textContent,/R-phase customer count 2/);
    assert.match(input('R').parentElement.textContent,/missing R conductor/);
    updatePhaseFields(form,end,[{...start,conductors:{R:'A',Y:'A',B:'__none__'}}],'T-test');
    assert.equal(input('R').readOnly,false);assert.equal(input('B').disabled,true);assert.equal(input('R').value,'2');
    updatePhaseFields(form,end,[start],'T-test');
    input('R').parentElement.querySelector('button').click();
    assert.equal(input('R').value,'');assert.equal(inputEvents,1);assert.equal(end.consumer_phases.rs.R,2);
    dom.window.close();
});

test('verification correction displays phase values and blocks invalid saves before sending a request',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/verifier-workspace.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1/review',runScripts:'outside-only'});
    const document=dom.window.document;
    dom.window.eval([...document.scripts].find(script=>script.textContent.includes('window.mdbVerificationBoot')).textContent);
    const boot=dom.window.mdbVerificationBoot, pair=boot.pairs[0];
    pair.rows.S.data.conductors.R=''; pair.rows.S.data.conductors.Y='A';
    pair.rows.E.data.consumer_phases={rs:{R:2,Y:null,B:null}};
    for(const phase of ['R','Y','B'])pair.rows.E.fields.push({key:`row.${pair.rows.E.id}.consumer_phases.rs.${phase}`,path:`consumer_phases.rs.${phase}`,label:`RS ${phase} phase customers`,value:phase==='R' ? 2 : null,type:'number',editable:true,group:'Consumers',hash:'a'.repeat(64)});
    dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
    dom.window.HTMLDialogElement.prototype.close=function(){this.open=false;};
    Object.assign(globalThis,{window:dom.window,document});
    const oldFetch=globalThis.fetch;let requests=0;globalThis.fetch=async()=>{requests++;throw new Error('Unexpected request');};
    try {
        initializeVerification(boot);
        document.querySelector('[data-edit-row="E"]').click();
        const input=document.querySelector('[data-edit-path="consumer_phases.rs.R"]');
        assert.equal(input.value,'2');assert.equal(input.readOnly,true);
        assert.match(input.parentElement.textContent,/R-phase customer count 2/);
        document.getElementById('verification-correction-reason').value='Checked PDF source';
        document.getElementById('verification-edit-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(requests,0);assert.equal(input.value,'2');
        assert.match(document.getElementById('verification-editor-errors').textContent,/R-phase customer count 2/);
    } finally {globalThis.fetch=oldFetch;delete globalThis.window;delete globalThis.document;dom.window.close();}
});
