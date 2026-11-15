const {chromium}=require('./feeder-kmz-qa/node_modules/playwright-core');
const fs=require('fs');const assert=require('assert/strict');
(async()=>{
const browser=await chromium.launch({headless:true,channel:'chrome'});const page=await browser.newPage({viewport:{width:1600,height:1000}});
let html=fs.readFileSync('tmp/field-correction-preview.html','utf8').replaceAll('http://localhost/','http://127.0.0.1:8000/');
html=html.replace(/<script src="[^"]*field-survey-correction.js[^>]*><\/script>/,'');
await page.setContent(html,{waitUntil:'networkidle'});
await page.evaluate(()=>{
 const form=document.getElementById('field-correction-form'), hidden=document.getElementById('field-correction-payload'),data=JSON.parse(hidden.value);
 const original=form.querySelector('[data-row-index="0"]');let previous=form.querySelector('[data-row-index="3"]');
 for(let i=4;i<25;i++){
  const row=original.cloneNode(true);row.dataset.rowIndex=String(i);row.id=`survey-row-${i}`;row.open=false;
  row.querySelector('summary strong').textContent=`Row ${i+1} · S`;
  row.querySelectorAll('[data-field]').forEach(input=>{input.dataset.field=input.dataset.field.replace('rows.0.',`rows.${i}.`);});
  row.querySelector(`[data-field="rows.${i}.gps_waypoint"]`).value=String(i).padStart(3,'0');
  previous.after(row);previous=row;data.rows.push(structuredClone(data.rows[0]));
  document.getElementById('field-row-jump').add(new Option(`Row ${i+1}`,String(i)));
 }
 hidden.value=JSON.stringify(data);
 form.addEventListener('submit',event=>event.preventDefault());
});
await page.addScriptTag({content:fs.readFileSync('public/js/field-survey-correction.js','utf8')});
assert.equal(await page.locator('[data-row-index]:visible').count(),10);
await page.selectOption('#field-row-jump','20');
await page.locator('[data-field="rows.20.gps_waypoint"]').fill('888');
await page.click('#field-rows-prev');await page.click('#field-rows-next');
assert.equal(await page.locator('[data-field="rows.20.gps_waypoint"]').inputValue(),'888');
await page.fill('#field-row-search','888');assert.equal(await page.locator('[data-row-index]:visible').count(),1);
await page.locator('.field-row-tools button[type="submit"]').click();
const data=JSON.parse(await page.locator('#field-correction-payload').inputValue());
assert.equal(data.rows.length,25);assert.equal(data.rows[20].gps_waypoint,'888');
console.log('Passed: 25-row pagination, jump, search, edits retained and all rows serialized on save.');
await browser.close();
})();
