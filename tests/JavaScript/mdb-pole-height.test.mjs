import test from 'node:test';
import assert from 'node:assert/strict';
import {rowValidationErrors} from '../../public/js/mdb-entry.js';
test('PDF pole height permits 30 through 10000 feet and rejects values outside',()=>{
 for (const height of [0,29.99,30,10000,10000.01]) {
  const errors=rowValidationErrors({pole_height:height});
  assert.equal(Boolean(errors.pole_height),height<30 || height>10000);
 }
 assert.equal(Boolean(rowValidationErrors({pole_height:null}).pole_height),false);
});
