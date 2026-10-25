// No application server or production access: rendered SQLite fixture, intercepted HTTP only.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium} from '../../tmp/assignment-qa/node_modules/playwright-core/index.mjs';

const fixture = (await fs.readFile('tmp/assignment-qa/survey-sync.html', 'utf8'))
    .replaceAll('http://localhost', 'http://survey-sync.test')
    .replace(/<script[^>]*src="[^"]*livewire[^>]*><\/script>/g, '')
    .replace(/if \('serviceWorker' in navigator\)[^\n]*/g, '');
const initial = JSON.parse(fixture.match(/<script type="application\/json" id="survey-sync-boot">([\s\S]*?)<\/script>/)[1]);
const browser = await chromium.launch({executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless:true});
try {
    const context = await browser.newContext({viewport:{width:1440,height:1000}, serviceWorkers:'block'});
    const errors = []; let posts = 0, polls = 0, releasePost;
    await context.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname === '/survey-progress/sync' && request.method() === 'POST') {
            posts++; assert.match(request.postData(), /_token/);
            await new Promise(resolve => {releasePost = resolve;});
            return route.fulfill({status:202, json:{...initial, run:{id:42,type:'sync',status:'queued',active:true,metrics:{},issues:[]}}});
        }
        if (url.pathname === '/survey-progress/sync-status') {
            polls++;
            return route.fulfill({json:{...initial, run:{id:42,type:'sync',status:'running',active:true,phase:'Scanning 407-NAWAN',issues:[],metrics:{files_scanned:20,reported_transformers:50,feeders_updated:2,folders_total:4,folders_processed:2}}}});
        }
        if (url.pathname === '/survey-progress') return route.fulfill({contentType:'text/html',body:fixture});
        const file = path.resolve('public', '.' + url.pathname);
        if (file.startsWith(path.resolve('public') + path.sep)) {
            try {return route.fulfill({body:await fs.readFile(file),contentType:({'.js':'text/javascript','.css':'text/css','.png':'image/png'})[path.extname(file)] || 'application/octet-stream'});} catch {}
        }
        return route.fulfill({status:404,body:'Not in isolated fixture'});
    });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://survey-sync.test/survey-progress');
    await page.getByRole('button', {name:'Synchronize now',exact:true}).click();
    await page.waitForFunction(() => document.querySelector('[data-sync-message]').textContent.includes('Requesting'));
    assert.equal(await page.locator('[data-sync-button]').isDisabled(), true);
    assert.equal(await page.locator('[data-sync-spinner]').isVisible(), true);
    await page.waitForFunction(() => document.querySelector('[data-survey-sync]').getAttribute('aria-busy') === 'true');
    while (!releasePost) await new Promise(resolve => setTimeout(resolve, 5));
    releasePost();
    await page.waitForFunction(() => document.querySelector('[data-sync-metric="files_scanned"]').textContent === '20');
    assert.equal(posts, 1); assert.ok(polls > 0);
    assert.equal(await page.locator('[data-sync-progress]').evaluate(element => element.value), 50);
    await page.locator('[data-survey-sync]').screenshot({path:'tmp/assignment-qa/survey-sync-desktop.png'});
    await page.setViewportSize({width:390,height:844});
    await page.locator('[data-survey-sync]').screenshot({path:'tmp/assignment-qa/survey-sync-mobile.png'});
    const outside = await page.locator('[data-survey-sync]').evaluate(element => element.getBoundingClientRect().right > innerWidth);
    assert.equal(outside, false);
    assert.deepEqual(errors, []);
    console.log('Browser checks passed: immediate spinner, one POST, live counts, folder progress, desktop and mobile.');
} finally {await browser.close();}
