// Isolated browser fixture. Never signs in to Laravel or generates real export history.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium} from '../../tmp/feeder-kmz-qa/node_modules/playwright-core/index.mjs';

const features = [
    {type:'Feature',properties:{name:'HT transformer',layer:'ht',layer_label:'Complete HT network',kind:'ht',details:{Duty:'General Duty'}},geometry:{type:'Point',coordinates:[73.25,34.16]}},
    {type:'Feature',properties:{name:'LT pole',layer:'lt-1',layer_label:'LT - T001',kind:'lt',details:{'Pole height':'36 ft'}},geometry:{type:'Point',coordinates:[73.251,34.161]}},
    {type:'Feature',properties:{name:'LT line',layer:'lt-1',layer_label:'LT - T001',kind:'lt',details:{Length:'44.35 m',Location:'<script>bad()</script>'}},geometry:{type:'LineString',coordinates:[[73.25,34.16],[73.251,34.161]]}},
];
const html = `<!doctype html><html><head><link rel="stylesheet" href="/vendor/leaflet/leaflet.css"><style>body{font:16px Arial;padding:20px}[data-map-canvas]{height:480px}fieldset label{display:block;margin:10px}.feeder-map-popup{max-height:300px;overflow:auto}</style></head><body><h1>Feeder preview browser check</h1><section id="fixture" data-url="/preview"><button data-map-load>Load map</button><button data-map-fit disabled>Fit networks</button><p data-map-status></p><fieldset data-map-layers></fieldset><div data-map-canvas></div></section><script src="/vendor/leaflet/leaflet.js"></script><script type="module">import {installFeederMap} from '/js/feeder-kmz-preview.js'; window.qaMap=installFeederMap(document.getElementById('fixture'));</script></body></html>`;
const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
try {
    const context = await browser.newContext({viewport:{width:1280,height:900}});
    let fail = false;
    await context.route('**/*',async route=>{
        const url=new URL(route.request().url());
        if(url.hostname==='tile.openstreetmap.org') return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5ioAAAAASUVORK5CYII=','base64')});
        if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:html});
        if(url.pathname==='/preview') return route.fulfill(fail?{status:422,json:{message:'HT source unavailable'}}:{json:{type:'FeatureCollection',features,counts:{total_transformers:161,approved_lt:1},warnings:[]}});
        const file=path.resolve('public','.'+url.pathname);
        if(file.startsWith(path.resolve('public')+path.sep)) {
            try{return route.fulfill({body:await fs.readFile(file),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});}catch{}
        }
        return route.fulfill({status:404,body:'Not in test fixture'});
    });
    const page=await context.newPage(), errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto('http://feeder-map.test/');
    await page.locator('[data-map-load]').click();
    await page.waitForFunction(()=>document.querySelector('[data-map-status]').textContent.includes('3 map features loaded'));
    assert.equal(await page.locator('[data-map-layers] input').count(),2);
    assert.equal(await page.locator('[data-map-fit]').isEnabled(),true);
    const countVisible=()=>page.evaluate(()=>{let count=0;window.qaMap.map.eachLayer(layer=>{if(layer.feature)count++;});return count;});
    assert.equal(await countVisible(),3);
    await page.locator('[data-map-layers] input').nth(1).uncheck();
    assert.equal(await countVisible(),1);
    await page.locator('[data-map-layers] input').nth(1).check();
    assert.equal(await countVisible(),3);
    await page.evaluate(()=>window.qaMap.map.eachLayer(layer=>{if(layer.feature?.properties.name==='LT line')layer.openPopup();}));
    assert.match(await page.locator('.leaflet-popup-content').textContent(),/44.35 m/);
    assert.equal(await page.locator('.leaflet-popup-content script').count(),0);
    await page.setViewportSize({width:390,height:844});
    await page.locator('[data-map-fit]').click();
    fail=true; await page.locator('[data-map-load]').click();
    await page.waitForFunction(()=>document.querySelector('[data-map-status]').textContent.includes('HT source unavailable'));
    assert.equal(await page.locator('[data-map-load]').isEnabled(),true);
    assert.equal(await countVisible(),3); // A failed refresh retains the last successful map.
    assert.deepEqual(errors,[]);
    console.log('PASS: map renders, HT/LT switches work, details stay safe, mobile fit and retry work.');
} finally {await browser.close();}
