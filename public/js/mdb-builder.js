(() => {
    'use strict';
    const {initial, references, waypoints} = window.mdbEditorData;
    const form = document.getElementById('mdb-form');
    const rows = document.getElementById('mdb-rows');
    const solar = document.getElementById('mdb-solar');
    const feeder = document.getElementById('mdb-feeder');
    const transformer = document.getElementById('mdb-transformer');
    const requiredEngineering = [...form.querySelectorAll('[data-setting][required]')];
    let dirty = false;
    let submitting = false;
    let pdfUrl = null;
    const capacityChoice = document.getElementById('mdb-capacity');
    const capacityCustom = document.getElementById('mdb-capacity-custom');
    const standardCapacities = new Set([...capacityChoice.options].map(option => option.value).filter(value => value && value !== '__custom__'));
    document.addEventListener('input', event => {
        const search=event.target.closest('[data-measurement-search]');if(!search)return;
        const select=search.parentElement.querySelector('select');if(!select)return;
        for(const option of select.options)option.hidden=!!search.value && !option.selected && !option.text.toLowerCase().includes(search.value.toLowerCase());
    });
    function setCapacity(value) {
        const text = value === null || value === undefined ? '' : String(value);
        const standard=[...standardCapacities].find(option=>Number(option)===Number(text));
        capacityChoice.value = standard || (text ? '__custom__' : '');
        capacityCustom.value = capacityChoice.value === '__custom__' ? text : '';
        capacityCustom.hidden = capacityChoice.value !== '__custom__';
        capacityCustom.required = capacityChoice.value === '__custom__';
    }
    function getCapacity() { return capacityChoice.value === '__custom__' ? read(capacityCustom) : read(capacityChoice); }
    capacityChoice.addEventListener('change', () => { capacityCustom.hidden = capacityChoice.value !== '__custom__'; capacityCustom.required = capacityChoice.value === '__custom__'; if (capacityChoice.value !== '__custom__') capacityCustom.value = ''; changed(); });
    const read = element => element.type === 'checkbox' ? element.checked : element.value.trim() === '' ? null : element.value.trim();
    const set = (element, value) => { if (element.type === 'checkbox') element.checked = value === true || ['1','true','yes','on','int','intersection','?','?','x','?','+'].includes(String(value ?? '').trim().toLowerCase()); else element.value = value ?? ''; };
    function changed() { dirty = true; document.getElementById('mdb-save-state').textContent = 'Unsaved changes. Save before reviewing the network.'; }
    function matches(row) {
        const name = row.querySelector('[data-row="gps_waypoint"]').value.trim();
        const date = row.querySelector('[data-row="date"]').value;
        const cell = row.querySelector('[data-gpx-link]');
        const aliases = form.querySelector('[data-setting="transformer_waypoints"]').value.split(',').map(value => value.trim()).filter(Boolean);
        if (aliases.includes(name)) { cell.textContent = 'Transformer connection'; return; }
        const lat = row.querySelector('[data-row="latitude"]').value;
        const lon = row.querySelector('[data-row="longitude"]').value;
        if (lat !== '' && lon !== '') { cell.textContent = 'Entered coordinates'; return; }
        let points = waypoints.filter(point => point.name === name);
        if (points.length > 1 && date) points = points.filter(point => point.date === date);
        cell.textContent = !name ? 'Enter waypoint' : points.length === 1 ? `${points[0].latitude.toFixed(6)}, ${points[0].longitude.toFixed(6)}` : points.length > 1 ? 'Ambiguous GPX waypoint' : 'No GPX match';
    }
    function renumber() { [...rows.children].forEach((row, index) => { row.querySelector('[data-number]').textContent = index + 1; row.querySelector('[data-delete-pair]').hidden = index % 2 === 1; matches(row); }); }
    function addRow(data) {
        const row = document.getElementById('mdb-row-template').content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-row]').forEach(input => {
            if (input.dataset.row === 'pole_height_choice' || input.dataset.row === 'pole_height_custom') return;
            set(input, data[input.dataset.row]);
        });
        const height = data.pole_height_ft;
        const heightChoice = row.querySelector('[data-row="pole_height_choice"]');
        const heightCustom = row.querySelector('[data-row="pole_height_custom"]');
        heightChoice.value = height === null || height === undefined || height === '' ? '' : ['31','36'].includes(String(height)) ? String(height) : '__custom__';
        heightCustom.value = heightChoice.value === '__custom__' ? height : '';
        heightCustom.hidden = heightChoice.value !== '__custom__';
        heightCustom.required = heightChoice.value === '__custom__';
        heightChoice.addEventListener('change', () => { heightCustom.hidden = heightChoice.value !== '__custom__'; heightCustom.required = heightChoice.value === '__custom__'; if (heightChoice.value !== '__custom__') heightCustom.value = ''; changed(); });
        row.querySelectorAll('[data-consumer]').forEach(input => set(input, data.consumers?.[input.dataset.consumer]));
        row.dataset.gpsAccuracy = data.gps_accuracy_m ?? '';
        row.addEventListener('input', event => { if (['latitude','longitude'].includes(event.target.dataset.row)) row.dataset.gpsAccuracy = ''; matches(row); });
        row.querySelector('[data-delete-pair]').addEventListener('click', () => { const partner = row.nextElementSibling; row.remove(); partner?.remove(); renumber(); changed(); });
        rows.appendChild(row);
    }
    function addPair() { addRow({se:'S',date:document.getElementById('mdb-date').value,consumers:{}}); addRow({se:'E',date:document.getElementById('mdb-date').value,consumers:{}}); renumber(); changed(); }
    function addSolar(data = {}) {
        const entry = document.getElementById('mdb-solar-template').content.firstElementChild.cloneNode(true);
        entry.querySelectorAll('[data-solar]').forEach(input => set(input, data[input.dataset.solar]));
        entry.querySelector('[data-delete-solar]').addEventListener('click', () => { entry.remove(); changed(); });
        solar.appendChild(entry);
    }
    function updateTransformers() {
        transformer.replaceChildren(new Option('Enter manually', ''));
        (references[feeder.value]?.transformers ?? []).forEach((item, index) => transformer.add(new Option(`${item.transformer_code} | ${item.capacity_kva} kVA`, index)));
        const simple = references[feeder.value]?.simple_mdb_generation === true;
        requiredEngineering.forEach(input => { input.required = !simple; });
        document.getElementById('simple-mdb-status').textContent = simple
            ? 'Simple MDB Generation is ON for this project. Missing engineering settings may remain blank; identifiers and connectivity are still required.'
            : 'Simple MDB Generation is OFF for this project. Full engineering configuration validation applies.';
    }
    set(feeder, initial.feeder_id); updateTransformers();
    set(document.getElementById('mdb-code'), initial.transformer_code);
    set(document.getElementById('mdb-date'), initial.survey_date);
    set(document.getElementById('mdb-remarks'), initial.remarks);
    form.querySelectorAll('[data-header]').forEach(input => set(input, initial.header?.[input.dataset.header]));
    setCapacity(initial.header?.capacity_kva);
    form.querySelectorAll('[data-setting]').forEach(input => set(input, initial.export_settings?.[input.dataset.setting]));
    form.querySelectorAll('[data-rate]').forEach(input => set(input, initial.export_settings?.consumer_kva?.[input.dataset.rate]));
    (initial.rows ?? []).forEach(addRow); if (!rows.children.length) { addPair(); dirty = false; document.getElementById('mdb-save-state').textContent = 'Changes are saved when you choose Save.'; } renumber();
    (initial.solar ?? []).forEach(addSolar);
    const datalist = document.getElementById('gpx-names');
    [...new Set(waypoints.map(point => point.name))].forEach(name => datalist.appendChild(new Option(name, name)));
    const rootGpx = document.getElementById('root-gpx');
    function zoneHint() {
        const value = form.querySelector('[data-setting="transformer_longitude"]').value;
        if (value === '' || !Number.isFinite(Number(value))) return;
        const zone = Math.min(60, Math.max(1, Math.floor((Number(value) + 180) / 6) + 1));
        document.getElementById('utm-hint').textContent = `The standard zone at this longitude is ${zone}N. Confirm the project coordinate system before export.`;
    }
    function suggestZone(longitude) {
        set(form.querySelector('[data-setting="utm_zone"]'), Math.min(60, Math.max(1, Math.floor((Number(longitude) + 180) / 6) + 1)));
        zoneHint();
    }
    form.querySelector('[data-setting="transformer_longitude"]').addEventListener('input',zoneHint);
    zoneHint();
    waypoints.forEach((point,index) => rootGpx.add(new Option(`${point.name} | ${point.date ?? 'No date'} | ${point.latitude.toFixed(6)}, ${point.longitude.toFixed(6)}`,index)));
    rootGpx.addEventListener('change', () => {
        if (rootGpx.value === '') return;
        const point = waypoints[Number(rootGpx.value)];
        set(form.querySelector('[data-setting="transformer_latitude"]'),point.latitude); set(form.querySelector('[data-setting="transformer_longitude"]'),point.longitude);
        suggestZone(point.longitude);
        const aliases = form.querySelector('[data-setting="transformer_waypoints"]'); if (!aliases.value.trim()) set(aliases,point.name); renumber(); changed();
    });
    feeder.addEventListener('change', () => { updateTransformers(); ['substation','division','sub_division','sub_division_code'].forEach(key => set(form.querySelector(`[data-header="${key}"]`), references[feeder.value]?.[key])); changed(); });
    transformer.addEventListener('change', () => {
        if (transformer.value === '') return;
        const item = references[feeder.value].transformers[Number(transformer.value)];
        set(document.getElementById('mdb-code'),item.transformer_code);
        setCapacity(item.capacity_kva);
        ['transformer_make','location'].forEach(key => set(form.querySelector(`[data-header="${key}"]`), item[{transformer_make:'equipment_make',location:'equipment_location'}[key]]));
        set(form.querySelector('[data-setting="transformer_latitude"]'),item.latitude); set(form.querySelector('[data-setting="transformer_longitude"]'),item.longitude);
        if (item.longitude !== null) suggestZone(item.longitude);
        if (item.gps_waypoint_number) set(form.querySelector('[data-setting="transformer_waypoints"]'),item.gps_waypoint_number);
        renumber(); changed();
    });
    document.getElementById('add-pair').addEventListener('click',addPair);
    document.getElementById('add-solar').addEventListener('click',() => { addSolar(); changed(); });
    form.addEventListener('input',changed); form.addEventListener('change',changed);
    form.querySelector('[data-setting="transformer_waypoints"]').addEventListener('input',renumber);
    document.getElementById('mdb-pdf').addEventListener('change', event => {
        if (pdfUrl) URL.revokeObjectURL(pdfUrl);
        const file = event.target.files[0]; if (!file) return;
        pdfUrl = URL.createObjectURL(file); document.getElementById('pdf-viewer').src = pdfUrl; document.getElementById('pdf-reference').open = true;
    });
    document.getElementById('preview-mdb')?.addEventListener('click',event => { if (dirty) { event.preventDefault(); document.getElementById('mdb-save-state').textContent = 'Save changes first, then review the updated network.'; document.getElementById('save-mdb').focus(); } });
    form.addEventListener('submit', () => {
        const payload = {...initial, feeder_id:read(feeder),transformer_code:read(document.getElementById('mdb-code')),survey_date:read(document.getElementById('mdb-date')),remarks:read(document.getElementById('mdb-remarks')),header:{},export_settings:{consumer_kva:{}},rows:[],solar:[]};
        form.querySelectorAll('[data-header]').forEach(input => payload.header[input.dataset.header] = read(input));
        payload.header.capacity_kva = getCapacity();
        form.querySelectorAll('[data-setting]').forEach(input => payload.export_settings[input.dataset.setting] = read(input));
        form.querySelectorAll('[data-rate]').forEach(input => payload.export_settings.consumer_kva[input.dataset.rate] = read(input));
        [...rows.children].forEach(row => { const data = {consumers:{},gps_accuracy_m:row.dataset.gpsAccuracy || null}; row.querySelectorAll('[data-row]').forEach(input => {if(!['pole_height_choice','pole_height_custom'].includes(input.dataset.row))data[input.dataset.row] = read(input);}); const choice=row.querySelector('[data-row="pole_height_choice"]').value;data.pole_height_ft=choice==='__custom__' ? read(row.querySelector('[data-row="pole_height_custom"]')) : choice==='' ? null : Number(choice); row.querySelectorAll('[data-consumer]').forEach(input => data.consumers[input.dataset.consumer] = read(input)); payload.rows.push(data); });
        [...solar.children].forEach(entry => { const data = {}; entry.querySelectorAll('[data-solar]').forEach(input => data[input.dataset.solar] = read(input)); payload.solar.push(data); });
        document.getElementById('mdb-payload').value = JSON.stringify(payload); submitting = true;
    });
    window.addEventListener('beforeunload',event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
})();
