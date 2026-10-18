(() => {
    'use strict';
    const project = document.querySelector('[data-mdb-project]');
    const updateReferences = () => {
        if (!project) return;
        document.querySelectorAll('[data-mdb-project-dependent]').forEach(select => {
            Array.from(select.options).forEach(option => {
                const allowed = !option.value || !project.value || option.dataset.projectId === project.value;
                option.hidden = !allowed;
                option.disabled = !allowed;
                if (!allowed && option.selected) select.value = '';
            });
        });
    };
    project?.addEventListener('change', updateReferences);
    updateReferences();
    document.querySelectorAll('form[data-mdb-confirm]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!window.confirm(form.dataset.mdbConfirm)) event.preventDefault();
        });
    });

    const capacities = new Set(['5','10','15','25','37.5','50','63','75','100','125','160','200','250','315','400','500','630','750','800','1000','1250','1500','1600','2000','2500','3150','5000','6300','10000','12500','16000','20000','25000']);
    document.addEventListener('input', event => {
        const search=event.target.closest('[data-measurement-search]');if(!search)return;
        const select=search.parentElement.querySelector('select');if(!select)return;
        for(const option of select.options)option.hidden=!!search.value && !option.selected && !option.text.toLowerCase().includes(search.value.toLowerCase());
    });
    document.querySelectorAll('[data-capacity-choice]').forEach(select => {
        const field = select.closest('label');
        const value = field.querySelector('[data-capacity-value]');
        const custom = field.querySelector('[data-capacity-custom]');
        const initial = String(select.dataset.savedCapacity ?? value.value ?? '');
        const standard=[...capacities].find(option=>Number(option)===Number(initial));
        select.value = standard || (initial ? '__custom__' : '');
        custom.hidden = select.value !== '__custom__';
        custom.required = select.value === '__custom__';
        custom.value = select.value === '__custom__' ? initial : '';
        value.value = select.value === '__custom__' ? custom.value : select.value;
        const sync = () => {
            custom.hidden = select.value !== '__custom__';
            custom.required = select.value === '__custom__';
            if (select.value !== '__custom__') custom.value = '';
            value.value = select.value === '__custom__' ? custom.value : select.value;
        };
        select.addEventListener('change', sync);
        custom.addEventListener('input', sync);
    });
    document.querySelectorAll('[data-height-choice]').forEach(select => {
        const field = select.closest('label');
        const value = field.querySelector('[data-height-value]');
        const custom = field.querySelector('[data-height-custom]');
        let initial = Number(select.dataset.savedHeight);
        if (select.dataset.savedUnit === 'm' && Number.isFinite(initial)) initial *= 3.280839895;
        const text = Number.isFinite(initial) && select.dataset.savedHeight !== '' ? String(Number(initial.toFixed(4))) : '';
        select.value = ['31','36'].includes(text) ? text : text ? '__custom__' : '';
        custom.hidden = select.value !== '__custom__';
        custom.required = select.value === '__custom__';
        custom.value = select.value === '__custom__' ? text : '';
        value.value = select.value === '__custom__' ? custom.value : select.value;
        const sync = () => {
            custom.hidden = select.value !== '__custom__';
            custom.required = select.value === '__custom__';
            if (select.value !== '__custom__') custom.value = '';
            value.value = select.value === '__custom__' ? custom.value : select.value;
        };
        select.addEventListener('change', sync);
        custom.addEventListener('input', sync);
    });

    const source = document.getElementById('mdb-pdf-source');
    const page = document.getElementById('mdb-pdf-page');
    const viewer = document.getElementById('mdb-pdf-viewer');
    const openPdf = document.getElementById('mdb-pdf-open');
    const showPage = () => {
        const url = source?.selectedOptions[0]?.dataset.url;
        if (!url || !viewer || !page) return;
        const number = Math.max(1, Math.min(10000, Number.parseInt(page.value, 10) || 1));
        page.value = String(number);
        viewer.src = `${url}#page=${number}`;
        if (openPdf) openPdf.href = `${url}#page=${number}`;
    };
    source?.addEventListener('change', showPage);
    document.getElementById('mdb-pdf-go')?.addEventListener('click', showPage);
    page?.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); showPage(); }
    });
    document.querySelectorAll('[data-mdb-pdf-page]').forEach(link => {
        link.addEventListener('click', event => {
            if (!source || !page || !viewer) return;
            const selected = Array.from(source.options).find(option => option.value === link.dataset.mdbPdfSource);
            if (selected) source.value = selected.value;
            page.value = link.dataset.mdbPdfPage || '1';
            showPage();
            event.preventDefault();
            viewer.scrollIntoView({behavior:'smooth', block:'center'});
        });
    });

    document.getElementById('mdb-copy-confirm')?.addEventListener('click', () => {
        const selector = document.getElementById('mdb-copy-section');
        const form = document.getElementById('mdb-new-section-form');
        const previous = window.mdbSectionCopyData?.[selector?.value];
        if (!form || !previous) {
            window.alert('Choose an existing section whose repeated values you checked against the new PDF row.');
            return;
        }
        const description = [
            `Phases: ${(previous.phases || []).join('/') || 'unrecorded'}`,
            ...['R','Y','B','N'].map(phase => `${phase} conductor: ${previous.conductors?.[phase] || 'unrecorded'}`),
            `Equipment: ${previous.equipment_type || 'unrecorded'} / ${previous.equipment_ref || 'unrecorded'}`,
            `Pole: ${previous.pole_class || 'unrecorded'}, ${previous.pole_height ?? 'unrecorded'} ${previous.pole_height_unit || ''}`,
        ].join('\n');
        if (!window.confirm(`Confirm the new source row explicitly repeats these verified values:\n\n${description}\n\nExisting phase, conductor and equipment values in the new form will be replaced. Endpoints, raw transcription and consumer counts still need their own entry and verification.`)) return;
        const values = {
            equipment_type: previous.equipment_type,
            equipment_ref: previous.equipment_ref,
            pole_class: previous.pole_class,
            pole_height: previous.pole_height,
            pole_height_unit: previous.pole_height_unit,
        };
        ['R','Y','B','N'].forEach(phase => {
            values[`conductor_${phase}`] = previous.conductors?.[phase];
            const field = form.querySelector(`[data-copy-key="phase_${phase}"]`);
            if (field) field.checked = (previous.phases || []).includes(phase);
        });
        Object.entries(values).forEach(([key, value]) => {
            const field = form.querySelector(`[data-copy-key="${key}"]`);
            if (field) field.value = value ?? '';
        });
        document.getElementById('mdb-ditto-source').value = selector.value;
        document.getElementById('mdb-ditto-confirmed').value = '1';
        const verified = form.querySelector('[name="original_entry[manually_verified]"]');
        if (verified) verified.checked = false;
        const firstInput = form.querySelector('[name="start_reference"]');
        firstInput?.focus();
    });
})();
