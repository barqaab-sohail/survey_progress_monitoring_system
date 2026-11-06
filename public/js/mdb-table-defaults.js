document.querySelectorAll('[data-mdb-defaults]').forEach(root => {
    const cards = [...root.querySelectorAll('[data-table]')];
    const reveal = card => { card.hidden = false; card.open = true; };
    root.querySelector('[data-table-picker]').addEventListener('change', event => {
        cards.forEach(card => { card.hidden = !!event.target.value && card.dataset.table !== event.target.value; if (!card.hidden && event.target.value) card.open = true; });
    });
    root.addEventListener('invalid', event => {
        const card = event.target.closest('[data-table]');
        if (card) reveal(card);
        const hourly = event.target.closest('.mdb-hourly');
        if (hourly) hourly.open = true;
    }, true);
    cards.forEach(card => {
        const first = card.querySelector('[data-year="first_year"]');
        const last = card.querySelector('[data-year="last_year"]');
        if (first && last) {
            const update = () => {
                const valid = first.value !== '' && last.value !== '' && +last.value >= +first.value;
                last.setCustomValidity(valid ? '' : 'Last year index must be at least the first year index.');
                card.querySelector('[data-year-summary]').textContent = valid ? `${+last.value - +first.value + 1} records per ${card.dataset.table === 'InstMymLoads' ? 'load' : 'transformer'} (year indexes ${first.value}–${last.value}).` : 'Check the year range.';
            };
            first.addEventListener('input', update); last.addEventListener('input', update); update();
        }
        card.querySelectorAll('.mdb-selections fieldset').forEach(group => {
            const boxes = [...group.querySelectorAll('input[type=checkbox]')];
            const update = () => boxes[0].setCustomValidity(boxes.some(box => box.checked) ? '' : 'Select at least one option.');
            boxes.forEach(box => box.addEventListener('change', update)); update();
        });
    });
    root.querySelectorAll('[data-managed-field]').forEach(link => link.addEventListener('click', event => {
        const target = document.querySelector(`[name="${link.dataset.managedField}"]`);
        if (target) { event.preventDefault(); target.scrollIntoView({block: 'center', behavior: 'smooth'}); target.focus({preventScroll: true}); }
    }));
    const fill = root.querySelector('[data-fill-years]');
    fill.addEventListener('click', () => {
        const base = root.querySelector('#mdb-SAI_Control-MYM_YearDesc_B').value;
        const message = root.querySelector('[data-year-label-message]');
        if (!/^\d{4}$/.test(base) || +base > 9989) { message.textContent = 'Enter a four-digit base year up to 9989 first.'; return; }
        for (let index = 1; index <= 10; index++) root.querySelector(`#mdb-SAI_Control-${index === 10 ? 'MYM_YearDesc10' : `MYM_YearDesc_${index}`}`).value = +base + index;
        message.textContent = `Forecast labels updated to ${+base + 1}–${+base + 10}. Save the configuration to apply.`;
    });
});
