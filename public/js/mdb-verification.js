import {displayDate, isoDate} from './mdb-entry.js';
import {paperHeader, paperRows} from './mdb-verification-sheet.js?v=1';

export function initializeVerification(boot, root = document) {
    const $ = id => root.getElementById('verification-' + id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const comparison = root.querySelector('[data-review-layout]');
    if (comparison) comparison.dataset.reviewLayout = 'paper';
    let state = boot, pairIndex = 0, busy = false, editing = null;
    let checked = new Set(state.checked);
    let sourceId = state.header.fields.find(field=>field.path==='source_pdf_id')?.value;
    let sourcePage = state.header.fields.find(field=>field.path==='source_page')?.value || 1;
    const pair = () => state.pairs[pairIndex];
    const allFields = () => [...state.header.fields, ...state.pairs.flatMap(p => Object.values(p.rows).flatMap(row => row.fields))];
    const complete = fields => fields.length > 0 && fields.every(field => checked.has(field.key));
    const rowFields = p => Object.values(p?.rows || {}).flatMap(row => row.fields);
    const errorBox = (box, error) => {
        box.hidden = false;
        box.textContent = [error.message, ...Object.values(error.errors || {}).flat()].filter(Boolean).join(' ');
    };
    async function api(url, method = 'GET', body) {
        const response = await fetch(url, {method, credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json',
            'X-CSRF-TOKEN':root.querySelector('meta[name="csrf-token"]').content}, ...(body ? {body:JSON.stringify(body)} : {})});
        let result;
        try { result = await response.json(); } catch { throw new Error('Could not read the server response. Your correction is retained. Refresh saved values before retrying.'); }
        if (!response.ok) { const error = new Error(result.message || 'Saving failed.'); error.errors = result.errors; throw error; }
        return result;
    }
    function paperCell(field, digits = false) {
        const done=checked.has(field.key), empty=field.value===null || field.value===undefined || field.value==='';
        let text=empty ? (field.empty_label || '\u2014') : field.value;
        if (!empty && field.type==='survey-date') text=displayDate(field.value);
        if (!empty && field.type==='date' && /^\d{4}-\d{2}-\d{2}$/.test(field.value)) text=field.value.split('-').reverse().join('/');
        if (!empty && field.type==='boolean') text=field.value ? 'Yes' : 'No';
        if (text==='__none__') text='None';
        if (field.path==='source_pdf_id') text=field.options?.[field.value] ?? text;
        const title=field.label+': '+(empty ? (field.empty_label || 'Not entered') : (field.options?.[field.value] ?? text));
        const value=digits && !empty ? `<span class="paper-digits">${String(text).split('').map(char=>`<span>${esc(char)}</span>`).join('')}</span>` : esc(text);
        return `<div class="paper-cell" data-checked="${done}" data-missing="${empty && !field.empty_label}">
            ${boot.can_correct && field.editable ? `<button type="button" class="paper-value" data-correct-key="${esc(field.key)}" title="Edit ${esc(title)}" aria-label="Edit ${esc(field.label)}" ${busy ? 'disabled' : ''}>${value}</button>` : `<span class="paper-value" title="${esc(title)}">${value}</span>`}
            <input type="checkbox" data-review-key="${esc(field.key)}" aria-label="Checked ${esc(field.label)}" title="Check ${esc(field.label)}" ${done ? 'checked' : ''} ${!boot.can_correct || busy ? 'disabled' : ''}>
        </div>`;
    }
    function render() {
        checked = new Set(state.checked);
        if(pairIndex >= state.pairs.length) pairIndex = Math.max(0,state.pairs.length - 1);
        $('header-values').innerHTML = paperHeader(state.header.fields, paperCell);
        const headerCount = state.header.fields.filter(field=>checked.has(field.key)).length;
        $('header-progress').textContent = `${headerCount} / ${state.header.fields.length} checked`;
        $('progress').textContent = `${checked.size} / ${state.total} values checked`;
        $('check-header').disabled = busy || !boot.can_correct;
        $('edit-header').disabled = busy || !boot.can_correct;
        $('pair').innerHTML = state.pairs.map((p,index)=>`<option value="${index}" ${index===pairIndex ? 'selected' : ''}>Pair ${p.number}${complete(rowFields(p)) ? ' — checked' : ''}</option>`).join('');
        $('previous').disabled = busy || pairIndex <= 0;
        $('next').disabled = busy || pairIndex >= state.pairs.length - 1;
        $('next-unchecked').disabled = busy || state.pairs.every(p=>complete(rowFields(p)));
        $('empty').hidden = state.pairs.length > 0;
        $('row-tools').innerHTML = ['S','E'].map(designation => {
            const row = pair()?.rows[designation];
            if(!row)return `<div><strong>${designation}</strong><span>Row not entered</span></div>`;
            return `<div><strong>${designation} values</strong><button type="button" class="btn btn-sm btn-light" data-review-page="${designation}">Show PDF page ${esc(row.data.source_page || '')}</button><button type="button" class="btn btn-sm btn-light" data-check-row="${designation}" ${!boot.can_correct || busy ? 'disabled' : ''}>Check ${designation} values</button><button type="button" class="btn btn-sm btn-light" data-edit-row="${designation}" ${!boot.can_correct || busy ? 'disabled' : ''}>Correct ${designation}</button></div>`;
        }).join('');
        const sheet=paperRows(state.pairs, sourceId, sourcePage, paperCell, pairIndex);
        $('values').innerHTML=sheet.html;
        const sourceName=state.header.fields.find(field=>field.path==='source_pdf_id')?.options?.[sourceId] || 'Survey PDF';
        $('sheet-page').textContent=`${sourceName} | PDF page ${sourcePage} | ${sheet.rows.length} saved S/E rows. Rows follow the paper form order.`;
        $('empty').hidden=sheet.rows.length>0;
        $('empty').textContent='No S/E rows for this transformer have been entered on the displayed PDF page.';
        $('sheet-references').innerHTML=`<details><summary>Source references for the displayed rows</summary>${sheet.rows.map(({row,pair})=>`<div class="paper-source-reference"><strong>Pair ${pair.number} ${row.designation}</strong>${row.fields.filter(field=>['source_pdf_id','source_page','gpx_source_id'].includes(field.path)).map(field=>`<span><small>${esc(field.label)}</small>${paperCell(field)}</span>`).join('')}</div>`).join('')}</details>`;
        for(const designation of ['S','E']) {
            const row = pair()?.rows[designation];
            $(designation.toLowerCase()+'-page').textContent = row ? `PDF page ${row.data.source_page || 'not recorded'}` : 'Row not entered';
        }
        const current = rowFields(pair());
        $('pair-progress').textContent = current.length ? `${current.filter(field=>checked.has(field.key)).length} / ${current.length} checked` : '';
        root.querySelectorAll('input[name="revision"]').forEach(input=>input.value=state.revision);
        const issues = $('data-issues');
        if(issues){issues.hidden=!state.issues?.length;issues.innerHTML=(state.issues || []).length ? '<strong>Values needing correction</strong><ul>'+state.issues.map(issue=>`<li>${esc(issue.message)}</li>`).join('')+'</ul>' : '';}
    }
    async function mark(fields, value, hide = false) {
        if(busy || !boot.can_correct || !fields.length)return;
        busy = true; $('errors').hidden = true; render();
        try {
            state = {...state, ...await api(boot.check_url,'POST',{revision:state.revision,fields:fields.map(({key,hash})=>({key,hash})),checked:value})};
            $('message').textContent = 'Verification checks saved.';
            if(hide || (fields.some(field=>field.key.startsWith('header.')) && state.header.fields.every(field=>state.checked.includes(field.key)))) $('header').open = false;
        } catch(error) {errorBox($('errors'),error);} finally {busy=false;render();}
    }
    function showPage(row) {
        if(!row || !row.data.source_page)return;
        const url = boot.source_urls[row.data.source_pdf_id];
        if(!url)return;
        root.dispatchEvent(new window.CustomEvent('verification:pdf-page',{detail:{url,page:row.data.source_page}}));
    }
    function inputFor(field) {
        const value = field.type === 'survey-date' ? displayDate(field.value) : field.value ?? '';
        const attributes = `data-edit-path="${esc(field.path)}" aria-label="${esc(field.label)}"`;
        if(field.type==='select') {
            const options = {...field.options};
            if(value!=='' && !Object.hasOwn(options,value))options[value]=String(value);
            return `<select ${attributes}><option value="">Not entered</option>${Object.entries(options).map(([key,label])=>`<option value="${esc(key)}" ${String(key)===String(value) ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
        }
        if(field.type==='boolean')return `<input ${attributes} type="checkbox" ${value ? 'checked' : ''}>`;
        const limits = field.type==='number' ? `min="${field.path==='capacity_kva' ? '.001' : 0}" max="${field.path==='pole_height' ? 200 : 1000000}" step="${field.path.startsWith('consumers.') || field.path==='source_page' ? 1 : 'any'}"` : 'maxlength="500"';
        return `<input ${attributes} type="${field.type==='number' ? 'number' : field.type==='date' ? 'date' : 'text'}" value="${esc(value)}" ${limits} ${field.type==='survey-date' ? 'inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="131222"' : ''}>`;
    }
    function pvFields(records) {
        return records.map((pv,index)=>`<div class="verification-pv-editor"><h3>PV record ${index+1}</h3>${[['reference','Consumer reference'],['service_load_kw','Service load (kW)'],['installed_capacity_kw','Solar capacity (kW)'],['remarks','Remarks']].map(([key,label])=>`<label>${label}${inputFor({path:`pv_details.${index}.${key}`,label,value:pv[key],type:key.endsWith('_kw') ? 'number' : 'text'})}</label>`).join('')}<button type="button" class="btn btn-sm btn-light" data-remove-pv="${index}">Remove PV record</button></div>`).join('');
    }
    function edit(row = null, focusPath = null) {
        if(!boot.can_correct || busy)return;
        editing = row ? {row,fields:row.fields.filter(field=>field.group!=='PV'),pv:structuredClone(row.data.pv_details || [])} : {fields:state.header.fields};
        $('editor-title').textContent = row ? `Correct pair ${state.pairs.find(pair=>Object.values(pair.rows).some(saved=>saved.id===row.id))?.number} ${row.designation}` : 'Correct transformer header';
        $('correction-reason').value='';$('editor-errors').hidden=true;
        let group = '';
        $('editor-fields').innerHTML = editing.fields.filter(field=>field.editable).map(field=>{
            const heading = group!==field.group ? `<h3 class="verification-editor-group">${esc(field.group)}</h3>` : '';group=field.group;
            return heading+`<label>${esc(field.label)}${inputFor(field)}</label>`;
        }).join('')+(row ? `<div id="verification-pv-fields" style="display:contents">${pvFields(editing.pv)}</div><button type="button" class="btn btn-light" id="verification-add-pv">Add PV record</button>` : '');
        $('editor').showModal();
        const input = [...$('editor-fields').querySelectorAll('[data-edit-path]')].find(input => input.dataset.editPath === focusPath);
        if (input) {
            input.closest('label')?.classList.add('verification-edit-target');
            input.focus();
            input.scrollIntoView?.({block:'center'});
        }
    }
    function setPath(object,path,value) {
        const keys=path.split('.');let target=object;
        for(let i=0;i<keys.length-1;i++)target=target[keys[i]] ??= /^\d+$/.test(keys[i+1]) ? [] : {};
        target[keys.at(-1)]=value;
    }
    function readInputs(target, scope=$('editor-fields')) {
        scope.querySelectorAll('[data-edit-path]').forEach(input=>{
            let value=input.type==='checkbox' ? input.checked : input.type==='number' ? (input.value==='' ? null : Number(input.value)) : (input.value || null);
            if(input.dataset.editPath==='row_date')value=isoDate(input.value,state.entry_settings.two_digit_year_start) || null;
            setPath(target,input.dataset.editPath,value);
        });
        return target;
    }
    $('edit-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy || !editing)return;
        $('editor-errors').hidden=true;
        if(!$('edit-form').checkValidity()){$('edit-form').reportValidity();return;}
        const initial=editing.row ? structuredClone(editing.row.data) : {header:{}};
        if(editing.row)initial.pv_details=[];
        const body=readInputs(initial);
        body.revision=state.revision;body.correction_reason=$('correction-reason').value;
        if(editing.row){body.inheritance={};body.manually_verified=false;body.save_uuid=crypto.randomUUID();delete body.composite_identifier;}
        busy=true;$('save-correction').disabled=true;$('cancel-correction').disabled=true;
        try {
            await api(editing.row ? boot.row_url+editing.row.id : boot.header_url,'PUT',body);
            state=await api(boot.state_url);checked=new Set(state.checked);
            $('editor').close();editing=null;$('message').textContent='Correction saved. Check the updated values again.';
            if(!body.client_uuid)$('header').open=true;
            render();
        } catch(error) {errorBox($('editor-errors'),error);} finally {busy=false;$('save-correction').disabled=false;$('cancel-correction').disabled=false;render();}
    });
    $('workspace').addEventListener('change',event=>{
        const key=event.target.dataset.reviewKey;
        if(key){const field=allFields().find(field=>field.key===key);if(field)mark([field],event.target.checked);}
    });
    $('workspace').addEventListener('click',event=>{
        const selected=event.target.closest('[data-select-pair]');
        if(selected && !busy){pairIndex=Number(selected.dataset.selectPair);render();return;}
        const correction = event.target.closest('[data-correct-key]');
        if (correction) {
            const key = correction.dataset.correctKey;
            const field = allFields().find(field => field.key === key);
            if (field?.editable) {
                const row = state.pairs.flatMap(pair => Object.values(pair.rows)).find(row => row.fields.some(field => field.key === key));
                edit(row || null, field.path);
            }
            return;
        }
        const markButton=event.target.closest('[data-check-row]'), editButton=event.target.closest('[data-edit-row]'), pageButton=event.target.closest('[data-review-page]');
        if(markButton)mark(pair().rows[markButton.dataset.checkRow].fields,true);
        if(editButton)edit(pair().rows[editButton.dataset.editRow]);
        if(pageButton)showPage(pair().rows[pageButton.dataset.reviewPage]);
        if(event.target.closest('#verification-add-pv')){
            const current=readInputs({});editing.pv=current.pv_details || [];editing.pv.push({reference:'',service_load_kw:null,installed_capacity_kw:null,remarks:''});
            $('pv-fields').innerHTML=pvFields(editing.pv);
        }
        const remove=event.target.closest('[data-remove-pv]');
        if(remove){const current=readInputs({});editing.pv=current.pv_details || [];editing.pv.splice(Number(remove.dataset.removePv),1);$('pv-fields').innerHTML=pvFields(editing.pv);}
    });
    function changePair(index){
        if(busy)return;
        pairIndex=index;
        const row=pair()?.rows.S || pair()?.rows.E;
        if(row){sourceId=row.data.source_pdf_id;sourcePage=row.data.source_page;}
        render();showPage(row);
    }
    root.addEventListener('verification:pdf-current',event=>{
        const id=Object.keys(boot.source_urls).find(id=>boot.source_urls[id]===event.detail?.url);
        if(id && (String(sourceId)!==String(id) || Number(sourcePage)!==Number(event.detail.page))){sourceId=id;sourcePage=event.detail.page;render();}
    });
    $('previous').addEventListener('click',()=>changePair(Math.max(0,pairIndex-1)));
    $('next').addEventListener('click',()=>changePair(Math.min(state.pairs.length-1,pairIndex+1)));
    $('pair').addEventListener('change',event=>changePair(Number(event.target.value)));
    $('next-unchecked').addEventListener('click',()=>{
        for(let offset=1;offset<=state.pairs.length;offset++){const index=(pairIndex+offset)%state.pairs.length;if(!complete(rowFields(state.pairs[index]))){changePair(index);break;}}
    });
    $('check-header').addEventListener('click',()=>mark(state.header.fields,true,true));
    $('hide-header').addEventListener('click',()=>$('header').open=false);
    $('edit-header').addEventListener('click',()=>edit());
    $('cancel-correction').addEventListener('click',()=>$('editor').close());
    $('editor').addEventListener('cancel',event=>{if(busy)event.preventDefault();});
    $('table').addEventListener('click',()=>{const legacy=root.getElementById('verification-legacy-data');if(legacy){legacy.hidden=!legacy.hidden;$('table').textContent=legacy.hidden ? 'Show full saved table' : 'Hide full saved table';}});
    $('refresh').addEventListener('click',async()=>{
        if(busy)return;busy=true;
        try {state=await api(boot.state_url);$('errors').hidden=true;$('message').textContent='Saved values and checks refreshed.';} catch(error){errorBox($('errors'),error);} finally {busy=false;render();}
    });
    render();
    if(complete(state.header.fields))$('header').open=false;
    const legacy=root.getElementById('verification-legacy-data');if(legacy)legacy.hidden=true;
    root.querySelectorAll('.entry-review-issues').forEach(element=>element.hidden=true);
    $('workspace').hidden=false;
}

if(typeof window!=='undefined' && window.mdbVerificationBoot)initializeVerification(window.mdbVerificationBoot);
