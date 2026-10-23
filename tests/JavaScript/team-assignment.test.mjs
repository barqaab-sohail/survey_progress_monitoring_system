import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

test('member dropdown follows team type, clears incompatible users and retains eligible additional roles',async()=>{
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/team-assignment.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/legacy-admin/teams',runScripts:'outside-only'});
    try{
        const d=dom.window.document,type=d.getElementById('member-type'),user=d.getElementById('member-user');
        const all=[...user.options];
        const survey=all.find(option=>option.dataset.teamTypes==='survey').value;
        const mdb=all.find(option=>option.dataset.teamTypes==='mdb').value;
        const dual=all.find(option=>option.dataset.teamTypes==='survey mdb').value;
        const viewer=all.find(option=>option.dataset.teamTypes==='').value;
        const options=()=>[...user.options].map(option=>option.value);
        const script=[...d.scripts].find(script=>script.textContent.includes('const memberType'));
        dom.window.eval(script.textContent);
        assert.ok(options().includes(survey));assert.ok(options().includes(dual));
        assert.ok(!options().includes(mdb));assert.ok(!options().includes(viewer));
        user.value=survey;
        type.value='mdb';type.dispatchEvent(new dom.window.Event('change'));
        assert.equal(user.value,'');assert.ok(options().includes(mdb));
        assert.ok(!options().includes(survey));assert.ok(!options().includes(viewer));
        assert.equal(d.getElementById('member-leader').disabled,true);
        user.value=mdb;
        type.value='survey';type.dispatchEvent(new dom.window.Event('change'));
        assert.equal(user.value,'');assert.equal(d.getElementById('member-leader').disabled,false);
        user.value=dual;
        type.value='mdb';type.dispatchEvent(new dom.window.Event('change'));
        assert.equal(user.value,dual);
        assert.equal(d.getElementById('member-team').value,'');
    }finally{dom.window.close();}
});
