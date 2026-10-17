import {installCompactSelects} from './mdb-entry-select.js?v=3';
import {pdfLoadErrorMessage, clearPdfFallback, showPdfFallback} from './mdb-pdf-errors.js';

export function transformerCodeKey(code) {
    return String(code ?? '').toLowerCase().replace(/[\u2010-\u2014\u2212]/g,'-').replace(/\s+/gu,'');
}

export function isoDate(value, yearStart = null) {
    yearStart ??= 2000;
    const compact = /^(\d{2})(\d{2})(\d{2})$/.exec(value || '');
    if (compact && yearStart !== null && yearStart !== undefined) {
        let year = Math.floor(Number(yearStart) / 100) * 100 + Number(compact[3]);
        if (year < Number(yearStart)) year += 100;
        return `${year}-${compact[2]}-${compact[1]}`;
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return value;
    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value || '');
    return match ? `${match[3]}-${match[2]}-${match[1]}` : value || '';
}
export function displayDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    return match ? `${match[3]}${match[2]}${match[1].slice(-2)}` : value || '';
}
export function completeWaypoint(group, date, gps, yearStart = null) {
    if (!group || !date || !gps) return '';
    if (!/^\d{2}$/.test(group) || !/^\d{3}$/.test(gps)) throw new Error('Use two group digits and three GPS digits.');
    yearStart ??= 2000;
    date = isoDate(date, yearStart);
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
    if (!match) throw new Error('Use six date digits in DDMMYY format, for example 131222.');
    const year=Number(match[1]),month=Number(match[2]),day=Number(match[3]);
    const check=new Date(Date.UTC(year,month-1,day));
    if(check.getUTCFullYear()!==year || check.getUTCMonth()!==month-1 || check.getUTCDate()!==day) throw new Error('Enter a valid calendar date.');
    if(yearStart!==null && (year<Number(yearStart) || year>=Number(yearStart)+100)) throw new Error('Date is outside the configured year window.');
    return group+match[3]+match[2]+match[1].slice(-2)+gps;
}
export function parseWaypoint(identifier, yearStart) {
    if(!/^\d{11}$/.test(identifier)) throw new Error('The complete waypoint must contain exactly 11 digits.');
    yearStart ??= 2000;
    let year=Math.floor(Number(yearStart)/100)*100+Number(identifier.slice(6,8));if(year<Number(yearStart))year+=100;
    const result={group_number:identifier.slice(0,2),row_date:`${year}-${identifier.slice(4,6)}-${identifier.slice(2,4)}`,waypoint_reference:identifier.slice(8)};
    completeWaypoint(result.group_number,result.row_date,result.waypoint_reference,yearStart);return result;
}
export function waypointMatches(row, points) {
    let matches=points.filter(p=>p.name===row.waypoint_reference && (!row.gpx_source_id || p.source_file_id===row.gpx_source_id));
    if(row.row_date){
        const dated=matches.filter(p=>p.recorded_at && new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(p.recorded_at))===row.row_date);
        if(dated.length && matches.length>1)matches=dated;
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

// Blank fields remain valid for drafts; entered values must have a valid shape/range.
export function rowValidationErrors(payload, yearStart = null) {
    const errors = {}, add = (key, message) => { errors[key] = [message]; };
    if(payload.identity_version === 2) {
        if(payload.group_number && !/^\d{2}$/.test(payload.group_number))add('group_number','Enter exactly two group digits, for example 01.');
        if(payload.waypoint_reference && !/^\d{3}$/.test(payload.waypoint_reference))add('waypoint_reference','Enter exactly three GPS digits, retaining zeros, for example 001.');
    }
    if(payload.row_date) {
        try { completeWaypoint('01',payload.row_date,'001',yearStart); }
        catch(e) { add('row_date',e.message); }
    }
    if(payload.pole_height !== null && (!Number.isFinite(payload.pole_height) || payload.pole_height < 0 || payload.pole_height > 200))add('pole_height','Enter pole/tower height from 0 to 200 in the displayed unit, or leave it blank.');
    for(const [key, value] of Object.entries(payload.consumers || {})) {
        if(value !== null && (!Number.isInteger(value) || value < 0 || value > 1000000))add('consumers.'+key,`${key.toUpperCase()}: enter a whole number from 0 to 1,000,000, or leave it blank.`);
    }
    for(const [index, pv] of (payload.pv_details || []).entries()) {
        for(const key of ['service_load_kw','installed_capacity_kw']) {
            if(pv[key] !== null && (!Number.isFinite(pv[key]) || pv[key] < 0 || pv[key] > 1000000))add(`pv_details.${index}.${key}`,`${key === 'service_load_kw' ? 'PV service load' : 'PV solar capacity'}: enter 0 to 1,000,000 kW, or leave it blank.`);
        }
    }
    return errors;
}

export function nextDraft(previous = {}, defaults = {}) {
    let pair=previous.designation === 'S' ? Number(previous.pair_number) : Number(defaults.next_pair || 1), designation=previous.designation === 'S' ? 'E' : 'S';
    const rows=defaults.rows || [];
    if(rows.some(row=>row.pair_number===pair && row.designation===designation)) {
        const open=rows.find(row=>row.designation==='S' && !rows.some(end=>end.pair_number===row.pair_number && end.designation==='E'));
        pair=open ? Number(open.pair_number) : Number(defaults.next_pair || 1);designation=open ? 'E' : 'S';
    }
    return {pair_number: pair, designation, group_number: previous.group_number ?? defaults.group_number ?? '',
        row_date: previous.row_date ?? defaults.row_date ?? '', waypoint_reference: '', gpx_source_id: defaults.gpx_source_id ?? null,
        source_pdf_id: defaults.source_pdf_id ?? null, source_page: defaults.source_page ?? null, source_row: '',
        conductors: {R: null, Y: null, B: null, N: null}, equipment_type: '', pole_class: '', pole_height: null,
        pole_height_unit: defaults.pole_height_unit ?? '', consumers: Object.fromEntries(['rs','rl','sc','lc','si','li','pb','ag','st','pv'].map(key => [key,null])),
        intersection: false, pv_details: [], inheritance: previous.id ? {group_date:previous.id} : {}, manually_verified: false};
}

// Bounds use fractions of the page at its original rotation, independent of zoom.
export function rotatedRowBounds(bounds, rotation) {
    const {x,y,width,height}=bounds;
    switch (((rotation % 360)+360)%360) {
        case 90: return {x:1-y-height,y:x,width:height,height:width};
        case 180: return {x:1-x-width,y:1-y-height,width,height};
        case 270: return {x:y,y:1-x-width,width:height,height:width};
        default: return bounds;
    }
}

export function rotatedPdfPoint(point, rotation) {
    switch (((rotation % 360)+360)%360) {
        case 90: return {x:1-point.y,y:point.x};
        case 180: return {x:1-point.x,y:1-point.y};
        case 270: return {x:point.y,y:1-point.x};
        default: return {...point};
    }
}

export function nextPendingPdfRow(rows, sourceId) {
    return rows.filter(row=>row.source_file_id===Number(sourceId) && !row.entered)
        .sort((a,b)=>a.page-b.page || a.row_number-b.row_number)[0] || null;
}

export function pdfPageProgress(rows) {
    const ordered=[...rows].sort((a,b)=>a.row_number-b.row_number);
    const firstPending=ordered.findIndex(row=>!row.entered);
    const completed=ordered.slice(0,firstPending===-1 ? ordered.length : firstPending);
    const areaFor = entries => {
        if(!entries.length)return null;
        const x=Math.min(...entries.map(row=>row.bounds.x)),y=Math.min(...entries.map(row=>row.bounds.y));
        return {x,y,width:Math.max(...entries.map(row=>row.bounds.x+row.bounds.width))-x,
            height:Math.max(...entries.map(row=>row.bounds.y+row.bounds.height))-y};
    };
    return {area:areaFor(ordered),enteredArea:areaFor(completed),enteredThrough:completed.at(-1)?.row_number || 0,
        remaining:ordered.filter(row=>!row.entered).length};
}

if (typeof window !== 'undefined' && window.mdbEntryBoot) initialize(window.mdbEntryBoot);

function initialize(boot) {
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const uuid = () => crypto.randomUUID();
    const headerForm = $('entry-header-form'), rowForm = $('entry-row-form');
    const syncSelects = installCompactSelects();
    let data = boot, activeId = boot.active_transformer_id || boot.transformers[0]?.id || null;
    let registeredId = null, manualTransformer = false;
    let rowId = null, clientUuid = uuid(), saveUuid = null, inheritance = {}, headerDirty = false, rowDirty = false, busy = false;
    let pageNumber = 1, rotation = 0, zoom = 1, pdf = null, pdfLoading = null, pdfLib = null, pdfGeneration = 0, renderTask = null, renderChain = Promise.resolve();
    let selectedPdfRowId = null, drawingLines = false, lineStart = null, linePreview = null, linePointer = null;
    let renderedPdfLocation = null;
    const pdfRows = () => data.pdf_rows || [];
    const selectedPdfRow = () => pdfRows().find(row=>row.id===selectedPdfRowId);
    const active = () => data.transformers.find(t => t.id === Number(activeId));
    const registered = () => (data.registered_transformers || []).find(t => t.id === Number(registeredId));
    const transformerSelection = () => activeId ? String(activeId) : registeredId ? `registered:${registeredId}` : manualTransformer ? '__manual__' : '';
    const editable = () => boot.can_edit && (!data.staged_workflow || ['entry','returned'].includes(data.survey_status));
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
        $('entry-save-status').hidden = !$('entry-errors').hidden;
        $('entry-save-status').parentElement.dataset.state = $('entry-errors').hidden ? state : 'error';
    }
    function clearFieldErrors() {
        document.querySelectorAll('.entry-field-error').forEach(note=>{
            document.querySelectorAll('[aria-describedby]').forEach(element=>{
                const descriptions=element.getAttribute('aria-describedby').split(/\s+/).filter(id=>id!==note.id);
                descriptions.length ? element.setAttribute('aria-describedby',descriptions.join(' ')) : element.removeAttribute('aria-describedby');
            });
            note.remove();
        });
        document.querySelectorAll('[aria-invalid]').forEach(element=>element.removeAttribute('aria-invalid'));
    }
    function error(message, errors = {}, preferredForm = null) {
        const box = $('entry-errors');
        const messages = Object.entries(errors).flatMap(([key,value])=>[value].flat().map(message=>({key,message})));
        box.innerHTML = `<strong>${esc(message)}</strong>${messages.length ? '<ul>'+messages.map(item=>`<li data-validation-key="${esc(item.key)}">${esc(item.message)}</li>`).join('')+'</ul>' : ''}`;
        box.hidden = false;
        clearFieldErrors();
        for (const [key, messages] of Object.entries(errors)) {
            const parts = key.split('.'), name = parts.shift()+parts.map(p=>`[${p}]`).join('');
            const headerSource=preferredForm===headerForm ? ({source_pdf_id:$('entry-header-pdf'),source_page:$('entry-header-pdf-page'),registered_transformer_id:$('entry-transformer')})[key] : null;
            const pvMatch=/^pv_details\.(\d+)\.(\w+)$/.exec(key);
            const pvField=pvMatch ? $('entry-pv-records')?.children[Number(pvMatch[1])]?.querySelector(`[data-pv-key="${pvMatch[2]}"]`) : null;
            const rowSource=preferredForm===rowForm ? ({pdf_progress_row_id:$('entry-pdf-row'),source_pdf_id:$('entry-pdf-source'),source_page:$('entry-page'),pole_height_unit:field(rowForm,'pole_height_unit')})[key] : null;
            const element = pvField || rowSource || field(preferredForm,name) || headerSource || field(rowForm, name) || field(headerForm, name);
            if (element) {
                element.setAttribute('aria-invalid', 'true');
                const note = document.createElement('span'); note.className = 'entry-field-error';note.id='entry-field-error-'+uuid(); note.dataset.validationKey=key;note.textContent = [messages].flat().join(' ');
                element.setAttribute('aria-describedby',[element.getAttribute('aria-describedby'),note.id].filter(Boolean).join(' '));
                element.parentElement.append(note);
                const trigger=element.closest('.entry-combobox')?.querySelector('.entry-combobox-trigger');
                if(trigger){trigger.setAttribute('aria-invalid','true');trigger.setAttribute('aria-describedby',note.id);}
            }
        }
        status('', 'error');
    }
    function clearErrors() {
        $('entry-errors').hidden = true;
        $('entry-save-status').hidden = false;
        clearFieldErrors();
    }
    function clearCorrectedField(element, scope) {
        const pvKey=element.dataset.pvKey, index=pvKey ? Array.from($('entry-pv-records').children).indexOf(element.closest('.entry-pv-row')) : null;
        const key=pvKey ? `pv_details.${index}.${pvKey}` : (element.name || '').replace(/\[([^\]]+)\]/g,'.$1');
        if(!key || !element.validity?.valid || (scope==='row' && rowValidationErrors(rowPayload(),data.entry_settings?.two_digit_year_start ?? 2000)[key]))return;
        const notes=[...document.querySelectorAll('.entry-field-error')].filter(note=>note.dataset.validationKey===key);
        for(const note of notes){
            for(const control of document.querySelectorAll('[aria-describedby]')){
                const ids=control.getAttribute('aria-describedby').split(/\s+/);
                if(!ids.includes(note.id))continue;
                const kept=ids.filter(id=>id!==note.id);kept.length ? control.setAttribute('aria-describedby',kept.join(' ')) : control.removeAttribute('aria-describedby');control.removeAttribute('aria-invalid');
            }
            note.remove();
        }
        if(notes.length){
            $('entry-errors').querySelectorAll('li[data-validation-key]').forEach(item=>{if(item.dataset.validationKey===key)item.remove();});
            if(!$('entry-errors').querySelector('li'))clearErrors();
        }
    }
    function setBusy(value) {
        busy = value;
        [rowForm, headerForm].filter(Boolean).forEach(form => Array.from(form.elements).forEach(el => el.disabled = value || !editable()));
        document.querySelectorAll('.entry-source-files form input,.entry-source-files form button').forEach(el=>el.disabled=value || !editable());
        $('entry-transformer').disabled = value;
        document.querySelectorAll('[data-entry-add-transformer],#entry-continue,#entry-submit-form button').forEach(el=>el.disabled=value || !editable());
        document.querySelectorAll('.entry-copy-bar button').forEach(el=>el.disabled=value || !editable());
        $('entry-refresh').disabled=value;
        if (!value) { updateAssociation(); if(rowId && rowForm){field(rowForm,'pair_number').disabled=true;field(rowForm,'designation').disabled=true;} }
        syncSelects();
        renderPdfRows();
    }
    async function api(path, method = 'GET', body) {
        let response;
        try{response = await fetch(`${boot.base_url}${path}`, {method, credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, ...(body ? {body:JSON.stringify(body)} : {})});}
        catch{throw new Error('Could not reach the server. Check your connection and retry. Your entered fields are retained.');}
        const reasons={401:'Your sign-in session has ended. Sign in again, then retry with the retained fields.',403:'You do not have permission for this action, or survey entry is locked.',404:'The selected survey or record could not be found. Refresh saved records before retrying.',
            419:'Your session has expired. Reload this page and sign in again if needed. Your entered fields are kept in this browser.',429:'Too many requests were sent. Wait a moment and retry.',500:'The server could not confirm saving. Refresh saved records before retrying to check whether the record was saved.'};
        if(response.redirected && new URL(response.url,location.href).pathname.endsWith('/login'))throw new Error(reasons[401]);
        let result;
        try{result=await response.json();}
        catch{throw new Error(reasons[response.status] || 'The server returned an unreadable response and saving could not be confirmed. Your fields are retained. Refresh saved records before retrying.');}
        if (!response.ok) { const failure = new Error(([401,419,429].includes(response.status) ? reasons[response.status] : result?.message) || reasons[response.status] || 'Saving failed. Your fields are retained.'); failure.errors = result?.errors || {};failure.reference=result?.reference; throw failure; }
        if(!result || typeof result!=='object')throw new Error('The server returned an unexpected response and saving could not be confirmed. Your fields are retained.');
        return result;
    }
    function optionList(select, entries, empty = 'Not entered', selected = '') {
        if (!select) return;
        select.replaceChildren(new Option(empty, ''));
        for (const [value,label] of entries) select.add(new Option(label, String(value)));
        select.value = selected;
    }
    function renderLists() {
        const available=(data.registered_transformers || []).filter(t=>!data.transformers.some(saved=>transformerCodeKey(saved.code)===transformerCodeKey(t.code)));
        optionList($('entry-transformer'), [...data.transformers.map(t=>[t.id,t.code]),...(editable() ? available.map(t=>[`registered:${t.id}`,`${t.code} — registered; new survey header`]) : []),...(editable() ? [['__manual__','Transformer not found? Add manually']] : [])], 'Choose a transformer', transformerSelection());
        if($('entry-transformer-help'))$('entry-transformer-help').textContent='Search to choose a saved or registered transformer. If it is not listed, use Add Transformer to enter it manually.';
        const selectedPdf = $('entry-pdf-source').value;
        optionList($('entry-pdf-source'), data.sources.filter(s=>s.kind==='pdf').map(s=>[s.id,`${s.original_name} · ${s.status}`]), 'Choose PDF', selectedPdf);
        if (!$('entry-pdf-source').value) $('entry-pdf-source').value = String(data.sources.find(s=>s.kind==='pdf')?.id || '');
        optionList($('entry-header-pdf'), data.sources.filter(s=>s.kind==='pdf').map(s=>[s.id,s.original_name]), 'Choose PDF', val(headerForm,'header[pdf_source_id]'));
        optionList($('entry-gpx'), data.sources.filter(s=>s.kind==='gpx').map(s=>[s.id,s.original_name]), 'Match across batch', val(rowForm,'gpx_source_id'));
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
        const settings={...data.entry_settings,two_digit_year_start:data.entry_settings?.two_digit_year_start ?? 2000};
        if($('entry-project-settings')) $('entry-project-settings').innerHTML=`Project default height unit: <strong>${esc(settings.pole_height_unit || 'unconfirmed')}</strong> &middot; Year window: <strong>${settings.two_digit_year_start}&ndash;${Number(settings.two_digit_year_start)+99}</strong>${boot.can_configure ? ` &middot; <a href="${esc(boot.settings_url)}" data-entry-leave>Project entry settings</a>` : ''}`;
        if($('entry-paste-rule')) $('entry-paste-rule').textContent=`YY uses ${settings.two_digit_year_start} to ${Number(settings.two_digit_year_start)+99}.`;
        syncSelects();
        renderPdfRows();
    }
    function headerPayload() {
        const header = {};
        Array.from(headerForm?.elements || []).forEach(el=>{ const match=el.name?.match(/^header\[(.+)\]$/); if(match) header[match[1]]=el.value || null; });
        return {source_pdf_id:number(val(headerForm,'header[pdf_source_id]')),source_page:number(val(headerForm,'header[pdf_start_page]')),code:active()?.code ?? registered()?.code ?? val(headerForm,'code').trim(),manual_transformer:manualTransformer,registered_transformer_id:active()?.header?.registered_transformer_id ?? registered()?.id ?? null,capacity_kva:number(val(headerForm,'capacity_kva')),header};
    }
    function fillHeader(payload) {
        if (!headerForm) {
            $('entry-readonly-header').innerHTML = Object.entries(payload.header || {}).filter(([k,v])=>typeof v==='string' && !['location_source','source_survey_identifier'].includes(k)).map(([k,v])=>`<div>${esc(k.replaceAll('_',' '))}: ${esc(v)}</div>`).join('');
            return;
        }
        headerForm.reset();
        for (const name of ['code','capacity_kva']) set(headerForm,name,payload[name]);
        if(!active())registeredId=number(payload.registered_transformer_id);
        const selected=active() || registered(), code=field(headerForm,'code');
        manualTransformer=!active() && !!payload.manual_transformer;
        code.readOnly=!manualTransformer;
        code.placeholder=manualTransformer ? 'Enter transformer code' : 'Filled from Active transformer';
        code.value=selected?.code || (manualTransformer ? payload.code || '' : '');
        $('entry-transformer').value=transformerSelection();
        for (const [key,value] of Object.entries(payload.header || {})) set(headerForm,`header[${key}]`,value);
        const savedPage=(payload.header?.pages || []).find(page=>page.role==='header') || payload.header?.pages?.[0];
        set(headerForm,'header[pdf_source_id]',Object.hasOwn(payload,'source_pdf_id') ? payload.source_pdf_id : payload.header?.pdf_source_id ?? savedPage?.source_pdf_id ?? $('entry-pdf-source').value);
        set(headerForm,'header[pdf_start_page]',Object.hasOwn(payload,'source_page') ? payload.source_page : payload.header?.pdf_start_page ?? savedPage?.page ?? pageNumber);
        updateHeaderPdfLimit();
        syncSelects();
        $('entry-header-caption').textContent = code.value ? `— ${code.value}` : '— new transformer';
    }
    function updateHeaderPdfLimit() {
        const input=$('entry-header-pdf-page');if(!input)return;
        const source=data.sources.find(s=>s.id===number(val(headerForm,'header[pdf_source_id]')));
        const count=Number(source?.metadata?.page_count || 0);
        if(count>0)input.max=count;else input.removeAttribute('max');
    }
    function pvPayload() {
        return Array.from($('entry-pv-records')?.children || []).map(element=>Object.fromEntries(['reference','service_load_kw','installed_capacity_kw','remarks'].map(key=>{const value=element.querySelector(`[data-pv-key="${key}"]`).value;return [key,key.endsWith('_kw') ? number(value) : (value || null)];})));
    }
    function addPv(record = {}) {
        const element = document.createElement('div'); element.className='entry-pv-row';
        element.innerHTML = [['reference','Consumer reference number'],['service_load_kw','PV service load (kW)'],['installed_capacity_kw','PV solar capacity (kW)'],['remarks','Remarks']].map(([key,label])=>`<label>${label}<input data-pv-key="${key}" aria-label="${label}" value="${esc(record[key])}" ${key.endsWith('_kw') ? 'type="number" min="0" max="1000000" step="any"' : `maxlength="${key==='remarks' ? 4000 : 255}"`}></label>`).join('')+'<button type="button" class="btn btn-sm btn-light" data-pv-remove>Remove</button>';
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
        payload.row_date=isoDate(payload.row_date,data.entry_settings?.two_digit_year_start ?? 2000) || null;
        const saved=active()?.rows.find(row=>row.id===rowId);
        payload.identity_version=saved && !saved.identity_version && !saved.composite_identifier ? null : 2;
        payload.composite_identifier=val(rowForm,'composite_identifier') || null;
        const selected=selectedPdfRow();
        payload.pdf_progress_row_id=selected && selected.source_file_id===payload.source_pdf_id && selected.page===payload.source_page ? selected.id : null;
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
        selectedPdfRowId=pdfRows().find(row=>row.entry_row_id===id && id)?.id || payload.pdf_progress_row_id || null;
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
        const draft=nextDraft(previous,{rows,next_pair:Math.max(0,...rows.map(r=>r.pair_number))+1,group_number:/^\d{2}$/.test(context.team_group || '') ? context.team_group : '',row_date:context.survey_date,
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
        try{set(rowForm,'composite_identifier',completeWaypoint(payload.group_number,payload.row_date,payload.waypoint_reference,data.entry_settings?.two_digit_year_start ?? 2000));}catch(e){set(rowForm,'composite_identifier','');identityError=e.message;}
        const matches=waypointMatches(payload,data.waypoints);
        const firstPair=Math.min(Number(payload.pair_number),...(active()?.rows || []).map(row=>Number(row.pair_number)));
        const kmzStart=payload.designation==='S' && Number(payload.pair_number)===firstPair && !payload.gpx_source_id;

        const namedPoints=data.waypoints.filter(point=>point.name===payload.waypoint_reference);
        const automaticMatches=waypointMatches({...payload,gpx_source_id:null},data.waypoints);
        const sourceConfirmation=namedPoints.length>0 && automaticMatches.length!==1;
        $('entry-gpx-ambiguity').hidden=kmzStart || !(sourceConfirmation || matches.length>1 || (payload.gpx_source_id && matches.length===0));
        if($('entry-gpx-help'))$('entry-gpx-help').textContent=sourceConfirmation
            ? 'This GPS number has more than one possible GPX match. Select the file containing the surveyed point. The PDF date stays unchanged.'
            : 'Choose the GPX file containing this surveyed GPS point.';
        $('entry-waypoint-status').textContent=!payload.waypoint_reference ? 'Text references keep leading zeros.' : matches.length===1 ? 'Matched to GPX.' : matches.length===0 ? 'Missing from GPX — draft can still be saved.' : 'Multiple GPX matches — choose source.';
        if(kmzStart && payload.waypoint_reference)$('entry-waypoint-status').textContent='First S point: KMZ reference. Transformer coordinates come from the first E point in GPX.';
        if(identityError)$('entry-waypoint-status').textContent=identityError;
        else if(!kmzStart && matches.length===0 && namedPoints.length)$('entry-waypoint-status').textContent='GPS number exists in another GPX source. Select the correct GPX file below.';
        else if(!kmzStart && matches.length===1){
            const point=matches[0];
            const recordedDate=point.recorded_at ? new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(point.recorded_at)) : null;
            if(recordedDate && payload.row_date && recordedDate!==payload.row_date)$('entry-waypoint-status').textContent='Matched to GPX by GPS number. Recording date differs from the PDF date; the PDF date stays unchanged.';
        }
        $('entry-group-date-status').textContent=inheritance.group_date ? `Group/date inherited from saved row #${inheritance.group_date}; correct either field if different.` : '';
        $('entry-height-unit-note').hidden=payload.pole_height===null || !!payload.pole_height_unit;
        field(rowForm,'pole_height_unit').title=payload.pole_height_unit ? 'Height is recorded in '+(payload.pole_height_unit==='ft' ? 'feet' : 'metres') : 'Select Feet or Metres as printed on the survey PDF before verification.';
        const unit=data.entry_settings?.pole_height_unit;
        $('entry-unit-convert').hidden=!!rowId || !!inheritance.equipment || !['ft','m'].includes(payload.pole_height_unit) || !['ft','m'].includes(unit) || payload.pole_height_unit===unit;
        $('entry-unit-convert').textContent=`Project unit changed: convert draft ${payload.pole_height ?? 'height'} ${payload.pole_height_unit || ''} to ${unit || ''}`;
        $('entry-consumer-total').textContent=`Entered consumer total: ${consumerTotal(payload.consumers)}${Object.values(payload.consumers).some(v=>v===null) ? ' (blank fields retained)' : ''}`;
        $('entry-row-page').textContent=`Row source: page ${payload.source_page ?? 'not entered'}`;
        $('entry-pair-caption').textContent=`· Pair ${payload.pair_number || 'not selected'}`;
        for (const scope of ['conductors','equipment']) $('entry-'+(scope==='conductors' ? 'conductor' : 'equipment')+'-copy-status').textContent=inheritance[scope] ? `Explicitly copied from saved row #${inheritance[scope]} in this transformer.` : '';
        syncSelects();
        renderPdfRows();
    }
    function renderSaved() {
        const t=active(), rows=t?.rows || [];
        const point=data.waypoints.find(point=>point.id===t?.source_waypoint_id);
        if($('entry-transformer-location'))$('entry-transformer-location').textContent=point
            ? `Transformer location: GPS ${point.name}, selected automatically from the first E row. The first S point is a KMZ reference.`
            : 'Transformer location will be taken from the first E row with a unique GPX match. The first S point is a KMZ reference.';

        $('entry-saved-count').textContent=`${rows.length} rows`;
        $('entry-row-panel')?.classList.toggle('entry-row-panel-disabled',!t);
        $('entry-review-link').href=`${boot.base_url}/review${t ? '?transformer_id='+t.id : ''}`;
        $('entry-saved-rows').innerHTML=rows.length ? '<div class="table-wrap"><table class="table entry-saved-table"><thead><tr><th>Pair / S/E</th><th>Page / entry</th><th>Waypoint</th><th>Phase / conductors</th><th>Equipment</th><th>Entered counts</th><th>Int</th><th></th></tr></thead><tbody>'+rows.map(row=>`<tr class="${row.designation==='S' ? 'entry-pair-start' : ''}"><td>${row.pair_number} / <strong>${row.designation}</strong>${rows.filter(r=>r.pair_number===row.pair_number).length===1 ? '<small class="entry-pair-incomplete">Incomplete pair</small>' : ''}</td><td>${row.source_page ?? '—'} / ${esc(row.source_row || '—')}</td><td>${esc(row.composite_identifier || 'Legacy identity')}<small>GPS ${esc(row.waypoint_reference || 'Not entered')} ? ${esc(row.group_number)} ? ${esc(displayDate(row.row_date))} ? source #${esc(row.gpx_source_id || 'unresolved')}</small></td><td>${derivePhase(row.conductors).display || '—'}<small>${['R','Y','B','N'].map(k=>`${k}: ${esc(row.conductors[k]===null ? 'Not entered' : row.conductors[k]==='' ? 'None' : row.conductors[k])}`).join(' · ')}</small></td><td>${esc(row.equipment_type || '—')} / ${esc(row.pole_class || '—')}<small>${row.pole_height ?? '—'} ${esc(row.pole_height_unit || '(unit unconfirmed)')}</small></td><td>${Object.entries(row.consumers).filter(([,v])=>v!==null).map(([k,v])=>`${k.toUpperCase()}: ${v}`).join(' · ') || 'Blank'}<small>Total: ${consumerTotal(row.consumers)}</small></td><td>${row.intersection ? '✓' : '—'}</td><td>${editable() ? `<button type="button" class="btn btn-sm btn-light" data-entry-edit="${row.id}">Edit</button> <button type="button" class="btn btn-sm btn-light" data-entry-delete="${row.id}">Delete</button>` : ''}</td></tr>`).join('')+'</tbody></table></div>' : '<p class="entry-empty">Save the transformer header, then enter its first S row.</p>';
        $('entry-saved-rows').querySelectorAll('tbody tr').forEach((element,index)=>{
            const marker=pdfRows().find(marker=>marker.entry_row_id===rows[index].id), entered=!marker || marker.entered;
            const badge=document.createElement('small');badge.className=entered ? 'entry-completed' : 'entry-pair-incomplete';badge.textContent=entered ? '✓ Entered' : 'PDF marker pending';
            element.dataset.entered=String(entered);element.firstElementChild.append(badge);
        });
        $('entry-legacy-records').innerHTML=t?.legacy_sections?.length ? `<p>Existing saved network: ${t.legacy_sections.length} section(s). <a href="${esc(boot.base_url)}/advanced?transformer_id=${t.id}#entry">Review or correct existing sections</a></p>` : '';
    }
    function chooseTransformer(id) {
        const match=/^registered:(\d+)$/.exec(String(id || ''));
        manualTransformer=id==='__manual__';
        registeredId=match ? Number(match[1]) : null;activeId=match || manualTransformer ? null : number(id); clearErrors(); renderLists();
        fillHeader(active() || {registered_transformer_id:registeredId,manual_transformer:manualTransformer,header:data.defaults}); headerDirty=!!registered() || manualTransformer;
        $('entry-header-panel').open=!active(); renderSaved(); newRow(); updateAssociation();
        if(manualTransformer)status('Enter the missing transformer code, complete its header and save.','dirty');
        if(registered())status('Registered transformer selected. Complete and save its survey header.','dirty');
        const url=new URL(location.href); activeId ? url.searchParams.set('transformer_id',activeId) : url.searchParams.delete('transformer_id'); history.replaceState(null,'',url);
    }
    function persist() {
        if(!boot.can_edit)return;
        try { localStorage.setItem(boot.storage_key,JSON.stringify({activeId,pageNumber,pdfSource:$('entry-pdf-source').value,rotation,zoom,height:$('entry-viewer-height').value,
            headerDirty,rowDirty,header:headerPayload(),row:rowPayload(),rowId,clientUuid,saveUuid,selectedPdfRowId})); }
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
        updateRowHints();clearCorrectedField(event.target,scope);status('Unsaved changes — kept in this browser. Save Draft to store them in the application.','dirty'); persist();
    }
    const allowSwitch = () => !busy && (!(headerDirty || rowDirty) || window.confirm('Some fields are unsaved. Continue and discard these fields? Use Save Draft first to retain them in the application.'));
    function updateAssociation() {
        const t=active(), source=number($('entry-pdf-source').value), ready=data.sources.find(s=>s.id===source)?.status==='ready';
        const associated=t?.header?.pages?.some(p=>p.source_pdf_id===source && p.page===pageNumber && p.confirmed);
        $('entry-page-association').textContent=t ? `${t.code} · page ${pageNumber} ${associated ? 'belongs to this transformer' : 'not yet confirmed — continue current or add new transformer'}` : 'Save a new transformer header for this PDF page.';
        if($('entry-continue')) $('entry-continue').disabled=busy || !editable() || !t || !source || !ready || associated;
    }
    async function saveHeader(event) {
        event?.preventDefault(); if(busy || !headerForm || !editable()) return;
        clearErrors();
        if(!active() && !registered() && !manualTransformer){
            headerDirty=true;error('Header not saved. Choose a registered transformer from this feeder.',{registered_transformer_id:['Choose a transformer in Active transformer.']},headerForm);revealHeaderErrors();persist();return;
        }
        if(!headerForm.checkValidity()){
            const errors=Object.fromEntries(Array.from(headerForm.elements).filter(element=>element.name && !element.validity.valid).map(element=>[element.name.replace(/\[([^\]]+)\]/g,'.$1'),[element.validationMessage]]));
            headerDirty=true;error('Header not saved. Correct the highlighted fields and save again.',errors,headerForm);revealHeaderErrors();persist();return;
        }
        if(!active() && data.transformers.some(t=>transformerCodeKey(t.code)===transformerCodeKey(val(headerForm,'code')))){
            headerDirty=true;error('Header not saved.',{code:['This transformer is already added to this survey. Select its saved header in Active transformer to edit it.']},headerForm);revealHeaderErrors();persist();return;
        }
        const body=headerPayload();
        body.revision=data.revision; const path=active() ? `/entry-headers/${activeId}` : '/entry-headers';
        headerDirty=true;persist();setBusy(true); status('Saving transformer header…');
        let failed=false;
        try {
            const saved=await api(path,active() ? 'PUT' : 'POST',body);
            if(!saved.transformers?.some(transformer=>transformer.id===Number(saved.active_transformer_id)))throw new Error('The server response did not confirm the saved header. Refresh saved records before retrying. Your fields are retained.');
            data=saved;activeId=data.active_transformer_id;manualTransformer=false;headerDirty=false;renderLists();fillHeader(active());renderSaved();if(!rowDirty)newRow();$('entry-header-panel').open=false;status('Transformer header saved.');persist();
        }
        catch(e){failed=true;headerDirty=true;error(`Header not saved. ${e.message} Your entered fields are retained.${e.reference ? ' Reference: '+e.reference : ''}`,e.errors || {},headerForm);persist();}
        finally{setBusy(false);if(failed)revealHeaderErrors();}
    }
    function revealHeaderErrors() {
        $('entry-header-panel').open=true;
        $('entry-errors').scrollIntoView({block:'nearest',behavior:'smooth'});
        const invalid=Array.from(headerForm.elements).find(element=>element.getAttribute('aria-invalid')==='true' && element.type!=='hidden');
        (invalid || $('entry-errors')).focus({preventScroll:true});
    }
    async function saveRow(advance) {
        if(busy || !editable() || !rowForm || !active()) return;
        clearErrors();
        const body=rowPayload(), validation=rowValidationErrors(body,data.entry_settings?.two_digit_year_start ?? 2000);
        for(const element of Array.from(rowForm.elements).filter(el=>el.willValidate && !el.validity.valid)) {
            const pvKey=element.dataset.pvKey, index=pvKey ? Array.from($('entry-pv-records').children).indexOf(element.closest('.entry-pv-row')) : null;
            const key=pvKey ? `pv_details.${index}.${pvKey}` : element.name.replace(/\[([^\]]+)\]/g,'.$1');
            if(key && !validation[key])validation[key]=[element.validationMessage];
        }
        if(Object.keys(validation).length){rowDirty=true;error('Row not saved. Correct the highlighted fields and retry.',validation,rowForm);revealRowErrors();persist();return;}
        const marker=selectedPdfRow(), markedSelection=!!marker;
        if(pdfRows().some(row=>row.source_file_id===number(val(rowForm,'source_pdf_id')) && row.page===number(val(rowForm,'source_page'))) && !marker){error('Row not saved. Select the corresponding PDF row.',{pdf_progress_row_id:['Choose the PDF row for this record before saving.']},rowForm);revealRowErrors();return;}
        saveUuid ||= uuid(); Object.assign(body,{client_uuid:clientUuid,save_uuid:saveUuid,revision:data.revision});
        const path=`/transformers/${activeId}/rows${rowId ? '/'+rowId : ''}`;
        setBusy(true); status('Saving row…');
        let succeeded=false;
        try {const response=await api(path,rowId ? 'PUT' : 'POST',body), saved=response.transformers?.find(t=>t.id===activeId)?.rows?.find(r=>r.client_uuid===clientUuid);
            if(!saved)throw new Error('The server did not confirm the saved row. Refresh saved records before retrying.');
            data=response;rowDirty=false;
            renderLists();renderSaved(); if(advance)newRow(saved); else fillRow(saved,saved.id,saved.client_uuid);
            if(advance && markedSelection){const pending=nextPendingPdfRow(pdfRows(),body.source_pdf_id);if(pending)selectPdfRow(pending.id,false);}
            status(advance ? 'Row completed and saved. Enter the next S/E row.' : 'Draft saved in the application. You can resume later.');persist();succeeded=true;
        }catch(e){rowDirty=true;error(`Row not saved. ${e.message} Your fields are retained.`,e.errors || {},rowForm);persist();}
        finally{setBusy(false);if(rowId){field(rowForm,'pair_number').disabled=true;field(rowForm,'designation').disabled=true;}
            if(succeeded && advance){const nextField=['group_number','row_date','waypoint_reference'].find(key=>!val(rowForm,key)) || 'waypoint_reference';field(rowForm,nextField).focus({preventScroll:true});$('entry-row-panel').scrollIntoView({block:'nearest'});}
            else if(!succeeded)revealRowErrors();
        }
    }
    function revealRowErrors() {
        const invalid=rowForm.querySelector('[aria-invalid=true]:not([type=hidden])') || document.querySelector('[aria-invalid=true]:not([type=hidden])');
        const target=invalid?.closest('.entry-combobox')?.querySelector('.entry-combobox-trigger') || invalid || $('entry-errors');
        for(let parent=target.parentElement;parent;parent=parent.parentElement)if(parent.tagName==='DETAILS')parent.open=true;
        target.focus({preventScroll:true});target.scrollIntoView({block:'nearest'});
    }
    function copyValues(scope) {
        if(busy || !editable() || !active()) return;
        const current=rowPayload(), rows=active().rows, marker=selectedPdfRow();
        const candidates=rows.filter((r,index)=>{
            if(r.id===rowId || (rowId && index>=rows.findIndex(saved=>saved.id===rowId)))return false;
            const physical=pdfRows().find(progress=>progress.entry_row_id===r.id);
            return !marker || !physical || physical.source_file_id!==marker.source_file_id || physical.page<marker.page || (physical.page===marker.page && physical.row_number<marker.row_number);
        });
        const previous=current.designation==='E' ? candidates.find(r=>r.pair_number===current.pair_number && r.designation==='S') || candidates.at(-1) : candidates.at(-1);
        if(!previous){error('No previous saved row in this transformer.');return;}
        // Explicit allowlist: no identity, coordinates, source, pairing, counts or PV fields.
        const patch=[];
        if(scope==='conductors' || scope==='both')for(const key of ['R','Y','B','N']){const value=previous.conductors[key];if(value!==null && value!==undefined)patch.push([`conductors[${key}]`,value==='' ? '__none__' : value,`${key==='N' ? 'Neutral' : key} conductor`]);}
        if(scope==='equipment' || scope==='both'){
            for(const [key,label] of [['equipment_type','Equipment type'],['pole_class','Pole class']])if(previous[key])patch.push([key,previous[key],label]);
            if(previous.pole_height!==null && previous.pole_height!==undefined && ['ft','m'].includes(previous.pole_height_unit))patch.push(['pole_height',previous.pole_height,'Pole/tower height'],['pole_height_unit',previous.pole_height_unit,'Height unit']);
        }
        const changed=patch.filter(([key,value])=>val(rowForm,key)!==String(value));
        if(!changed.length){status('No additional reusable values to copy. Your fields are unchanged.',rowDirty ? 'dirty' : 'saved');return;}
        const overwritten=changed.filter(([key])=>val(rowForm,key)!=='' && (key!=='pole_height_unit' || val(rowForm,'pole_height')!=='')).map(([, ,label])=>label);
        if(overwritten.length && !window.confirm(`Replace your entered values for ${overwritten.join(', ')} with values from pair ${previous.pair_number} ${previous.designation}? All other fields will be kept.`))return;
        for(const [key,value] of patch)set(rowForm,key,value);
        const copied=rowPayload();
        if(scope==='conductors' || scope==='both'){if(['R','Y','B','N'].every(key=>(copied.conductors[key]==='__none__' ? '' : copied.conductors[key])===(previous.conductors[key] ?? null)))inheritance.conductors=previous.id;else delete inheritance.conductors;}
        if(scope==='equipment' || scope==='both'){if(['equipment_type','pole_class','pole_height','pole_height_unit'].every(key=>(copied[key] ?? null)===(previous[key] ?? null)))inheritance.equipment=previous.id;else delete inheritance.equipment;}
        rowDirty=true;saveUuid=null;clearErrors();updateRowHints();status(`Reusable values copied from pair ${previous.pair_number} ${previous.designation}, including height when its unit is known. Check the PDF and edit any field before saving.`,'dirty');persist();
    }
    function renderPdfRows() {
        const source=number($('entry-pdf-source').value), rows=pdfRows().filter(row=>row.source_file_id===source && row.page===pageNumber);
        const pending=nextPendingPdfRow(pdfRows(),source), selected=selectedPdfRow();
        const overlay=$('entry-pdf-rows');
        const pageRendered=!$('entry-pdf-canvas').hidden && renderedPdfLocation===`${source}:${pageNumber}:${rotation}:${zoom}`;
        overlay.hidden=!pageRendered;
        const progress=pdfPageProgress(rows);
        renderPdfLines(pageRendered, rows);
        const focusedRow=document.activeElement?.dataset.pdfRow;
        overlay.innerHTML=rows.map(row=>{
            const bounds=rotatedRowBounds(row.bounds,rotation), next=pending?.id===row.id;
            return `<button type="button" class="entry-pdf-row" data-pdf-row="${row.id}" data-entered="${row.entered}" data-pending="${next}" aria-pressed="${selected?.id===row.id}" aria-label="PDF page ${row.page}, row ${row.row_number}: ${row.entered ? 'Entered' : next ? 'Next pending' : 'Pending'}" ${busy ? 'disabled' : ''} style="left:${bounds.x*100}%;top:${bounds.y*100}%;width:${bounds.width*100}%;height:${bounds.height*100}%"><span>${row.entered ? '&#10003; ' : next ? 'Next: ' : ''}${row.row_number}</span></button>`;
        }).join('');
        if(focusedRow)overlay.querySelector(`[data-pdf-row="${focusedRow}"]`)?.focus({preventScroll:true});
        optionList($('entry-pdf-row'),rows.map(row=>[row.id,`${row.row_number} — ${row.entered ? '✓ Entered' : pending?.id===row.id ? 'Next pending' : 'Pending'}${row.entry_row_id && !row.entered ? ' (record saved)' : ''}`]),'Select row',String(rows.some(row=>row.id===selected?.id) ? selected.id : ''));
        $('entry-pdf-row').disabled=busy || !rows.length;
        $('entry-pdf-pending').disabled=busy || !pending;
        const configuredPages=new Set(pdfRows().filter(row=>row.source_file_id===source).map(row=>row.page)).size;
        $('entry-pdf-progress').textContent=rows.length ? `${rows.length-progress.remaining}/${rows.length} entered on this page; ${progress.remaining} remaining. ${progress.enteredThrough ? `Entered area reaches row ${progress.enteredThrough}. ` : ''}${pending ? `Next pending: page ${pending.page}, row ${pending.row_number}.` : 'All configured rows entered.'} ${configuredPages}/${pdf?.numPages || data.sources.find(s=>s.id===source)?.metadata?.page_count || '?'} pages set up.` : 'Rows are not set up for this page. Set up its S/E table first.';
        if($('entry-pdf-mark'))$('entry-pdf-mark').disabled=busy || !editable() || !selected?.entry_row_id || selected.entered || rowDirty;
        if($('entry-pdf-undo'))$('entry-pdf-undo').disabled=busy || !editable() || !selected?.entered;
        if($('entry-legacy-progress'))$('entry-legacy-progress').hidden=!rows.length;
    }
    function pageLines() {
        return (data.pdf_lines || []).filter(line=>line.source_file_id===number($('entry-pdf-source').value) && line.page===pageNumber);
    }
    function renderPdfLines(pageRendered, rows) {
        const svg=$('entry-pdf-lines');
        if(!svg)return;
        svg.toggleAttribute('hidden',!pageRendered);
        const lines=pageLines().map(line=>({id:line.id,start:rotatedPdfPoint(line.start,rotation),end:rotatedPdfPoint(line.end,rotation)}));
        for(const row of rows.filter(row=>row.entered)){
            const bounds=row.bounds;
            lines.push({id:`row-${row.id}`,start:rotatedPdfPoint({x:bounds.x,y:bounds.y+bounds.height},rotation),end:rotatedPdfPoint({x:bounds.x+bounds.width,y:bounds.y+bounds.height},rotation)});
        }
        if(linePreview)lines.push({id:'preview',...linePreview});
        svg.innerHTML=lines.map(line=>`<line data-pdf-line="${esc(line.id)}" x1="${line.start.x}" y1="${line.start.y}" x2="${line.end.x}" y2="${line.end.y}" vector-effect="non-scaling-stroke" />`).join('');
        if($('entry-pdf-draw')){
            $('entry-pdf-draw').disabled=busy || !editable() || !pageRendered || data.sources.find(source=>source.id===number($('entry-pdf-source').value))?.status!=='ready';
            $('entry-pdf-draw').setAttribute('aria-pressed',String(drawingLines));
        }
        if($('entry-line-undo')){
            $('entry-line-undo').hidden=!pageLines().length;
            $('entry-line-undo').disabled=busy || !editable();
        }
        if($('entry-line-status'))$('entry-line-status').textContent=drawingLines ? 'Click a row or drag across it. Esc to stop.' : (lines.length ? `${lines.length} red ${lines.length===1 ? 'line' : 'lines'} on this page` : '');
    }
    function selectPdfRow(id, checkDraft = true) {
        const marker=pdfRows().find(row=>row.id===Number(id));
        if(!marker || (busy && checkDraft) || (checkDraft && !allowSwitch())){renderPdfRows();return;}
        if(rowForm){
            const owner=data.transformers.find(t=>t.rows.some(row=>row.id===marker.entry_row_id));
            if(owner){
                if(activeId!==owner.id)chooseTransformer(owner.id);
                const saved=owner.rows.find(row=>row.id===marker.entry_row_id);fillRow(saved,saved.id,saved.client_uuid);
                status(marker.entered ? 'Entered row selected. Editing its saved record.' : 'Pending marker selected. Its saved record is loaded; save or mark Entered.');
            }else{
                if((rowId && pdfRows().some(row=>row.entry_row_id===rowId)) || (checkDraft && rowDirty))newRow();
                set(rowForm,'source_pdf_id',marker.source_file_id);set(rowForm,'source_page',marker.page);set(rowForm,'source_row',`PDF row ${marker.row_number}`);
                if(rowId)rowDirty=true;
                saveUuid=null;
            }
        }
        const sourceChanged=number($('entry-pdf-source').value)!==marker.source_file_id;
        selectedPdfRowId=marker.id;$('entry-pdf-source').value=String(marker.source_file_id);
        if(pageNumber!==marker.page)setPage(marker.page);
        if(sourceChanged)loadPdf();
        updateRowHints();renderPdfRows();persist();
        window.requestAnimationFrame?.(()=>$('entry-pdf-rows').querySelector(`[data-pdf-row="${marker.id}"]`)?.scrollIntoView({block:'nearest',inline:'nearest'}));
    }
    async function markPdfRow(entered) {
        const marker=selectedPdfRow();if(busy || !marker || (entered && rowDirty))return;
        setBusy(true);clearErrors();
        try{data=await api(`/pdf-rows/${marker.id}`,'PUT',{revision:data.revision,entered});renderPdfRows();persist();status(entered ? 'PDF row marked Entered.' : 'Entered marker undone. The saved record is retained.');}
        catch(e){error('PDF row marker was not changed.',e.errors || {save:[e.message]});}
        finally{setBusy(false);}
    }
    function cancelLineDrawing() {
        drawingLines=false;lineStart=null;linePreview=null;linePointer=null;
        $('entry-pdf-stage').dataset.drawing='false';
        renderPdfRows();
    }
    function drawPoint(event) {
        const rect=$('entry-pdf-canvas').getBoundingClientRect();
        return {x:Math.max(0,Math.min(1,(event.clientX-rect.left)/rect.width)),y:Math.max(0,Math.min(1,(event.clientY-rect.top)/rect.height))};
    }
    async function savePdfLine(preview) {
        const source=number($('entry-pdf-source').value), page=pageNumber;
        const start=rotatedPdfPoint(preview.start,-rotation),end=rotatedPdfPoint(preview.end,-rotation);
        setBusy(true);clearErrors();
        try {
            data=await api(`/sources/${source}/lines`,'POST',{revision:data.revision,page,client_uuid:uuid(),start,end});
            status('Red line saved.');persist();
        }catch(e){error('Red line was not confirmed. Refresh saved records before retrying.',e.errors || {line:[e.message]});}
        finally{linePreview=null;setBusy(false);}
    }
    async function undoPdfLine() {
        const line=pageLines().at(-1);if(busy || !editable() || !line)return;
        setBusy(true);clearErrors();
        try{data=await api(`/pdf-lines/${line.id}`,'DELETE',{revision:data.revision});status('Last red line removed.');persist();}
        catch(e){error('Red line could not be removed.',e.errors || {line:[e.message]});}
        finally{setBusy(false);}
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
                if(token!==pdfGeneration)return;
                renderedPdfLocation=`${number($('entry-pdf-source').value)}:${selectedPage}:${selectedRotation}:${selectedZoom}`;
                renderPdfRows();
            }catch(e){if(e.name!=='RenderingCancelledException'){$('entry-pdf-message').textContent='PDF rendering failed. Use Open PDF; entered fields are retained.';$('entry-pdf-message').hidden=false;}}
        });
        return renderChain;
    }
    async function loadPdf() {
        let phase='library';
        clearPdfFallback($('entry-pdf-scroll'));
        const source=number($('entry-pdf-source').value), token=++pdfGeneration;
        const previousLoading=pdfLoading;
        renderTask?.cancel();pdfLoading=null;pdf=null;
        $('entry-pdf-canvas').hidden=true;$('entry-pdf-message').hidden=false;$('entry-pdf-message').textContent=source ? 'Loading PDF…' : 'Upload a PDF and GPX to begin.';
        cancelLineDrawing();renderPdfRows();
        try {
            // The loading task owns the worker and document resources. Destroy it
            // once; document proxies in the bundled PDF.js have no destroy method.
            await previousLoading?.destroy();
            if(token!==pdfGeneration)return;
            if(!source){$('entry-page-total').textContent='of 0';updateAssociation();return;}
            $('entry-pdf-open').href=sourceUrl(source);
            pdfLib ||= await import(boot.pdfjs_url);pdfLib.GlobalWorkerOptions.workerSrc=boot.pdfjs_worker;
            phase='document';
            if(token!==pdfGeneration)return;
            pdfLoading=pdfLib.getDocument({url:sourceUrl(source),withCredentials:true,disableRange:true,disableStream:true,isEvalSupported:false,cMapUrl:boot.pdfjs_assets+'cmaps/',cMapPacked:true,standardFontDataUrl:boot.pdfjs_assets+'standard_fonts/',wasmUrl:boot.pdfjs_assets+'wasm/'});
            const loaded=await pdfLoading.promise;if(token!==pdfGeneration)return;pdf=loaded;pageNumber=Math.max(1,Math.min(pdf.numPages,pageNumber));setPage(pageNumber);await renderPdf();
        }catch(e){if(token===pdfGeneration){
            showPdfFallback($('entry-pdf-scroll'),sourceUrl(source),pageNumber);
            $('entry-pdf-message').textContent='Browser PDF preview: use its toolbar to navigate. Row overlays and line drawing require the interactive viewer. '+pdfLoadErrorMessage(e,phase)+' Your entry is retained.';
        }}
    }
    function setPage(numberValue) {
        pageNumber=Math.max(1,Math.min(pdf?.numPages || data.sources.find(s=>s.id===number($('entry-pdf-source').value))?.metadata?.page_count || 1,numberValue));
        $('entry-page').value=pageNumber;$('entry-page-total').textContent=`of ${pdf?.numPages || 0}`;$('entry-page-previous').disabled=pageNumber<=1;$('entry-page-next').disabled=!pdf || pageNumber>=pdf.numPages;
        cancelLineDrawing();
        if(rowForm && !rowId && !busy && !selectedPdfRowId){set(rowForm,'source_pdf_id',number($('entry-pdf-source').value));set(rowForm,'source_page',pageNumber);updateRowHints();}
        renderPdfRows();
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
    if(recovery && boot.can_edit && Number(recovery.activeId || 0)===Number(activeId || 0)){
        pageNumber=recovery.pageNumber || 1;rotation=recovery.rotation || 0;zoom=recovery.zoom || 1;$('entry-pdf-source').value=recovery.pdfSource || $('entry-pdf-source').value;
        $('entry-viewer-height').value=recovery.height || 240;
        if(recovery.headerDirty && recovery.header){fillHeader(recovery.header);headerDirty=true;$('entry-header-panel').open=true;}
        if(recovery.rowDirty && recovery.row){fillRow(recovery.row,recovery.rowId,recovery.clientUuid);rowDirty=true;saveUuid=recovery.saveUuid;}
        else if(recovery.selectedPdfRowId)selectPdfRow(recovery.selectedPdfRowId,false);
        if(headerDirty || rowDirty)status('Unsaved fields restored from this browser. Check current saved records before saving.','dirty');
    }
    if(!recovery && window.innerWidth<650)$('entry-viewer-height').value='180';
    $('entry-pdf-scroll').style.height=$('entry-viewer-height').value+'px';$('entry-height-value').textContent=$('entry-viewer-height').value+' px';
    $('entry-pdf-rows').addEventListener('click',event=>{const button=event.target.closest('[data-pdf-row]');if(button)selectPdfRow(button.dataset.pdfRow);});
    $('entry-pdf-row').addEventListener('change',()=>selectPdfRow($('entry-pdf-row').value));
    $('entry-pdf-pending').addEventListener('click',()=>{const pending=nextPendingPdfRow(pdfRows(),$('entry-pdf-source').value);if(pending)selectPdfRow(pending.id);});
    $('entry-pdf-mark')?.addEventListener('click',()=>markPdfRow(true));$('entry-pdf-undo')?.addEventListener('click',()=>markPdfRow(false));
    $('entry-pdf-draw')?.addEventListener('click',()=>{
        if(busy || !pdf || !editable())return;
        if(drawingLines){cancelLineDrawing();return;}
        drawingLines=true;$('entry-pdf-stage').dataset.drawing='true';renderPdfRows();
    });
    $('entry-line-undo')?.addEventListener('click',undoPdfLine);
    $('entry-pdf-stage').addEventListener('pointerdown',event=>{
        if(!drawingLines || busy || event.button!==0 || lineStart)return;
        event.preventDefault();lineStart=drawPoint(event);linePointer=event.pointerId;
        linePreview={start:lineStart,end:lineStart};
        $('entry-pdf-stage').setPointerCapture?.(event.pointerId);
    });
    $('entry-pdf-stage').addEventListener('pointermove',event=>{
        if(!drawingLines || !lineStart || event.pointerId!==linePointer)return;
        linePreview={start:lineStart,end:{x:drawPoint(event).x,y:lineStart.y}};renderPdfRows();
    });
    $('entry-pdf-stage').addEventListener('pointerup',event=>{
        if(!drawingLines || !lineStart || event.pointerId!==linePointer || busy)return;
        const point=drawPoint(event),start=lineStart;
        linePreview=Math.abs(point.x-start.x)<.01 ? {start:{x:.01,y:start.y},end:{x:.99,y:start.y}} : {start,end:{x:point.x,y:start.y}};
        lineStart=null;linePointer=null;
        $('entry-pdf-stage').releasePointerCapture?.(event.pointerId);
        savePdfLine(linePreview);
    });
    $('entry-pdf-stage').addEventListener('pointercancel',cancelLineDrawing);
    document.addEventListener('keydown',event=>{if(event.key==='Escape' && drawingLines){event.preventDefault();cancelLineDrawing();}});
    if(!data.sources.some(s=>s.kind==='pdf'))document.querySelector('.entry-source-files').open=true;
    $('entry-header-pdf')?.addEventListener('change',updateHeaderPdfLimit);
    headerForm?.addEventListener('submit',saveHeader);headerForm?.addEventListener('input',event=>markDirty(event,'header'));headerForm?.addEventListener('change',event=>markDirty(event,'header'));
    $('entry-submit-form')?.addEventListener('submit',event=>{
        if(busy || !editable() || headerDirty || rowDirty){event.preventDefault();error('Save the header and all row changes before submitting for verification. Your entered fields are retained.');}
    });
    rowForm?.addEventListener('submit',event=>{event.preventDefault();saveRow(true);});rowForm?.addEventListener('input',event=>markDirty(event,'row'));rowForm?.addEventListener('change',event=>markDirty(event,'row'));
    $('entry-save-draft')?.addEventListener('click',()=>saveRow(false));$('entry-row-new')?.addEventListener('click',()=>{if(allowSwitch()){newRow();status('New draft row.');}});
    $('entry-transformer').addEventListener('change',()=>{if(allowSwitch()){chooseTransformer($('entry-transformer').value);persist();}else $('entry-transformer').value=transformerSelection();});
    document.querySelectorAll('[data-entry-add-transformer]').forEach(button=>button.addEventListener('click',()=>{if(!allowSwitch())return;chooseTransformer('__manual__');$('entry-header-panel').open=true;$('entry-transformer-search').value='';filterSelect($('entry-transformer'),'');field(headerForm,'code')?.focus();persist();}));
    $('entry-transformer-search').addEventListener('input',e=>filterSelect($('entry-transformer'),e.target.value));document.querySelectorAll('[data-entry-filter]').forEach(input=>input.addEventListener('input',()=>filterSelect($(input.dataset.entryFilter),input.value)));
    $('entry-copy-conductors')?.addEventListener('click',()=>copyValues('conductors'));$('entry-copy-equipment')?.addEventListener('click',()=>copyValues('equipment'));$('entry-copy-both')?.addEventListener('click',()=>copyValues('both'));
    $('entry-paste-apply')?.addEventListener('click',()=>{
        try{
            const parsed=parseWaypoint($('entry-paste-waypoint').value.trim(),data.entry_settings?.two_digit_year_start);
            if(Object.entries(parsed).some(([key,value])=>val(rowForm,key) && (key==='row_date' ? isoDate(val(rowForm,key),data.entry_settings?.two_digit_year_start) : val(rowForm,key))!==value) && !window.confirm('Replace the entered group, date or GPS number with this pasted waypoint?'))return;
            for(const [key,value] of Object.entries(parsed))set(rowForm,key,value);
            set(rowForm,'gpx_source_id',null);delete inheritance.group_date;rowDirty=true;saveUuid=null;clearErrors();updateRowHints();persist();status('Complete waypoint applied. Check the PDF date and GPS match.','dirty');
        }catch(e){error(e.message);}
    });
    $('entry-unit-convert')?.addEventListener('click',()=>{
        if(busy)return;
        const current=rowPayload(),unit=data.entry_settings?.pole_height_unit;
        try{const height=convertPoleHeight(current.pole_height,current.pole_height_unit,unit);set(rowForm,'pole_height',height);set(rowForm,'pole_height_unit',unit);rowDirty=true;saveUuid=null;updateRowHints();persist();status(`Draft height converted from ${current.pole_height ?? 'blank'} ${current.pole_height_unit} to ${height ?? 'blank'} ${unit}. Check against the source before saving.`,'dirty');}catch(e){error(e.message);}
    });
    $('entry-use-page')?.addEventListener('click',()=>{if(busy)return;if(selectedPdfRowId){error('This record is linked to a selected PDF row. Select the correct PDF row to change its source.');return;}set(rowForm,'source_pdf_id',number($('entry-pdf-source').value));set(rowForm,'source_page',pageNumber);rowDirty=true;saveUuid=null;updateRowHints();status('Displayed page selected for this row. Save to confirm.','dirty');persist();});
    $('entry-pv-add')?.addEventListener('click',()=>{addPv();rowDirty=true;saveUuid=null;persist();});$('entry-pv-records')?.addEventListener('click',event=>{if(event.target.closest('[data-pv-remove]')){event.target.closest('.entry-pv-row').remove();rowDirty=true;saveUuid=null;$('entry-pv-count').textContent=`(${pvPayload().length})`;persist();}});
    $('entry-saved-rows').addEventListener('click',async event=>{
        const edit=event.target.closest('[data-entry-edit]'),remove=event.target.closest('[data-entry-delete]');if(busy)return;
        if(edit){if(!allowSwitch())return;const row=active().rows.find(r=>r.id===Number(edit.dataset.entryEdit));fillRow(row,row.id,row.client_uuid);persist();$('entry-row-panel').scrollIntoView({block:'end',behavior:'smooth'});}
        if(remove){if(headerDirty || rowDirty){error('Save or cancel your current draft before deleting a saved row.');return;}if(!window.confirm('Delete this saved S/E row? Its paired row will remain as an incomplete draft.'))return;setBusy(true);try{data=await api(`/transformers/${activeId}/rows/${remove.dataset.entryDelete}`,'DELETE',{revision:data.revision,confirmed:true});renderLists();renderSaved();newRow();status('Row deleted. Check the remaining pair in review.');}catch(e){error('Row could not be deleted.',e.errors || {delete:[e.message]});}finally{setBusy(false);}}
    });
    $('entry-continue')?.addEventListener('click',async()=>{if(busy || !active())return;setBusy(true);try{data=await api(`/transformers/${activeId}/pages`,'POST',{revision:data.revision,source_pdf_id:number($('entry-pdf-source').value),page:pageNumber,role:'continuation',confirmed:true});renderLists();updateAssociation();status('Current transformer retained; continuation page confirmed.');persist();}catch(e){error('Continuation page could not be confirmed.',e.errors || {page:[e.message]});}finally{setBusy(false);}});
    $('entry-refresh').addEventListener('click',async()=>{if(busy)return;const h=headerPayload(),r=rowPayload(),id=rowId,identity=clientUuid;setBusy(true);try{data=await api('/entry-data');clearErrors();renderLists();renderSaved();if(headerDirty)fillHeader(h);else fillHeader(active() || {header:data.defaults});if(rowDirty){fillRow(r,id,identity);rowDirty=true;}else newRow();status('Saved records refreshed. Review retained fields before saving.',headerDirty || rowDirty ? 'dirty' : 'saved');persist();await loadPdf();}catch(e){error(e.message);}finally{setBusy(false);}});
    $('entry-pdf-source').addEventListener('change',()=>{pageNumber=1;loadPdf();persist();});$('entry-page').addEventListener('change',()=>setPage(Number($('entry-page').value)||1));$('entry-page-previous').addEventListener('click',()=>setPage(pageNumber-1));$('entry-page-next').addEventListener('click',()=>setPage(pageNumber+1));
    $('entry-zoom-out').addEventListener('click',()=>{cancelLineDrawing();zoom=Math.max(.25,zoom/1.25);renderPdf();persist();});$('entry-zoom-in').addEventListener('click',()=>{cancelLineDrawing();zoom=Math.min(3,zoom*1.25);renderPdf();persist();});$('entry-zoom-fit').addEventListener('click',()=>{cancelLineDrawing();zoom=1;renderPdf();persist();});$('entry-rotate').addEventListener('click',()=>{cancelLineDrawing();rotation=(rotation+90)%360;renderPdf();persist();});
    $('entry-viewer-height').addEventListener('input',()=>{$('entry-pdf-scroll').style.height=$('entry-viewer-height').value+'px';$('entry-height-value').textContent=$('entry-viewer-height').value+' px';persist();});
    let resizeTimer;new ResizeObserver(()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(renderPdf,150);}).observe($('entry-pdf-scroll'));
    new ResizeObserver(()=>{$('mdb-entry-workspace').style.setProperty('--entry-document-height',Math.ceil(document.querySelector('.entry-document').getBoundingClientRect().height)+'px');}).observe(document.querySelector('.entry-document'));
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
