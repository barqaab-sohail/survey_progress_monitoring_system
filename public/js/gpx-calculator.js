(() => {
    'use strict';
    document.querySelectorAll('[data-gpx-project]').forEach(project => {
        const feeder = project.closest('form').querySelector('[data-gpx-feeder]');
        const filter = () => {
            for (const option of feeder.options) {
                if (!option.value) continue;
                option.hidden = option.dataset.project !== project.value;
                option.disabled = option.hidden;
            }
            if (feeder.selectedOptions[0]?.disabled) feeder.value = '';
        };
        project.addEventListener('change', filter);
        filter();
    });
    const form = document.getElementById('gpx-calculator');
    if (!form) return;
    const initial = JSON.parse(document.getElementById('gpx-initial').textContent);
    const field = name => form.querySelector(`[name="${name}"]:not([type="hidden"])`);
    const el = id => document.getElementById(id);
    const rows = el('gpx-span-rows');
    const status = el('gpx-preview-status');
    const error = el('gpx-preview-error');
    const exportButton = el('gpx-export');
    const overrides = new Map(Object.entries(initial.settings.sag_overrides || {}));
    const fmt = value => value === null || value === undefined ? '—' : Number(value).toLocaleString(undefined, {minimumFractionDigits: 3, maximumFractionDigits: 3});
    let timer, controller, requestId = 0;
    const node = (tag, text, parent) => {
        const result = document.createElement(tag);
        if (text !== undefined) result.textContent = text;
        if (parent) parent.append(result);
        return result;
    };
    const toggleOptions = () => {
        const connected = field('span_sequences').value.trim() !== '' && field('connections_confirmed').checked;
        field('supports_confirmed').disabled = !connected;
        if (!connected) field('supports_confirmed').checked = false;
        field('include_sag').disabled = !connected || !field('supports_confirmed').checked;
        if (field('include_sag').disabled) field('include_sag').checked = false;
        field('default_sag').disabled = !field('include_sag').checked;
        field('default_sag').required = field('include_sag').checked;
        field('missing_elevation').disabled = !field('include_elevation').checked;
        rows.querySelectorAll('input').forEach(input => input.disabled = !field('include_sag').checked);
    };
    const render = result => {
        el('gpx-summary').replaceChildren();
        const metric = (title, value, note) => {
            const box = node('div', undefined, el('gpx-summary'));
            node('span', title, box); node('strong', value, box);
            if (note) node('small', note, box);
        };
        metric('Points / included spans', `${result.point_count} / ${result.valid_spans}`, `${result.span_count} defined; ${result.excluded_spans} excluded; ${result.blocked_spans} blocked`);
        metric('Total horizontal length', `${fmt(result.horizontal_m)} m`, result.horizontal_complete ? 'All defined spans' : 'Partial: invalid coordinates remain');
        if (field('include_elevation').checked) metric('Length including elevation', `${fmt(result.straight_m)} m`, 'Included spans; explicit horizontal fallbacks identified below');
        if (field('include_sag').checked) metric('Additional length due to sag', `${fmt(result.sag_additional_m)} m`, 'Sum of individual span additions');
        metric('Final calculated total', result.complete ? `${fmt(result.final_m)} m` : 'Needs review', result.complete ? `${Number(result.final_km).toFixed(6)} km` : `Calculated subtotal: ${fmt(result.subtotal_m)} m`);
        el('gpx-result-label').textContent = `${result.label}${result.excluded_spans ? ' — included spans only (explicit exclusions)' : ''}.`;
        el('gpx-warning-count').textContent = `(${result.warnings.length})`;
        el('gpx-warning-list').replaceChildren();
        for (const warning of result.warnings) node('li', warning, el('gpx-warning-list'));
        // Preserve an override field's focus when its live result arrives.
        const focusedId = document.activeElement?.dataset.spanId;
        rows.replaceChildren();
        rows.removeAttribute('aria-busy');
        rows.style.opacity = '';
        for (const span of result.spans) {
            const tr = node('tr', undefined, rows);
            const label = node('td', span.id, tr); node('small', span.group_name, label);
            for (const point of [span.start, span.end]) {
                const td = node('td', `${point.name} (${point.id})`, tr);
                node('small', point.valid ? `${point.lat}, ${point.lon}` : 'Invalid coordinates', td);
                node('small', point.elevation === null ? 'Elevation missing' : `Elevation: ${point.elevation} m`, td);
            }
            for (const value of [span.horizontal_m, span.elevation_difference_m, span.straight_m]) node('td', fmt(value), tr);
            const sag = node('td', fmt(span.sag_m), tr);
            const input = node('input', undefined, sag);
            input.type = 'number'; input.min = '0'; input.max = '1000'; input.step = 'any';
            input.dataset.spanId = span.id;
            input.name = `sag_overrides[${span.id}]`;
            input.value = overrides.get(span.id) ?? '';
            input.placeholder = 'Default sag';
            input.setAttribute('aria-label', `Sag override for ${span.start.name} to ${span.end.name} in metres`);
            input.disabled = !field('include_sag').checked;
            node('td', fmt(span.final_m), tr);
            const state = node('td', span.status.replaceAll('_', ' '), tr);
            state.className = span.final_m === null ? 'gpx-status-blocked' : 'gpx-status-ok';
            for (const warning of span.warnings) node('small', warning, state);
            if (focusedId === span.id) input.focus({preventScroll: true});
        }
        if (!result.spans.length) node('td', 'No spans defined. Review the points and enter separate sequences for each chain or branch.', node('tr', undefined, rows)).colSpan = 9;
        exportButton.disabled = !result.complete;
    };
    const preview = async () => {
        const id = ++requestId;
        controller?.abort();
        controller = new AbortController();
        const data = new FormData(form);
        for (const [key, value] of overrides) data.set(`sag_overrides[${key}]`, value);
        try {
            const response = await fetch(form.dataset.previewUrl, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}, signal: controller.signal});
            if (id !== requestId) return;
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : (result.message || 'Calculation failed.'));
            error.hidden = true;
            render(result);
            status.textContent = 'Preview recalculated. Save calculation to retain changes; Export KMZ also saves current settings.';
        } catch (exception) {
            if (exception.name === 'AbortError' || id !== requestId) return;
            error.textContent = exception.message;
            error.hidden = false;
            status.textContent = 'Preview unavailable. Correct the fields below; no final result is available for these settings.';
            rows.replaceChildren();
            node('td', 'No result for the current settings. Correct the validation messages to recalculate.', node('tr', undefined, rows)).colSpan = 9;
            rows.removeAttribute('aria-busy');
            rows.style.opacity = '';
            exportButton.disabled = true;
        }
    };
    const changed = event => {
        if (event.target.matches('[data-gpx-project],[data-gpx-feeder]')) return;
        if (event.target.dataset.spanId) {
            if (event.target.value === '') overrides.delete(event.target.dataset.spanId);
            else overrides.set(event.target.dataset.spanId, event.target.value);
        }
        if (event.target.name === 'span_sequences') {
            field('connections_confirmed').checked = false;
            field('supports_confirmed').checked = false;
            field('include_sag').checked = false;
        }
        toggleOptions();
        clearTimeout(timer);
        ++requestId;
        controller?.abort();
        exportButton.disabled = true;
        el('gpx-summary').replaceChildren();
        el('gpx-result-label').textContent = 'Calculation pending for the current settings.';
        rows.setAttribute('aria-busy', 'true');
        rows.style.opacity = '0.5';
        status.textContent = 'Recalculating… The span table shows previous results until calculation finishes.';
        timer = setTimeout(preview, 500);
    };
    form.addEventListener('input', changed);
    form.addEventListener('change', event => {
        // input fires on modern checkboxes/selects too; debounce coalesces both events.
        if (event.target.tagName === 'SELECT' || event.target.type === 'checkbox') changed(event);
    });
    form.addEventListener('formdata', event => {
        // Keep overrides when a sequence temporarily removes a row from the table.
        for (const [key, value] of overrides) event.formData.set(`sag_overrides[${key}]`, value);
    });
    el('gpx-copy-segments').addEventListener('click', () => {
        const sequences = initial.source.groups.filter(group => group.kind !== 'waypoints' && group.points.length > 1).map(group => group.points.join(', '));
        if (!sequences.length) {
            error.textContent = 'This file has no route/track sequences. Define waypoint spans from confirmed survey connectivity.';
            error.hidden = false;
            return;
        }
        field('span_sequences').value = sequences.join('\n');
        changed({target: field('span_sequences')});
    });
    toggleOptions();
    render(initial.result);
    if (Object.keys(initial.settings).includes('_token')) preview();
})();
