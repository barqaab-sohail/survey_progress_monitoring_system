(() => {
    'use strict';
    const form = document.querySelector('[data-batch-create]');
    if (!form) return;
    const status = form.querySelector('[data-create-status]');
    const viewer = form.querySelector('[data-create-pdf]');
    const open = form.querySelector('[data-preview-open]');
    const placeholder = form.querySelector('[data-preview-placeholder]');
    let previewUrl;
    let generation = 0;
    const clearPreview = () => {
        generation++;
        viewer.removeAttribute('src');
        viewer.hidden = open.hidden = true;
        open.removeAttribute('href');
        placeholder.hidden = false;
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        status.textContent = '';
    };
    const show = blob => {
        previewUrl = URL.createObjectURL(blob);
        viewer.src = open.href = previewUrl;
        viewer.hidden = open.hidden = false;
        placeholder.hidden = true;
    };
    const fileId = value => {
        value = value.trim();
        if (/^[A-Za-z0-9_-]{10,200}$/.test(value)) return value;
        try {
            const url = new URL(value);
            if (url.protocol !== 'https:' || !['drive.google.com', 'docs.google.com'].includes(url.hostname)) return '';
            const id = url.pathname.match(/\/d\/([A-Za-z0-9_-]+)/)?.[1] || url.searchParams.get('id') || '';
            return /^[A-Za-z0-9_-]{10,200}$/.test(id) ? id : '';
        } catch { return ''; }
    };
    form.querySelectorAll('[data-source-field]').forEach(group => {
        const local = group.querySelector('[data-source-local]');
        const link = group.querySelector('[data-drive-link]');
        const id = group.querySelector('[data-drive-id]');
        const pdf = group.dataset.sourceField === 'survey_pdf';
        const sync = () => {
            if (!link) return;
            id.value = fileId(link.value);
            link.setCustomValidity(link.value.trim() && !id.value ? 'Enter a Google Drive file link or file ID.' : '');
            local.required = !id.value;
        };
        sync();
        link?.addEventListener('input', () => {
            if (link.value.trim()) local.value = '';
            sync();
            if (pdf) clearPreview();
        });
        local.addEventListener('change', () => {
            if (local.files.length && link) link.value = '';
            sync();
            if (!pdf) return;
            clearPreview();
            const file = local.files[0];
            if (!file) return;
            if (!/\.pdf$/i.test(file.name)) { status.textContent = 'Choose a PDF file.'; return; }
            show(new Blob([file], {type: 'application/pdf'}));
        });
        group.querySelector('[data-preview-drive]')?.addEventListener('click', async event => {
            sync();
            if (!id.value) { link.reportValidity(); status.textContent = 'Enter a Drive PDF link or file ID first.'; return; }
            clearPreview();
            const current = generation;
            const button = event.currentTarget;
            button.disabled = true;
            status.textContent = 'Loading PDF from Google Drive…';
            try {
                const response = await fetch(form.dataset.drivePreview, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value},
                    body: JSON.stringify({drive_file_id: id.value})
                });
                if (!response.ok) {
                    const result = await response.json().catch(() => ({}));
                    throw new Error(Object.values(result.errors || {}).flat().join(' ') || 'Could not load PDF. Check your Drive connection and file access.');
                }
                const blob = await response.blob();
                if (current !== generation) return;
                show(blob);
                status.textContent = 'PDF ready. Read the feeder and survey date, then enter them below.';
            } catch (error) {
                if (current === generation) status.textContent = error.message;
            } finally { button.disabled = false; }
        });
    });
    window.addEventListener('pagehide', () => { if (previewUrl) URL.revokeObjectURL(previewUrl); });
})();
