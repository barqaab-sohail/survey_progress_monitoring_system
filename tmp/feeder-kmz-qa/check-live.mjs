import {chromium} from './node_modules/playwright-core/index.mjs';
import fs from 'node:fs/promises';
import path from 'node:path';
const html=await fs.readFile('tmp/feeder-kmz-qa/live-preview.html','utf8');
const payload=JSON.parse(await fs.readFile('tmp/feeder-kmz-qa/live-preview.json','utf8'));
const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
try {
 const context=await browser.newContext({viewport:{width:1500,height:1000},serviceWorkers:'block'});
 await context.route('**/*',async route=>{
  const url=new URL(route.request().url());
  if(url.hostname==='tile.openstreetmap.org')return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5ioAAAAASUVORK5CYII=','base64')});
  if(url.pathname==='/feeder-kmz/62/preview')return route.fulfill({json:payload});
  if(url.pathname==='/feeder-kmz')return route.fulfill({contentType:'text/html',body:html});
  const file=path.resolve('public','.'+url.pathname);
  if(file.startsWith(path.resolve('public')+path.sep)){try{return route.fulfill({body:await fs.readFile(file),contentType:({'.js':'text/javascript','.css':'text/css','.png':'image/png','.jpg':'image/jpeg'})[path.extname(file)]||'application/octet-stream'});}catch{}}
  return route.fulfill({status:404,body:'Not in rendered fixture'});
 });
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://localhost/feeder-kmz?feeder_id=62');
 await page.locator('[data-map-load]').click();
 await page.waitForFunction(()=>document.querySelector('[data-map-status]').textContent.includes('699 map features loaded'));
 await page.locator('[data-feeder-map]').scrollIntoViewIfNeeded();
 await page.screenshot({path:'tmp/feeder-kmz-qa/desktop-preview.png'});
 const desktopOverflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
 await page.setViewportSize({width:390,height:844});
 await page.locator('[data-map-fit]').click();
 await page.waitForFunction(()=>document.querySelector('.sidebar').getBoundingClientRect().right <= 0);
 await page.screenshot({path:'tmp/feeder-kmz-qa/mobile-preview.png'});
 console.log(JSON.stringify({overflowElements:await page.evaluate(()=>[...document.querySelectorAll('body *')].map(e=>({tag:e.tagName,cls:e.className,right:Math.round(e.getBoundingClientRect().right),width:Math.round(e.getBoundingClientRect().width)})).filter(e=>e.right>innerWidth+5).slice(0,12)),dimensions:await page.evaluate(()=>({doc:document.documentElement.scrollWidth,body:document.body.scrollWidth,viewport:innerWidth,wrappers:[...document.querySelectorAll('.table-wrap')].map(e=>({w:e.clientWidth,scroll:e.scrollWidth,overflow:getComputedStyle(e).overflowX})),sidebar:getComputedStyle(document.querySelector('.sidebar')).transform})),errors,desktopOverflow,mobileOverflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),layerControls:await page.locator('[data-map-layers] input').count()}));
}finally{await browser.close();}
