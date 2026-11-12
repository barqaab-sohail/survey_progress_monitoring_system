(() => {
    const nodes = document.getElementById('ht-nodes');
    if (!nodes) return;
    const kind = document.getElementById('ht-kind');
    const add = document.getElementById('ht-add-node');
    function refresh() {
        const rows = [...nodes.children];
        rows.forEach((row, index) => {
            row.querySelector('[data-ht-node-label]').textContent = index === 0 ? 'Existing HT connection' : index === rows.length - 1 ? 'HT transformer' : `Intermediate HT pole ${index}`;
            row.querySelector('[data-ht-remove]').hidden = index === 0 || index === rows.length - 1;
            row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/nodes\[[^\]]+\]/, `nodes[${index}]`); });
        });
        add.hidden = kind.value !== 'ht_extension';
        kind.setCustomValidity(kind.value === 'missing_transformer' && rows.length !== 2 ? 'Remove intermediate poles for a transformer on existing HT.' : '');
    }
    add.addEventListener('click', () => {
        if (nodes.children.length >= 200) return;
        const row = document.getElementById('ht-node-template').content.cloneNode(true);
        nodes.insertBefore(row, nodes.lastElementChild);
        refresh();
    });
    nodes.addEventListener('click', event => {
        if (event.target.matches('[data-ht-remove]')) {
            const row = event.target.closest('[data-ht-node]');
            if (row !== nodes.firstElementChild && row !== nodes.lastElementChild) { row.remove(); refresh(); }
        }
    });
    kind.addEventListener('change', refresh);
    refresh();
})();
