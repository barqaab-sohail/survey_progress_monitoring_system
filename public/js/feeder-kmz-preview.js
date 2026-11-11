// Leaflet 1.9.4 API: https://leafletjs.com/reference-1.9.4.html
export function groupPreviewFeatures(features) {
    const groups = new Map();
    for (const feature of features || []) {
        const key = feature.properties?.layer || 'ht';
        if (!groups.has(key)) groups.set(key, {label:feature.properties?.layer_label || 'HT network', kind:feature.properties?.kind || 'ht', features:[]});
        groups.get(key).features.push(feature);
    }
    return groups;
}

export function featurePopup(feature, doc = document) {
    const box = doc.createElement('div'); box.className = 'feeder-map-popup';
    const heading = doc.createElement('strong'); heading.textContent = feature.properties?.name || 'Network feature'; box.append(heading);
    const table = doc.createElement('table');
    for (const [key,value] of Object.entries(feature.properties?.details || {})) {
        const row = doc.createElement('tr'), label = doc.createElement('th'), cell = doc.createElement('td');
        label.textContent = key; cell.textContent = value === null || value === '' ? 'Not recorded' : String(value);
        row.append(label,cell); table.append(row);
    }
    box.append(table); return box;
}

export function installFeederMap(root, L = globalThis.L, fetcher = globalThis.fetch) {
    const load = root.querySelector('[data-map-load]'), fit = root.querySelector('[data-map-fit]');
    const status = root.querySelector('[data-map-status]'), layersBox = root.querySelector('[data-map-layers]');
    if (!L) {status.textContent = 'The map library could not load. Refresh the page to retry.'; load.disabled = true; return;}
    const map = L.map(root.querySelector('[data-map-canvas]'), {preferCanvas:true, scrollWheelZoom:false}).setView([34,73],10);
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom:19, attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
    tiles.on('tileerror', () => {root.querySelector('[data-map-canvas]').setAttribute('aria-description', 'Background tiles unavailable; network geometry can still be reviewed.');});
    let displayed = [];
    function fitVisible() {
        const visible = displayed.filter(layer => map.hasLayer(layer));
        if (!visible.length) return;
        const bounds = L.featureGroup(visible).getBounds();
        if (bounds.isValid()) map.fitBounds(bounds, {padding:[24,24], maxZoom:17});
    }
    fit.addEventListener('click', fitVisible);
    load.addEventListener('click', async () => {
        load.disabled = true; status.textContent = 'Loading the feeder geometry and approved LT networks...';
        try {
            const response = await fetcher(root.dataset.url, {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store'});
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Unable to load the map.');
            displayed.forEach(layer => map.removeLayer(layer)); displayed = [];
            layersBox.replaceChildren(); const legend = document.createElement('legend'); legend.textContent = 'Visible networks'; layersBox.append(legend);
            for (const group of groupPreviewFeatures(result.features).values()) {
                const color = group.kind === 'ht' ? '#b521b2' : '#16833b';
                const layer = L.geoJSON(group.features, {
                    style:() => ({color,weight:3,fillOpacity:0.15}),
                    pointToLayer:(_feature,latlng) => L.circleMarker(latlng,{radius:5,color,fillColor:color,fillOpacity:0.85,weight:1}),
                    onEachFeature:(feature,child) => {child.bindPopup(featurePopup(feature),{maxWidth:420}); child.bindTooltip(String(feature.properties?.name || ''));},
                }).addTo(map);
                displayed.push(layer);
                const label = document.createElement('label'), checkbox = document.createElement('input'), name = document.createElement('span');
                checkbox.type = 'checkbox'; checkbox.checked = true; name.textContent = group.label; name.style.color = color;
                checkbox.addEventListener('change', () => {if (checkbox.checked) layer.addTo(map); else map.removeLayer(layer);});
                label.append(checkbox,name); layersBox.append(label);
            }
            fit.disabled = !displayed.length; map.invalidateSize(); fitVisible();
            status.textContent = `${result.counts?.total_transformers ?? ''} HT transformer records; ${result.counts?.approved_lt ?? 0} approved LT networks. ${result.features.length} map features loaded.`
                + (result.skipped_geometries ? ` ${result.skipped_geometries} invalid geometry items could not be shown.` : '')
                + (result.warnings?.length ? ` ${result.warnings.length} excluded-update notices are listed in coverage.` : '');
        } catch (error) {status.textContent = `Map preview unavailable: ${error.message}`;}
        finally {load.disabled = false;}
    });
    return {map, load:() => load.click()};
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-feeder-map]').forEach(root => installFeederMap(root));
