export const phaseCategories = ['rs', 'sc', 'st'];

// Match SurveyPhaseConsistency: unknown differs from explicitly absent.
export function conductorAvailability(value) {
    if (value === null || value === undefined) return null;
    return !['', '__none__', '+', '-', '–', '—', 'x', '×'].includes(String(value).trim().toLowerCase());
}

export function phaseConsistencyIssues(rows, transformer = '(unrecorded)', requiredPhases = []) {
    const issues = [];
    for (const row of rows) {
        const start = rows.find(candidate => String(candidate.pair_number) === String(row.pair_number) && candidate.designation === 'S');
        if (!start) continue;
        const counts = row.consumer_phases ?? row.original_entry?.consumer_phases ?? {};
        for (const category of phaseCategories) for (const phase of ['R', 'Y', 'B']) {
            const count = counts[category]?.[phase];
            const available = conductorAvailability(start.conductors?.[phase]);
            if (Number(count) > 0 && (available === false || (available === null && requiredPhases.includes(phase)))) {
                issues.push({row, phase, category, count, field: `consumer_phases.${category}.${phase}`,
                    message: `Transformer ${transformer}, pair ${row.pair_number} ${row.designation} ${row.waypoint_reference || '(waypoint unrecorded)'}: ${category.toUpperCase()} ${phase}-phase customer count ${count} conflicts with the missing ${phase} conductor on the section S row. Check the recorded conductor and customer count; no values were changed.`});
            }
        }
    }
    return issues;
}

export function phaseEntryState(payload, savedRows = [], transformer = '(unrecorded)') {
    const rows = savedRows.filter(row => !(String(row.pair_number) === String(payload.pair_number) && row.designation === payload.designation));
    rows.push(payload);
    const start = rows.find(row => String(row.pair_number) === String(payload.pair_number) && row.designation === 'S');
    const errors = {};
    const previous = savedRows.find(row => String(row.pair_number) === String(payload.pair_number) && row.designation === 'S');
    const removed = payload.designation === 'S' ? ['R','Y','B'].filter(phase => conductorAvailability(previous?.conductors?.[phase]) === true && conductorAvailability(payload.conductors?.[phase]) !== true) : [];
    for (const issue of phaseConsistencyIssues(rows.filter(row => String(row.pair_number) === String(payload.pair_number)), transformer, removed)) {
        const key = issue.row === payload ? issue.field : `conductors.${issue.phase}`;
        (errors[key] ??= []).push(issue.message);
    }
    return {errors, availability: Object.fromEntries(['R', 'Y', 'B'].map(phase => [phase, conductorAvailability(start?.conductors?.[phase])]))};
}

/** Unavailable values stay visible; clearing one requires an explicit action. */
export function updatePhaseFields(form, payload, savedRows, transformer, editable = true) {
    const state = phaseEntryState(payload, savedRows, transformer);
    form.querySelectorAll('[data-phase-consistency-note]').forEach(note => note.remove());
    form.querySelectorAll('[data-phase-consistency-invalid]').forEach(input => {
        input.removeAttribute('aria-invalid'); input.removeAttribute('data-phase-consistency-invalid');
    });
    for (const category of phaseCategories) for (const phase of ['R', 'Y', 'B']) {
        const input = form.elements.namedItem(`consumer_phases[${category}][${phase}]`);
        if (!input) continue;
        const available = state.availability[phase], retained = input.value !== '';
        input.disabled = !editable || (available !== true && !retained);
        input.readOnly = available !== true;
        const message = state.errors[`consumer_phases.${category}.${phase}`]?.join(' ');
        if (available !== true || message) {
            const note = form.ownerDocument.createElement('small');
            note.dataset.phaseConsistencyNote = 'true';
            note.className = message ? 'entry-phase-error' : 'entry-phase-help';
            note.textContent = message || (available === false ? `${phase} conductor absent on section S row.` : `Record the section S row ${phase} conductor first.`);
            if (message) { input.setAttribute('aria-invalid', 'true'); input.dataset.phaseConsistencyInvalid = 'true'; }
            if (retained && available !== true && editable) {
                const clear = form.ownerDocument.createElement('button');
                clear.type = 'button'; clear.className = 'btn btn-sm btn-light'; clear.textContent = 'Clear recorded value';
                clear.addEventListener('click', () => { input.value = ''; input.dispatchEvent(new form.ownerDocument.defaultView.Event('input', {bubbles:true})); });
                note.append(clear);
            }
            input.parentElement.append(note);
        }
    }
    for (const [key, messages] of Object.entries(state.errors).filter(([key]) => key.startsWith('conductors.'))) {
        const input = form.elements.namedItem(`conductors[${key.split('.')[1]}]`);
        if (!input) continue;
        input.setAttribute('aria-invalid', 'true'); input.dataset.phaseConsistencyInvalid = 'true';
        const note = form.ownerDocument.createElement('small'); note.dataset.phaseConsistencyNote = 'true'; note.className = 'entry-phase-error'; note.textContent = messages.join(' '); input.parentElement.append(note);
    }
    let summary = form.querySelector('[data-phase-consistency-summary]');
    if (!summary) { summary = form.ownerDocument.createElement('div'); summary.dataset.phaseConsistencySummary = 'true'; summary.setAttribute('role', 'alert'); summary.className = 'entry-phase-error'; form.append(summary); }
    summary.hidden = Object.keys(state.errors).length === 0;
    summary.textContent = [...new Set(Object.values(state.errors).flat())].join(' ');
    return state;
}
