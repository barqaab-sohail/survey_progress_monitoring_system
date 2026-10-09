(() => {
    'use strict';
    const root = document.getElementById('field-test');
    if (!root) return;
    const config = JSON.parse(root.dataset.config);
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const clone = value => structuredClone(value);
    const isIntersection = value => value === true || ['1','true','yes','on','int','intersection','?','?','x','?','+'].includes(String(value ?? '').trim().toLowerCase());
    const phaseFromConductors = row => ['r','y','b'].filter(key => !['','+','-','–','—','x','×'].includes(String(row['conductor_'+key] ?? '').trim().toLowerCase())).join('').toUpperCase();
    const uuid = () => {
        if (crypto.randomUUID) return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, x => x.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
    };
    const consumerLabels = {rs:'RS · 1-phase residential',rl:'RL · 3-phase residential',sc:'SC · 1-phase commercial',lc:'LC · Commercial (LC)',si:'SI · 1-phase industrial',li:'LI · 3-phase industrial',pb:'PB · Public buildings',ag:'AG · Agriculture',st:'ST · Street lights'};
    const stateLabels = {draft:'Browser draft',queued:'Waiting to sync',synced:'Synced',error:'Needs attention',conflict:'Version conflict'};
    const tabs = ['Transformer details','S/E observations','Solar / net-metering','Photos and sketches'];
    const conductors = ['A','W','GN','2/0 AWG','PVC 7/0.052','PVC 19/0.052','PVC 19/0.083','USAID 50mm2','USAID 95mm2','Ang','Int'];
    let db, reference = null, records = [], current = null, section = 0, filter = 'all', syncing = false, saved = true, saveGeneration = 0;
    let saveChain = Promise.resolve();
    const previewUrls = [];
    const offline = () => !navigator.onLine || $('simulate-offline').checked;
    const locked = () => current && (current.outbound || current.state === 'conflict' || syncing);
    function notice(message) { $('test-notice').textContent = message; $('test-notice').hidden = false; }
    function connected() { $('connection-status').textContent = offline() ? 'Offline · drafts stay in this browser' : 'Connected · test workspace'; }
    function storage(operation, key, value) {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('notebook', operation);
            const store = transaction.objectStore('notebook');
            const request = operation === 'readonly' ? store.get(key) : store.put(value, key);
            transaction.oncomplete = () => resolve(request.result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error || new Error('Browser storage failed.'));
        });
    }
    function persist() {
        const generation = ++saveGeneration;
        const snapshot = clone(records);
        saved = false;
        if (current) $('save-status').textContent = 'Saving in this browser…';
        saveChain = saveChain.catch(() => {}).then(() => storage('readwrite', 'records', snapshot));
        saveChain.then(() => {
            if (generation !== saveGeneration) return;
            saved = true;
            if (current) $('save-status').textContent = current.state === 'synced' ? 'Confirmed by server · saved here' : 'Saved in this browser';
        }).catch(() => { saved = false; $('save-status').textContent = 'Save failed'; notice('Browser storage failed. Export your JSON and retry saving before leaving.'); });
        return saveChain;
    }
    async function api(path, options = {}) {
        if (offline()) throw Object.assign(new Error('Offline. Saved surveys remain queued in this browser.'), {offline:true});
        const response = await fetch(config.base + path, {
            credentials:'same-origin', cache:'no-store', ...options,
            headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...options.headers},
        });
        if (response.redirected || !response.headers.get('Content-Type')?.includes('application/json')) {
            throw Object.assign(new Error('Your session is unavailable. Sign in again and reopen this workspace. Browser drafts are retained.'), {status:401});
        }
        let body;
        try { body = await response.json(); } catch { body = {}; }
        if (!response.ok) throw Object.assign(new Error(body.message || (response.status === 419 ? 'Session expired. Sign in again, then reopen this workspace. Your browser drafts are retained.' : `Request failed (${response.status}).`)), {status:response.status,errors:body.errors});
        return body;
    }
    async function refresh() {
        reference = await api('/bootstrap');
        await storage('readwrite','reference',reference);
        await mergeServer();
        $('new-survey').disabled = !reference.teams.some(t => reference.feeders.some(f => f.team_ids.includes(t.id)));
        renderList(); connected();
        notice(reference.feeders.length ? 'Assignments downloaded. Existing survey details are preserved.' : 'No active team/feeder assignments are available. Add an assignment under Teams & Assignments to start testing.');
    }
    async function mergeServer() {
        const remote = await api('/records');
        for (const record of remote) {
            if (!records.some(r => r.data.client_uuid === record.data.client_uuid)) records.push({...record,state:'synced',error:'',outbound:null});
        }
        await persist();
    }
    function feederFor(record) { return reference?.feeders.find(f => f.id === record.data.feeder_id); }
    function renderList() {
        const filters = {all:'All',draft:'Drafts',queued:'Waiting',synced:'Synced',attention:'Attention'};
        $('survey-filters').innerHTML = Object.entries(filters).map(([key,label]) => `<button type="button" data-filter="${key}" aria-selected="${filter === key}">${label} (${records.filter(r => matches(r,key)).length})</button>`).join('');
        const search = $('survey-search').value.toLowerCase();
        const visible = records.filter(r => matches(r,filter) && `${r.data.transformer_code} ${feederFor(r)?.feeder_name || ''} ${r.data.survey_date}`.toLowerCase().includes(search));
        $('survey-list').innerHTML = visible.length ? visible.map(r => `<button class="card test-record" type="button" data-open="${esc(r.data.client_uuid)}"><h3>${esc(r.data.transformer_code || 'New transformer survey')}</h3><p>${esc(feederFor(r)?.feeder_name || 'Assigned feeder')} · ${esc(r.data.survey_date)}</p><p>${r.data.rows.length} S/E rows · <span class="test-state ${esc(r.state)}">${stateLabels[r.state]}</span></p>${r.error ? `<p>${esc(r.error)}</p>` : ''}</button>`).join('') : '<div class="card empty">No surveys in this view. Start a new survey using an assigned feeder.</div>';
    }
    function matches(r,key) { return key === 'all' || r.state === key || (key === 'attention' && ['error','conflict'].includes(r.state)); }
    function begin() {
        const teams = reference.teams.filter(t => reference.feeders.some(f => f.team_ids.includes(t.id)));
        $('start-team').innerHTML = teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        startFeeders(); $('start-dialog').showModal();
    }
    function startFeeders() {
        $('start-feeder').innerHTML = reference.feeders.filter(f => f.team_ids.includes(Number($('start-team').value))).map(f => `<option value="${f.id}">${esc(f.feeder_code)} · ${esc(f.feeder_name)}</option>`).join('');
    }
    async function create(event) {
        event.preventDefault();
        const feeder = reference.feeders.find(f => f.id === Number($('start-feeder').value));
        if (!feeder) return;
        const record = {state:'draft',error:'',outbound:null,attachments:[],data:{client_uuid:uuid(),base_revision:0,survey_team_id:Number($('start-team').value),feeder_id:feeder.id,transformer_id:null,transformer_code:'',survey_date:config.today,status:'draft',header:{substation:feeder.grid_station_name || '',division:feeder.division_name || '',sub_division:feeder.sub_division_name || '',sub_division_code:feeder.sub_division_code || '',transformer_make:'',inspectors:config.userName,location:'',capacity_kva:'',mounting:'',duty:''},rows:[],solar:[],remarks:''}};
        records.unshift(record); await persist(); $('start-dialog').close(); open(record);
    }
    function open(record) {
        current = record; section = 0;
        $('notebook').hidden = true; $('editor').hidden = false; $('new-survey').hidden = true;
        $('validation-errors').hidden = true; renderEditor();
    }
    function at(path) { return path.split('.').reduce((v,k) => v?.[k],current.data); }
    function set(path,value) {
        const keys = path.split('.'); const last = keys.pop();
        const parent = keys.reduce((v,k) => v[k],current.data); parent[last] = value;
    }
    function field(path,label,{type='text',max=200,list='',options=null,min=null,limit=null,readOnly=false} = {}) {
        const value = at(path) ?? '';
        const disabled = locked() ? 'disabled' : readOnly ? 'readonly aria-readonly="true"' : '';
        const id = 'f-' + path.replaceAll('.','-');
        const limits = type === 'number' ? `step="any" ${min !== null ? `min="${min}"` : ''} ${limit !== null ? `max="${limit}"` : ''}` : `maxlength="${max}"`;
        const control = options ? `<select id="${id}" data-path="${path}" ${disabled}>${options.map(o => {const [key,title] = Array.isArray(o) ? o : [o,o || 'Not recorded'];return `<option value="${esc(key)}" ${String(value) === String(key) ? 'selected' : ''}>${esc(title)}</option>`;}).join('')}</select>` : type === 'textarea' ? `<textarea id="${id}" data-path="${path}" maxlength="${max}" ${disabled}>${esc(value)}</textarea>` : `<input id="${id}" data-path="${path}" type="${type}" value="${esc(value)}" ${limits} ${type === 'date' ? `max="${config.today}"` : ''} ${list ? `list="${list}"` : ''} ${disabled}>`;
        return `<div class="field"><label for="${id}">${esc(label)}</label>${control}</div>`;
    }
    const card = (title,body) => `<div class="card"><h2>${title}</h2><div class="form-grid">${body}</div></div>`;
    function renderEditor() {
        for (const url of previewUrls) URL.revokeObjectURL(url); previewUrls.length = 0;
        const data = current.data;
        if (!locked()) for (const row of data.rows) row.phase = phaseFromConductors(row);
        $('editor-meta').textContent = `${feederFor(current)?.feeder_code || ''} · ${feederFor(current)?.feeder_name || 'Assigned feeder'} · ${stateLabels[current.state]} · revision ${data.base_revision}${current.error ? ' · ' + current.error : ''}`;
        $('save-status').textContent = current.state === 'synced' ? 'Confirmed by server · saved here' : 'Saved in this browser';
        $('editor-tabs').innerHTML = tabs.map((name,index) => `<button type="button" role="tab" aria-selected="${section === index}" data-tab="${index}">${name}</button>`).join('');
        $('save-draft').disabled = !!locked(); $('submit-survey').disabled = !!locked();
        $('sync-current').disabled = syncing || !current.outbound && !current.attachments.some(a => a.state !== 'synced');
        $('load-server').hidden = current.state !== 'conflict';
        let html = '';
        if (section === 0) {
            const transformers = reference?.transformers.filter(t => t.feeder_id === data.feeder_id) || [];
            html = card('Transformer details',field('transformer_id','Reference transformer (optional)',{options:[['','Enter transformer manually'],...transformers.map(t => [t.id,t.transformer_code])]}) + field('transformer_code','Transformer code',{max:100}) + field('survey_date','Survey date',{type:'date'}) + field('header.capacity_kva','T/F capacity (kVA)',{type:'number',min:0,limit:10000000}) + field('header.transformer_make','T/F make') + field('header.inspectors','Inspectors') + field('header.mounting','Mounting',{options:['','S.Pole','D.Pole','Pad']}) + field('header.duty','Duty',{options:['','General Duty','Dedicated']}) + field('header.location','Location',{type:'textarea',max:500})) + card('Feeder and survey details',field('header.substation','Substation') + field('header.division','Division') + field('header.sub_division','Sub-division') + field('header.sub_division_code','Sub-division code') + field('remarks','Overall remarks',{type:'textarea',max:2000}));
        } else if (section === 1) {
            html = '<p>Each printed S or E row is a separate observation. Preserve waypoint identifiers and leading zeros. Blank consumer counts mean not recorded.</p>' + (locked() ? '' : '<button type="button" class="btn btn-primary" data-action="add-row">Add S/E row</button>');
            html += '<datalist id="conductor-options">' + conductors.map(v => `<option value="${esc(v)}">`).join('') + '</datalist><datalist id="pole-options">' + ['S','PCO','PCS','RS','TS','WB'].map(v => `<option value="${v}">`).join('') + '</datalist>';
            html += data.rows.map((row,i) => {
                const p = `rows.${i}.`; const f = (key,label,options) => field(p+key,label,options);
                return `<div class="card"><div class="test-row-head"><h3>Row ${i+1} · ${esc(row.se || 'S/E')} · ${esc(row.gps_waypoint || 'Waypoint needed')}</h3>${locked() ? '' : `<button type="button" class="btn btn-light" data-action="remove-row" data-index="${i}">Remove row</button>`}</div><div class="form-grid">${f('se','S/E',{options:['','S','E']})}${f('group','Group',{max:20})}${f('date','Row date',{type:'date'})}${f('gps_waypoint','GPS waypoint',{max:50})}${f('phase','Phase (automatic; Neutral excluded)',{max:30,readOnly:true})}${f('equipment_type','Equipment type',{max:50})}${f('pole_class','Pole class',{max:50,list:'pole-options'})}${f('pole_height_ft','Pole height (ft)',{type:'number',min:0,limit:1000000})}${['r','y','b','neutral'].map(k => f('conductor_'+k,'Conductor '+(k === 'neutral' ? 'Neutral' : k.toUpperCase()),{max:100,list:'conductor-options'})).join('')}</div><p class="test-legend">A = Ant; W = Wasp; GN = Gnat. S = Steel Structure; PCO = PC Ordinary; PCS = PC Spun; RS = Rail Steel; TS = Tubular Steel; WB = Wall Bracket. Other values remain editable.</p><p><label><input type="checkbox" data-path="${p}intersection" ${isIntersection(row.intersection) ? 'checked' : ''} ${locked() ? 'disabled' : ''}> Intersection</label></p><h4>Consumer counts</h4><div class="test-consumers">${Object.entries(consumerLabels).map(([k,label]) => f('consumers.'+k,label,{type:'number',min:0,limit:1000000})).join('')}</div><div class="form-grid">${f('remarks','Row remarks',{type:'textarea',max:1000})}${f('latitude','Latitude',{type:'number',min:-90,limit:90})}${f('longitude','Longitude',{type:'number',min:-180,limit:180})}${f('gps_accuracy_m','GPS accuracy (m)',{type:'number',min:0,limit:100000000})}</div>${locked() ? '' : `<div class="test-row-actions"><button type="button" class="btn btn-light" data-action="gps" data-index="${i}">Capture GPS</button><button type="button" class="btn btn-light" data-action="clear-gps" data-index="${i}">Clear GPS</button></div>`}</div>`;
            }).join('');
            if (!data.rows.length) html += '<div class="card empty">No observations yet. Add a row for each paper-form observation.</div>';
        } else if (section === 2) {
            html = '<p>Optional. Record each consumer reference and installed PV capacity separately.</p>' + (locked() ? '' : '<button type="button" class="btn btn-primary" data-action="add-solar">Add solar installation</button>');
            html += data.solar.map((item,i) => card(`Installation ${i+1}`,field(`solar.${i}.consumer_reference`,'Consumer reference number',{max:100}) + field(`solar.${i}.installed_pv_kw`,'Installed PV capacity (kW)',{type:'number',min:0,limit:10000000}) + field(`solar.${i}.remarks`,'Remarks',{type:'textarea',max:1000}) + (locked() ? '' : `<button type="button" class="btn btn-light" data-action="remove-solar" data-index="${i}">Remove installation</button>`))).join('');
            if (!data.solar.length) html += '<div class="card empty">No solar installations recorded.</div>';
        } else {
            html = '<p>Photos and sketches are optional. Files are retained in browser storage and uploaded after the test survey syncs. JPEG, PNG or PDF; maximum 10 MB per file.</p>';
            if (!locked()) html += '<div class="test-upload"><label class="btn btn-light">Choose image / PDF<input type="file" id="photo-upload" accept="image/jpeg,image/png,application/pdf" hidden></label><label class="btn btn-light">Take photo<input type="file" id="camera-upload" accept="image/jpeg,image/png" capture="environment" hidden></label><label class="btn btn-light">Attach sketch<input type="file" id="sketch-upload" accept="image/jpeg,image/png,application/pdf" hidden></label></div>';
            html += current.attachments.map((file,i) => {
                let url = file.url;
                if (file.blob) { url = URL.createObjectURL(file.blob); previewUrls.push(url); }
                return `<div class="card test-attachment">${url ? (file.blob?.type === 'application/pdf' || !file.blob && url ? `<a href="${esc(url)}" target="_blank" rel="noopener">Open ${esc(file.kind)}</a>` : `<a href="${esc(url)}" target="_blank" rel="noopener"><img src="${esc(url)}" alt="Attached ${esc(file.kind)}"></a>`) : ''}<p>${esc(file.name || file.kind)} · ${file.state === 'synced' ? 'Uploaded' : 'Waiting to upload'}</p>${file.error ? `<p>${esc(file.error)}</p>` : ''}${!locked() && file.state !== 'synced' ? `<button type="button" class="btn btn-light" data-action="remove-file" data-index="${i}">Remove file</button>` : ''}</div>`;
            }).join('');
            if (!current.attachments.length) html += '<div class="card empty">No images attached.</div>';
        }
        $('survey-form').innerHTML = html;
    }
    function touch() { current.state = 'draft'; current.data.status = 'draft'; current.error = ''; persist(); }
    function payload(record,status) {
        const data = clone(record.data); data.status = status;
        const number = value => value === '' || value == null ? null : Number.isFinite(Number(value)) ? Number(value) : value;
        data.transformer_id = number(data.transformer_id);
        data.header.capacity_kva = number(data.header.capacity_kva);
        for (const row of data.rows) {
            row.phase = phaseFromConductors(row);
            row.intersection = isIntersection(row.intersection);
            for (const key of ['latitude','longitude','gps_accuracy_m','pole_height_ft']) row[key] = number(row[key]);
            for (const key of Object.keys(row.consumers)) {
                if (row.consumers[key] === '' || row.consumers[key] == null) delete row.consumers[key];
                else row.consumers[key] = number(row.consumers[key]);
            }
        }
        for (const item of data.solar) item.installed_pv_kw = number(item.installed_pv_kw);
        return data;
    }
    function validate(data) {
        const errors = {};
        const fail = (path,message) => { errors[path] = [message]; };
        const required = (path,value,label) => { if (value == null || String(value).trim() === '') fail(path,`${label} is required.`); };
        const date = (path,value,label) => { if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '') || value > config.today || Number.isNaN(Date.parse(value))) fail(path,`${label} must be a valid date on or before today.`); };
        const numeric = (path,value,min,max,label,integer=false) => { if (value !== null && value !== undefined && (typeof value !== 'number' || !Number.isFinite(value) || value < min || value > max || integer && !Number.isInteger(value))) fail(path,`${label} must be ${integer ? 'a whole number' : 'a number'} between ${min} and ${max}.`); };
        required('transformer_code',data.transformer_code,'Transformer code');
        required('header.inspectors',data.header.inspectors,'Inspectors');
        required('header.capacity_kva',data.header.capacity_kva,'T/F capacity');
        numeric('header.capacity_kva',data.header.capacity_kva,Number.MIN_VALUE,10000000,'T/F capacity');
        date('survey_date',data.survey_date,'Survey date');
        if (!data.rows.length) fail('rows','Add at least one S/E observation.');
        data.rows.forEach((row,i) => {
            const p = `rows.${i}.`;
            if (!['S','E'].includes(row.se)) fail(p+'se',`Row ${i+1}: choose S or E.`);
            required(p+'gps_waypoint',row.gps_waypoint,`Row ${i+1} waypoint`); date(p+'date',row.date,`Row ${i+1} date`);
            for (const [key,min,max] of [['latitude',-90,90],['longitude',-180,180],['gps_accuracy_m',0,100000000],['pole_height_ft',0,1000000]]) numeric(p+key,row[key],min,max,`Row ${i+1} ${key}`);
            for (const [key,value] of Object.entries(row.consumers)) numeric(p+'consumers.'+key,value,0,1000000,`Row ${i+1} ${key.toUpperCase()} count`,true);
        });
        data.solar.forEach((item,i) => numeric(`solar.${i}.installed_pv_kw`,item.installed_pv_kw,0,10000000,`Solar installation ${i+1} capacity`));
        return errors;
    }
    function showErrors(errors) {
        const paths = Object.keys(errors || {});
        if (!paths.length) { $('validation-errors').hidden = true; return; }
        if (paths.some(p => p.startsWith('rows'))) section = 1;
        else if (paths.some(p => p.startsWith('solar'))) section = 2;
        else section = 0;
        renderEditor();
        $('validation-errors').innerHTML = '<strong>Review these fields before submitting</strong><ul>' + paths.map(p => `<li>${esc(p)}: ${esc(errors[p].join(' '))}</li>`).join('') + '</ul>';
        $('validation-errors').hidden = false;
        for (const input of $('survey-form').querySelectorAll('[data-path]')) if (errors[input.dataset.path]) input.classList.add('test-invalid');
        $('validation-errors').scrollIntoView({block:'nearest'});
    }
    async function submit() {
        if (locked()) return;
        const data = payload(current,'submitted'); const errors = validate(data);
        if (Object.keys(errors).length) { showErrors(errors); return; }
        if (!confirm(`Submit ${data.rows.length} S/E rows to the test workspace? The saved form will remain here until the server confirms it.`)) return;
        current.outbound = data; current.state = 'queued'; current.error = ''; current.data.status = 'submitted';
        await persist(); renderEditor();
        if (offline()) notice('Test survey queued. Turn off simulated offline or reconnect, then click Sync now.');
        else await sync(current);
    }
    async function sync(one = null) {
        if (syncing) return;
        if (offline()) { notice('Offline. Test surveys remain queued in this browser.'); return; }
        syncing = true; if (current) renderEditor();
        let confirmed = 0, failed = 0;
        try {
            await saveChain;
            for (const record of one ? [one] : records) {
                if (record.state === 'conflict') continue;
                try {
                    if (record.outbound) {
                        const result = await api('/sync',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(record.outbound)});
                        record.data.base_revision = result.revision; record.outbound = null; record.state = 'synced'; record.error = '';
                        await persist(); confirmed++;
                    }
                    if (!record.outbound && record.data.base_revision > 0) {
                        for (const file of record.attachments.filter(a => a.state !== 'synced')) {
                            if (!file.blob) throw new Error('Attachment file is missing from browser storage.');
                            const form = new FormData(); form.append('client_uuid',file.uuid); form.append('kind',file.kind); form.append('file',file.blob,file.name || 'attachment');
                            const result = await api(`/${record.data.client_uuid}/attachments`,{method:'POST',body:form});
                            file.state = 'synced'; file.url = result.url; file.error = ''; await persist();
                        }
                    }
                } catch (error) {
                    failed++; record.error = error.message;
                    if (record.outbound) {
                        record.state = error.status === 409 ? 'conflict' : 'error';
                        if (error.status === 422) { record.outbound = null; record.data.status = 'draft'; }
                    }
                    for (const file of record.attachments.filter(a => a.state !== 'synced')) file.error = error.message;
                    await persist();
                    if (current === record && error.errors) showErrors(error.errors);
                    if ([401,403,419].includes(error.status)) break;
                }
            }
        } catch (error) { notice(error.message); }
        finally {
            syncing = false; renderList(); if (current) renderEditor();
            $('sync-status').textContent = `${confirmed} test survey(s) confirmed${failed ? `; ${failed} need attention. Open the survey for details.` : '. Pending attachments uploaded where possible.'}`;
            if (!failed) $('test-notice').hidden = true;
        }
    }
    async function attach(file,kind) {
        if (!file || locked()) return;
        if (!['image/jpeg','image/png','application/pdf'].includes(file.type) || file.size > 10*1024*1024) { notice('Choose a JPEG, PNG or PDF file up to 10 MB.'); return; }
        current.attachments.push({uuid:uuid(),kind,name:file.name,blob:file,state:'pending',error:''});
        await persist(); renderEditor();
    }
    function exportJson() {
        const blob = new Blob([JSON.stringify(current.outbound || payload(current,current.data.status),null,2)],{type:'application/json'});
        const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `field-survey-test-${current.data.client_uuid}.json`; link.click(); setTimeout(() => URL.revokeObjectURL(url),1000);
    }
    async function action(button) {
        if (locked()) return;
        const index = Number(button.dataset.index), name = button.dataset.action;
        if (name === 'add-row') {
            if (current.data.rows.length >= 500) { notice('Maximum 500 observations per survey.'); return; }
            const row = {se:'S',group:'',date:current.data.survey_date,gps_waypoint:'',latitude:null,longitude:null,gps_accuracy_m:null,phase:'',conductor_r:'',conductor_y:'',conductor_b:'',conductor_neutral:'',equipment_type:'',pole_class:'',pole_height_ft:'',consumers:{},intersection:false,remarks:''};
            current.data.rows.push(row);
        } else if (name === 'add-solar') {
            if (current.data.solar.length >= 100) { notice('Maximum 100 solar installations per survey.'); return; }
            current.data.solar.push({consumer_reference:'',installed_pv_kw:'',remarks:''});
        } else if (name.startsWith('remove-')) {
            if (!confirm('Remove this item from the browser draft?')) return;
            if (name === 'remove-row') current.data.rows.splice(index,1);
            if (name === 'remove-solar') current.data.solar.splice(index,1);
            if (name === 'remove-file') { current.attachments.splice(index,1); await persist(); renderEditor(); return; }
        } else if (name === 'clear-gps') {
            for (const key of ['latitude','longitude','gps_accuracy_m']) current.data.rows[index][key] = null;
        } else if (name === 'gps') {
            if (!navigator.geolocation) { notice('This browser does not support location. Enter coordinates manually.'); return; }
            const record = current, row = current.data.rows[index]; button.disabled = true; button.textContent = 'Getting location…';
            navigator.geolocation.getCurrentPosition(position => {
                if (current !== record || locked() || !record.data.rows.includes(row)) return;
                row.latitude = position.coords.latitude; row.longitude = position.coords.longitude; row.gps_accuracy_m = position.coords.accuracy;
                touch(); renderEditor(); notice(`GPS captured · accuracy ${Math.round(position.coords.accuracy)} m.`);
            }, error => { notice(`${error.message} Allow location access on HTTPS or localhost, or enter coordinates manually.`); if (current === record) renderEditor(); },{enableHighAccuracy:true,timeout:20000,maximumAge:0});
            return;
        }
        touch(); renderEditor();
    }
    function events() {
        $('new-survey').onclick = begin; $('start-team').onchange = startFeeders; $('start-form').onsubmit = event => create(event).catch(e => notice(e.message));
        $('cancel-start').onclick = () => $('start-dialog').close();
        $('survey-search').oninput = renderList;
        $('survey-filters').onclick = event => { const button = event.target.closest('[data-filter]'); if (button) { filter = button.dataset.filter; renderList(); } };
        $('survey-list').onclick = event => { const button = event.target.closest('[data-open]'); if (button) open(records.find(r => r.data.client_uuid === button.dataset.open)); };
        $('editor-tabs').onclick = event => { const button = event.target.closest('[data-tab]'); if (button) { section = Number(button.dataset.tab); renderEditor(); } };
        $('survey-form').onsubmit = event => event.preventDefault();
        $('survey-form').oninput = event => {
            const input = event.target; if (!input.dataset.path || locked()) return;
            set(input.dataset.path,input.type === 'checkbox' ? input.checked : input.value); input.classList.remove('test-invalid');
            const match = input.dataset.path.match(/^rows\.(\d+)\.conductor_[ryb]$/);
            if (match) {
                const row = current.data.rows[Number(match[1])];
                row.phase = phaseFromConductors(row);
                const phaseInput = $('survey-form').querySelector(`[data-path="rows.${match[1]}.phase"]`);
                if (phaseInput) phaseInput.value = row.phase;
            }
            touch();
        };
        $('survey-form').onchange = event => {
            const input = event.target;
            if (input.dataset.path === 'transformer_id' && !locked()) {
                const selected = reference.transformers.find(t => t.id === Number(input.value));
                if (selected && confirm('Fill transformer code, capacity, make and location from the selected reference?')) {
                    current.data.transformer_code = selected.transformer_code;
                    current.data.header.capacity_kva = selected.capacity_kva ?? '';
                    current.data.header.transformer_make = selected.equipment_make || '';
                    current.data.header.location = selected.equipment_location || ''; touch(); renderEditor();
                }
            }
            if (['photo-upload','camera-upload','sketch-upload'].includes(input.id)) attach(input.files[0],input.id === 'sketch-upload' ? 'sketch' : 'photo').catch(e => notice(e.message));
        };
        $('survey-form').onclick = event => { const button = event.target.closest('[data-action]'); if (button) action(button).catch(e => notice(e.message)); };
        $('save-draft').onclick = async () => { try { await persist(); notice('Draft saved in this browser. Submit when complete.'); } catch { notice('Saving failed. Export your JSON before leaving.'); } };
        $('submit-survey').onclick = () => submit().catch(e => notice(e.message));
        $('sync-all').onclick = () => sync(); $('sync-current').onclick = () => sync(current);
        $('refresh-reference').onclick = () => refresh().catch(e => notice(e.message));
        $('export-payload').onclick = exportJson;
        $('back-notebook').onclick = async () => { try { await persist(); current = null; $('editor').hidden = true; $('notebook').hidden = false; $('new-survey').hidden = false; renderList(); } catch { notice('Save failed. Export your JSON before leaving.'); } };
        $('load-server').onclick = async () => {
            try {
                const remote = (await api('/records')).find(r => r.data.client_uuid === current.data.client_uuid);
                if (!remote) { notice('No server copy was found for this account. Export this draft for review.'); return; }
                if (!confirm('Your browser draft will first be downloaded as JSON. Then replace it with the server revision for review? Pending attachments will be kept.')) return;
                exportJson();
                const pending = current.attachments.filter(a => a.state !== 'synced' && !remote.attachments.some(b => b.uuid === a.uuid));
                Object.assign(current,{...remote,state:'synced',outbound:null,error:'',attachments:[...remote.attachments,...pending]});
                await persist(); $('validation-errors').hidden = true; renderEditor();
            } catch (error) { notice(error.message); }
        };
        $('simulate-offline').onchange = () => { sessionStorage.setItem(`field-test-offline:${config.userId}`,String($('simulate-offline').checked)); connected(); if (!offline()) sync(); };
        window.addEventListener('online', () => { connected(); sync(); }); window.addEventListener('offline',connected);
        window.addEventListener('beforeunload', event => { if (!saved) { event.preventDefault(); event.returnValue = ''; } });
    }
    async function initialize() {
        db = await new Promise((resolve,reject) => {
            const request = indexedDB.open(`field-survey-test:${location.pathname}:${config.userId}`,1);
            request.onupgradeneeded = () => request.result.createObjectStore('notebook');
            request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error);
        });
        records = await storage('readonly','records') || []; reference = await storage('readonly','reference');
        $('simulate-offline').checked = sessionStorage.getItem(`field-test-offline:${config.userId}`) === 'true';
        events(); connected(); renderList();
        if (reference) $('new-survey').disabled = !reference.teams.some(t => reference.feeders.some(f => f.team_ids.includes(t.id)));
        try { await refresh(); await sync(); } catch (error) { notice(error.message + ' Cached assignments and saved drafts remain available.'); }
    }
    initialize().catch(error => { notice('Unable to open browser storage: ' + error.message); $('connection-status').textContent = 'Browser storage unavailable'; });
})();
