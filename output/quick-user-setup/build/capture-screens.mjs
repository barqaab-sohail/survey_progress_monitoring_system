// Browser captures of actual Laravel-rendered pages. All HTTP is intercepted;
// no request is sent to production or a real application database.
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import {chromium} from '../../../tmp/assignment-qa/node_modules/playwright-core/index.mjs';

const base = 'output/quick-user-setup', ids = JSON.parse(await fs.readFile(`${base}/build/fixture-ids.json`));
const browser = await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
const context = await browser.newContext({viewport:{width:1120,height:1000},deviceScaleFactor:1.6,serviceWorkers:'block'});
let active = '', blockedWrites = 0;
const errors = [];
await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (request.method() !== 'GET') {blockedWrites++; return route.fulfill({status:409,json:{message:'Documentation capture does not submit changes.'}});}
    if (url.pathname === '/capture') {
        let html = await fs.readFile(`${base}/build/${active}.html`,'utf8');
        html = html.replaceAll('http://localhost','https://guide.test').replaceAll('http://guide.test','https://guide.test').replace(/if \('serviceWorker' in navigator\)[^\n]*/g,'');
        return route.fulfill({contentType:'text/html',body:html});
    }
    if (/\/sources\/\d+$/.test(url.pathname)) return route.fulfill({contentType:'application/pdf',body:await fs.readFile(`${base}/build/training-source.pdf`)});
    if (url.pathname.includes('/verification/')) return route.fulfill({json:await page.evaluate(() => window.mdbVerificationBoot || {})});
    if (url.pathname.endsWith('/entry-data')) return route.fulfill({json:await page.evaluate(() => window.mdbEntryBoot || {})});
    const local = path.resolve('public','.'+url.pathname);
    if (local.startsWith(path.resolve('public')+path.sep)) {
        try {return route.fulfill({body:await fs.readFile(local),contentType:({'.js':'text/javascript','.mjs':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.wasm':'application/wasm'})[path.extname(local)] || 'application/octet-stream'});} catch {}
    }
    return route.fulfill({status:404,body:'Resource unavailable in documentation fixture'});
});
const page = await context.newPage(); page.on('pageerror', error=>errors.push({screen:active,message:error.message}));
async function open(name) {active=name;await page.goto('https://guide.test/capture',{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);}
async function shot(name,locator) {await locator.screenshot({path:`${base}/screenshots/${name}.png`});}
async function clip(name, first, last) {
    const a=await first.boundingBox(),b=await last.boundingBox();
    await page.screenshot({path:`${base}/screenshots/${name}.png`,clip:{x:a.x,y:a.y,width:a.width,height:b.y+b.height-a.y+4}});
}
try {
    await open('teams');
    await shot('01-project-team',page.locator('section.period-grid .card').first().locator('div[style]').first());
    const member = page.locator('form').filter({has:page.locator('#member-type')});
    await page.locator('#member-team').selectOption(String(ids.team));
    await page.locator('#member-user').selectOption(String(ids.entry));
    await clip('03-team-selection',member.locator('.field').nth(0),member.locator('.field').nth(1));
    await clip('03-user-membership',member.locator('.field').nth(2),member.getByRole('button',{name:'Assign member',exact:true}));
    const pool=page.locator('form').filter({has:page.locator('select[name=survey_team_id]')});
    await pool.locator('select[name=survey_team_id]').selectOption(String(ids.team));
    await pool.locator('select[name=feeder_id]').selectOption(String(ids.feeder));
    await shot('03-shared-feeder',pool);
    await open('users');
    const create = page.locator('form').filter({has:page.getByRole('heading',{name:'Add user',exact:true})});
    await create.locator('[name=name]').fill('Sara Entry');await create.locator('[name=email]').fill('sara.entry@example.com');await create.locator('[name=role]').selectOption('survey_data_entry_operator');
    await shot('02-create-user',create);
    const existing = page.locator(`form[action$="/users/${ids.entry}"]`);
    await shot('02-active-user',existing.locator('.field').filter({has:page.locator('select[name=status]')}));
    await open('mobile-assignment');
    await page.locator('[data-choice]:not(:disabled)').first().check();
    await clip('04-mobile-assignment',page.locator('#assign-tasks'),page.locator('#task-results'));
    await page.locator('#assign-form [name=confirmed]').check();await page.locator('#review-assignments').click();
    await shot('04-confirm-assignment',page.locator('#assignment-review'));
    await open('create-batch');
    await page.locator('[name=project_id]').selectOption(String(ids.project));await page.locator('[name=survey_team_id]').selectOption(String(ids.team));
    await page.locator('[name=feeder_id]').selectOption(String(ids.feeder));await page.locator('[name=survey_date]').fill('2026-10-31');
    await shot('05-create-sources',page.locator('[data-batch-create] > .mdb-fields'));
    await shot('05-create-details',page.locator('.mdb-create-layout > section').nth(1));
    await open('workflow-entry-assign');
    await page.locator('select[name=stage]').selectOption('entry');await page.locator('select[name=user_id]').selectOption(String(ids.entry));
    await shot('05-entry-assignment',page.locator('.card.table-wrap'));
    await open('entry-screen');
    await page.waitForFunction(()=>document.querySelector('#entry-transformer')?.options.length > 0);
    await shot('05-entry-saved-rows',page.locator('#entry-saved'));
    await shot('05-entry-submit-button',page.locator('#entry-submit-form'));
    await open('entry-submit');
    await shot('05-submit-for-verification',page.locator('section.card').filter({has:page.getByRole('heading',{name:'Survey workflow',exact:true})}));
    await open('workflow-verifier-assign');
    await page.locator('select[name=stage]').selectOption('verification');await page.locator('select[name=user_id]').selectOption(String(ids.verifier));
    await shot('05-verifier-assignment',page.locator('.card.table-wrap'));
    await open('verification-review');
    await page.waitForFunction(()=>!document.querySelector('#verification-workspace')?.hidden);
    await shot('05-review-decisions',page.locator('section.card').filter({has:page.getByRole('heading',{name:'Survey workflow',exact:true})}));
    await open('workflow-owners');await shot('06-assignment-check',page.locator('.card.table-wrap'));
    assert.equal(blockedWrites,0,'Capture should not submit any write requests.');
    await fs.writeFile(`${base}/build/browser-checks.json`,JSON.stringify({blockedWrites,errors},null,2));
    console.log('Captured actual web forms with training fixtures. No external requests or submitted writes.');
    console.log(JSON.stringify(errors));
} finally {
    await fs.writeFile(`${base}/build/browser-checks.json`,JSON.stringify({blockedWrites,errors},null,2));
    console.log(JSON.stringify(errors));
    await browser.close();
}
