import test from 'node:test';
import assert from 'node:assert/strict';
import {paperRows, sheetColumns} from '../../public/js/mdb-verification-sheet.js';

const row=(id,page,source=1)=>({id,designation:id%2 ? 'S' : 'E',data:{source_pdf_id:source,source_page:page},fields:[
    {path:'group_number',value:'01',key:`row.${id}.group_number`},
    {path:'row_date',value:'2026-10-08',key:`row.${id}.row_date`},
    {path:'waypoint_reference',value:'018',key:`row.${id}.waypoint_reference`},
    {path:'conductors.R',value:'W'}, {path:'conductors.Y',value:'__none__'}, {path:'conductors.B',value:null},
]});

test('paper rows follow S/E order and include only the selected source and page',()=>{
    const pairs=[{number:1,rows:{E:row(2,1),S:row(1,1)}},{number:2,rows:{S:row(3,1),E:row(4,2)}},{number:3,rows:{S:row(5,1,2)}}];
    const sheet=paperRows(pairs,'1',1,field=>`<span>${field.path}</span>`,0);
    assert.deepEqual(sheet.rows.map(({row})=>row.id),[1,2,3]);
    assert.match(sheet.html,/paper-phase[^>]*>R</);
    assert.equal((sheet.html.match(/data-sheet-row=/g)||[]).length,3);
    assert.equal(sheetColumns.length,22);
    assert.deepEqual(paperRows(pairs,1,2,()=>'',0).rows.map(({row})=>row.id),[4]);
    assert.equal(paperRows(pairs,1,9,()=>'',0).rows.length,0);
});

test('PV details stay tied to their saved pair and row on the displayed page',()=>{
    const saved=row(1,1);
    saved.fields.push({path:'pv_details.0.reference',group:'PV',value:'Consumer 01'});
    saved.fields.push({path:'pv_details.0.meter_number',group:'PV',value:'001-AB/09'});
    const html=paperRows([{number:7,rows:{S:saved}}],1,1,field=>field.value,0).html;
    assert.match(html,/data-sheet-pair="7"/);
    assert.match(html,/Consumer 01/);
    assert.match(html,/001-AB\/09/);
    assert.match(html,/paper-pv-value/);
});
