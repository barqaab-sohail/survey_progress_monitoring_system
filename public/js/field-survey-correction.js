(() => {
    const form = document.getElementById('field-correction-form');
    if (!form) return;
    const rows = [...form.querySelectorAll('[data-row-index]')];
    const search = document.getElementById('field-row-search');
    const jump = document.getElementById('field-row-jump');
    const previous = document.getElementById('field-rows-prev');
    const next = document.getElementById('field-rows-next');
    const pageSize = 10;
    let page = 0;
    const value = (row, key) => row.querySelector(`[data-field="rows.${row.dataset.rowIndex}.${key}"]`)?.value || '';
    const matching = () => {
        const query = search.value.trim().toLowerCase();
        return rows.filter(row => !query || [row.querySelector('summary strong').textContent,
            ...['gps_waypoint', 'group', 'date'].map(key => value(row, key))].join(' ').toLowerCase().includes(query));
    };
    function render() {
        const filtered = matching();
        const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
        page = Math.max(0, Math.min(page, pages - 1));
        const visible = new Set(filtered.slice(page * pageSize, (page + 1) * pageSize));
        rows.forEach(row => { row.hidden = !visible.has(row); });
        previous.disabled = page === 0;
        next.disabled = page === pages - 1;
        document.getElementById('field-rows-count').textContent = `Page ${page + 1} of ${pages} · ${filtered.length} matching rows`;
        document.getElementById('field-rows-empty').hidden = filtered.length !== 0;
    }
    function reveal(row) {
        if (!row) return;
        search.value = '';
        page = Math.floor(rows.indexOf(row) / pageSize);
        rows.forEach(item => { item.open = item === row; });
        render();
        row.scrollIntoView({block: 'start', behavior: 'smooth'});
    }
    search.addEventListener('input', () => { page = 0; render(); });
    jump.addEventListener('change', () => { if (jump.value !== '') reveal(rows.find(row => row.dataset.rowIndex === jump.value)); });
    previous.addEventListener('click', () => { page--; render(); });
    next.addEventListener('click', () => { page++; render(); });
    rows.forEach(row => {
        row.addEventListener('toggle', () => {
            if (row.open) rows.forEach(other => { if (other !== row) other.open = false; });
        });
        const confirmation = row.querySelector('[data-waypoint-check]');
        const token = row.querySelector(`[data-field="rows.${row.dataset.rowIndex}.waypoint_confirmation"]`);
        confirmation?.addEventListener('change', () => {
            token.value = confirmation.checked ? ['group', 'date', 'gps_waypoint'].map(key => value(row, key)).join('|') : '';
        });
        row.addEventListener('input', event => {
            if (confirmation && ['group', 'date', 'gps_waypoint'].some(key => event.target.dataset.field === `rows.${row.dataset.rowIndex}.${key}`)) {
                confirmation.checked = false;
                token.value = '';
            }

            row.querySelector('[data-row-summary]').textContent = `GPS ${value(row, 'gps_waypoint') || 'not recorded'} · Group ${value(row, 'group') || '-'} · ${value(row, 'date') || '-'}`;
        });
    });
    // Hidden/collapsed rows remain in the form and retain their edits on every page.
    let showingInvalid = false;
    form.addEventListener('invalid', event => {
        if (showingInvalid) return;
        showingInvalid = true;
        reveal(event.target.closest('[data-row-index]'));
        setTimeout(() => { showingInvalid = false; }, 0);
    }, true);
    function revealHash() {
        const id = location.hash.slice(1);
        if (id.startsWith('survey-row-')) reveal(document.getElementById(id));
    }
    window.addEventListener('hashchange', revealHash);
    render();
    const invalidRow = rows.find(row => row.hasAttribute('data-row-errors'));
    if (invalidRow) reveal(invalidRow);
    revealHash();
})();
