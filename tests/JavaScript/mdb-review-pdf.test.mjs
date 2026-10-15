import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import {initializeReviewPdf} from '../../public/js/mdb-review-pdf.js';

test('verification PDF controls navigate, zoom, rotate, resize and handle failed sources without saving records', async () => {
    const {JSDOM} = await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html = await fs.readFile('tmp/mdb-entry-qa/review.html', 'utf8');
    const stubPath = path.resolve('tmp/mdb-entry-qa/review-pdf-stub.mjs');
    await fs.writeFile(stubPath, `
        export const GlobalWorkerOptions = {}, loads = [], renders = [];
        export function getDocument(options) {
            const state = {options, destroys:0}; loads.push(state);
            return {destroy:async()=>{if(++state.destroys>1)throw Error('Destroyed twice');},
                promise:options.url.includes('missing') ? Promise.reject(Error('Missing PDF')) : Promise.resolve({numPages:14,
                    getPage:async number=>({
                        getViewport:({scale,rotation})=>({width:(rotation%180?600:800)*scale,height:(rotation%180?800:600)*scale}),
                        render:({viewport})=>{renders.push({number,viewport});return {promise:Promise.resolve(),cancel(){}};}
                    })})};
        }
    `);
    const stubUrl = pathToFileURL(stubPath).href;
    const pdf = await import(stubUrl);
    const dom = new JSDOM(html, {url:'http://localhost/mdb-workflow/1/review'});
    const d = dom.window.document, $ = name => d.getElementById('review-pdf-' + name);
    const savedRows = d.querySelector('.entry-review-table').outerHTML;
    dom.window.HTMLCanvasElement.prototype.getContext = () => ({});
    Object.defineProperty($('scroll'), 'clientWidth', {value:1000});
    Object.assign(globalThis, {window:dom.window, document:d, ResizeObserver:class {observe(){}}});
    const settle = async () => {for(let i=0;i<15;i++)await new Promise(resolve=>setImmediate(resolve));};
    initializeReviewPdf({pdfjs_url:stubUrl,pdfjs_worker:'/worker.mjs',pdfjs_assets:'/pdfjs/'});
    await settle();
    assert.equal($('canvas').hidden,false);assert.equal($('message').hidden,true);
    assert.equal($('total').textContent,'of 14');assert.equal($('previous').disabled,true);
    assert.equal(pdf.loads[0].options.withCredentials,true);
    assert.equal($('open').href,$('source').value+'#page=1');
    $('next').click();await settle();assert.equal($('page').value,'2');assert.equal(pdf.renders.at(-1).number,2);
    $('page').value='99';$('page').dispatchEvent(new dom.window.Event('change'));await settle();
    assert.equal($('page').value,'14');assert.equal($('next').disabled,true);
    $('previous').click();await settle();assert.equal($('page').value,'13');
    const fitWidth=parseFloat($('canvas').style.width);
    $('zoom-in').click();await settle();assert.ok(parseFloat($('canvas').style.width)>fitWidth);
    $('zoom-out').click();await settle();assert.equal(parseFloat($('canvas').style.width),fitWidth);
    $('zoom-in').click();$('fit').click();await settle();assert.equal(parseFloat($('canvas').style.width),fitWidth);
    assert.ok(parseFloat($('canvas').style.width)>parseFloat($('canvas').style.height));
    $('rotate').click();await settle();assert.ok(parseFloat($('canvas').style.height)>parseFloat($('canvas').style.width));
    $('height').value='400';$('height').dispatchEvent(new dom.window.Event('input'));
    assert.equal($('scroll').style.height,'400px');assert.equal($('height-value').textContent,'400 px');
    const link=d.querySelector('[data-review-pdf-source]');
    const modified=new dom.window.MouseEvent('click',{ctrlKey:true,bubbles:true,cancelable:true});
    link.dispatchEvent(modified);assert.equal(modified.defaultPrevented,false);
    const next=d.createElement('option');next.value='http://localhost/second.pdf';next.textContent='Second PDF';$('source').append(next);
    $('source').value=next.value;$('source').dispatchEvent(new dom.window.Event('change'));await settle();
    assert.equal($('page').value,'1');assert.equal($('open').href,next.value+'#page=1');assert.equal(pdf.loads[0].destroys,1);
    const missing=d.createElement('option');missing.value='http://localhost/missing.pdf';$('source').append(missing);
    $('source').value=missing.value;$('source').dispatchEvent(new dom.window.Event('change'));await settle();
    assert.equal($('canvas').hidden,true);assert.match($('message').textContent,/Could not open PDF/);
    assert.equal($('next').disabled,true);assert.equal($('rotate').disabled,true);assert.equal($('open').href,missing.value+'#page=1');
    $('source').value=next.value;$('source').dispatchEvent(new dom.window.Event('change'));await settle();
    assert.equal($('canvas').hidden,false);assert.equal($('message').hidden,true);
    assert.equal(d.querySelector('.entry-review-table').outerHTML,savedRows);
    dom.window.close();delete globalThis.window;delete globalThis.document;delete globalThis.ResizeObserver;
});


test('verification automatically opens the selected transformer PDF at its saved page seven', async () => {
    const {JSDOM}=await import('../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js');
    const html=await fs.readFile('tmp/mdb-entry-qa/review-page-seven.html','utf8');
    const dom=new JSDOM(html,{url:'http://localhost/mdb-workflow/1/review',runScripts:'outside-only'});
    const d=dom.window.document, $=name=>d.getElementById('review-pdf-'+name);
    dom.window.eval([...d.scripts].find(script=>script.textContent.includes('window.mdbReviewPdfBoot')).textContent);
    const boot=dom.window.mdbReviewPdfBoot;
    assert.equal(boot.initial_page,7);
    assert.match($('source').selectedOptions[0].textContent,/Second survey.pdf/);
    boot.pdfjs_url=pathToFileURL(path.resolve('tmp/mdb-entry-qa/review-pdf-stub.mjs')).href;
    const library=await import(boot.pdfjs_url);
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({});
    Object.assign(globalThis,{window:dom.window,document:d,ResizeObserver:class{observe(){}}});
    initializeReviewPdf(boot);
    for(let i=0;i<15;i++)await new Promise(resolve=>setImmediate(resolve));
    assert.equal(library.loads.at(-1).options.url,boot.initial_source_url);
    assert.equal($('page').value,'7');assert.equal(library.renders.at(-1).number,7);
    assert.equal($('open').href,boot.initial_source_url+'#page=7');
    $('next').click();for(let i=0;i<15;i++)await new Promise(resolve=>setImmediate(resolve));
    assert.equal($('page').value,'8');assert.equal(library.renders.at(-1).number,8);
    dom.window.close();delete globalThis.window;delete globalThis.document;delete globalThis.ResizeObserver;
});
