(() => {
    const nodes = document.getElementById('ht-nodes');
    if (!nodes) return;
    const kinds = [...document.querySelectorAll('input[name="kind"]')];
    const extension = () => kinds.some(input => input.checked && input.value === 'ht_extension');
    const add = document.getElementById('ht-add-node');
    const count = document.getElementById('ht-pole-count');
    function refresh() {
        const rows = [...nodes.children];
        const active = rows.filter((row, index) => extension() || index === 0 || index === rows.length - 1);
        rows.forEach((row, index) => {
            const middle = index > 0 && index < rows.length - 1;
            row.hidden = middle && !extension();
            row.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = row.hidden; });
        });
        active.forEach((row, index) => {
            row.querySelector('[data-ht-node-label]').textContent = index === 0 ? 'Existing HT connection (already in KMZ)' : index === active.length - 1 ? 'New transformer (final location)' : `New HT pole ${index}`;
            row.querySelector('[data-ht-remove]').hidden = index === 0 || index === active.length - 1;
            row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/nodes\[[^\]]+\]/, `nodes[${index}]`); });
        });
        document.getElementById('ht-pole-tools').hidden = !extension();
        count.disabled = !extension();
        const poles = active.length - 2;
        document.getElementById('ht-route-summary').textContent = extension()
            ? `Existing HT connection \u2192 ${poles} new pole${poles === 1 ? '' : 's'} \u2192 new transformer`
            : 'Existing HT connection \u2192 new transformer \u00b7 No new poles or HT spans';
        document.getElementById('ht-route-help').textContent = extension()
            ? 'Add poles in route order, then enter the transformer location in the final row. New HT spans will connect the route. Latitude and longitude are required for every route row before submission.'
            : 'Enter the new transformer location on the existing HT network. It may share the existing pole waypoint and coordinates. Only the transformer will be added to the KMZ. Latitude and longitude are required for every route row before submission.';
        add.disabled = rows.length >= 200;
        count.max = Math.max(1, 200 - rows.length);
    }
    add.addEventListener('click', () => {
        if (!extension() || !count.reportValidity()) return;
        const amount = Number(count.value);
        if (!Number.isInteger(amount) || amount < 1 || nodes.children.length + amount > 200) return;
        for (let i = 0; i < amount; i++) {
            nodes.insertBefore(document.getElementById('ht-node-template').content.cloneNode(true), nodes.lastElementChild);
        }
        refresh();
        nodes.children[nodes.children.length - amount - 1].querySelector('input').focus();
        count.value = '1';
    });
    nodes.addEventListener('click', event => {
        if (event.target.matches('[data-ht-remove]')) {
            const row = event.target.closest('[data-ht-node]');
            if (row !== nodes.firstElementChild && row !== nodes.lastElementChild) { row.remove(); refresh(); }
        }
    });
    document.getElementById('ht-use-connection').addEventListener('click', () => {
        const option = document.getElementById('ht-upstream').selectedOptions[0];
        const coordinates = JSON.parse(option?.dataset.coordinates || '[]')[0];
        const help = document.getElementById('ht-connection-help');
        if (!coordinates) { help.textContent = 'Select an existing HT connection first.'; return; }
        const row = nodes.firstElementChild;
        row.querySelector('[name$="[longitude]"]').value = coordinates[0];
        row.querySelector('[name$="[latitude]"]').value = coordinates[1];
        help.textContent = option.dataset.type === 'line'
            ? 'First endpoint filled from the selected HT line. Check or adjust it to the actual connection point on this line.'
            : 'Existing connection coordinates filled from the selected KMZ point. Enter its waypoint name below.';
    });
    kinds.forEach(input => input.addEventListener('change', refresh));
    refresh();
})();
