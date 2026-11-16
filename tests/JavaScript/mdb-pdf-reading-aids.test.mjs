import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from '../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js';
import {installPdfReadingAids} from '../../public/js/mdb-pdf-reading-aids.js';

test('header stays pinned across pages, guides can be placed, sources remain separate',()=>{
    const ids=['pin','unpin','add','clear','guides','row','angle','status','header','header-label','header-window','header-canvas'];
    const dom=new JSDOM(`<div id="entry-pdf-scroll"><div id="entry-pdf-stage"><canvas id="entry-pdf-canvas"></canvas></div></div>${ids.map(id=>id.includes('canvas')?`<canvas id="entry-aid-${id}"></canvas>`:id==='guides'||id==='row'?`<input type="checkbox" id="entry-aid-${id}">`:id==='angle'?`<input id="entry-aid-angle" value="0">`:`<div id="entry-aid-${id}"></div>`).join('')}<select id="entry-aid-label"><option>RS-R</option></select>`);
    globalThis.document=dom.window.document;
    dom.window.HTMLCanvasElement.prototype.getContext=()=>({drawImage(){}});
    const $=id=>document.getElementById(id);
    const canvas=$('entry-pdf-canvas'),stage=$('entry-pdf-stage');
    canvas.width=1000;canvas.height=1000;canvas.style.width='1000px';
    Object.defineProperties(canvas,{clientWidth:{value:1000},clientHeight:{value:1000}});
    stage.getBoundingClientRect=()=>({left:0,top:0,width:1000,height:1000});
    let source='1',page=1;
    const aids=installPdfReadingAids({source:()=>source,page:()=>page});
    const pointer=(type,x,y)=>stage.querySelector('.entry-reading-layer').dispatchEvent(new dom.window.MouseEvent(type,{bubbles:true,clientX:x,clientY:y,button:0}));
    try{
        aids.rendered();$('entry-aid-pin').click();pointer('pointerdown',0,20);
        pointer('pointermove',900,120);
        const selection=stage.querySelector('.entry-reading-selection');
        assert.ok(selection);
        assert.ok(Math.abs(parseFloat(selection.style.top)-2)<0.001);
        assert.ok(Math.abs(parseFloat(selection.style.height)-10)<0.001);
        pointer('pointerup',900,120);
        assert.equal(stage.querySelector('.entry-reading-selection'),null);
        assert.equal($('entry-aid-header').hidden,false);
        assert.equal($('entry-aid-header-canvas').height,100);
        page=6;aids.loading();aids.rendered();
        assert.equal($('entry-aid-header').hidden,false);
        assert.match($('entry-aid-header-label').textContent,/page 1/);
        $('entry-aid-add').click();pointer('pointerdown',530,400);pointer('pointerup',530,400);
        assert.equal(stage.querySelector('.entry-reading-guide button').textContent,'RS-R');
        assert.equal(stage.querySelector('.entry-reading-guide').style.left,'53%');
        $('entry-aid-angle').value='0.5';$('entry-aid-angle').dispatchEvent(new dom.window.Event('input'));
        assert.equal(stage.style.transform,'rotate(0.5deg)');
        source='2';aids.loading();aids.rendered();
        assert.equal($('entry-aid-header').hidden,true);
        assert.equal(stage.querySelector('.entry-reading-guide'),null);
        source='1';aids.rendered();assert.equal($('entry-aid-header').hidden,false);
        $('entry-aid-clear').click();assert.equal(stage.querySelector('.entry-reading-guide'),null);
    }finally{dom.window.close();delete globalThis.document;}
});
