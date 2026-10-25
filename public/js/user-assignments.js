// The native form remains the source of submitted values; selections survive task searches.
export class TaskSelection {
    constructor(single = false) { this.items = new Map(); this.single = single; }
    set(item, checked) {
        const key = String(item.id);
        if (!checked) { this.items.delete(key); return true; }
        if (item.disabled) return false;
        if (this.single) this.items.clear();
        if (!this.items.has(key) && this.items.size >= 50) return false;
        this.items.set(key, item);
        return true;
    }
    clear() { this.items.clear(); }
}

export function initAssignments(root = document) {
    if (!root.querySelector('[data-assignment-hub]')) return;
    root.querySelectorAll('[data-filter-select]').forEach(input => {
        const select = root.getElementById(input.dataset.filterSelect);
        input.addEventListener('input', () => {
            const search = input.value.trim().toLocaleLowerCase();
            let matches = 0;
            for (const option of select.options) {
                const show = !option.value || option.textContent.toLocaleLowerCase().includes(search);
                option.hidden = !show;
                if (option.value && show) matches++;
            }
            const feedback = root.querySelector('[data-user-search-status]');
            if (feedback && select.id === 'assignment-user') feedback.textContent = search ? `${matches} matching user(s). Choose from the User list.` : '';
        });
    });
    root.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    root.getElementById('assignment-user')?.addEventListener('change', event => { if (event.target.value) event.target.form.requestSubmit(); });
    root.getElementById('task-kind')?.addEventListener('change', event => event.target.form.requestSubmit());
    const form = root.getElementById('assign-form');
    if (form) {
        const finder = root.getElementById('task-finder');
        const result = root.getElementById('task-results');
        const feedback = root.getElementById('task-feedback');
        const prev = root.getElementById('choices-prev');
        const next = root.getElementById('choices-next');
        const selection = new TaskSelection(form.dataset.taskType === 'processing');
        const hidden = root.getElementById('selected-hidden');
        let page = Number(root.getElementById('choices-page').textContent.replace(/\D/g, '')) || 1;
        prev.disabled = page <= 1;
        let controller, requestNumber = 0, ready = false, submitting = false;
        const update = () => {
            hidden.replaceChildren();
            for (const item of selection.items.values()) {
                const input = root.createElement('input');
                input.type = 'hidden'; input.name = selection.single ? 'feeder_id' : 'task_ids[]'; input.value = item.id; hidden.append(input);
                if (['entry', 'verification', 'mdb'].includes(form.dataset.taskType)) {
                    const owner = root.createElement('input'); owner.type = 'hidden'; owner.name = `expected_assignees[${item.id}]`; owner.value = item.owner; hidden.append(owner);
                }
            }
            result.querySelectorAll('[data-choice]').forEach(input => { input.checked = selection.items.has(input.value); });
            root.getElementById('selected-count').textContent = `${selection.items.size} selected${selection.single ? '' : ' (maximum 50)'}`;
            root.getElementById('selection-summary').textContent = [...selection.items.values()].map(item => item.label).join('; ');
        };
        // Drop the fallback field names once JS takes ownership, avoiding duplicate submitted IDs.
        const bindChoices = () => {
            result.querySelectorAll('input[name^="expected_assignees"]').forEach(input => input.remove());
            result.querySelectorAll('[data-choice]').forEach(input => {
                input.removeAttribute('name');
                input.addEventListener('change', () => {
                    const accepted = selection.set({ id: input.value, label: input.dataset.label, owner: Number(input.dataset.owner), disabled: input.disabled }, input.checked);
                    feedback.textContent = accepted ? '' : 'Select up to 50 tasks at a time.';
                    update();
                });
            });
            update();
        };
        bindChoices();
        const load = async (newPage = 1) => {
            controller?.abort(); controller = new AbortController();
            const current = ++requestNumber;
            const params = new URLSearchParams(new FormData(finder)); params.set('choice_page', String(newPage));
            result.setAttribute('aria-busy', 'true');
            root.getElementById('review-assignments').disabled = true;
            prev.disabled = next.disabled = true;
            feedback.textContent = 'Loading tasks…';
            try {
                const response = await fetch(`${finder.dataset.optionsUrl}?${params}`, { signal: controller.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Unable to load tasks. Refresh the page or try again.');
                const data = await response.json();
                if (current !== requestNumber) return;
                result.replaceChildren();
                for (const item of data.items) {
                    const label = root.createElement('label'); label.className = `assignment-choice${item.disabled ? ' is-disabled' : ''}`;
                    const input = root.createElement('input'); input.type = selection.single ? 'radio' : 'checkbox'; input.value = item.id;
                    input.dataset.choice = ''; input.dataset.label = item.label; input.dataset.owner = item.owner; input.disabled = item.disabled;
                    const span = root.createElement('span');
                    for (const [tag, value] of [['strong', item.label], ['small', item.detail], ['small', item.note]]) {
                        const element = root.createElement(tag); element.textContent = value ?? ''; span.append(element);
                    }
                    label.append(input, span); result.append(label);
                }
                if (!data.items.length) { const empty = root.createElement('p'); empty.className = 'assignment-empty'; empty.textContent = 'No matching tasks. Try another search, project or team.'; result.append(empty); }
                page = newPage;
                prev.disabled = page <= 1; next.disabled = !data.more;
                root.getElementById('choices-page').textContent = `Page ${page}`;
                feedback.textContent = 'Task list updated. Selections from other pages are retained.';
                bindChoices();
            } catch (error) {
                if (error.name !== 'AbortError' && current === requestNumber) feedback.textContent = error.message;
            } finally {
                if (current === requestNumber) { result.setAttribute('aria-busy', 'false'); root.getElementById('review-assignments').disabled = false; }
            }
        };
        finder.addEventListener('submit', event => { event.preventDefault(); load(); });
        let debounce;
        finder.querySelector('[name="task_search"]').addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => load(), 300); });
        finder.querySelectorAll('select').forEach(select => select.addEventListener('change', () => {
            clearTimeout(debounce);
            if (select.name === 'survey_team_id') {
                selection.clear(); update(); root.getElementById('assign-team').value = select.value;
            }
            load();
        }));
        prev.addEventListener('click', () => load(page - 1)); next.addEventListener('click', () => load(page + 1));
        root.getElementById('clear-selected').addEventListener('click', () => { selection.clear(); update(); });
        root.getElementById('select-visible')?.addEventListener('click', () => {
            result.querySelectorAll('[data-choice]:not(:disabled)').forEach(input => selection.set({ id: input.value, label: input.dataset.label, owner: Number(input.dataset.owner) }, true));
            update();
        });
        const dialog = root.getElementById('assignment-review');
        form.addEventListener('submit', event => {
            if (submitting) { event.preventDefault(); return; }
            if (ready) { submitting = true; root.getElementById('review-assignments').disabled = true; return; }
            event.preventDefault();
            if (!selection.items.size) { feedback.textContent = 'Select at least one available task.'; result.scrollIntoView({block: 'center'}); return; }
            const replacing = ['entry', 'verification', 'mdb'].includes(form.dataset.taskType) && [...selection.items.values()].some(item => item.owner);
            if (replacing && (!form.elements.replace_existing.checked || form.elements.reason.value.trim().length < 3)) {
                feedback.textContent = 'Confirm replacing the current owners and enter a reason with at least 3 characters.';
                form.elements.reason.focus(); return;
            }
            root.getElementById('review-summary').textContent = `${form.dataset.taskLabel}: assign ${selection.items.size} selected task(s) to ${form.dataset.userName}?`;
            root.getElementById('review-details').textContent = [...selection.items.values()].map(item => item.label).join('; ') + (replacing ? '. Existing owners will be replaced and recorded in the audit trail.' : '');
            dialog.showModal();
        });
        root.getElementById('confirm-assignment').addEventListener('click', () => { if (submitting) return; ready = true; dialog.close(); form.requestSubmit(); ready = false; });
        // Do not leave browser-restored buttons disabled after navigating back.
        window.addEventListener('pageshow', () => { submitting = false; root.getElementById('review-assignments').disabled = false; });
    }
    const reassignDialog = root.getElementById('reassign-dialog');
    const reassign = root.getElementById('reassign-form');
    let candidateRequest = 0;
    root.querySelectorAll('[data-reassign]').forEach(button => button.addEventListener('click', async () => {
        const current = ++candidateRequest;
        reassign.reset(); reassign.elements.assignment_id.value = button.dataset.id; reassign.elements.expected_owner.value = button.dataset.owner;
        root.getElementById('reassign-label').textContent = button.dataset.label;
        const select = root.getElementById('reassign-user'); select.replaceChildren(new Option('Loading eligible users…', ''));
        const submit = reassign.querySelector('[type="submit"]'); submit.disabled = true;
        const feedback = root.getElementById('reassign-feedback'); feedback.textContent = '';
        reassignDialog.showModal();
        try {
            const response = await fetch(button.dataset.url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Unable to load eligible users. Close and try again.');
            const data = await response.json(); if (current !== candidateRequest) return;
            select.replaceChildren(new Option('Select a new owner', ''));
            data.users.forEach(user => select.append(new Option(user.name, user.id)));
            feedback.textContent = data.users.length ? '' : 'No other eligible active surveyors belong to this team.';
            submit.disabled = !data.users.length;
        } catch (error) { if (current === candidateRequest) feedback.textContent = error.message; }
    }));
    reassign.addEventListener('submit', () => { reassign.querySelector('[type="submit"]').disabled = true; });
}

if (typeof document !== 'undefined') initAssignments();
