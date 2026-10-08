(() => {
    const form = document.querySelector('[data-entry-form]');
    if (!form) return;
    const list = form.querySelector('[data-row-list]');
    const template = document.querySelector('#row-template');
    const total = form.querySelector('[data-total]');
    const status = form.querySelector('[data-draft-status]');
    const restore = form.querySelector('[data-restore-draft]');
    const key = form.dataset.draftKey;
    let dirty = false, submitting = false, storageAvailable = true;
    let saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(key)); } catch (_) { storageAvailable = false; }
    function fields() { return [...form.querySelectorAll('input[name],select[name]')].filter(el => !['_token','_method','survey_team_id'].includes(el.name) && !el.disabled); }
    function refresh() {
        const rows = [...list.children];
        const selected = rows.map(row => row.querySelector('[data-feeder]').value);
        rows.forEach((row, i) => {
            row.querySelector('[data-row-number]').textContent = `Feeder ${i + 1}`;
            row.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/items\[\d+\]/, `items[${i}]`));
            const feeder = row.querySelector('[data-feeder]');
            const quantity = row.querySelector('[data-quantity]');
            const option = feeder.selectedOptions[0];
            const capacity = option?.dataset.capacity;
            quantity.setCustomValidity('');
            feeder.setCustomValidity(!feeder.disabled && feeder.value && selected.filter(id => id === feeder.value).length > 1 ? 'Select each feeder only once.' : '');
            if (capacity !== undefined) {
                quantity.max = capacity;
                row.querySelector('[data-capacity-hint]').textContent = `Available quantity: ${capacity}. Capacity is checked again when saving.`;
                if (+quantity.value > +capacity) quantity.setCustomValidity(`Only ${capacity} are currently available for this feeder.`);
            } else {
                quantity.removeAttribute('max');
                row.querySelector('[data-capacity-hint]').textContent = 'Select a feeder to see available capacity.';
            }
            row.querySelectorAll('input[type="url"]').forEach(el => el.setCustomValidity(el.value && !/^https?:\/\//i.test(el.value) ? 'Use an HTTP or HTTPS URL.' : ''));
        });
        total.textContent = rows.reduce((sum, row) => sum + (+row.querySelector('[data-quantity]').value || 0), 0);
        const add = form.querySelector('[data-add-row]');
        if (add) add.disabled = rows.length >= 25;
    }
    function save() {
        dirty = true;
        refresh();
        if (!storageAvailable) return;
        try {
            const values = Object.fromEntries(fields().map(el => [el.name, el.value]));
            saved = { values, rows: list.children.length, at: Date.now() };
            sessionStorage.setItem(key, JSON.stringify(saved));
            status.textContent = 'Draft saved in this browser tab. Submit to send it for review.';
            restore.hidden = false;
        } catch (_) { storageAvailable = false; status.textContent = 'Draft storage unavailable. Keep this tab open until you submit.'; }
    }
    form.querySelector('[data-add-row]')?.addEventListener('click', () => {
        if (list.children.length >= 25) return;
        list.append(template.content.cloneNode(true)); save();
    });
    list.addEventListener('click', e => {
        const button = e.target.closest('[data-remove-row]');
        if (button && list.children.length > 1) { button.closest('.row-card').remove(); save(); }
    });
    form.addEventListener('input', e => {
        if (e.target.matches('[data-feeder-search]')) {
            const select = e.target.closest('.field').querySelector('[data-feeder]');
            const term = e.target.value.trim().toLowerCase();
            [...select.options].forEach(option => { option.hidden = Boolean(option.value && !option.selected && !option.textContent.toLowerCase().includes(term)); });
            return;
        }
        save();
    });
    form.addEventListener('change', save);
    restore.addEventListener('click', () => {
        if (!saved?.values || !Number.isInteger(saved.rows) || saved.rows < 1 || saved.rows > 25) return;
        if (form.querySelector('[data-add-row]')) {
            list.replaceChildren();
            for (let i = 0; i < saved.rows; i++) list.append(template.content.cloneNode(true));
        }
        refresh();
        fields().forEach(el => { if (Object.hasOwn(saved.values, el.name)) el.value = saved.values[el.name]; });
        dirty = true; refresh(); status.textContent = 'Draft restored. Check quantities and links before submitting.';
    });
    form.querySelector('[data-discard-draft]').addEventListener('click', () => {
        try { sessionStorage.removeItem(key); } catch (_) {}
        saved = null; restore.hidden = true; status.textContent = 'Saved draft discarded. Current form values are unchanged.';
    });
    form.addEventListener('submit', e => {
        if (submitting) { e.preventDefault(); return; }
        refresh();
        if (!form.checkValidity()) { e.preventDefault(); form.reportValidity(); return; }
        submitting = true;
        form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button => { button.disabled = true; });
    });
    window.addEventListener('beforeunload', e => { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });
    window.addEventListener('pageshow', () => { submitting = false; form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button => { button.disabled = false; }); });
    refresh();
    if (!storageAvailable) status.textContent = 'Draft storage unavailable. Keep this tab open until you submit.';
    if (saved?.values && Date.now() - saved.at < 86400000) {
        restore.hidden = false; status.textContent = 'A saved draft is available. Restore it to continue.';
    } else {
        saved = null; try { sessionStorage.removeItem(key); } catch (_) {}
    }
    if (form.dataset.hasErrors === '1') save();
})();
