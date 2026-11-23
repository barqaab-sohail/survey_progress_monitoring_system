(() => {
    const form = document.getElementById('service-cable-export-selection');
    if (!form) return;
    const all = document.getElementById('cable-export-select-all');
    const choices = [...form.querySelectorAll('input[name="transformers[]"]')];
    const button = document.getElementById('cable-export-download');
    const count = document.getElementById('cable-export-selection-count');
    function refresh() {
        const selected = choices.filter(input => input.checked).length;
        all.checked = choices.length > 0 && selected === choices.length;
        all.indeterminate = selected > 0 && selected < choices.length;
        button.disabled = selected === 0;
        count.textContent = `${selected} transformer${selected === 1 ? '' : 's'} selected`;
    }
    all.addEventListener('change', () => {
        choices.forEach(input => { input.checked = all.checked; });
        refresh();
    });
    choices.forEach(input => input.addEventListener('change', refresh));
    form.addEventListener('submit', event => {
        if (!choices.some(input => input.checked)) {
            event.preventDefault();
            count.textContent = 'Check at least one transformer to export.';
            choices[0]?.focus();
        }
    });
    window.addEventListener('pageshow', refresh);
    refresh();
})();
