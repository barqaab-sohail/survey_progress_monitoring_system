// Browser-local reading aids never modify survey data or the source PDF.
export function installPdfReadingAids({source, page}, root = document) {
    const $ = name => root.getElementById('entry-aid-' + name);
    if (!$('pin')) return {rendered() {}, loading() {}};
    const canvas = root.getElementById('entry-pdf-canvas');
    const stage = root.getElementById('entry-pdf-stage');
    const scroll = root.getElementById('entry-pdf-scroll');
    const layer = document.createElement('div');
    layer.className = 'entry-reading-layer'; stage.append(layer);
    const states = new Map();
    let current = null, mode = null, start = null, selected = null, ready = false;
    const status = text => $('status').textContent = text;
    const state = () => {
        const key = source();
        if (!states.has(key)) states.set(key, {guides: [], header: null, angle: 0});
        return states.get(key);
    };
    function paint() {
        layer.replaceChildren();
        layer.style.pointerEvents = ready && mode ? 'auto' : 'none';
        layer.style.cursor = mode ? 'crosshair' : '';
        layer.style.touchAction = mode ? 'none' : '';
        if (!ready) return;
        if ($('guides').checked) state().guides.forEach((guide, index) => {
            const line = document.createElement('div'); line.className = 'entry-reading-guide';
            line.style.left = guide.x * 100 + '%';
            const label = document.createElement('button'); label.type = 'button';
            label.textContent = guide.label; label.title = 'Drag to align this column guide';
            label.addEventListener('pointerdown', event => {
                event.stopPropagation(); event.preventDefault(); selected = index;
                label.setPointerCapture?.(event.pointerId);
            });
            label.addEventListener('pointermove', event => {
                if (selected !== index) return;
                guide.x = point(event).x; line.style.left = guide.x * 100 + '%';
            });
            label.addEventListener('pointerup', () => { selected = null; });
            label.addEventListener('pointercancel', () => { selected = null; });
            line.append(label); layer.append(line);
        });
    }
    // Invert the stage's small rotation so pointer positions remain aligned.
    function point(event) {
        const rect = stage.getBoundingClientRect();
        const angle = -state().angle * Math.PI / 180;
        const dx = event.clientX - (rect.left + rect.width / 2);
        const dy = event.clientY - (rect.top + rect.height / 2);
        return {x: Math.max(0, Math.min(1, (dx*Math.cos(angle)-dy*Math.sin(angle))/canvas.clientWidth+.5)),
            y: Math.max(0, Math.min(1, (dx*Math.sin(angle)+dy*Math.cos(angle))/canvas.clientHeight+.5))};
    }
    function header() {
        const saved = state().header;
        $('header').hidden = !saved;
        if (!saved) return;
        const target = $('header-canvas');
        target.width = saved.width; target.height = saved.height;
        target.getContext('2d').drawImage(saved, 0, 0);
        target.style.width = canvas.style.width;
        target.style.transform = `rotate(${state().angle}deg)`;
        $('header-label').textContent = `Pinned survey header · page ${state().headerPage} (check alignment on each page)`;
        $('header-window').scrollLeft = scroll.scrollLeft;
    }
    $('pin').addEventListener('click', () => {
        if (!ready) return;
        mode = 'header'; start = null; paint();
        status('Drag from the top to the bottom of the printed header on the PDF. The full-width strip will be pinned. Esc cancels.');
    });
    $('unpin').addEventListener('click', () => { state().header = null; header(); });
    $('add').addEventListener('click', () => {
        if (!ready) return;
        $('guides').checked = true; mode = 'guide'; paint();
        status('Click the centre of the chosen consumer column. Drag its label to adjust. Guides are shared across pages of this PDF; check alignment on each scan.');
    });
    $('clear').addEventListener('click', () => { state().guides = []; mode = null; paint(); });
    $('guides').addEventListener('change', paint);
    $('angle').addEventListener('input', () => {
        state().angle = Math.max(-5, Math.min(5, Number($('angle').value) || 0));
        stage.style.transform = `rotate(${state().angle}deg)`; header();
    });
    function selectionPreview(end) {
        let box = layer.querySelector('.entry-reading-selection');
        if (!box) {
            box = document.createElement('div');
            box.className = 'entry-reading-selection';
            layer.append(box);
        }
        // The pinned crop uses the entire page width, so preview that exact strip.
        box.style.top = Math.min(start.y, end.y) * 100 + '%';
        box.style.height = Math.abs(start.y - end.y) * 100 + '%';
    }
    layer.addEventListener('pointermove', event => {
        if (mode === 'header' && start) {
            event.preventDefault(); event.stopPropagation(); selectionPreview(point(event));
        }
    });
    layer.addEventListener('pointerdown', event => {
        if (!ready || !mode || event.button !== 0) return;
        event.preventDefault(); event.stopPropagation();
        start = point(event); layer.setPointerCapture?.(event.pointerId);
        if (mode === 'header') selectionPreview(start);
    });
    layer.addEventListener('pointerup', event => {
        if (!start || !mode) return;
        event.preventDefault(); event.stopPropagation();
        const end = point(event);
        layer.querySelector('.entry-reading-selection')?.remove();
        if (mode === 'guide') state().guides.push({x:end.x, label:$('label').value});
        else {
            const top = Math.min(start.y, end.y), height = Math.abs(start.y-end.y);
            if (height < .005 || height > .4) {
                start = null; status('Select a header strip between 0.5% and 40% of page height. Drag again.'); return;
            }
            const crop = document.createElement('canvas');
            crop.width = canvas.width; crop.height = Math.max(1, Math.round(height * canvas.height));
            crop.getContext('2d').drawImage(canvas, 0, top*canvas.height, canvas.width, crop.height, 0, 0, crop.width, crop.height);
            state().header = crop; state().headerPage = page(); header();
        }
        start = null; mode = null; paint();
        status('Reading aid set. Confirm guides match the printed columns on this page. Aids last until this page is reloaded.');
    });
    layer.addEventListener('pointercancel', () => { start = null; layer.querySelector('.entry-reading-selection')?.remove(); });
    stage.addEventListener('pointermove', event => {
        let band = layer.querySelector('.entry-reading-row');
        if (!ready || !$('row').checked || mode) { band?.remove(); return; }
        if (!band) { band = document.createElement('div'); band.className = 'entry-reading-row'; layer.append(band); }
        band.style.top = point(event).y * 100 + '%';
    });
    $('row').addEventListener('change', () => layer.querySelector('.entry-reading-row')?.remove());
    root.addEventListener('keydown', event => { if (event.key === 'Escape') { mode = null; start = null; paint(); } });
    scroll.addEventListener('scroll', () => {
        $('header-window').scrollLeft = scroll.scrollLeft;
        // Keep guide handles within reach while reading lower rows.
        layer.style.setProperty('--guide-label-top', Math.max(0, scroll.scrollTop) + 'px');
    });
    return {
        loading() { ready = false; mode = null; start = null; layer.replaceChildren(); layer.style.pointerEvents = 'none'; $('header').hidden = true; },
        rendered() {
            ready = true;
            if (current !== source()) { current = source(); $('angle').value = state().angle; }
            stage.style.transform = `rotate(${state().angle}deg)`;
            header(); paint();
        }
    };
}
