// Uses only an in-memory-test HTML fixture and intercepted HTTP requests; never contacts Laravel or production.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from '../../tmp/assignment-qa/node_modules/playwright-core/index.mjs';

const fixture = (await fs.readFile('tmp/assignment-qa/assignments.html', 'utf8'))
    .replace(/<script[^>]*src="[^"]*livewire[^>]*><\/script>/g, '')
    .replace(/if \('serviceWorker' in navigator\)[^\n]*/g, '');
const browser = await chromium.launch({executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true});
try {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}, serviceWorkers: 'block'});
    const errors = [], posts = [];
    await context.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (request.method() === 'POST') { posts.push(new URLSearchParams(request.postData())); return route.fulfill({contentType: 'text/html', body: '<p>Saved (test only)</p>'}); }
        if (url.pathname.endsWith('/candidates')) return route.fulfill({json: {users: [{id: 77, name: 'Replacement surveyor'}]}});
        if (url.pathname.endsWith('/assignments/options')) return route.fulfill({json: {items: [{id: 777, label: 'Search result feeder', owner: 0, disabled: false}], more: false}});
        if (url.pathname === '/legacy-admin/assignments') return route.fulfill({contentType: 'text/html', body: fixture});
        const file = path.resolve('public', '.' + url.pathname);
        if (file.startsWith(path.resolve('public') + path.sep)) {
            try {
                const body = await fs.readFile(file);
                const contentType = ({'.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.webmanifest': 'application/json'})[path.extname(file)] || 'application/octet-stream';
                return route.fulfill({body, contentType});
            } catch {}
        }
        return route.fulfill({status: 404, body: 'Not found in isolated fixture'});
    });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://assignment.test/legacy-admin/assignments');
    await page.waitForFunction(() => document.getElementById('selected-count')?.textContent.startsWith('0 selected'));
    await page.screenshot({path: 'tmp/assignment-qa/desktop.png', fullPage: true});
    await page.locator('[data-choice]:not(:disabled)').first().check();
    await page.locator('#assign-form [name="confirmed"]').check();
    await page.getByRole('button', {name: 'Review and assign', exact: true}).click();
    assert.equal(await page.locator('#assignment-review').isVisible(), true);
    assert.match(await page.locator('#review-summary').textContent(), /1 selected/);
    await page.screenshot({path: 'tmp/assignment-qa/confirmation.png'});
    await page.getByRole('button', {name: 'Confirm and save', exact: true}).click();
    await page.waitForSelector('p:text("Saved (test only)")');
    assert.equal(posts.length, 1); assert.equal(posts[0].getAll('task_ids[]').length, 1); assert.equal(posts[0].get('task_type'), 'mobile');
    await page.goto('http://assignment.test/legacy-admin/assignments');
    await page.locator('[data-reassign]').click();
    await page.locator('#reassign-user').selectOption('77');
    await page.locator('#reassign-reason').fill('Balance the workload');
    await page.locator('#reassign-form [name="confirmed"]').check();
    await page.getByRole('button', {name: 'Confirm reassignment', exact: true}).click();
    await page.waitForSelector('p:text("Saved (test only)")');
    assert.equal(posts[1].get('task_type'), 'mobile_reassign'); assert.equal(posts[1].get('user_id'), '77');
    await page.setViewportSize({width: 390, height: 844});
    await page.goto('http://assignment.test/legacy-admin/assignments');
    await page.waitForFunction(() => document.getElementById('selected-count')?.textContent.startsWith('0 selected'));
    await page.screenshot({path: 'tmp/assignment-qa/mobile.png', fullPage: true});
    const overflow = await page.evaluate(() => [...document.querySelectorAll('body *')].filter(element => element.getBoundingClientRect().right > innerWidth + 1 && !element.closest('.assignment-table-wrap') && !element.closest('select')).slice(0, 12).map(element => `${element.tagName}.${element.className}: ${element.getBoundingClientRect().right}`));
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Mobile page must not overflow horizontally: ${overflow.join('; ')}`);
    assert.deepEqual(errors, []);
    console.log('PASS: Chromium desktop/mobile layout, confirmation, exact submitted selections, reassignment, no browser errors. All HTTP requests intercepted.');
    await context.close();
} finally { await browser.close(); }
