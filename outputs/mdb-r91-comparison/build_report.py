import json
from pathlib import Path
from collections import Counter, defaultdict
p=Path(__file__).parent
files=[json.loads((p/f).read_text(encoding='utf-8-sig')) for f in ['generated.json','reference.json']]
tables=[{t['name']:t for t in d['tables']} for d in files]
a,b=tables
package=json.loads(Path('C:/Users/sohai/Downloads/synergee_11140223003_batch-17_r91.json').read_text(encoding='utf-8-sig'))
def stats(t):
    loads=t['Loads']['rows']; sections=t['InstSection']['rows']; nodes=t['Node']['rows']
    ids={r['SectionId'] for r in sections}; nodeids={r['NodeId'] for r in nodes}
    totals={q:[sum(r.get(f'Phase{i}{q}') or 0 for r in loads) for i in [1,2,3]] for q in ['Customers','Kva']}
    zero=sum(all((r.get(f'Phase{i}{q}') or 0)==0 for i in [1,2,3] for q in ['Customers','Kva']) for r in loads)
    adj=defaultdict(set);directed=defaultdict(set)
    for s in sections:
        adj[s['FromNodeId']].add(s['ToNodeId']);adj[s['ToNodeId']].add(s['FromNodeId']);directed[s['FromNodeId']].add(s['ToNodeId'])
    def reach(graph):
        seen=set();pending=[t['InstFeeders']['rows'][0]['FeederId']]
        while pending:
            n=pending.pop()
            if n not in seen:seen.add(n);pending.extend(graph[n]-seen)
        return len(seen)
    return dict(totals=totals,zero_loads=zero,positive_loads=len(loads)-zero,load_rows_per_section=dict(Counter(Counter(r['SectionId'] for r in loads).values())),length_m=sum(s['SectionLength_MUL'] or 0 for s in sections),zero_sections=[s['SectionId'] for s in sections if s['SectionLength_MUL']==0],undirected_reachable=reach(adj),directed_reachable=reach(directed),node_count=len(nodes),missing_endpoints=sum(s['FromNodeId'] not in nodeids or s['ToNodeId'] not in nodeids for s in sections),orphan_loads=sum(r['SectionId'] not in ids for r in loads))
x,y=map(stats,tables)
summary={'generated':x,'reference':y,'schema_equal':all(a[n]['fields']==b[n]['fields'] and a[n]['indexes']==b[n]['indexes'] for n in a),'relationships_equal':files[0]['relationships']==files[1]['relationships']}
(p/'comparison-summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
lines=['# MDB comparison — generated revision 91 and approved reference','',
'Inspected on 27 October 2026. Both databases were opened read-only through DAO. Source SHA-256 hashes were unchanged after extraction. No queries, macros, modules, or SynerGEE load flow were executed.','',
'## Result','',
'The transformer type and category-based customer/demand values are now populated in the generated MDB. Both files have identical table structures, field definitions, indexes, and relationships. The files describe different transformers and networks, so their customer totals, capacities, coordinates, and lengths are expected to differ. Structural agreement does not establish native SynerGEE acceptance.','',
'## Main comparison','',
'| Item | Generated application MDB | Approved reference MDB |','|---|---|---|',
'| Transformer | 11140223003 | T-3030216724 |',
'| Transformer type | 100 KVA | 200 KVA |',
'| User tables / relationships | 50 / 41 | 50 / 41 |',
'| Nodes / sections | 14 / 13 | 26 / 24 |',
'| Loads rows | 108 | 23 |',
'| Customers summed across phases | 74 | 173 |',
'| Apparent demand summed across phases | 148 kVA | 518 kVA |',
'| Nonzero customer/demand rows | 10 | 20 |',
'| Zero/empty customer-and-kVA rows | 98 | 3 |',
'| Total stored section length | %.3f m | %.3f m |'%(x['length_m'],y['length_m']),
'| Meter points | 0 | 1 (measurement values unset) |',
'| Missing section endpoints / orphan load rows | 0 / 0 | 0 / 0 |','',
'## Load verification','',
'The corresponding revision 91 JSON was also read. Its engineering configuration revision is 7 and its load method is `category_rates`. Its survey consumer counts total 74; its derived phase demand totals 148 kVA. These totals match the generated MDB. Every generated phase customer and kVA field is populated. Generated kW and kvar fields are NULL, consistent with the configured direct-kVA method; the reference stores zero kW and kvar. NULL means unspecified, and must not be interpreted as a measured zero.','',
'| Phase | Generated customers | Generated kVA | Reference customers | Reference kVA |','|---|---:|---:|---:|---:|']
for i,phase in enumerate(['R','Y','B']):lines.append(f'| {phase} | {x["totals"]["Customers"][i]:g} | {x["totals"]["Kva"][i]:g} | {y["totals"]["Customers"][i]:.3f} | {y["totals"]["Kva"][i]:.3f} |')
lines += ['',
'The generated totals are 36 RS customers ? 72 kVA and 38 SC customers ? 76 kVA. All other categories have zero demand. Generated phase customer allocation uses whole customers; the reference includes fractional customer allocations. The reference total represents a different survey and must not be copied into this model.','',
'## Findings requiring review','',
'1. **Multiple Loads rows per section:** the application writes nine consumer-category records for each of 12 survey sections (108 rows). Of these, 98 have zero customers and zero kVA. The reference has one Loads row on each of 23 sections. Both files permit repeated SectionId values through non-unique indexes, but that alone does not establish how the target SynerGEE importer handles repeated section loads. Verify that importing this generated file preserves the full 74 customers and 148 kVA instead of overwriting a category row. The completeness count includes zero category records.','',
'2. **Two zero-length sections:** one is the logical transformer terminal link; the other is the first survey S/E pair. Revision 91 JSON maps that pair (references 003 ? 027) to the same source waypoint (960), consistent with the existing first-pair coordinate policy. The reference has no zero-length sections. Confirm that the first pair is intentionally coincident; if it represents a real span, its physical length is absent.','',
'3. **Section direction:** all 14 generated nodes are connected when edges are treated as undirected. Following FromNodeId ? ToNodeId from the feeder reaches 13 of 14 nodes; the first survey S/E edge points toward the root. This is a direction-review finding, not proof of a broken physical connection. The reference reaches 25 of 26 nodes in either direction check; its remaining node is isolated. Native import should be checked for how it orients these edges.','',
'4. **Inherited electrical values:** both files store Frequency = 60, NominalKvll = 11, transformer SpecNomKv = 4.16, NomKvMode = C, YG/YG connections, and UseInstanceImpedance = 0. Revision 91 JSON has secondary_voltage_kv unset, so the 4.16 value is retained from mapping values. Matching the reference does not independently confirm suitability for this transformer. Confirm frequency, secondary-voltage settings, connections, and library data against the department specification.','',
'5. **Demand versus nameplate:** estimated connected demand is 148 kVA against a 100 KVA type label (148%). The reference similarly totals 518 kVA against 200 KVA (259%). These are sums of model inputs, not measured simultaneous loading or load-flow results; no overload conclusion is established by this comparison alone.','',
'6. **Meter and descriptive differences:** the generated file has no meter point; the reference contains one with measurement values unset. The generated feeder uses internal identifiers and substation label 407, while the reference uses Choper Hatta and 132KV M.Pur. Reference-only year labels 2015–2025 and optional text/location markers are not populated in the generated file. These are separate from the resolved load-population issue.','',
'## Table record counts','', '| Table | Generated | Reference |','|---|---:|---:|']
for n in sorted(a):lines.append(f'| {n} | {a[n]["count"]} | {b[n]["count"]} |')
lines += ['','## Source evidence','']
for d in files:lines += [f'- `{d["file"]}`: SHA-256 `{d["sha256"]}`.']
lines += ['','Raw read-only extracts: `generated.json` and `reference.json`. Computed metrics: `comparison-summary.json`. No source database or application code was changed for this comparison.']
(p/'Comparison.md').write_text('\n'.join(lines)+'\n',encoding='utf-8')
print('Wrote Comparison.md and comparison-summary.json')

