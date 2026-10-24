import {pdfLoadErrorMessage, clearPdfFallback, showPdfFallback} from './mdb-pdf-errors.js';

// Verification only reads PDF sources; it never changes survey records.
export function initializeReviewPdf(boot, root = document) {
    const $ = name => root.getElementById('review-pdf-' + name);
    const source = $('source'), canvas = $('canvas'), scroll = $('scroll'), message = $('message');
    let pdf = null, loading = null, library = null, pageNumber = 1, zoom = 1, rotation = 0;
    let generation = 0, task = null, renderChain = Promise.resolve();
    let drawing = false, start = null, preview = null, ready = false;
    const guides = new Map(), stage = $('stage'), overlay = $('lines');
    const key = () => `verification-pdf-guides:${source.value}:${pageNumber}`;
    const points = () => {
        if (!guides.has(key())) {
            let saved = [];
            try { saved = JSON.parse(window.localStorage.getItem(key()) || '[]'); } catch {}
            guides.set(key(), Array.isArray(saved) ? saved.filter(line => line && [line.start,line.end].every(p => p && Number.isFinite(p.x) && Number.isFinite(p.y) && p.x >= 0 && p.x <= 1 && p.y >= 0 && p.y <= 1)) : []);
        }
        return guides.get(key());
    };
    const rotatePoint = (p, angle) => {
        switch ((angle + 360) % 360) {
            case 90: return {x:1-p.y,y:p.x};
            case 180: return {x:1-p.x,y:1-p.y};
            case 270: return {x:p.y,y:1-p.x};
            default: return p;
        }
    };
    function drawGuides() {
        if (!overlay) return;
        overlay.toggleAttribute('hidden', !ready);
        overlay.innerHTML = [...points().map(line => ({start:rotatePoint(line.start,rotation),end:rotatePoint(line.end,rotation)})), ...(preview ? [preview] : [])].map(line => `<line x1="${line.start.x}" y1="${line.start.y}" x2="${line.end.x}" y2="${line.end.y}" stroke="#dc2626" stroke-width="2" vector-effect="non-scaling-stroke" />`).join('');
        $('draw').disabled = !ready; $('draw').setAttribute('aria-pressed',String(drawing));
        $('undo').disabled = $('clear').disabled = !ready || !points().length;
        stage.style.touchAction = drawing ? 'none' : '';
        stage.style.cursor = drawing ? 'crosshair' : '';
        $('line-status').textContent = drawing ? 'Click a row or drag across it to draw a horizontal line. Esc to stop.' : `${points().length} red lines on this page. Saved in this browser.`;
    }
    function saveGuides() {
        try { window.localStorage.setItem(key(),JSON.stringify(points())); } catch {}
        drawGuides();
    }
    function resetDrawing() { start = preview = null; ready = false; drawGuides(); }
    if (stage && overlay) {
        const point = event => { const rect = canvas.getBoundingClientRect(); return {x:Math.max(0,Math.min(1,(event.clientX-rect.left)/rect.width)),y:Math.max(0,Math.min(1,(event.clientY-rect.top)/rect.height))}; };
        $('draw').addEventListener('click',() => { drawing = !drawing; start = preview = null; drawGuides(); });
        $('undo').addEventListener('click',() => { points().pop(); saveGuides(); });
        $('clear').addEventListener('click',() => { points().splice(0); saveGuides(); });
        stage.addEventListener('pointerdown',event => { if (!drawing || !ready || event.button !== 0) return; event.preventDefault(); start = point(event); stage.setPointerCapture?.(event.pointerId); });
        stage.addEventListener('pointermove',event => { if (!start) return; preview = {start,end:{x:point(event).x,y:start.y}}; drawGuides(); });
        stage.addEventListener('pointerup',event => {
            if (!start) return;
            const end = point(event);
            const line = Math.abs(end.x-start.x) < .01 ? {start:{x:0,y:start.y},end:{x:1,y:start.y}} : {start,end:{x:end.x,y:start.y}};
            points().push({start:rotatePoint(line.start,-rotation),end:rotatePoint(line.end,-rotation)});
            start = preview = null; saveGuides();
        });
        stage.addEventListener('pointercancel',() => { start = preview = null; drawGuides(); });
        root.addEventListener('keydown',event => { if(event.key === 'Escape') { drawing = false; start = preview = null; drawGuides(); } });
    }

    function showMessage(text) { resetDrawing(); message.textContent = text; message.hidden = false; canvas.hidden = true; }
    function controls() {
        $('open').href = source.value + '#page=' + pageNumber;
        $('page').disabled = !pdf;
        $('page').value = pageNumber;
        $('page').max = pdf?.numPages || 1;
        $('total').textContent = `of ${pdf?.numPages || 0}`;
        $('previous').disabled = !pdf || pageNumber <= 1;
        $('next').disabled = !pdf || pageNumber >= pdf.numPages;
        for (const name of ['zoom-out', 'fit', 'zoom-in', 'rotate']) $(name).disabled = !pdf;
        if(pdf) root.dispatchEvent(new window.CustomEvent('verification:pdf-current',{detail:{url:source.value,page:pageNumber}}));
    }
    function render() {
        const document = pdf, token = generation, number = pageNumber, angle = rotation, factor = zoom;
        if (!document) return Promise.resolve();
        resetDrawing();
        task?.cancel();
        const current = () => token === generation && document === pdf && number === pageNumber && angle === rotation && factor === zoom;
        renderChain = renderChain.catch(() => {}).then(async () => {
            if (!current()) return;
            try {
                const page = await document.getPage(number);
                if (!current()) return;
                const original = page.getViewport({scale:1, rotation:angle});
                const scale = Math.max(250, scroll.clientWidth - 20) / original.width * factor;
                const css = page.getViewport({scale, rotation:angle});
                const ratio = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(16000000 / (css.width * css.height)));
                const viewport = page.getViewport({scale:scale * ratio, rotation:angle});
                canvas.width = Math.floor(viewport.width); canvas.height = Math.floor(viewport.height);
                canvas.style.width = `${css.width}px`; canvas.style.height = `${css.height}px`;
                message.hidden = true; canvas.hidden = false;
                task = page.render({canvasContext:canvas.getContext('2d'), viewport});
                await task.promise; task = null;
                if (current()) { ready = true; drawGuides(); }
            } catch (error) {
                if (current() && error.name !== 'RenderingCancelledException') showMessage('PDF rendering failed. Use Open PDF to view the source.');
            }
        });
        return renderChain;
    }
    function setPage(value) {
        pageNumber = Math.max(1, Math.min(pdf?.numPages || 1, Number.isFinite(Number(value)) ? Math.trunc(Number(value)) : 1));
        controls(); scroll.scrollTop = 0; render();
    }
    async function load(startPage = 1) {
        let phase = 'library';
        clearPdfFallback(scroll);
        const token = ++generation, previous = loading;
        task?.cancel(); loading = null; pdf = null; pageNumber = Math.max(1, Math.trunc(Number(startPage)) || 1); zoom = 1; rotation = 0;
        controls(); showMessage('Loading PDF...');
        try {
            await previous?.destroy();
            if (token !== generation) return;
            library ||= await import(boot.pdfjs_url);
            library.GlobalWorkerOptions.workerSrc = boot.pdfjs_worker;
            phase = 'document';
            if (token !== generation) return;
            loading = library.getDocument({url:source.value, withCredentials:true, disableRange:true, disableStream:true, isEvalSupported:false,
                cMapUrl:boot.pdfjs_assets + 'cmaps/', cMapPacked:true, standardFontDataUrl:boot.pdfjs_assets + 'standard_fonts/', wasmUrl:boot.pdfjs_assets + 'wasm/'});
            const loaded = await loading.promise;
            if (token !== generation) return;
            pdf = loaded; pageNumber = Math.max(1, Math.min(pdf.numPages, pageNumber)); controls(); await render();
        } catch (error) {
            if (token === generation) {
                showMessage('Browser PDF preview: use its toolbar to navigate. ' + pdfLoadErrorMessage(error, phase));
                showPdfFallback(scroll, source.value, pageNumber);
            }
        }
    }
    source.addEventListener('change', () => load());
    root.querySelectorAll('[data-review-pdf-source]').forEach(link => link.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button) return;
        event.preventDefault();
        if (source.value !== link.href) { source.value = link.href; load(); }
    }));
    root.addEventListener('verification:pdf-page',event=>{
        const {url,page}=event.detail || {};
        if(![...source.options].some(option=>option.value===url))return;
        if(source.value===url)setPage(page);else {source.value=url;load(page);}
    });
    $('previous').addEventListener('click', () => setPage(pageNumber - 1));
    $('next').addEventListener('click', () => setPage(pageNumber + 1));
    $('page').addEventListener('change', event => setPage(event.target.value));
    $('zoom-out').addEventListener('click', () => { zoom = Math.max(.25, zoom / 1.2); render(); });
    $('zoom-in').addEventListener('click', () => { zoom = Math.min(4, zoom * 1.2); render(); });
    $('fit').addEventListener('click', () => { zoom = 1; scroll.scrollLeft = 0; render(); });
    $('rotate').addEventListener('click', () => { rotation = (rotation + 90) % 360; render(); });
    $('height').addEventListener('input', () => {
        scroll.style.height = $('height').value + 'px'; $('height-value').textContent = $('height').value + ' px';
    });
    let timer;
    const observer = new ResizeObserver(() => { clearTimeout(timer); timer = setTimeout(render, 150); });
    observer.observe(scroll);
    if(boot.initial_source_url && [...source.options].some(option=>option.value===boot.initial_source_url)) source.value=boot.initial_source_url;
    load(boot.initial_page || 1);
}

if (typeof window !== 'undefined' && window.mdbReviewPdfBoot) initializeReviewPdf(window.mdbReviewPdfBoot);
