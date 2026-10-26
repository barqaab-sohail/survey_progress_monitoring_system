import fs from 'node:fs';
const dir = 'outputs/mdb-comparison-2026-10-26/';
const read = name => JSON.parse(fs.readFileSync(dir + name, 'utf8').replace(/^\uFEFF/, ''));
const empty = value => value === null || value === undefined || value === '';
function analyze(document) {
    const tables = Object.fromEntries(document.tables.map(table => [table.name, table]));
    const rows = name => tables[name]?.rows || [];
    const missing = (table, field) => rows(table).filter(row => empty(row[field])).length;
    const nodes = rows('Node'), sections = rows('InstSection'), feeders = rows('InstFeeders'), loads = rows('Loads');
    const nodeIds = new Set(nodes.map(row => row.NodeId));
    const sectionIds = new Set(sections.map(row => row.SectionId));
    const feederIds = new Set(feeders.map(row => row.FeederId));
    const adjacency = new Map(nodes.map(row => [row.NodeId, new Set()]));
    let selfLoops = 0, duplicateEdges = 0;
    const seenEdges = new Set();
    for (const section of sections) {
        if (section.FromNodeId === section.ToNodeId) selfLoops++;
        const edge = JSON.stringify([section.FromNodeId, section.ToNodeId].sort());
        if (seenEdges.has(edge)) duplicateEdges++;
        seenEdges.add(edge);
        adjacency.get(section.FromNodeId)?.add(section.ToNodeId);
        adjacency.get(section.ToNodeId)?.add(section.FromNodeId);
    }
    const reachable = new Set(), queue = feeders.map(row => row.FeederId).filter(id => nodeIds.has(id));
    while (queue.length) {
        const id = queue.pop();
        if (reachable.has(id)) continue;
        reachable.add(id);
        queue.push(...(adjacency.get(id) || []));
    }
    const relationChecks = document.relationships.map(relation => {
        const key = (row, foreign) => JSON.stringify(relation.fields.map(field => row[foreign ? field.foreign_name : field.name]));
        const parents = new Set(rows(relation.table).map(row => key(row, false)));
        const children = rows(relation.foreign_table).filter(row => relation.fields.every(field => !empty(row[field.foreign_name])));
        return {name:relation.name, parent:relation.table, child:relation.foreign_table, checked:children.length,
            orphans:children.filter(row => !parents.has(key(row, true))).length};
    });
    const uniqueChecks = [];
    for (const table of document.tables) for (const index of table.indexes.filter(index => index.unique)) {
        const keys = table.rows.filter(row => index.fields.every(field => !empty(row[field]))).map(row => JSON.stringify(index.fields.map(field => row[field])));
        uniqueChecks.push({table:table.name, index:index.name, checked:keys.length, duplicates:keys.length - new Set(keys).size});
    }
    const finiteRange = field => {
        const values = nodes.map(node => node[field]).filter(value => typeof value === 'number' && Number.isFinite(value));
        return values.length ? {min:Math.min(...values), max:Math.max(...values)} : null;
    };
    const phaseFields = suffix => [1,2,3].map(phase => 'Phase' + phase + suffix);
    const demand = Object.fromEntries(['Kw','Kvar','Kva','Customers'].map(suffix => [suffix, {
        rows_with_no_phase_values:loads.filter(row => phaseFields(suffix).every(field => empty(row[field]))).length,
        nonempty_phase_cells:loads.flatMap(row => phaseFields(suffix).map(field => row[field])).filter(value => !empty(value)).length,
        numeric_total:loads.flatMap(row => phaseFields(suffix).map(field => row[field])).filter(value => typeof value === 'number').reduce((a,b) => a+b,0)
    }]));
    const missingConductors = [];
    for (const row of sections) {
        if (empty(row.SectionPhases)) continue;
        for (const [position, phase, field] of [[0,'R','PhaseConductorId'],[1,'Y','PhaseConductor2Id'],[2,'B','PhaseConductor3Id'],[3,'N','NeutralConductorId']]) {
            if (row.SectionPhases[position] === phase && empty(row[field])) missingConductors.push({section:row.SectionId, phase});
        }
    }
    return {file:document.file,sha256:document.sha256,
        row_counts:Object.fromEntries(document.tables.map(table => [table.name,table.count])),
        controls:rows('SAI_Control'), transformer_types:rows('InstPrimaryTransformers').map(row => ({id:row.UniqueDeviceId,type:row.TransformerType,use_instance_impedance:row.UseInstanceImpedance})),
        coordinates:{nodes:nodes.length,missing_x:missing('Node','X'),missing_y:missing('Node','Y'),x_range:finiteRange('X'),y_range:finiteRange('Y')},
        sections:{total:sections.length,missing_length:missing('InstSection','SectionLength_MUL'),missing_phases:missing('InstSection','SectionPhases'),missing_configuration:missing('InstSection','ConfigurationId'),missing_load_connection:missing('InstSection','LoadConnectionPhaseGnd'),active_phase_missing_conductor:missingConductors},
        demand,
        network:{nodes:nodes.length,sections:sections.length,reachable_nodes:reachable.size,unused_isolated_nodes:nodes.filter(row=>adjacency.get(row.NodeId).size===0).length,
            sections_unreachable_from_any_feeder:sections.filter(row=>!reachable.has(row.FromNodeId)||!reachable.has(row.ToNodeId)).length,
            sections_with_unknown_endpoint:sections.filter(row=>!nodeIds.has(row.FromNodeId)||!nodeIds.has(row.ToNodeId)).length,
            sections_with_unknown_feeder:sections.filter(row=>!feederIds.has(row.FeederId)).length,
            self_loops:selfLoops,duplicate_undirected_edges:duplicateEdges,
            orphan_loads:loads.filter(row=>!sectionIds.has(row.SectionId)).length},
        relationship_checks:relationChecks,unique_index_checks:uniqueChecks};
}
const generated = analyze(read('generated-inspection.json'));
const reference = analyze(read('reference-inspection.json'));
const report = {schema_validation:read('schema-validation.json'),generated,reference,
    conclusion:'The generated MDB has the same Access schema as the approved reference, but is incomplete for a validated electrical model. Native SynerGEE opening and load-flow tests have not been performed. These are different transformer networks; differing row counts are not themselves errors.'};
fs.writeFileSync(dir+'comparison.json',JSON.stringify(report,null,2)+'\n');
const names = Object.keys(generated.row_counts).sort();
fs.writeFileSync(dir+'table-counts.csv','Table,Generated,Approved reference\n'+names.map(name=>[name,generated.row_counts[name],reference.row_counts[name]].join(',')).join('\n')+'\n');
console.log(JSON.stringify({generated:{network:generated.network,relations:generated.relationship_checks.filter(r=>r.orphans),unique:generated.unique_index_checks.filter(r=>r.duplicates),demand:generated.demand},reference:{network:reference.network,relations:reference.relationship_checks.filter(r=>r.orphans),unique:reference.unique_index_checks.filter(r=>r.duplicates),demand:reference.demand}},null,2));
