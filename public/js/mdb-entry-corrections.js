import {saveFeedback} from './mdb-save-feedback.js?v=2';

export function installEntryCorrections({initial = [], api, context, isBusy, setBusy, chooseNext, storageKey}) {
    const root = document.getElementById('entry-corrections');
    if (!root) return;
    const $ = id => document.getElementById(id);
    const form = $('entry-correction-form');
    const field = name => form?.elements.namedItem(name);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const time = value => value ? new Date(value).toLocaleString('en-GB', {timeZone:'Asia/Karachi'}) : '';
    let notes = initial, dirty = false, clientUuid = null, requestPending = false, screenshotName = '', previewUrl = null, screenshotFile = null;
    const values = () => Object.fromEntries([...form.elements].filter(el => el.name && el.type !== 'file').map(el => [el.name, el.value]));
    function persist() {
        if (!form || !storageKey) return;
        try {
            if (dirty) localStorage.setItem(storageKey, JSON.stringify({values:values(), clientUuid, screenshotName}));
            else localStorage.removeItem(storageKey);
        } catch { /* Saving remains available even when browser storage is full. */ }
    }
    function feedback(message, failed = false) {
        saveFeedback($('entry-correction-status'), message, failed ? 'error' : 'saved', true);
    }
    function lock(value) {
        requestPending = value;
        setBusy(value);
        root.querySelectorAll('input,select,textarea,button').forEach(el => el.disabled = value);
    }
    function render() {
        const resolutions = new Map([...root.querySelectorAll('[data-resolve-correction]')].map(el => [el.dataset.resolveCorrection, el.elements.namedItem('resolution').value]));
        const open = notes.filter(note => note.status === 'open').length;
        $('entry-correction-count').textContent = `(${open} open)`;
        $('entry-correction-list').innerHTML = notes.length ? [...notes].sort((a,b) => (a.status !== 'open') - (b.status !== 'open') || b.id - a.id).map(note => `
            <article class="entry-correction-item">
                <strong>${note.status === 'open' ? 'Awaiting PDF correction' : 'Resolved'} &middot; ${escape(note.transformer_reference)}</strong>
                <p>${escape(note.pdf_name)} (v${escape(note.pdf_version)}), page ${escape(note.source_page)}${note.source_row ? ' &middot; '+escape(note.source_row) : ''}</p>
                <p class="entry-correction-comment">${escape(note.comment)}</p>
                ${note.screenshot_url ? `<p><a href="${escape(note.screenshot_url)}" target="_blank" rel="noopener">View error screenshot</a></p>` : ''}
                <small>Reported by ${escape(note.reporter_name)} &middot; ${escape(time(note.created_at))}</small>
                ${note.status === 'resolved' ? `<p class="entry-correction-comment"><strong>Resolution:</strong> ${escape(note.resolution)}</p><small>${escape(note.resolver_name)} &middot; ${escape(time(note.resolved_at))}</small>`
                    : root.dataset.admin === '1' ? `<form data-resolve-correction="${Number(note.id)}"><label>How was it resolved?<textarea name="resolution" required minlength="5" maxlength="4000" rows="2" placeholder="Record the corrected PDF or information provided."></textarea></label><button class="btn btn-success" type="submit">Mark resolved</button></form>` : ''}
            </article>`).join('') : '<p>No PDF corrections reported.</p>';
        root.querySelectorAll('[data-resolve-correction]').forEach(el => {
            el.elements.namedItem('resolution').value = resolutions.get(el.dataset.resolveCorrection) || '';
        });
        if (open) $('entry-correction-history').open = true;
    }
    function accept(result) {
        if (!Array.isArray(result.corrections)) throw new Error('The server did not confirm the comment. Your entered fields are retained; refresh comments before retrying.');
        notes = result.corrections;
        render();
    }
    $('entry-correction-editor')?.addEventListener('toggle', () => {
        if (!$('entry-correction-editor').open || dirty) return;
        const current = context();
        for (const [name,value] of Object.entries(current)) if (field(name)) field(name).value = value ?? '';
        field('transformer_reference').readOnly = !!current.transformer_id;
    });
    form?.addEventListener('input', () => {dirty = true;clientUuid = null;persist();});
    form?.addEventListener('change', () => {dirty = true;clientUuid = null;persist();});
    function clearScreenshot() {
        if (previewUrl) window.URL.revokeObjectURL?.(previewUrl);
        previewUrl = null;screenshotName = '';screenshotFile = null;

        if ($('entry-correction-preview')) {$('entry-correction-preview').hidden = true;$('entry-correction-preview').removeAttribute('src');}
        if ($('entry-correction-screenshot-help')) $('entry-correction-screenshot-help').textContent = '';
        if ($('entry-correction-remove-screenshot')) $('entry-correction-remove-screenshot').hidden = true;
    }
    function attachClipboardScreenshot(file) {
        if (isBusy() || requestPending) return;
        if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
            $('entry-correction-screenshot-help').textContent = 'Paste a PNG, JPG or WebP image up to 5 MB.';
            return;
        }
        const extension = {'image/png':'png','image/jpeg':'jpg','image/webp':'webp'}[file.type];
        screenshotFile = new File([file], `screenshot-${Date.now()}.${extension}`, {type:file.type});
        screenshotName = screenshotFile.name;
        if (previewUrl) window.URL.revokeObjectURL?.(previewUrl);
        previewUrl = window.URL.createObjectURL(screenshotFile);
        $('entry-correction-preview').src = previewUrl;
        $('entry-correction-preview').hidden = false;
        $('entry-correction-screenshot-help').textContent = 'Screenshot attached. Paste another image to replace it.';
        $('entry-correction-remove-screenshot').hidden = false;
        $('entry-correction-paste-target').value = '';
        dirty = true;clientUuid = null;persist();
    }
    document.addEventListener('paste', event => {
        if (!form || !root.open || !$('entry-correction-editor')?.open || isBusy() || requestPending) return;
        const item = [...(event.clipboardData?.items || [])].find(item => item.kind === 'file' && item.type.startsWith('image/'));
        const file = item?.getAsFile() || [...(event.clipboardData?.files || [])].find(file => file.type.startsWith('image/'));
        if (!file) return;
        event.preventDefault();
        attachClipboardScreenshot(file);
    });
    $('entry-correction-paste-screenshot')?.addEventListener('click', async () => {
        if (isBusy() || requestPending) return;
        try {
            if (!navigator.clipboard?.read) throw new Error('Clipboard reading unavailable');
            const items = await navigator.clipboard.read();
            for (const item of items) {
                const type = item.types.find(type => ['image/png','image/jpeg','image/webp'].includes(type));
                if (type) {attachClipboardScreenshot(await item.getType(type));return;}
            }
            $('entry-correction-screenshot-help').textContent = 'No screenshot found. Copy an image first, then paste it here.';
        } catch {
            $('entry-correction-screenshot-help').textContent = 'Click the paste box and press Ctrl+V (Cmd+V on Mac) to paste your screenshot.';
            $('entry-correction-paste-target')?.focus();
        }
    });
    $('entry-correction-remove-screenshot')?.addEventListener('click', () => {clearScreenshot();dirty=true;clientUuid=null;persist();});
    form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (isBusy() || requestPending || !form.reportValidity()) return;
        if (screenshotName && !screenshotFile) {feedback('Paste the screenshot again, or remove it before saving.', true);return;}
        const body = values();
        clientUuid ||= crypto.randomUUID();
        dirty = true;persist();
        Object.assign(body, {client_uuid:clientUuid, transformer_id:body.transformer_id ? Number(body.transformer_id) : null,
            source_file_id:Number(body.source_file_id), source_page:Number(body.source_page)});
        let requestBody = body;
        const screenshot = screenshotFile;
        if (screenshot) {
            requestBody = new FormData();
            for (const [key,value] of Object.entries(body)) if (value !== null && value !== undefined) requestBody.append(key, String(value));
            requestBody.append('screenshot', screenshot);
        }
        const advance = event.submitter?.value === 'next';
        lock(true);
        let saved = false;
        try {
            const result = await api('/entry-corrections', 'POST', requestBody);
            if (!result.corrections?.some(note => note.client_uuid === clientUuid)) throw new Error('The server did not confirm the saved comment. Refresh comments before retrying.');
            accept(result);
            dirty = false;clientUuid = null;persist();
            field('comment').value = '';
            clearScreenshot();
            $('entry-correction-editor').open = false;
            feedback('Comment saved. Super Admin can see why entry stopped.');
            saved = true;
        } catch (error) {feedback(Object.values(error.errors || {}).flat().join(' ') || error.message, true);}
        finally {lock(false);}
        if (saved && advance) {root.open=false;chooseNext();}
    });
    root.addEventListener('submit', async event => {
        const resolveForm = event.target.closest('[data-resolve-correction]');
        if (!resolveForm) return;
        event.preventDefault();
        if (isBusy() || requestPending || !resolveForm.reportValidity()) return;
        const resolution = resolveForm.elements.namedItem('resolution').value;
        lock(true);
        try {
            accept(await api(`/entry-corrections/${resolveForm.dataset.resolveCorrection}/resolve`, 'PUT', {resolution}));
            feedback('PDF correction resolved. The operator can resume entry.');
        } catch (error) {feedback(Object.values(error.errors || {}).flat().join(' ') || error.message, true);}
        finally {lock(false);}
    });
    $('entry-correction-refresh').hidden = false;
    $('entry-correction-refresh').addEventListener('click', async () => {
        if (isBusy() || requestPending) return;
        if ([...root.querySelectorAll('[name="resolution"]')].some(el => el.value.trim()) && !window.confirm('Refresh comments and discard the unsaved resolution text?')) return;
        lock(true);
        try {accept(await api('/entry-corrections'));feedback('Comments refreshed.');}
        catch (error) {feedback(error.message, true);}
        finally {lock(false);}
    });
    if (form && storageKey) {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey));
            if (saved?.values) {
                for (const [name,value] of Object.entries(saved.values)) if (field(name) && field(name).type !== 'file') field(name).value = value;
                dirty = true;clientUuid = saved.clientUuid;
                screenshotName = saved.screenshotName || '';
                if (screenshotName) {
                    if ($('entry-correction-screenshot-help')) $('entry-correction-screenshot-help').textContent = `Please paste ${screenshotName} again; browsers cannot restore a screenshot after reloading.`;
                    if ($('entry-correction-remove-screenshot')) $('entry-correction-remove-screenshot').hidden = false;
                }
                field('transformer_reference').readOnly = !!field('transformer_id').value;
                $('entry-correction-editor').open = true;
            }
        } catch { /* No recoverable comment draft. */ }
    }
    window.addEventListener('beforeunload', event => {
        persist();
        if (dirty || requestPending || [...root.querySelectorAll('[name="resolution"]')].some(el => el.value.trim())) {event.preventDefault();event.returnValue='';}
    });
    render();
    if (window.location.hash === '#entry-corrections') {root.open = true;const panel=root.closest('#entry-transformer-panel');if(panel)panel.open=true;}
}
