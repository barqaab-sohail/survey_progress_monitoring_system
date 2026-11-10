export function installServiceCableEntry({active, data, editable, api, accept, isBusy, setBusy, isoDate, displayDate, completeWaypoint, waypointMatches}) {
    const root = document.getElementById('entry-service-cables');
    if (!root) return {render() {}, setBusy() {}};
    const $ = id => document.getElementById(id);
    const form = $('service-cable-form');
    const control = name => form?.elements.namedItem(name);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let transformerId = null, cableId = null, dirty = false;
    const drafts = new Map();
    const canEdit = () => !!data().service_cables_can_enter && editable();
    const message = (value, failed = false) => {
        $('service-cable-status').textContent = value;
        $('service-cable-status').className = failed ? 'entry-error-summary' : 'mdb-note';
    };
    const values = () => form ? Object.fromEntries([...form.elements].filter(el => el.name).map(el => [el.name, el.value])) : {};
    function options(select, entries, selected = '', empty = 'Choose') {
        if (!select) return;
        select.replaceChildren(new Option(empty, ''));
        for (const [value, label] of entries) select.add(new Option(label, String(value)));
        select.value = selected;
    }
    function identity() {
        if (!form) return;
        try {
            control('composite_identifier').value = completeWaypoint(control('group_number').value, control('row_date').value,
                control('waypoint_reference').value, data().entry_settings?.two_digit_year_start ?? 2000);
        } catch { control('composite_identifier').value = ''; }
        const previous = control('meter_waypoint_id').value;
        const matches = waypointMatches({waypoint_reference:control('waypoint_reference').value, gpx_source_id:Number(control('gpx_source_id').value),
            row_date:isoDate(control('row_date').value, data().entry_settings?.two_digit_year_start ?? 2000)}, data().waypoints || []);
        options(control('meter_waypoint_id'), matches.map(p => [p.id, `${p.name} | ${p.latitude}, ${p.longitude}${p.recorded_at ? ' | '+p.recorded_at : ''}`]),
            matches.some(p => String(p.id) === previous) ? previous : matches.length === 1 ? matches[0].id : '');
        coordinates();
    }
    function coordinates() {
        const point = (data().waypoints || []).find(p => p.id === Number(control('meter_waypoint_id')?.value));
        $('service-cable-coordinates').textContent = point
            ? `Meter coordinates from GPX: ${point.latitude}, ${point.longitude}. ${control('measured_length_m').value ? 'Recorded measured cable length will be used.' : 'Cable distance will be an estimate between the pole and meter coordinates.'}`
            : 'Select the actual recorded meter waypoint. No next waypoint number is assumed.';
    }
    function seed(row = null) {
        if (!form) return;
        const t = active();
        const e = row || t?.rows?.find(r => r.id === Number(control('entry_row_id').value)) || t?.rows?.find(r => r.designation === 'E');
        const category = control('category').value || 'rs';
        const selected = Number(control('entry_row_id').value) || e?.id;
        const n = (t?.service_cables || []).filter(c => c.entry_row_id === selected && c.category === category).map(c => c.consumer_number);
        let next = 1; while (n.includes(next)) next++;
        control('consumer_number').value = next;
        if (e) {
            control('entry_row_id').value = e.id;
            control('group_number').value = e.group_number || '';
            control('row_date').value = displayDate(e.row_date);
            control('gpx_source_id').value = e.gpx_source_id || '';
            control('source_pdf_id').value = e.source_pdf_id || '';
            control('source_page').value = e.source_page || '';
        }
        identity();
    }
    function reset() {
        cableId = null; dirty = false;
        if (form) {form.reset(); seed();}
        drafts.delete(transformerId);
    }
    function lock(value = isBusy()) {
        $('service-cables-enabled').disabled = value || !canEdit() || !active();
        form?.querySelectorAll('input,select,textarea,button').forEach(el => el.disabled = value || !canEdit());
        root.querySelectorAll('[data-service-edit],[data-service-delete]').forEach(el => el.disabled = value || !canEdit());
    }
    async function run(path, method, body, success, clear = false) {
        if (isBusy() || !canEdit()) return;
        setBusy(true); lock(true);
        try {
            const result = await api(path, method, {...body, revision:data().revision});
            if (clear) {cableId = null; dirty = false; drafts.delete(transformerId);}
            accept(result);
            if (clear) reset();
            message(success);
        } catch (error) {
            message(Object.values(error.errors || {}).flat().join(' ') || error.message, true);
            $('service-cables-enabled').checked = !!active()?.service_cables_enabled;
        } finally {setBusy(false); lock(false);}
    }
    function render() {
        const t = active();
        root.hidden = !t || !Object.hasOwn(t, 'service_cables_enabled');
        if (root.hidden) return;
        const current = values();
        if (transformerId && transformerId !== t.id && dirty) drafts.set(transformerId, {values:current, cableId});
        const changed = transformerId !== t.id;
        transformerId = t.id;
        $('service-cables-enabled').checked = !!t.service_cables_enabled;
        $('service-cable-content').hidden = !t.service_cables_enabled;
        const ends = (t.rows || []).filter(row => row.designation === 'E');
        const cables = t.service_cables || [];
        $('service-cable-coverage').innerHTML = ends.map(row => {
            const total = Object.entries(row.consumers || {}).filter(([category]) => category !== 'pv').reduce((sum, [,n]) => sum + Number(n || 0), 0);
            const count = cables.filter(c => c.entry_row_id === row.id).length;
            return `<p>E ${esc(row.composite_identifier || row.waypoint_reference)}: ${total} consumers; ${count} meter waypoints recorded; ${Math.max(0,total-count)} without meter waypoints.</p>`;
        }).join('') || '<p>Save an S/E pair with consumer counts before adding meter connections.</p>';
        $('service-cable-list').innerHTML = cables.length ? '<table class="table"><thead><tr><th>E pole / consumer</th><th>Meter waypoint</th><th>Meter / phase</th><th>Length</th><th>Source</th><th></th></tr></thead><tbody>'+cables.map(c => {
            const row = ends.find(e => e.id === c.entry_row_id);
            return `<tr><td>${esc(row?.waypoint_reference)} / ${esc(c.category.toUpperCase())} ${c.consumer_number}</td><td>${esc(c.composite_identifier)}</td><td>${esc(c.meter_number || c.consumer_reference || 'Not recorded')} / ${esc(c.phase || 'Not recorded')}</td><td>${c.measured_length_m ?? 'Coordinate estimate'}${c.measured_length_m ? ' m (measured)' : ''}</td><td>PDF #${c.source_pdf_id}, page ${c.source_page}<small>${esc(c.source_row)}</small></td><td>${canEdit() ? `<button type="button" class="btn btn-sm btn-light" data-service-edit="${c.id}">Edit</button> <button type="button" class="btn btn-sm btn-light" data-service-delete="${c.id}">Delete</button>` : ''}</td></tr>`;
        }).join('')+'</tbody></table>' : '<p>No meter waypoints entered. Existing consumer generation applies.</p>';
        if (form) {
            const retained = changed ? drafts.get(t.id) : {values:current, cableId};
            options(control('entry_row_id'), ends.map(e => [e.id, `E ${e.composite_identifier || e.waypoint_reference} (pair ${e.pair_number})`]), retained?.values?.entry_row_id || ends[0]?.id);
            options(control('gpx_source_id'), data().sources.filter(s => s.kind === 'gpx' && s.status === 'ready').map(s => [s.id,s.original_name]), retained?.values?.gpx_source_id);
            options(control('source_pdf_id'), data().sources.filter(s => s.kind === 'pdf').map(s => [s.id,s.original_name]), retained?.values?.source_pdf_id);
            if (changed) {
                cableId = retained?.cableId || null; dirty = !!retained;
                if (!retained) {form.reset();control('entry_row_id').value=ends[0]?.id || '';seed();}
            }
            if (retained) {
                for (const [name, value] of Object.entries(retained.values)) if (control(name) && name !== 'meter_waypoint_id') control(name).value = value;
                identity();
                if (retained.values.meter_waypoint_id) control('meter_waypoint_id').value = retained.values.meter_waypoint_id;
                coordinates();
            }
        }
        lock();
    }
    $('service-cables-enabled').addEventListener('change', () => {
        const enabled = $('service-cables-enabled').checked;
        run(`/transformers/${active().id}/service-cables/settings`, 'PUT', {enabled}, enabled ? 'Meter waypoint entry enabled for this transformer.' : 'Meter waypoint entry disabled.');
    });
    form?.addEventListener('input', event => {
        dirty = true;
        if (['group_number','row_date','waypoint_reference','gpx_source_id'].includes(event.target.name)) identity();
        if (event.target.name === 'meter_waypoint_id' || event.target.name === 'measured_length_m') coordinates();
    });
    form?.addEventListener('change', event => {
        if (event.target.name === 'entry_row_id') seed(active()?.rows.find(r => r.id === Number(control('entry_row_id').value)));
        if (event.target.name === 'category') seed();
    });
    form?.addEventListener('submit', event => {
        event.preventDefault();
        const body = values();
        body.phase = body.recorded_phase;
        delete body.recorded_phase;
        try {body.row_date = isoDate(body.row_date, data().entry_settings?.two_digit_year_start ?? 2000); completeWaypoint(body.group_number, body.row_date, body.waypoint_reference, data().entry_settings?.two_digit_year_start ?? 2000);}
        catch (error) {message(error.message, true);return;}
        delete body.composite_identifier;
        for (const key of ['entry_row_id','consumer_number','gpx_source_id','meter_waypoint_id','source_pdf_id','source_page','measured_length_m']) body[key] = body[key] === '' ? null : Number(body[key]);
        for (const key of Object.keys(body)) if (body[key] === '') body[key] = null;
        run(`/transformers/${active().id}/service-cables${cableId ? '/'+cableId : ''}`, cableId ? 'PUT' : 'POST', body, 'Meter connection saved. Consumer totals are unchanged. Review the transformer again before submission.', true);
    });
    $('service-cable-cancel')?.addEventListener('click', reset);
    root.addEventListener('click', event => {
        const edit = event.target.closest('[data-service-edit]');
        const remove = event.target.closest('[data-service-delete]');
        if (edit && form && !isBusy()) {
            const cable = active().service_cables.find(c => c.id === Number(edit.dataset.serviceEdit));
            cableId = cable.id;
            for (const [name,value] of Object.entries(cable)) {
                const input = control(name === 'phase' ? 'recorded_phase' : name);
                if (input) input.value = name === 'row_date' ? displayDate(value) : value ?? '';
            }
            identity(); control('meter_waypoint_id').value = cable.meter_waypoint_id;coordinates();
            $('service-cable-editor').open = true;dirty = false;form.scrollIntoView({block:'nearest'});
        }
        if (remove && !isBusy() && window.confirm('Remove this meter connection? The consumer count at the E pole will stay the same.')) {
            run(`/transformers/${active().id}/service-cables/${remove.dataset.serviceDelete}`, 'DELETE', {}, 'Meter connection removed. Consumer totals are unchanged.', true);
        }
    });
    window.addEventListener('beforeunload', event => {if (dirty) {event.preventDefault();event.returnValue='';}});
    return {render, setBusy:lock};
}
