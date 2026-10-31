// Render the actual Blade response from SQLite tests. All browser traffic is intercepted.
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import {chromium} from '../../tmp/assignment-qa/node_modules/playwright-core/index.mjs';

const origin = 'https://audit-ui.test';
const browser = await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe', headless:true});
const context = await browser.newContext({viewport:{width:1280,height:1000},serviceWorkers:'block'});
const html = (await fs.readFile('output/audit-review/web-preview.html','utf8')).replaceAll('http://localhost',origin);
const errors = [], requests = [];
let finishing = false, failureMode = false, releaseFirst, releaseSecond, seenFirst, seenSecond;
const firstSeen = new Promise(r => seenFirst=r), secondSeen = new Promise(r => seenSecond=r);
const firstGate = new Promise(r => releaseFirst=r), secondGate = new Promise(r => releaseSecond=r);
await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (request.method() === 'POST' && url.pathname === '/legacy-admin/audit-log/clear') {
        if (failureMode) return route.fulfill({status:422,json:{errors:{cleanup:['A verified backup is required.']}}});
        requests.push(url.pathname);
        if (requests.length === 1) {
            seenFirst(); await firstGate;
            return route.fulfill({json:{processed:2,cleared:2,planned:3,skipped:0,completed:false}});
        }
        seenSecond(); await secondGate; finishing=true;
        return route.fulfill({json:{processed:3,cleared:3,planned:3,skipped:0,completed:true,message:'3 audit records cleared. Cleanup complete.'}});
    }
    if (request.method() !== 'GET') return route.fulfill({status:409,body:'No real writes permitted in this UI check.'});
    if (url.pathname === '/legacy-admin/audit-log') return route.fulfill({contentType:'text/html',body:finishing?'<h1>Cleanup complete</h1>':html});
    const local = path.resolve('public','.'+url.pathname);
    if (local.startsWith(path.resolve('public')+path.sep)) {
        try {return route.fulfill({body:await fs.readFile(local),contentType:({'.css':'text/css','.js':'text/javascript','.png':'image/png','.svg':'image/svg+xml','.ico':'image/x-icon'})[path.extname(local)] || 'application/octet-stream'});} catch {}
    }
    return route.fulfill({status:404,body:'Unavailable fixture asset'});
});
const page = await context.newPage(); page.on('pageerror', error=>errors.push(error.message));
await page.goto(origin+'/legacy-admin/audit-log');
await page.screenshot({path:'output/audit-review/web-desktop.png',fullPage:true,animations:'disabled'});
await page.setViewportSize({width:390,height:844});
await page.waitForFunction(()=>document.querySelector('.sidebar').getBoundingClientRect().right <= 0);
assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth),true,'Mobile document must not overflow horizontally');
await page.screenshot({path:'output/audit-review/web-mobile.png',fullPage:true,animations:'disabled'});
await page.setViewportSize({width:1280,height:1000});
await page.locator('#audit-clear-confirmation').fill('CLEAR LOGS');
await page.locator('#audit-clear-password').fill('Admin-password-123');
await page.locator('[name=backup_confirmed]').check();
await page.locator('#audit-clear-button').click();
await firstSeen;
assert.equal(await page.locator('#audit-clear-button').isDisabled(),true);
assert.equal(await page.locator('#audit-cleanup-scope').isDisabled(),true);
assert.match(await page.locator('#audit-clear-status').innerText(),/Verifying/);
releaseFirst(); await secondSeen;
assert.equal(await page.locator('#audit-clear-progress').getAttribute('value'),'2');
releaseSecond(); await page.getByRole('heading',{name:'Cleanup complete'}).waitFor();
assert.equal(requests.length,2); assert.deepEqual(errors,[]);
finishing=false; failureMode=true;
await page.goto(origin+'/legacy-admin/audit-log');
await page.locator('#audit-clear-confirmation').fill('CLEAR LOGS');
await page.locator('#audit-clear-password').fill('Admin-password-123');
await page.locator('[name=backup_confirmed]').check();
await page.locator('#audit-clear-button').click();
await page.waitForFunction(()=>document.getElementById('audit-clear-status').textContent.includes('A verified backup is required.'));
assert.equal(await page.locator('#audit-clear-button').isEnabled(),true);
assert.equal(await page.locator('#audit-cleanup-scope').isEnabled(),true);
assert.equal(await page.locator('#audit-clear-password').inputValue(),'');
await fs.writeFile('output/audit-review/web-ui-results.json',JSON.stringify({actual_blade:true,desktop:true,mobile_sidebar_collapsed:true,mobile_no_horizontal_overflow:true,loading_indicator:true,batch_progress:true,completed_redirect:true,visible_backup_error:true,retry_enabled:true,password_cleared:true,all_http_intercepted:true,requests:requests.length,errors},null,2));
await browser.close();
console.log('Audit UI checks passed: desktop, mobile, loading indicator, two batches, progress and completed redirect. No real writes.');
