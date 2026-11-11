import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from '../../tmp/mdb-entry-qa/node_modules/jsdom/lib/api.js';
import {groupPreviewFeatures, featurePopup} from '../../public/js/feeder-kmz-preview.js';

test('preview groups complete HT separately from each transformer LT network',()=>{
    const features = ['ht','lt-1','lt-2','lt-1'].map(layer=>({properties:{layer,layer_label:layer,kind:layer==='ht'?'ht':'lt'}}));
    const groups = groupPreviewFeatures(features);
    assert.equal(groups.size,3);
    assert.equal(groups.get('lt-1').features.length,2);
    assert.equal(groups.get('ht').kind,'ht');
});

test('feature popups display survey text without interpreting HTML from the KMZ',()=>{
    const dom = new JSDOM();
    try {
        const popup = featurePopup({properties:{name:'<img src=x onerror=alert(1)>', details:{Location:'<script>bad()</script>',Length:'44.35 m',Missing:null}}},dom.window.document);
        assert.equal(popup.querySelectorAll('script,img').length,0);
        assert.match(popup.textContent,/<script>bad\(\)<\/script>/);
        assert.match(popup.textContent,/44.35 m/);
        assert.match(popup.textContent,/Not recorded/);
    } finally {dom.window.close();}
});
