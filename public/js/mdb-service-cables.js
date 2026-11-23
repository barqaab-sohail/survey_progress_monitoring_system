import {saveFeedback, savedTime} from './mdb-save-feedback.js?v=2';
export function serviceCableDistance(pole, meter) {
    const a = pole?.service_coordinates, b = meter?.service_coordinates;
    if (!a || !b || ![a.x,a.y,b.x,b.y].every(v => typeof v === 'number' && Number.isFinite(v))) return null;
    return Math.hypot(a.x - b.x, a.y - b.y);
}

export function parseServiceCableRoute(text) {
    const lines = String(text || '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
    if (!lines.length) return [];
    if (lines.length < 2) throw new Error('Record at least the starting pole and ending meter coordinates.');
    if (lines.length > 500) throw new Error('A service cable route may contain at most 500 points.');
    return lines.map((line, index) => {
        const parts = line.split(',').map(value => value.trim());
        const latitude = Number(parts[0]), longitude = Number(parts[1]);
        if (parts.length < 2 || !parts[0] || !parts[1] || !Number.isFinite(latitude) || !Number.isFinite(longitude)
            || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
            throw new Error(`Route point ${index + 1}: enter latitude, longitude in decimal degrees.`);
        }
        const waypoint = parts.slice(2).join(',').trim();
        if (waypoint.length > 100) throw new Error(`Route point ${index + 1}: the waypoint label is too long (maximum 100 characters).`);
        return {latitude, longitude, ...(waypoint ? {waypoint} : {})};
    });
}

export function serviceCableRouteLength(points) {
    if (!Array.isArray(points) || points.length < 2) return null;
    const radians = value => value * Math.PI / 180;
    return points.slice(1).reduce((length, point, index) => {
        const previous = points[index];
        const a = Math.sin(radians(point.latitude - previous.latitude) / 2) ** 2
            + Math.cos(radians(previous.latitude)) * Math.cos(radians(point.latitude))
            * Math.sin(radians(point.longitude - previous.longitude) / 2) ** 2;
        return length + 6371008.8 * 2 * Math.atan2(Math.sqrt(Math.min(1, a)), Math.sqrt(Math.max(0, 1 - a)));
    }, 0);
}

export function installServiceCableEntry({active, data, editable, api, accept, isBusy, setBusy, isoDate, displayDate, completeWaypoint, waypointMatches}) {
    const root = document.getElementById('entry-service-cables');
    if (!root) return {render() {}, setBusy() {}};
    const $ = id => document.getElementById(id);
    const form = $('service-cable-form');
    const control = name => form?.elements.namedItem(name);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let transformerId = null, cableId = null, dirty = false;
    const drafts = new Map();
    let page = 1;
    const pageSize = 25;
    let advanceAfterSave = true;
    const guardDraft = () => !dirty || window.confirm('Discard unsaved cable changes? Save first to keep them.');
    function openEditor() {
        $('service-cable-editor').open = true;
        if ($('service-cable-editor-title')) $('service-cable-editor-title').textContent = cableId ? 'Edit service cable' : 'Add service cable';
        control('meter_number')?.focus({preventScroll:true});
    }
    const canEdit = () => !!data().service_cables_can_enter && editable();
    const message = (value, failed = false) => {
        saveFeedback($('service-cable-save-status'), value, failed ? 'error' : 'saved');
        $('service-cable-status').textContent = value;
        $('service-cable-status').className = failed ? 'entry-error-summary' : 'mdb-note';
    };
    const values = () => form ? Object.fromEntries([...form.elements].filter(el => el.name).map(el => [el.name, el.value])) : {};
    const routeText = points => (Array.isArray(points) ? points : []).map(point => `${point.latitude}, ${point.longitude}${point.waypoint ? ', '+point.waypoint : ''}`).join('\n');
    function routePreview() {
        const input = control('route_points');
        const output = $('service-cable-route-preview');
        if (!input) return;
        try {
            const points = parseServiceCableRoute(input.value);
            input.setCustomValidity('');
            if (output) output.textContent = points.length
                ? `${points.length} recorded route points · ${serviceCableRouteLength(points).toFixed(2)} m along the full route. The server confirms the saved length.`
                : 'No mapped route recorded. Enter all route points to calculate the actual path; endpoint estimates are excluded from the feeder Excel export.';
        } catch (error) {
            input.setCustomValidity(error.message);
            if (output) output.textContent = error.message;
        }
    }
    function options(select, entries, selected = '', empty = 'Choose') {
        if (!select) return;
        select.replaceChildren(new Option(empty, ''));
        for (const [value, label] of entries) select.add(new Option(label, String(value)));
        select.value = selected;
    }
    function inheritPoleIdentity() {
        const pole = active()?.rows?.find(r => r.id === Number(control('entry_row_id')?.value));
        for (const name of ['group_number', 'row_date']) control(name).readOnly = true;
        control('group_number').value = pole?.group_number || '';
        control('row_date').value = pole?.row_date ? displayDate(pole.row_date) : '';
        for (const name of ['gpx_source_id', 'source_pdf_id']) {
            control(name).value = pole?.[name] || '';
            control(name).disabled = true;
        }
    }
    function identity() {
        if (!form) return;
        inheritPoleIdentity();
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
    function polePoint(row = active()?.rows?.find(r => r.id === Number(control('entry_row_id')?.value))) {
        const matches = row ? waypointMatches(row, data().waypoints || []) : [];
        return matches.length === 1 ? matches[0] : null;
    }
    function sameLocation(a, b) {
        return a && b && Number(a.latitude) === Number(b.latitude) && Number(a.longitude) === Number(b.longitude);
    }
    function cableDefault() {
        control('conductor_code').value = ['rs','sc','st'].includes(control('category').value) ? 'PVC 7/0.052' : 'PVC 19/0.052';
        control('recorded_phase').value = ['rs','sc','st'].includes(control('category').value) ? '' : 'RYB';
    }
    function suggestWaypoint(e) {
        const cables = active()?.service_cables || [];
        const shared = cables.filter(c => c.entry_row_id === e.id && c.group_number === control('group_number').value
            && displayDate(c.row_date) === control('row_date').value);
        let next = Math.max(Number(e.waypoint_reference), ...shared.map(c => Number(c.waypoint_reference))) + 1;
        const used = [...(active()?.rows || []), ...cables];
        while (next <= 999 && used.some(r => r.group_number === control('group_number').value
            && displayDate(r.row_date) === control('row_date').value && Number(r.waypoint_reference) === next)) next++;
        control('waypoint_reference').value = next <= 999 ? String(next).padStart(3, '0') : '';
    }
    function lengthLabel(measured, pole, meter, routeLength = null) {
        if (routeLength !== null && routeLength !== undefined && routeLength !== '' && Number.isFinite(Number(routeLength))) return `${Number(routeLength).toFixed(2)} m (mapped route)`;
        if (measured !== null && measured !== undefined && measured !== '' && Number.isFinite(Number(measured))) return `${Number(measured).toFixed(2)} m (measured)`;
        const length = serviceCableDistance(pole, meter);
        return length === null ? 'Estimate unavailable (check E / meter coordinates)' : `${length.toFixed(2)} m (coordinate estimate)`;
    }
    function coordinates() {
        routePreview();
        const point = (data().waypoints || []).find(p => p.id === Number(control('meter_waypoint_id')?.value));
        const pole = polePoint();
        let mappedLength = null;
        try { mappedLength = serviceCableRouteLength(parseServiceCableRoute(control('route_points')?.value)); } catch { /* The route field displays validation feedback. */ }
        $('service-cable-coordinates').textContent = point
            ? `Meter coordinates from GPX: ${point.latitude}, ${point.longitude}. Service cable length: ${lengthLabel(control('measured_length_m').value, pole, point, mappedLength)}. ${sameLocation(pole, point) ? 'Same location as E (pole-mounted meter); this remains a separate meter waypoint. ' : ''}${mappedLength !== null ? 'The saved mapped route length will be used for the feeder Excel export.' : control('measured_length_m').value ? 'Recorded measured cable length will be used.' : sameLocation(pole, point) ? 'Coordinate distance is 0 m; enter a measured length if recorded.' : 'Endpoint coordinate distance is an estimate and is excluded from the feeder Excel export.'}`
            : 'The suggested waypoint must match an actual imported GPX meter record. Check the survey sheet if no match is available.';
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
        if (e) suggestWaypoint(e);
        cableDefault();
        identity();
    }
    function reset(context = null) {
        cableId = null; dirty = false;
        if ($('service-cable-editor-title')) $('service-cable-editor-title').textContent = 'Add service cable';
        if (form) {
            const previous = context || values();
            form.reset();
            control('entry_row_id').value = previous.entry_row_id || '';
            control('category').value = previous.category || 'rs';
            const eRow = active()?.rows.find(r => r.id === Number(control('entry_row_id').value));
            const available = [...control('category').options].find(option => {
                const recorded = eRow?.consumers?.[option.value];
                const count = recorded == null || recorded === '' ? Infinity : Number(recorded);
                return count > (active()?.service_cables || []).filter(c => c.entry_row_id === eRow?.id && c.category === option.value).length;
            });
            const recorded = eRow?.consumers?.[control('category').value];
            const currentCount = recorded == null || recorded === '' ? Infinity : Number(recorded);
            const entered = (active()?.service_cables || []).filter(c => c.entry_row_id === eRow?.id && c.category === control('category').value).length;
            if (entered >= currentCount && available) control('category').value = available.value;
            seed();
            if (context) {
                for (const key of ['group_number','row_date','gpx_source_id','source_pdf_id','source_page']) control(key).value = context[key];
                const e = active()?.rows.find(r => r.id === Number(context.entry_row_id));
                if (e) suggestWaypoint(e);
                identity();
            }
        }
        drafts.delete(transformerId);
    }
    function lock(value = isBusy()) {
        $('service-cables-enabled').disabled = value || !canEdit() || !active();
        if ($('service-cable-add')) $('service-cable-add').disabled = value || !canEdit();
        form?.querySelectorAll('input,select,textarea,button').forEach(el => el.disabled = value || !canEdit() || ['gpx_source_id','source_pdf_id'].includes(el.name));
        root.querySelectorAll('[data-service-edit],[data-service-delete]').forEach(el => el.disabled = value || !canEdit());
    }
    async function run(path, method, body, success, clear = false, advance = true) {
        if (isBusy() || !canEdit()) return;
        const context = clear ? values() : null;
        let completedPole = false, saved = false;
        form?.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
        setBusy(true); lock(true);
        saveFeedback($('service-cable-save-status'), 'Saving...', 'saving');
        try {
            const result = await api(path, method, {...body, revision:data().revision});
            const confirmed = clear && ['POST','PUT'].includes(method) ? result.transformers?.find(t => t.id === transformerId)?.service_cables?.find(c =>
                c.entry_row_id === body.entry_row_id && c.category === body.category && Number(c.consumer_number) === body.consumer_number && Number(c.meter_waypoint_id) === body.meter_waypoint_id) : null;
            if (clear && ['POST','PUT'].includes(method) && !confirmed) throw new Error('The server did not confirm the saved cable. Your fields are retained; refresh saved records before retrying.');
            if (clear) {cableId = null; dirty = false; drafts.delete(transformerId);}
            accept(result);
            if (clear) reset(context);
            if (confirmed && !advance) {
                cableId = confirmed.id;
                if ($('service-cable-editor-title')) $('service-cable-editor-title').textContent = 'Edit service cable';
                if (control('route_points')) control('route_points').value = routeText(confirmed.route_points);
                for (const [name,value] of Object.entries(confirmed)) {
                    const input = control(name === 'phase' ? 'recorded_phase' : name);
                    if (input) input.value = name === 'row_date' ? displayDate(value) : name === 'route_points' ? routeText(value) : value ?? '';
                }
                identity();control('meter_waypoint_id').value = confirmed.meter_waypoint_id;coordinates();
            }
            saved = true;
            if (clear && ['POST','PUT'].includes(method)) {
                const row = active()?.rows?.find(r => r.id === Number(context.entry_row_id));
                const counts = Object.entries(row?.consumers || {}).filter(([category]) => category !== 'pv');
                const cables = (active()?.service_cables || []).filter(c => c.entry_row_id === row?.id);
                completedPole = counts.some(([, count]) => Number(count) > 0) && counts.every(([category, count]) => {
                    const slots = new Set(cables.filter(c => c.category === category).map(c => Number(c.consumer_number)));
                    return count != null && count !== '' && slots.size === Number(count);
                });
                if (completedPole && advance) $('service-cable-editor').open = false;
            }
            const confirmation = confirmed ? `Service cable ${confirmed.composite_identifier || body.waypoint_reference} saved at ${savedTime()}` : success;
            saveFeedback($('service-cable-save-status'),confirmation,'saved',true);
            message(completedPole ? 'All consumer meters for this E pole are saved. Continue with the next pole entry. '+confirmation : confirmation);
        } catch (error) {
            for (const name of Object.keys(error.errors || {})) control(name === 'phase' ? 'recorded_phase' : name.startsWith('route_points') ? 'route_points' : name)?.setAttribute('aria-invalid','true');
            const failure = Object.values(error.errors || {}).flat().join(' ') || error.message;
            message('Not saved. '+failure+' Your entered fields are retained. Use Save to retry.', true);saveFeedback($('service-cable-save-status'),failure,'error',true);
            $('service-cables-enabled').checked = !!active()?.service_cables_enabled;
        } finally {
            setBusy(false); lock(false);
            if (saved && completedPole && advance) {
                const poleForm = $('entry-row-form');
                poleForm?.scrollIntoView?.({block:'start', behavior:'smooth'});
                const target = poleForm?.querySelector('input:not([type="hidden"]):not([disabled]):not([readonly]), select:not([disabled])')
                    || $('service-cable-editor')?.querySelector('summary');
                target?.focus({preventScroll:true});
            } else if (saved && clear) control('meter_number')?.focus();
        }
    }
    function render() {
        const t = active();
        root.hidden = !t || !Object.hasOwn(t, 'service_cables_enabled');
        if (root.hidden) return;
        const current = values();
        if (transformerId && transformerId !== t.id && dirty) drafts.set(transformerId, {values:current, cableId});
        const changed = transformerId !== t.id;
        if (changed) { message('');page = 1; if ($('service-cable-search')) $('service-cable-search').value = ''; if ($('service-cable-filter-pole')) $('service-cable-filter-pole').value = ''; }
        transformerId = t.id;
        $('service-cables-enabled').checked = !!t.service_cables_enabled;
        $('service-cable-content').hidden = !t.service_cables_enabled;
        const ends = (t.rows || []).filter(row => row.designation === 'E');
        const cables = t.service_cables || [];
        $('service-cable-coverage').innerHTML = ends.map(row => {
            const total = Object.entries(row.consumers || {}).filter(([category]) => category !== 'pv').reduce((sum, [category,n]) => sum + (n == null || n === '' ? (t.service_cables || []).filter(c => c.entry_row_id === row.id && c.category === category).length : Number(n)), 0);
            const count = cables.filter(c => c.entry_row_id === row.id).length;
            return `<p>E ${esc(row.composite_identifier || row.waypoint_reference)}: ${total} consumers; ${count} meter waypoints recorded; ${Math.max(0,total-count)} without meter waypoints.</p>`;
        }).join('') || '<p>Save an S/E pair before adding meter connections. Leave consumer counts blank to determine them from service cables.</p>';
        if ($('service-cable-count')) $('service-cable-count').textContent = '('+cables.length+')';
        const poleFilter = $('service-cable-filter-pole');
        if (poleFilter) options(poleFilter, ends.map(e => [e.id, 'E '+(e.composite_identifier || e.waypoint_reference)]), poleFilter.value, 'All poles');
        const query = ($('service-cable-search')?.value || '').trim().toLowerCase();
        const filtered = cables.filter(c => {
            const row = ends.find(e => e.id === c.entry_row_id);
            return (!poleFilter?.value || String(c.entry_row_id) === poleFilter.value)
                && (!$('service-cable-filter-status')?.value || !(c.meter_number || c.consumer_reference) || !c.phase)
                && [c.composite_identifier,c.waypoint_reference,c.meter_number,c.consumer_reference,c.category,c.source_row,row?.composite_identifier,row?.waypoint_reference].some(value => String(value ?? '').toLowerCase().includes(query));
        });
        const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
        page = Math.max(1, Math.min(page, pages));
        if ($('service-cable-page')) $('service-cable-page').textContent = 'Page '+page+' of '+pages+' / '+filtered.length+' matching cables';
        if ($('service-cable-prev')) $('service-cable-prev').disabled = page === 1;
        if ($('service-cable-next')) $('service-cable-next').disabled = page === pages;
        $('service-cable-list').innerHTML = filtered.length ? '<table class="table"><thead><tr><th>E pole / consumer</th><th>Meter waypoint</th><th>Phase</th><th>Length</th><th>Source</th><th></th></tr></thead><tbody>'+filtered.slice((page-1)*pageSize,page*pageSize).map(c => {
            const row = ends.find(e => e.id === c.entry_row_id);
            return `<tr><td>${esc(row?.waypoint_reference)} / ${esc(c.category.toUpperCase())} ${c.consumer_number}</td><td>${esc(c.composite_identifier)}</td><td>${esc(c.phase || 'Not recorded')}</td><td>${lengthLabel(c.measured_length_m, polePoint(row), (data().waypoints || []).find(p => p.id === Number(c.meter_waypoint_id)), c.route_length_m)}</td><td>PDF #${c.source_pdf_id}, page ${c.source_page}<small>${esc(c.source_row)}</small></td><td>${canEdit() ? `<button type="button" class="btn btn-sm btn-light" data-service-edit="${c.id}">Edit</button> <button type="button" class="btn btn-sm btn-light" data-service-delete="${c.id}">Delete</button>` : ''}</td></tr>`;
        }).join('')+'</tbody></table>' : '<p>No matching meter waypoints.</p>';
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
        event.target.removeAttribute('aria-invalid');
        dirty = true;saveFeedback($('service-cable-save-status'),'Unsaved changes','dirty');
        if (['group_number','row_date','waypoint_reference','gpx_source_id'].includes(event.target.name)) identity();
        if (['meter_waypoint_id', 'measured_length_m', 'route_points'].includes(event.target.name)) coordinates();
    });
    form?.addEventListener('change', event => {
        dirty = true;saveFeedback($('service-cable-save-status'),'Unsaved changes','dirty');
        if (event.target.name === 'entry_row_id') seed(active()?.rows.find(r => r.id === Number(control('entry_row_id').value)));
        if (event.target.name === 'category') {
            const used = (active()?.service_cables || []).filter(c => c.entry_row_id === Number(control('entry_row_id').value) && c.category === control('category').value);
            let next = 1; while (used.some(c => c.consumer_number === next)) next++;
            control('consumer_number').value = next;
            cableDefault();
        }
        if (event.target.name === 'recorded_phase' && control('recorded_phase').value) {
            control('conductor_code').value = control('recorded_phase').value === 'RYB' ? 'PVC 19/0.052' : 'PVC 7/0.052';
        }
    });
    form?.addEventListener('submit', event => {
        event.preventDefault();
        identity();
        if (!form.reportValidity()) return;
        advanceAfterSave = event.submitter?.value !== 'stay';
        const body = values();
        if (control('route_points')) body.route_points = parseServiceCableRoute(body.route_points);
        const duplicate = (active()?.service_cables || []).some(c => c.id !== cableId &&
            (Number(c.meter_waypoint_id) === Number(body.meter_waypoint_id) ||
            (c.entry_row_id === Number(body.entry_row_id) && c.category === body.category && c.consumer_number === Number(body.consumer_number))));
        if (duplicate) {message('This meter waypoint or consumer already has a service connection. Check the existing records.', true); return;}
        body.phase = body.recorded_phase;
        delete body.recorded_phase;
        try {body.row_date = isoDate(body.row_date, data().entry_settings?.two_digit_year_start ?? 2000); completeWaypoint(body.group_number, body.row_date, body.waypoint_reference, data().entry_settings?.two_digit_year_start ?? 2000);}
        catch (error) {message(error.message, true);return;}
        delete body.composite_identifier;
        for (const key of ['entry_row_id','consumer_number','gpx_source_id','meter_waypoint_id','source_pdf_id','source_page','measured_length_m']) body[key] = body[key] === '' ? null : Number(body[key]);
        for (const key of Object.keys(body)) if (body[key] === '') body[key] = null;
        run(`/transformers/${active().id}/service-cables${cableId ? '/'+cableId : ''}`, cableId ? 'PUT' : 'POST', body, 'Meter connection saved. Consumer totals are unchanged. Review the transformer again before submission.', true, advanceAfterSave);
    });
    $('service-cable-cancel')?.addEventListener('click', () => {if (guardDraft()) {reset();message('New cable - not saved yet.');}});
    $('service-cable-add')?.addEventListener('click', () => {
        if (isBusy() || !guardDraft()) return;
        const selectedPole = $('service-cable-filter-pole')?.value;
        reset(selectedPole ? {...values(), entry_row_id:selectedPole} : null);
        openEditor();message('New cable - not saved yet.');
    });
    $('service-cable-close')?.addEventListener('click', () => {$('service-cable-editor').open = false;$('service-cable-add')?.focus();});
    document.addEventListener('keydown', event => {if (event.key === 'Escape' && !isBusy() && $('service-cable-editor')?.open) {$('service-cable-editor').open = false;$('service-cable-add')?.focus();}});
    for (const id of ['service-cable-search','service-cable-filter-pole','service-cable-filter-status']) $(id)?.addEventListener(id === 'service-cable-search' ? 'input' : 'change', () => {page = 1;render();});
    $('service-cable-prev')?.addEventListener('click', () => {page--;render();});
    $('service-cable-next')?.addEventListener('click', () => {page++;render();});
    $('service-cable-copy')?.addEventListener('click', () => {
        if (isBusy()) return;
        const previous = [...(active()?.service_cables || [])].reverse().find(c => c.id !== cableId && c.entry_row_id === Number(control('entry_row_id').value) && c.category === control('category').value);
        if (!previous) {message('No previous cable for this pole and category.');return;}
        control('conductor_code').value = previous.conductor_code || '';
        control('recorded_phase').value = previous.phase || '';
        dirty = true;message('Phase and cable type copied. Check these values before saving.');
        saveFeedback($('service-cable-save-status'),'Unsaved changes','dirty');
    });
    $('service-cable-pole-location')?.addEventListener('click', () => {
        const pole = polePoint();
        const matches = waypointMatches({waypoint_reference:control('waypoint_reference').value, gpx_source_id:Number(control('gpx_source_id').value),
            row_date:isoDate(control('row_date').value, data().entry_settings?.two_digit_year_start ?? 2000)}, data().waypoints || [])
            .filter(p => p.id !== pole?.id && sameLocation(pole, p));
        if (matches.length !== 1) {message('No unique meter waypoint at E coordinates matches this reference. Check the imported GPX and survey sheet.', true);return;}
        control('meter_waypoint_id').value = matches[0].id;
        dirty = true; coordinates();
    });
    $('service-cable-route-endpoints')?.addEventListener('click', () => {
        if (isBusy() || !control('route_points')) return;
        const pole = polePoint();
        const meter = (data().waypoints || []).find(point => point.id === Number(control('meter_waypoint_id')?.value));
        if (!pole || !meter) {message('Choose a unique starting pole and meter waypoint before filling route endpoints.', true);return;}
        if (sameLocation(pole, meter)) {message('Pole and meter share coordinates. Record the actual cable route or an evidenced measured length; do not use duplicate route points.', true);return;}
        if (control('route_points').value.trim() && !window.confirm('Replace the entered route with the pole and meter endpoints?')) return;
        control('route_points').value = routeText([pole, meter]);
        dirty = true;coordinates();
        saveFeedback($('service-cable-save-status'),'Unsaved changes','dirty');
    });
    root.addEventListener('click', event => {
        const edit = event.target.closest('[data-service-edit]');
        const remove = event.target.closest('[data-service-delete]');
        if (edit && form && !isBusy()) {
            if (!guardDraft()) return;
            const cable = active().service_cables.find(c => c.id === Number(edit.dataset.serviceEdit));
            cableId = cable.id;
            if (control('route_points')) control('route_points').value = routeText(cable.route_points);
            for (const [name,value] of Object.entries(cable)) {
                const input = control(name === 'phase' ? 'recorded_phase' : name);
                if (input) input.value = name === 'row_date' ? displayDate(value) : name === 'route_points' ? routeText(value) : value ?? '';
            }
            identity(); control('meter_waypoint_id').value = cable.meter_waypoint_id;coordinates();
            dirty = false;openEditor();message('Saved cable loaded for editing.');
        }
        if (remove && !isBusy() && guardDraft() && window.confirm('Remove this meter connection? Consumer totals derived from service cables will update.')) {
            run(`/transformers/${active().id}/service-cables/${remove.dataset.serviceDelete}`, 'DELETE', {}, 'Meter connection removed. Consumer totals are unchanged.', true);
        }
    });
    window.addEventListener('beforeunload', event => {if (dirty) {event.preventDefault();event.returnValue='';}});
    return {render, setBusy:lock};
}
