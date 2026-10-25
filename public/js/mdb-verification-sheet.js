const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

export const sheetColumns = [
    ['group_number','Grp'], ['row_date','Date'], ['waypoint_reference','GPS WP'],
    ['phase','Phase'], ...['R','Y','B','N'].map(p=>['conductors.'+p,p==='N' ? 'Neutral' : p]),
    ['equipment_type','Type'], ['pole_class','Pole class'], ['pole_height','Pole height'],
    ...['rs','rl','sc','lc','si','li','pb','ag','st','pv'].map(k=>['consumers.'+k,k.toUpperCase()]),
    ['intersection','Int'],
];

export function paperHeader(fields, cell) {
    const byPath = new Map(fields.map(field=>[field.path,field]));
    const entry = (path,label,span) => `<div class="paper-header-field" style="grid-column:span ${span}"><span class="paper-label">${label}</span>${byPath.has(path) ? cell(byPath.get(path)) : '—'}</div>`;
    return `<div class="paper-form-title">Evaluation of Distribution Losses of HAZECO Network</div><div class="paper-form-subtitle">Data Input Form for LT Survey — entered data</div>
        <div class="paper-header-body"><div class="paper-header-grid">
            ${entry('header.substation_name','Substation',5)}${entry('capacity_kva','T/F Cap (kVA)',2)}${entry('header.mounting','Mounting',2)}${entry('header.survey_date','Survey date',3)}
            ${entry('header.feeder_name','Feeder',5)}${entry('header.make','T/F Make',3)}${entry('header.service_category','Service category',4)}
            ${entry('code','Transformer code',5)}${entry('header.inspector','Inspectors',3)}${entry('header.location','Location',4)}
            ${entry('header.feeder_identifier','Feeder code',2)}${entry('header.division','Division',4)}${entry('header.subdivision','Sub-Division',4)}${entry('header.subdivision_code','Sub Division Code',2)}
        </div><div class="paper-header-pv-label">PV Solar / Net-Metering Data</div></div>
        <div class="paper-reference-fields">${['header.team_group','header.client_transformer_information','source_pdf_id','source_page','transformer_gps','header.starting_pole_coordinates.latitude','header.starting_pole_coordinates.longitude'].map(path=>{
            const field=byPath.get(path);return field ? `<div><span class="paper-label">${escapeHtml(field.label)}</span>${cell(field)}</div>` : '';
        }).join('')}</div>`;
}

export function paperRows(pairs, sourceId, page, cell, selectedPair) {
    const rows = [];
    pairs.forEach((pair,index)=>{
        for (const designation of ['S','E']) {
            const row=pair.rows[designation];
            if(row && String(row.data.source_pdf_id)===String(sourceId) && Number(row.data.source_page)===Number(page)) rows.push({row,pair,index});
        }
    });
    return {
        rows,
        html: rows.map(({row,pair,index})=>{
            const byPath=new Map(row.fields.map(field=>[field.path,field]));
            return `<tr data-sheet-row="${row.id}" data-sheet-pair="${pair.number}" data-designation="${row.designation}" class="${row.designation==='S' ? 'paper-pair-start' : ''}" data-active="${index===selectedPair}">
                <th scope="row"><button type="button" data-select-pair="${index}" title="Select pair ${pair.number}">${row.designation}<small>${pair.number}</small></button></th>
                ${sheetColumns.map(([path])=>{
                    if(path==='phase') {
                        const phases=['R','Y','B'].filter(p=>{const value=byPath.get('conductors.'+p)?.value;return value && value!=='__none__';}).join('');
                        return `<td class="paper-phase" title="Phase derived from entered conductors">${phases || '—'}</td>`;
                    }
                    const field=byPath.get(path);
                    const height=path==='pole_height' ? byPath.get('pole_height_unit') : null;
                    return `<td data-paper-column="${path}">${field ? cell(field, ['group_number','row_date','waypoint_reference'].includes(path)) : '—'}${height ? `<div class="paper-unit">${cell(height)}</div>` : ''}</td>`;
                }).join('')}${['reference','service_load_kw','installed_capacity_kw','meter_number','remarks'].map(path=>{
                    const fields=row.fields.filter(field=>field.group==='PV' && field.path.endsWith('.'+path));
                    return `<td class="paper-pv-value">${fields.length ? fields.map(field=>cell(field)).join('') : '<span title="No PV detail entered">&mdash;</span>'}</td>`;
                }).join('')}</tr>`;
        }).join(''),
    };
}

