import {installCompactSelects} from './mdb-entry-select.js';

export function isoDate(value) {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return value;
    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value || '');
    return match ? `${match[3]}-${match[2]}-${match[1]}` : value || '';
}
export function displayDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    return match ? `${match[3]}/${match[2]}/${match[1]}` : value || '';
}
export function completeWaypoint(group, date, gps, yearStart = null) {
    if (!group || !date || !gps) return '';
    if (!/^\d{2}$/.test(group) || !/^\d{3}$/.test(gps)) throw new Error('Use two group digits and three GPS digits.');
    date = isoDate(date);
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
    if (!match) throw new Error('Use a valid date in DD/MM/YYYY.');
    const year=Number(match[1]),month=Number(match[2]),day=Number(match[3]);
    const check=new Date(Date.UTC(year,month-1,day));
    if(check.getUTCFullYear()!==year || check.getUTCMonth()!==month-1 || check.getUTCDate()!==day) throw new Error('Enter a valid calendar date.');
    if(yearStart!==null && (year<Number(yearStart) || year>=Number(yearStart)+100)) throw new Error('Date is outside the configured year window.');
    return group+match[3]+match[2]+match[1].slice(-2)+gps;
}
export function parseWaypoint(identifier, yearStart) {
    if(!/^\d{11}$/.test(identifier)) throw new Error('The complete waypoint must contain exactly 11 digits.');
    if(yearStart===null || yearStart===undefined) throw new Error('Configure the project two-digit-year window first.');
    let year=Math.floor(Number(yearStart)/100)*100+Number(identifier.slice(6,8));if(year<Number(yearStart))year+=100;
    const result={group_number:identifier.slice(0,2),row_date:`${year}-${identifier.slice(4,6)}-${identifier.slice(2,4)}`,waypoint_reference:identifier.slice(8)};
    completeWaypoint(result.group_number,result.row_date,result.waypoint_reference,yearStart);return result;
}
export function waypointMatches(row, points) {
    let matches=points.filter(p=>p.name===row.waypoint_reference && (!row.gpx_source_id || p.source_file_id===row.gpx_source_id));
    if(row.row_date){
        const dated=matches.filter(p=>p.recorded_at && new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(p.recorded_at))===row.row_date);
        if(dated.length && matches.length>1)matches=dated;
        else if(!row.gpx_source_id)matches=matches.filter(p=>!p.recorded_at || new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(p.recorded_at))===row.row_date);
    }
    return matches;
}
export function convertPoleHeight(height, from, to) {
    if(!['ft','m'].includes(from) || !['ft','m'].includes(to))throw new Error('Both units must be confirmed before conversion.');
    return height===null ? null : Math.round(Number(height)*(from===to ? 1 : from==='ft' ? .3048 : 1/.3048)*1000000)/1000000;
}

export function derivePhase(conductors) {
    const present = ['R', 'Y', 'B'].filter(key => typeof conductors[key] === 'string' && conductors[key] !== '' && conductors[key] !== '__none__');
    return {display: present.join(''), complete: ['R', 'Y', 'B'].every(key => conductors[key] !== null && conductors[key] !== undefined)};
}

export function consumerTotal(consumers) {
    return ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st', 'pv'].reduce((sum, key) => sum + (Number(consumers[key]) || 0), 0);
}

export function nextDraft(previous = {}, defaults = {}) {
    return {pair_number: previous.designation === 'S' ? Number(previous.pair_number) : Number(defaults.next_pair || 1),
        designation: previous.designation === 'S' ? 'E' : 'S', group_number: previous.group_number ?? defaults.group_number ?? '',
        row_date: previous.row_date ?? defaults.row_date ?? '', waypoint_reference: '', gpx_source_id: defaults.gpx_source_id ?? null,
        source_pdf_id: defaults.source_pdf_id ?? null, source_page: defaults.source_page ?? null, source_row: '',
        conductors: {R: null, Y: null, B: null, N: null}, equipment_type: '', pole_class: '', pole_height: null,
        pole_height_unit: defaults.pole_height_unit ?? '', consumers: Object.fromEntries(['rs','rl','sc','lc','si','li','pb','ag','st','pv'].map(key => [key,null])),
        intersection: false, pv_details: [], inheritance: previous.id ? {group_date:previous.id} : {}, manually_verified: false};
}

if (typeof window !== 'undefined' && window.mdbEntryBoot) initialize(window.mdbEntryBoot);

function initialize(boot) {
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const uuid = () => crypto.randomUUID();
    const headerForm = $('entry-header-form'), rowForm = $('entry-row-form');
    const syncSelects = installCompactSelects();
    let data = boot, activeId = boot.active_transformer_id || boot.transformers[0]?.id || null;
    let rowId = null, clientUuid = uuid(), saveUuid = null, inheritance = {}, headerDirty = false, rowDirty = false, busy = false;
    let pageNumber = 1, rotation = 0, zoom = 1, pdf = null, pdfLoading = null, pdfLib = null, pdfGeneration = 0, renderTask = null, renderChain = Promise.resolve();
    const active = () => data.transformers.find(t => t.id === Number(activeId));
    const field = (form, name) => form?.elements.namedItem(name);
    const set = (form, name, value) => {
        const element = field(form, name);
        if (!element) return;
        if (element.type === 'checkbox') element.checked = !!value;
        else {
            if (element.tagName === 'SELECT' && value !== null && value !== undefined && value !== '' && !Array.from(element.options).some(o => o.value === String(value))) element.add(new Option(`${value} — saved value; verify`, String(value)));
            element.value = name === 'row_date' ? displayDate(value) : value ?? '';
        }
    };
    const val = (form, name) => field(form, name)?.value ?? '';
    const number = value => value === '' || value === null || value === undefined ? null : Number(value);
    const sourceUrl = id => `${boot.base_url}/sources/${encodeURIComponent(id)}`;
    function status(message, state = 'saved') {
        $('entry-save-status').textContent = message;
        $('entry-save-status').parentElement.dataset.state = state;
    }
    function error(message, errors = {}) {
        const box = $('entry-errors');
        const messages = Object.values(errors).flat();
        box.innerHTML = `<strong>${esc(message)}</strong>${messages.length ? '<ul>'+messages.map(m=>`<li>${esc(m)}</li>`).join('')+'</ul>' : ''}`;
        box.hidden = false;
        document.querySelectorAll('.entry-field-error').forEach(e=>e.remove());
        document.querySelectorAll('[aria-invalid]').forEach(e=>e.removeAttribute('aria-invalid'));
        for (const [key, messages] of Object.entries(errors)) {
            const parts = key.split('.'), name = parts.shift()+parts.map(p=>`[${p}]`).join('');
            const element = field(rowForm, name) || field(headerForm, name);
            if (element) {
                element.setAttribute('aria-invalid', 'true');
                const note = document.createElement('span'); note.className = 'entry-field-error'; note.textContent = messages.join(' ');
                element.parentElement.append(note);
            }
        }
        status(message, 'error');
    }
    function clearErrors() {
        $('entry-errors').hidden = true;
        document.querySelectorAll('.entry-field-error').forEach(e=>e.remove());
        document.querySelectorAll('[aria-invalid]').forEach(e=>e.removeAttribute('aria-invalid'));
    }
    function setBusy(value) {
        busy = value;
        [rowForm, headerForm].filter(Boolean).forEach(form => Array.from(form.elements).forEach(el => el.disabled = value));
        $('entry-transformer').disabled = value;
        document.querySelectorAll('[data-entry-add-transformer],#entry-continue,#entry-refresh').forEach(el=>el.disabled=value);
        if (!value) { updateAssociation(); if(rowId && rowForm){field(rowForm,'pair_number').disabled=true;field(rowForm,'designation').disabled=true;} }
        syncSelects();
    }
    async function api(path, method = 'GET', body) {
        const response = await fetch(`${boot.base_url}${path}`, {method, credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, ...(body ? {body:JSON.stringify(body)} : {})});
        const result = await response.json().catch(()=>({message:'The server returned an unexpected response. Your fields are retained.'}));
        if (!response.ok) { const failure = new Error(result.message || 'Save failed. Your fields are retained.'); failure.errors = result.errors || {}; throw failure; }
        return result;
    }
    function optionList(select, entries, empty = 'Not entered', selected = '') {
        if (!select) return;
        select.replaceChildren(new Option(empty, ''));
        for (const [value,label] of entries) select.add(new Option(label, String(value)));
        select.value = selected;
    }
    function renderLists() {
        optionList($('entry-transformer'), data.transformers.map(t=>[t.id,t.code]), 'Add a transformer to begin', String(activeId ?? ''));
        const selectedPdf = $('entry-pdf-source').value;
        optionList($('entry-pdf-source'), data.sources.filter(s=>s.kind==='pdf').map(s=>[s.id,`${s.original_name} · ${s.status}`]), 'Choose PDF', selectedPdf);
        if (!$('entry-pdf-source').value) $('entry-pdf-source').value = String(data.sources.find(s=>s.kind==='pdf')?.id || '');
        optionList($('entry-gpx'), data.sources.filter(s=>s.kind==='gpx').map(s=>[s.id,s.original_name]), 'Match across batch', val(rowForm,'gpx_source_id'));
        optionList($('header-waypoint'), data.waypoints.map(p=>[p.id,`${p.name} · ${data.sources.find(s=>s.id===p.source_file_id)?.original_name || 'GPX'}`]), 'Not entered', val(headerForm,'source_waypoint_id'));
        $('entry-waypoint-options').innerHTML = [...new Set(data.waypoints.map(p=>p.name))].map(name=>`<option value="${esc(name)}">`).join('');
        if (rowForm) {
            for (const key of ['R','Y','B','N']) {
                const select = field(rowForm,`conductors[${key}]`), selected = select.value;
                optionList(select, [['__none__','None / Not Present'],...Object.entries(data.lookups.conductors)], 'Not Entered', selected);
            }
            optionList($('entry-equipment'), Object.entries(data.lookups.equipment_types), 'Not entered', val(rowForm,'equipment_type'));
            optionList($('entry-pole-class'), Object.entries(data.lookups.pole_classes), 'Not entered', val(rowForm,'pole_class'));
        }
        document.querySelectorAll('[data-entry-revision]').forEach(input=>input.value=data.revision);
        $('entry-sources-list').innerHTML = '<ul>'+data.sources.map(s=>`<li>${esc(s.original_name)} — ${esc(s.status)}${s.kind==='gpx' ? ` · ${data.waypoints.filter(p=>p.source_file_id===s.id).length} waypoints` : ''}${s.metadata?.error ? ` · ${esc(s.metadata.error)}` : ''}</li>`).join('')+'</ul>';
        const settings=data.entry_settings || {};
        if($('entry-project-settings')) $('entry-project-settings').innerHTML=`Height unit: <strong>${esc(settings.pole_height_unit || 'unconfirmed')}</strong> · Year window: <strong>${settings.two_digit_year_start==null ? 'unconfigured' : `${settings.two_digit_year_start}–${Number(settings.two_digit_year_start)+99}`}</strong> · <a href="${esc(boot.settings_url)}" data-entry-leave>Project entry settings</a>`;
        if($('entry-paste-rule')) $('entry-paste-rule').textContent=settings.two_digit_year_start==null ? 'Configure the project year window before pasting.' : `YY uses ${settings.two_digit_year_start}–${Number(settings.two_digit_year_start)+99}.`;
        syncSelects();
    }
    function headerPayload() {
        const header = {};
        Array.from(headerForm?.elements || []).forEach(el=>{ const match=el.name?.match(/^header\[(.+)\]$/); if(match) header[match[1]]=el.value || null; });
        return {code:val(headerForm,'code'),capacity_kva:number(val(headerForm,'capacity_kva')),source_waypoint_id:number(val(headerForm,'source_waypoint_id')),header};
    }
    function fillHeader(payload) {
        if (!headerForm) {
            $('entry-readonly-header').innerHTML = Object.entries(payload.header || {}).filter(([k,v])=>typeof v==='string').map(([k,v])=>`<div>${esc(k.replaceAll('_',' '))}: ${esc(v)}</div>`).join('');
            return;
        }
        headerForm.reset();
        for (const name of ['code','capacity_kva','source_waypoint_id']) set(headerForm,name,payload[name]);
        for (const [key,value] of Object.entries(payload.header || {})) set(headerForm,`header[${key}]`,value);
        $('entry-header-caption').textContent = payload.code ? `— ${payload.code}` : '— new transformer';
    }
    function pvPayload() {
        return Array.from($('entry-pv-records')?.children || []).map(element=>Object.fromEntries(['reference','service_load_kw','installed_capacity_kw','remarks'].map(key=>{const value=element.querySelector(`[data-pv-key="${key}"]`).value;return [key,key.endsWith('_kw') ? number(value) : (value || null)];})));
    }
    function addPv(record = {}) {
        const element = document.createElement('div'); element.className='entry-pv-row';
        element.innerHTML = [['reference','Consumer reference number'],['service_load_kw','PV service load (kW)'],['installed_capacity_kw','PV solar capacity (kW)'],['remarks','Remarks']].map(([key,label])=>`<label>${label}<input data-pv-key="${key}" value="${esc(record[key])}" ${key.endsWith('_kw') ? 'type="number" min="0" max="1000000" step="any"' : `maxlength="${key==='remarks' ? 4000 : 255}"`}></label>`).join('')+'<button type="button" class="btn btn-sm btn-light" data-pv-remove>Remove</button>';
        $('entry-pv-records').append(element); $('entry-pv-count').textContent=`(${pvPayload().length})`;
    }
    function rowPayload() {
        if (!rowForm) return null;
        const payload = {};
        for (const key of ['pair_number','designation','group_number','row_date','waypoint_reference','gpx_source_id','source_pdf_id','source_page','source_row','equipment_type','pole_class','pole_height','pole_height_unit']) {
            const value=val(rowForm,key);
            payload[key]=['pair_number','gpx_source_id','source_pdf_id','source_page','pole_height'].includes(key) ? number(value) : (value || null);
        }
        payload.conductors=Object.fromEntries(['R','Y','B','N'].map(key=>[key,val(rowForm,`conductors[${key}]`) || null]));
        payload.consumers=Object.fromEntries(Object.keys(data.lookups.consumers).map(key=>[key,number(val(rowForm,`consumers[${key}]`))]));
        payload.intersection=field(rowForm,'intersection').checked; payload.pv_details=pvPayload(); payload.inheritance=inheritance; payload.manually_verified=false;
        payload.row_date=isoDate(payload.row_date) || null;
        const saved=active()?.rows.find(row=>row.id===rowId);
        payload.identity_version=saved && !saved.identity_version && !saved.composite_identifier ? null : 2;
        payload.composite_identifier=val(rowForm,'composite_identifier') || null;
        return payload;
    }
    function pairs(selected) {
        const rows=active()?.rows || [], numbers=[...new Set(rows.map(r=>r.pair_number))];
        const next=Math.max(0,...numbers)+1;
        if (!numbers.includes(Number(selected))) numbers.push(Number(selected || next));
        if (!numbers.includes(next)) numbers.push(next);
        optionList($('entry-pair'), numbers.sort((a,b)=>a-b).map(n=>[n,`Pair ${n}${rows.filter(r=>r.pair_number===n).length===1 ? ' — incomplete' : ''}`]), 'Choose pair', String(selected || next));
    }
    function fillRow(payload, id = null, identity = null) {
        if (!rowForm) return;
        rowId=id; clientUuid=identity || uuid(); saveUuid=null; inheritance=structuredClone(payload.inheritance || {});
        rowForm.reset(); pairs(payload.pair_number);
        const legacy=!!id && !payload.identity_version && !payload.composite_identifier;
        field(rowForm,'waypoint_reference').maxLength=legacy ? 255 : 3;field(rowForm,'group_number').maxLength=legacy ? 40 : 2;
        for(const [name,pattern] of [['waypoint_reference','[0-9]{3}'],['group_number','[0-9]{2}']]){if(legacy)field(rowForm,name).removeAttribute('pattern');else field(rowForm,name).setAttribute('pattern',pattern);}
        for (const [key,value] of Object.entries(payload)) {
            if (key==='conductors') for (const phase of ['R','Y','B','N']) set(rowForm,`conductors[${phase}]`,value[phase]==='' ? '__none__' : value[phase]);
            else if (key==='consumers') for (const category of Object.keys(data.lookups.consumers)) set(rowForm,`consumers[${category}]`,value[category]);
            else set(rowForm,key,value);
        }
        $('entry-pv-records').replaceChildren(); (payload.pv_details || []).forEach(addPv);
        $('entry-pv-count').textContent=`(${payload.pv_details?.length || 0})`;
        $('entry-row-title').textContent=id ? `Edit pair ${payload.pair_number} ${payload.designation} row` : 'Enter S/E row';
        field(rowForm,'pair_number').disabled=!!id; field(rowForm,'designation').disabled=!!id;
        rowDirty=false; updateRowHints();
    }
    function newRow(previous = {}) {
        const rows=active()?.rows || [], context=active()?.header || data.defaults;
        if(!previous.designation && rows.length)previous=rows.at(-1);
        const draft=nextDraft(previous,{next_pair:Math.max(0,...rows.map(r=>r.pair_number))+1,group_number:/^\d{2}$/.test(context.team_group || '') ? context.team_group : '',row_date:context.survey_date,
            source_pdf_id:number($('entry-pdf-source').value),source_page:pageNumber,gpx_source_id:null,pole_height_unit:data.entry_settings?.pole_height_unit});
        // Resume the only open pair when its S is already saved.
        if (!previous.designation) { const open=rows.find(r=>r.designation==='S' && !rows.some(e=>e.pair_number===r.pair_number && e.designation==='E')); if(open){draft.pair_number=open.pair_number;draft.designation='E';} }
        fillRow(draft); persist();
    }
    function updateRowHints() {
        if (!rowForm) return;
        const payload=rowPayload(), phase=derivePhase(payload.conductors);
        $('entry-phase').value=phase.display || '—';
        $('entry-phase-note').textContent=phase.complete ? '' : 'Incomplete';
        $('entry-phase').title=phase.complete ? 'R/Y/B phases derived; neutral stays separate.' : 'Incomplete: choose a conductor or None for each R/Y/B field.';
        $('entry-phase-note').hidden=phase.complete;
        let identityError='';
        try{set(rowForm,'composite_identifier',completeWaypoint(payload.group_number,payload.row_date,payload.waypoint_reference,data.entry_settings?.two_digit_year_start ?? null));}catch(e){set(rowForm,'composite_identifier','');identityError=e.message;}
        const matches=waypointMatches(payload,data.waypoints);
        $('entry-gpx-ambiguity').hidden=matches.length<2 && !payload.gpx_source_id;
        if(payload.gpx_source_id){const unscoped=waypointMatches({...payload,gpx_source_id:null},data.waypoints);$('entry-gpx-ambiguity').hidden=unscoped.length<2;}
        $('entry-waypoint-status').textContent=!payload.waypoint_reference ? 'Text references keep leading zeros.' : matches.length===1 ? 'Matched to GPX.' : matches.length===0 ? 'Missing from GPX — draft can still be saved.' : 'Multiple GPX matches — choose source.';
        if(identityError)$('entry-waypoint-status').textContent=identityError;
        else if(matches.length===0 && data.waypoints.some(p=>p.name===payload.waypoint_reference))$('entry-waypoint-status').textContent='No date/source match — check the PDF date and correct GPX file. Draft can still be saved.';
        $('entry-group-date-status').textContent=inheritance.group_date ? `Group/date inherited from saved row #${inheritance.group_date}; correct either field if different.` : '';
        $('entry-unit-label').textContent=payload.pole_height_unit || data.entry_settings?.pole_height_unit || 'unit unconfirmed';
        const unit=data.entry_settings?.pole_height_unit;
        $('entry-unit-convert').hidden=!!rowId || !!inheritance.equipment || !['ft','m'].includes(payload.pole_height_unit) || !['ft','m'].includes(unit) || payload.pole_height_unit===unit;
        $('entry-unit-convert').textContent=`Project unit changed: convert draft ${payload.pole_height ?? 'height'} ${payload.pole_height_unit || ''} to ${unit || ''}`;
        $('entry-consumer-total').textContent=`Entered consumer total: ${consumerTotal(payload.consumers)}${Object.values(payload.consumers).some(v=>v===null) ? ' (blank fields retained)' : ''}`;
        $('entry-row-page').textContent=`Row source: page ${payload.source_page ?? 'not entered'}`;
        $('entry-copy-endpoint').disabled=busy || payload.designation!=='S';
        for (const scope of ['conductors','equipment']) $('entry-'+(scope==='conductors' ? 'conductor' : 'equipment')+'-copy-status').textContent=inheritance[scope] ? `Explicitly copied from saved row #${inheritance[scope]} in this transformer.` : '';
        syncSelects();
    }
    function renderSaved() {
        const t=active(), rows=t?.rows || [];
        $('entry-saved-count').textContent=`${rows.length} rows`;
        $('entry-row-panel')?.classList.toggle('entry-row-panel-disabled',!t);
        $('entry-review-link').href=`${boot.base_url}/review${t ? '?transformer_id='+t.id : ''}`;
        $('entry-saved-rows').innerHTML=rows.length ? '<div class="table-wrap"><table class="table entry-saved-table"><thead><tr><th>Pair / S/E</th><th>Page / entry</th><th>Waypoint</th><th>Phase / conductors</th><th>Equipment</th><th>Entered counts</th><th>Int</th><th></th></tr></thead><tbody>'+rows.map(row=>`<tr class="${row.designation==='S' ? 'entry-pair-start' : ''}"><td>${row.pair_number} / <strong>${row.designation}</strong>${rows.filter(r=>r.pair_number===row.pair_number).length===1 ? '<small class="entry-pair-incomplete">Incomplete pair</small>' : ''}</td><td>${row.source_page ?? '—'} / ${esc(row.source_row || '—')}</td><td>${esc(row.composite_identifier || 'Legacy identity')}<small>GPS ${esc(row.waypoint_reference || 'Not entered')} ? ${esc(row.group_number)} ? ${esc(displayDate(row.row_date))} ? source #${esc(row.gpx_source_id || 'unresolved')}</small></td><td>${derivePhase(row.conductors).display || '—'}<small>${['R','Y','B','N'].map(k=>`${k}: ${esc(row.conductors[k]===null ? 'Not entered' : row.conductors[k]==='' ? 'None' : row.conductors[k])}`).join(' · ')}</small></td><td>${esc(row.equipment_type || '—')} / ${esc(row.pole_class || '—')}<small>${row.pole_height ?? '—'} ${esc(row.pole_height_unit || '(unit unconfirmed)')}</small></td><td>${Object.entries(row.consumers).filter(([,v])=>v!==null).map(([k,v])=>`${k.toUpperCase()}: ${v}`).join(' · ') || 'Blank'}<small>Total: ${consumerTotal(row.consumers)}</small></td><td>${row.intersection ? '✓' : '—'}</td><td>${boot.can_edit ? `<button type="button" class="btn btn-sm btn-light" data-entry-edit="${row.id}">Edit</button> <button type="button" class="btn btn-sm btn-light" data-entry-delete="${row.id}">Delete</button>` : ''}</td></tr>`).join('')+'</tbody></table></div>' : '<p class="entry-empty">Save the transformer header, then enter its first S row.</p>';
        $('entry-legacy-records').innerHTML=t?.legacy_sections?.length ? `<p>Existing saved network: ${t.legacy_sections.length} section(s). <a href="${esc(boot.base_url)}/advanced?transformer_id=${t.id}#entry">Review or correct existing sections</a></p>` : '';
    }
    function chooseTransformer(id) {
        activeId=number(id); clearErrors(); renderLists();
        fillHeader(active() || {header:data.defaults}); headerDirty=false;
        $('entry-header-panel').open=!active(); renderSaved(); newRow(); updateAssociation();
        const url=new URL(location.href); activeId ? url.searchParams.set('transformer_id',activeId) : url.searchParams.delete('transformer_id'); history.replaceState(null,'',url);
    }
    function persist() {
        try { localStorage.setItem(boot.storage_key,JSON.stringify({activeId,pageNumber,pdfSource:$('entry-pdf-source').value,rotation,zoom,height:$('entry-viewer-height').value,
            headerDirty,rowDirty,header:headerPayload(),row:rowPayload(),rowId,clientUuid,saveUuid})); }
        catch { if(headerDirty || rowDirty) status('Unsaved changes — browser recovery storage is unavailable. Save Draft to keep them.','dirty'); }
    }
    function markDirty(event, scope) {
        if (event.target.type==='search') return;
        if(scope==='header') headerDirty=true;
        else { rowDirty=true; saveUuid=null;
            if(event.target.name?.startsWith('conductors[')) delete inheritance.conductors;
            if(['equipment_type','pole_class','pole_height','pole_height_unit'].includes(event.target.name)) delete inheritance.equipment;
            if(['group_number','row_date'].includes(event.target.name))delete inheritance.group_date;
            if(['group_number','row_date','waypoint_reference'].includes(event.target.name))set(rowForm,'gpx_source_id',null);
        }
        updateRowHints(); status('Unsaved changes — kept in this browser. Save Draft to store them in the application.','dirty'); persist();
    }
    const allowSwitch = () => !busy && (!(headerDirty || rowDirty) || window.confirm('Some fields are unsaved. Continue and discard these fields? Use Save Draft first to retain them in the application.'));
    function updateAssociation() {
        const t=active(), source=number($('entry-pdf-source').value), ready=data.sources.find(s=>s.id===source)?.status==='ready';
        const associated=t?.header?.pages?.some(p=>p.source_pdf_id===source && p.page===pageNumber && p.confirmed);
        $('entry-page-association').textContent=t ? `${t.code} · page ${pageNumber} ${associated ? 'belongs to this transformer' : 'not yet confirmed — continue current or add new transformer'}` : 'Save a new transformer header for this PDF page.';
        if($('entry-continue')) $('entry-continue').disabled=busy || !t || !source || !ready || associated;
    }
    async function saveHeader(event) {
        event?.preventDefault(); if(busy || !headerForm.reportValidity()) return;
        const body=headerPayload(), source=data.sources.find(s=>s.id===number($('entry-pdf-source').value));
        if(source?.status==='ready') {body.source_pdf_id=source.id;body.source_page=pageNumber;}
        body.revision=data.revision; const path=active() ? `/entry-headers/${activeId}` : '/entry-headers';
        clearErrors(); setBusy(true); status('Saving transformer header…');
        try { data=await api(path,active() ? 'PUT' : 'POST',body); activeId=data.active_transformer_id; headerDirty=false; renderLists(); fillHeader(active()); renderSaved(); if(!rowDirty)newRow(); $('entry-header-panel').open=false; status('Transformer header saved.'); persist(); }
        catch(e){error('Header not saved. Your fields are retained.',e.errors || {save:[e.message]});}
        finally{setBusy(false);}
    }
    async function saveRow(advance) {
        if(busy || !active() || !rowForm.reportValidity()) return;
        const body=rowPayload(); saveUuid ||= uuid(); Object.assign(body,{client_uuid:clientUuid,save_uuid:saveUuid,revision:data.revision});
        const path=`/transformers/${activeId}/rows${rowId ? '/'+rowId : ''}`;
        clearErrors(); setBusy(true); status('Saving row…');
        try {data=await api(path,rowId ? 'PUT' : 'POST',body); const saved=active().rows.find(r=>r.client_uuid===clientUuid); rowDirty=false;
            renderLists();renderSaved(); if(advance)newRow(saved); else fillRow(saved,saved.id,saved.client_uuid);
            status(advance ? 'Row saved. Enter the next S/E row.' : 'Draft saved in the application. You can resume later.');persist();
            if(advance)field(rowForm,'waypoint_reference').focus();
        }catch(e){error('Row not saved. Your fields are retained.',e.errors || {save:[e.message]});}
        finally{setBusy(false);if(rowId){field(rowForm,'pair_number').disabled=true;field(rowForm,'designation').disabled=true;}}
    }
    function copyValues(scope) {
        if(busy || !active()) return;
        const current=rowPayload(), candidates=active().rows.filter(r=>r.id!==rowId);
        const previous=current.designation==='E' ? candidates.find(r=>r.pair_number===current.pair_number && r.designation==='S') || candidates.at(-1) : candidates.at(-1);
        if(!previous){error('No previous saved row in this transformer.');return;}
        if(!window.confirm(`Copy ${scope==='both' ? 'conductors and equipment' : scope} from pair ${previous.pair_number} ${previous.designation}? Check the PDF ditto/continuation marks first.`)) return;
        if(scope==='conductors' || scope==='both'){for(const key of ['R','Y','B','N'])set(rowForm,`conductors[${key}]`,previous.conductors[key]==='' ? '__none__' : previous.conductors[key]);inheritance.conductors=previous.id;}
        if(scope==='equipment' || scope==='both'){for(const key of ['equipment_type','pole_class','pole_height','pole_height_unit'])set(rowForm,key,previous[key]);inheritance.equipment=previous.id;}
        rowDirty=true;saveUuid=null;updateRowHints();status('Copied values are shown below. Check and save this row.','dirty');persist();
    }
    function renderPdf() {
        const token=pdfGeneration, document=pdf, selectedPage=pageNumber, selectedRotation=rotation, selectedZoom=zoom;
        if(!document) return Promise.resolve();
        renderTask?.cancel();
        renderChain=renderChain.catch(()=>{}).then(async()=>{
            if(token!==pdfGeneration || document!==pdf || selectedPage!==pageNumber || selectedRotation!==rotation || selectedZoom!==zoom)return;
            try {
                const page=await document.getPage(selectedPage), initial=page.getViewport({scale:1,rotation:selectedRotation});
                if(token!==pdfGeneration)return;
                const scale=(Math.max(250,$('entry-pdf-scroll').clientWidth-20)/initial.width)*selectedZoom;
                const cssViewport=page.getViewport({scale,rotation:selectedRotation});
                const pixel=Math.min(window.devicePixelRatio || 1,2,Math.sqrt(16000000/(cssViewport.width*cssViewport.height))), viewport=page.getViewport({scale:scale*pixel,rotation:selectedRotation});
                const canvas=$('entry-pdf-canvas');canvas.width=Math.floor(viewport.width);canvas.height=Math.floor(viewport.height);canvas.style.width=`${viewport.width/pixel}px`;canvas.style.height=`${viewport.height/pixel}px`;canvas.hidden=false;$('entry-pdf-message').hidden=true;
                renderTask=page.render({canvasContext:canvas.getContext('2d'),viewport});await renderTask.promise;renderTask=null;
            }catch(e){if(e.name!=='RenderingCancelledException'){$('entry-pdf-message').textContent='PDF rendering failed. Use Open PDF; entered fields are retained.';$('entry-pdf-message').hidden=false;}}
        });
        return renderChain;
    }
    async function loadPdf() {
        const source=number($('entry-pdf-source').value), token=++pdfGeneration;
        renderTask?.cancel();pdfLoading?.destroy();if(pdf)await pdf.destroy();pdf=null;
        $('entry-pdf-canvas').hidden=true;$('entry-pdf-message').hidden=false;$('entry-pdf-message').textContent=source ? 'Loading PDF…' : 'Upload a PDF and GPX to begin.';
        if(!source){$('entry-page-total').textContent='of 0';updateAssociation();return;}
        $('entry-pdf-open').href=sourceUrl(source);
        try {
            pdfLib ||= await import(boot.pdfjs_url);pdfLib.GlobalWorkerOptions.workerSrc=boot.pdfjs_worker;
            if(token!==pdfGeneration)return;
            pdfLoading=pdfLib.getDocument({url:sourceUrl(source),withCredentials:true,disableRange:true,disableStream:true,isEvalSupported:false,cMapUrl:boot.pdfjs_assets+'cmaps/',cMapPacked:true,standardFontDataUrl:boot.pdfjs_assets+'standard_fonts/',wasmUrl:boot.pdfjs_assets+'wasm/'});
            const loaded=await pdfLoading.promise;if(token!==pdfGeneration){await loaded.destroy();return;}pdf=loaded;pageNumber=Math.max(1,Math.min(pdf.numPages,pageNumber));setPage(pageNumber);await renderPdf();
        }catch(e){if(token===pdfGeneration){$('entry-pdf-message').textContent='Could not open PDF. Use Open PDF or check your sign-in. Your entry is retained.';}}
    }
    function setPage(numberValue) {
        pageNumber=Math.max(1,Math.min(pdf?.numPages || data.sources.find(s=>s.id===number($('entry-pdf-source').value))?.metadata?.page_count || 1,numberValue));
        $('entry-page').value=pageNumber;$('entry-page-total').textContent=`of ${pdf?.numPages || 0}`;$('entry-page-previous').disabled=pageNumber<=1;$('entry-page-next').disabled=!pdf || pageNumber>=pdf.numPages;
        if(rowForm && !rowId && !busy){set(rowForm,'source_pdf_id',number($('entry-pdf-source').value));set(rowForm,'source_page',pageNumber);updateRowHints();}
        updateAssociation();persist();renderPdf();
    }
    function filterSelect(select, query) {
        if(!select)return;
        for(const option of select.options)option.hidden=!!option.value && !option.selected && !option.text.toLowerCase().includes(query.toLowerCase());
    }
    // Restore drafts only after the server's current records and permissions are loaded.
    let recovery=null;try{recovery=JSON.parse(localStorage.getItem(boot.storage_key));}catch{}
    if(recovery && (!boot.active_transformer_id || Number(recovery.activeId)===boot.active_transformer_id))activeId=recovery.activeId;
    if(activeId && !active())activeId=data.transformers[0]?.id || null;
    chooseTransformer(activeId);
    if(recovery && Number(recovery.activeId || 0)===Number(activeId || 0)){
        pageNumber=recovery.pageNumber || 1;rotation=recovery.rotation || 0;zoom=recovery.zoom || 1;$('entry-pdf-source').value=recovery.pdfSource || $('entry-pdf-source').value;
        $('entry-viewer-height').value=recovery.height || 300;
        if(recovery.headerDirty && recovery.header){fillHeader(recovery.header);headerDirty=true;$('entry-header-panel').open=true;}
        if(recovery.rowDirty && recovery.row){fillRow(recovery.row,recovery.rowId,recovery.clientUuid);rowDirty=true;saveUuid=recovery.saveUuid;}
        if(headerDirty || rowDirty)status('Unsaved fields restored from this browser. Check current saved records before saving.','dirty');
    }
    if(!recovery && window.innerWidth<650)$('entry-viewer-height').value='180';
    $('entry-pdf-scroll').style.height=$('entry-viewer-height').value+'px';$('entry-height-value').textContent=$('entry-viewer-height').value+' px';
    if(!data.sources.some(s=>s.kind==='pdf'))document.querySelector('.entry-source-files').open=true;
    headerForm?.addEventListener('submit',saveHeader);headerForm?.addEventListener('input',event=>markDirty(event,'header'));headerForm?.addEventListener('change',event=>markDirty(event,'header'));
    rowForm?.addEventListener('submit',event=>{event.preventDefault();saveRow(true);});rowForm?.addEventListener('input',event=>markDirty(event,'row'));rowForm?.addEventListener('change',event=>markDirty(event,'row'));
    $('entry-save-draft')?.addEventListener('click',()=>saveRow(false));$('entry-row-new')?.addEventListener('click',()=>{if(allowSwitch()){newRow();status('New draft row.');}});
    $('entry-transformer').addEventListener('change',()=>{if(allowSwitch())chooseTransformer($('entry-transformer').value);else $('entry-transformer').value=String(activeId || '');});
    document.querySelectorAll('[data-entry-add-transformer]').forEach(button=>button.addEventListener('click',()=>{if(!allowSwitch())return;chooseTransformer(null);$('entry-header-panel').open=true;field(headerForm,'code')?.focus();}));
    $('entry-transformer-search').addEventListener('input',e=>filterSelect($('entry-transformer'),e.target.value));document.querySelectorAll('[data-entry-filter]').forEach(input=>input.addEventListener('input',()=>filterSelect($(input.dataset.entryFilter),input.value)));
    $('entry-copy-conductors')?.addEventListener('click',()=>copyValues('conductors'));$('entry-copy-equipment')?.addEventListener('click',()=>copyValues('equipment'));$('entry-copy-both')?.addEventListener('click',()=>copyValues('both'));
    $('entry-copy-endpoint')?.addEventListener('click',()=>{const previous=active()?.rows.filter(r=>r.designation==='E').at(-1);if(previous){for(const key of ['group_number','row_date','waypoint_reference','gpx_source_id'])set(rowForm,key,previous[key]);inheritance.group_date=previous.id;rowDirty=true;saveUuid=null;updateRowHints();status('Previous E complete waypoint copied. Change it here for a branch.','dirty');persist();}});
    $('entry-paste-apply')?.addEventListener('click',()=>{
        try{
            const parsed=parseWaypoint($('entry-paste-waypoint').value.trim(),data.entry_settings?.two_digit_year_start);
            for(const [key,value] of Object.entries(parsed))set(rowForm,key,value);
            set(rowForm,'gpx_source_id',null);delete inheritance.group_date;rowDirty=true;saveUuid=null;clearErrors();updateRowHints();persist();status('Complete waypoint applied. Check the PDF date and GPS match.','dirty');
        }catch(e){error(e.message);}
    });
    $('entry-unit-convert')?.addEventListener('click',()=>{
        if(busy)return;
        const current=rowPayload(),unit=data.entry_settings?.pole_height_unit;
        try{const height=convertPoleHeight(current.pole_height,current.pole_height_unit,unit);set(rowForm,'pole_height',height);set(rowForm,'pole_height_unit',unit);rowDirty=true;saveUuid=null;updateRowHints();persist();status(`Draft height converted from ${current.pole_height ?? 'blank'} ${current.pole_height_unit} to ${height ?? 'blank'} ${unit}. Check against the source before saving.`,'dirty');}catch(e){error(e.message);}
    });
    $('entry-use-page')?.addEventListener('click',()=>{set(rowForm,'source_pdf_id',number($('entry-pdf-source').value));set(rowForm,'source_page',pageNumber);rowDirty=true;saveUuid=null;updateRowHints();status('Displayed page selected for this row. Save to confirm.','dirty');persist();});
    $('entry-pv-add')?.addEventListener('click',()=>{addPv();rowDirty=true;saveUuid=null;persist();});$('entry-pv-records')?.addEventListener('click',event=>{if(event.target.closest('[data-pv-remove]')){event.target.closest('.entry-pv-row').remove();rowDirty=true;saveUuid=null;$('entry-pv-count').textContent=`(${pvPayload().length})`;persist();}});
    $('entry-saved-rows').addEventListener('click',async event=>{
        const edit=event.target.closest('[data-entry-edit]'),remove=event.target.closest('[data-entry-delete]');if(busy)return;
        if(edit){if(!allowSwitch())return;const row=active().rows.find(r=>r.id===Number(edit.dataset.entryEdit));fillRow(row,row.id,row.client_uuid);persist();$('entry-row-panel').scrollIntoView({block:'end',behavior:'smooth'});}
        if(remove){if(headerDirty || rowDirty){error('Save or cancel your current draft before deleting a saved row.');return;}if(!window.confirm('Delete this saved S/E row? Its paired row will remain as an incomplete draft.'))return;setBusy(true);try{data=await api(`/transformers/${activeId}/rows/${remove.dataset.entryDelete}`,'DELETE',{revision:data.revision,confirmed:true});renderLists();renderSaved();newRow();status('Row deleted. Check the remaining pair in review.');}catch(e){error('Row could not be deleted.',e.errors || {delete:[e.message]});}finally{setBusy(false);}}
    });
    $('entry-continue')?.addEventListener('click',async()=>{if(busy || !active())return;setBusy(true);try{data=await api(`/transformers/${activeId}/pages`,'POST',{revision:data.revision,source_pdf_id:number($('entry-pdf-source').value),page:pageNumber,role:'continuation',confirmed:true});renderLists();updateAssociation();status('Current transformer retained; continuation page confirmed.');persist();}catch(e){error('Continuation page could not be confirmed.',e.errors || {page:[e.message]});}finally{setBusy(false);}});
    $('entry-refresh').addEventListener('click',async()=>{if(busy)return;const h=headerPayload(),r=rowPayload(),id=rowId,identity=clientUuid;setBusy(true);try{data=await api('/entry-data');renderLists();renderSaved();if(headerDirty)fillHeader(h);else fillHeader(active() || {header:data.defaults});if(rowDirty){fillRow(r,id,identity);rowDirty=true;}else newRow();status('Saved records refreshed. Review retained fields before saving.',headerDirty || rowDirty ? 'dirty' : 'saved');persist();await loadPdf();}catch(e){error(e.message);}finally{setBusy(false);}});
    $('entry-pdf-source').addEventListener('change',()=>{pageNumber=1;loadPdf();persist();});$('entry-page').addEventListener('change',()=>setPage(Number($('entry-page').value)||1));$('entry-page-previous').addEventListener('click',()=>setPage(pageNumber-1));$('entry-page-next').addEventListener('click',()=>setPage(pageNumber+1));
    $('entry-zoom-out').addEventListener('click',()=>{zoom=Math.max(.25,zoom/1.25);renderPdf();persist();});$('entry-zoom-in').addEventListener('click',()=>{zoom=Math.min(3,zoom*1.25);renderPdf();persist();});$('entry-zoom-fit').addEventListener('click',()=>{zoom=1;renderPdf();persist();});$('entry-rotate').addEventListener('click',()=>{rotation=(rotation+90)%360;renderPdf();persist();});
    $('entry-viewer-height').addEventListener('input',()=>{$('entry-pdf-scroll').style.height=$('entry-viewer-height').value+'px';$('entry-height-value').textContent=$('entry-viewer-height').value+' px';persist();});
    let resizeTimer;new ResizeObserver(()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(renderPdf,150);}).observe($('entry-pdf-scroll'));
    // Source jobs advance the batch revision. Refresh their results without replacing
    // entered fields; a concurrent change to saved transformer data still needs review.
    let sourcePoll=null;
    if(data.sources.some(s=>['queued','processing','pending'].includes(s.status))){
        sourcePoll=window.setInterval(async()=>{
            if(busy)return;
            try{
                const fresh=await api('/entry-data');
                if(JSON.stringify(fresh.transformers)===JSON.stringify(data.transformers)){
                    data=fresh;renderLists();renderSaved();updateRowHints();updateAssociation();persist();
                }else status('Saved records changed while sources were processing. Refresh saved records; your fields are retained.','dirty');
                if(!fresh.sources.some(s=>['queued','processing','pending'].includes(s.status))){window.clearInterval(sourcePoll);sourcePoll=null;}
            }catch{/* Keep the draft intact; the explicit refresh control remains available. */}
        },5000);
    }
    window.addEventListener('pagehide',()=>{if(sourcePoll)window.clearInterval(sourcePoll);});
    document.querySelectorAll('[data-entry-leave]').forEach(link=>link.addEventListener('click',event=>{if(busy || ((headerDirty || rowDirty) && !window.confirm('Some fields are saved only in this browser. Leave entry now? Use Save Draft to store them in the application.')))event.preventDefault();else persist();}));
    window.addEventListener('beforeunload',event=>{persist();if(headerDirty || rowDirty || busy){event.preventDefault();event.returnValue='';}});
    document.addEventListener('keydown',event=>{if(event.ctrlKey && event.key==='Enter'){event.preventDefault();saveRow(true);}if(event.ctrlKey && event.key.toLowerCase()==='s'){event.preventDefault();headerDirty ? saveHeader() : saveRow(false);}if(event.altKey && ['ArrowLeft','ArrowRight'].includes(event.key)){event.preventDefault();setPage(pageNumber+(event.key==='ArrowLeft' ? -1 : 1));}});
    loadPdf();persist();
}
