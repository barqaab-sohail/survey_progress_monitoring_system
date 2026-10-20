import test from 'node:test';
import assert from 'node:assert/strict';
import {consumerCounts, consumerTotal, consumerSummary, rowValidationErrors, nextDraft} from '../../public/js/mdb-entry.js';

test('R/Y/B counts update only single-phase categories and each contributes once to the overall total',()=>{
    const phases={rs:{R:5,Y:2,B:7},sc:{R:0,Y:null,B:3},si:{R:null,Y:null,B:null},st:{R:1,Y:null,B:0}};
    const counts=consumerCounts({rs:999,sc:999,rl:4,lc:5,li:6,pb:7,ag:8,pv:2},phases);
    assert.deepEqual(counts,{rs:14,sc:3,si:null,st:1,rl:4,lc:5,li:6,pb:7,ag:8,pv:2});
    assert.equal(consumerTotal(counts),50);
    assert.equal(consumerTotal({rs:999,sc:999,rl:4,pv:2},phases),24);
    assert.equal(consumerCounts({rl:4,pv:2},{rl:{R:1,Y:2,B:3},pv:{R:9,Y:9,B:9}}).rl,4);
});

test('all blanks remain unknown; explicit zero and partial allocation survive JSON reload and editing',()=>{
    const phases=JSON.parse(JSON.stringify({rs:{R:null,Y:null,B:null},sc:{R:0,Y:null,B:null},st:{R:0,Y:0,B:0}}));
    assert.deepEqual(consumerCounts({},phases),{rs:null,sc:0,st:0});
    phases.rs={R:5,Y:2,B:7};
    assert.equal(consumerCounts({},phases).rs,14);
    phases.rs.B=null;
    assert.equal(consumerCounts({},phases).rs,7);
    phases.rs={R:null,Y:null,B:null};
    assert.equal(consumerCounts({rs:14},phases).rs,null);
});

test('legacy totals remain unallocated and summaries show recorded phases without converting blanks to zero',()=>{
    assert.deepEqual(consumerCounts({rs:14,st:0}),{rs:14,st:0});
    assert.equal(consumerSummary({consumers:{rs:14}}),'RS: 14');
    assert.equal(consumerSummary({consumers:{st:0},consumer_phases:{st:{R:0,Y:null,B:null}}}),'ST: 0 (R=0, Y=blank, B=blank)');
    assert.equal(consumerSummary({consumers:{rs:14},original_entry:{consumer_phases:{rs:{R:5,Y:2,B:7}}}}),'RS: 14 (R=5, Y=2, B=7)');
});

test('phase validation rejects negatives, fractions and oversized totals',()=>{
    for(const value of [-1,1.5,1000001]) {
        assert.ok(rowValidationErrors({...nextDraft(),consumer_phases:{rs:{R:value,Y:null,B:null}}})['consumer_phases.rs.R']);
    }
    assert.ok(rowValidationErrors({...nextDraft(),consumer_phases:{rs:{R:1000000,Y:1,B:null}}})['consumer_phases.rs']);
    assert.deepEqual(rowValidationErrors({...nextDraft(),consumer_phases:{rs:{R:0,Y:null,B:7}}}),{});
});
