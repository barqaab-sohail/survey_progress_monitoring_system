export function initializeCreatePdf(form, libraryUrl) {
    const $ = name => form.querySelector(`[data-pdf-${name}]`);
    const toolbar = form.querySelector('[data-create-toolbar]');
    const fallback = form.querySelector('[data-create-pdf]');
    const open = form.querySelector('[data-preview-open]');
    const scroll = $('scroll'), canvas = $('canvas');
    let pdf, loading, task, library, page = 1, zoom = 1, rotation = 0, generation = 0;
    let chain = Promise.resolve();
    function controls() {
        $('page').value = page;
        $('page').max = pdf?.numPages || 1;
        $('total').textContent = `of ${pdf?.numPages || 0}`;
        $('previous').disabled = !pdf || page <= 1;
        $('next').disabled = !pdf || page >= pdf.numPages;
        for (const name of ['page', 'zoom-out', 'zoom-in', 'fit', 'rotate']) $(name).disabled = !pdf;
        if (open.href) open.href = open.href.split('#')[0] + '#page=' + page;
    }
    function render() {
        const doc = pdf, token = generation, number = page, angle = rotation, factor = zoom;
        if (!doc) return;
        task?.cancel();
        const current = () => token === generation && doc === pdf && number === page && angle === rotation && factor === zoom;
        chain = chain.catch(() => {}).then(async () => {
            if (!current()) return;
            try {
                const sheet = await doc.getPage(number);
                if (!current()) return;
                const base = sheet.getViewport({scale: 1, rotation: angle});
                const scale = Math.max(100, scroll.clientWidth - 20) / base.width * factor;
                const css = sheet.getViewport({scale, rotation: angle});
                const ratio = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(16000000 / (css.width * css.height)));
                const viewport = sheet.getViewport({scale: scale * ratio, rotation: angle});
                canvas.width = Math.floor(viewport.width); canvas.height = Math.floor(viewport.height);
                canvas.style.width = css.width + 'px'; canvas.style.height = css.height + 'px';
                task = sheet.render({canvasContext: canvas.getContext('2d'), viewport});
                await task.promise;
                task = null;
                if (current()) { canvas.hidden = false; fallback.hidden = true; }
            } catch (error) {
                if (current() && error.name !== 'RenderingCancelledException') {
                    canvas.hidden = true; fallback.hidden = false;
                }
            }
        });
        return chain;
    }
    function clear() {
        generation++;
        task?.cancel();
        loading?.destroy(); loading = null; pdf = null;
        toolbar.hidden = scroll.hidden = canvas.hidden = true;
        controls();
    }
    async function load(url) {
        clear();
        const token = generation;
        page = 1; zoom = 1; rotation = 0;
        toolbar.hidden = scroll.hidden = false;
        controls();
        try {
            library ||= await import(libraryUrl);
            if (token !== generation) return;
            library.GlobalWorkerOptions.workerSrc = new URL('pdf.worker.min.mjs', libraryUrl).href;
            const assets = new URL('../', libraryUrl).href;
            loading = library.getDocument({url, isEvalSupported: false, cMapUrl: assets + 'cmaps/', cMapPacked: true, standardFontDataUrl: assets + 'standard_fonts/', wasmUrl: assets + 'wasm/'});
            const loaded = await loading.promise;
            if (token !== generation) return;
            pdf = loaded; controls(); await render();
        } catch {
            if (token === generation) { scroll.hidden = true; fallback.hidden = false; }
        }
    }
    function setPage(value) {
        page = Math.max(1, Math.min(pdf?.numPages || 1, Math.trunc(Number(value)) || 1));
        controls(); scroll.scrollTop = 0; render();
    }
    $('previous').addEventListener('click', () => setPage(page - 1));
    $('next').addEventListener('click', () => setPage(page + 1));
    $('page').addEventListener('change', event => setPage(event.target.value));
    $('zoom-out').addEventListener('click', () => { zoom = Math.max(.25, zoom / 1.2); render(); });
    $('zoom-in').addEventListener('click', () => { zoom = Math.min(4, zoom * 1.2); render(); });
    $('fit').addEventListener('click', () => { zoom = 1; scroll.scrollLeft = 0; render(); });
    $('rotate').addEventListener('click', () => { rotation = (rotation + 90) % 360; render(); });
    $('height').addEventListener('input', () => {
        scroll.style.height = $('height').value + 'px';
        fallback.style.height = $('height').value + 'px';
        fallback.style.maxHeight = 'none';
        $('height-value').textContent = $('height').value + ' px';
    });
    let timer;
    const observer = new ResizeObserver(() => { clearTimeout(timer); timer = setTimeout(render, 150); });
    observer.observe(scroll);
    window.addEventListener('pagehide', () => { clear(); observer.disconnect(); clearTimeout(timer); });
    return {load, clear};
}
