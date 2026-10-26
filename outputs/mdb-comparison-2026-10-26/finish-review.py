import csv
import hashlib
import json
import math
from pathlib import Path

ROOT = Path(__file__).resolve().parent
def read(name):
    return json.loads((ROOT / name).read_text(encoding='utf-8-sig'))
documents = {role: read(role+'-inspection.json') for role in ['generated', 'reference']}
storage = read('schema-and-storage.json')
comparison = read('comparison.json')
tables = {role: {t['name']: t for t in doc['tables']} for role, doc in documents.items()}
def rows(role, name):
    return tables[role][name]['rows']
def canonical_rows(table):
    return sorted(json.dumps(row, sort_keys=True, ensure_ascii=False) for row in table['rows'])
verification = {}
for role, doc in documents.items():
    compact = read(role+'-compacted-inspection.json')
    ct = {t['name']:t for t in compact['tables']}
    verification[role] = {
        'all_50_user_table_row_multisets_preserved': all(canonical_rows(t)==canonical_rows(ct[t['name']]) for t in doc['tables']),
        'relationships_preserved': doc['relationships']==compact['relationships'],
        'source_sha256_unchanged': hashlib.sha256(Path(next(s['path'] for s in storage if s['role']==role)).read_bytes()).hexdigest()==doc['sha256'],
    }
schema_equal = all(
    {k:v for k,v in t.items() if k not in ['rows','count']} ==
    {k:v for k,v in tables['reference'][name].items() if k not in ['rows','count']}
    for name,t in tables['generated'].items()) and documents['generated']['relationships']==documents['reference']['relationships']
extended = {'compact_verification':verification,'independent_captured_schema_equal':schema_equal}
for role in documents:
    nodes = {n['NodeId']:n for n in rows(role,'Node')}
    lengths=[]
    conductors=set()
    for section in rows(role,'InstSection'):
        a,b = nodes[section['FromNodeId']],nodes[section['ToNodeId']]
        chord = math.hypot(a['X']-b['X'],a['Y']-b['Y'])
        length = section['SectionLength_MUL']
        lengths.append({'id':section['SectionId'],'length':length,'endpoint_distance_m':chord,'shorter_than_chord':length+0.1<chord})
        for field in ['PhaseConductorId','PhaseConductor2Id','PhaseConductor3Id','NeutralConductorId']:
            if section[field]: conductors.add(section[field])
    extended[role]={'length_total':sum(s['length'] for s in lengths),'length_checks':lengths,'conductor_codes':sorted(conductors),'configuration_codes':sorted(set(s['ConfigurationId'] for s in rows(role,'InstSection'))),'load_connection_values':sorted(set(str(s['LoadConnectionPhaseGnd']) for s in rows(role,'InstSection')))}
with (ROOT/'field-completeness.csv').open('w',newline='',encoding='utf-8-sig') as f:
    writer=csv.writer(f)
    writer.writerow(['Table','Field','Generated rows','Generated NULL','Generated empty string','Reference rows','Reference NULL','Reference empty string','Generated distinct examples','Reference distinct examples'])
    for name,t in tables['generated'].items():
        for field in t['fields']:
            values = {role:[r[field['name']] for r in rows(role,name)] for role in documents}
            cells=[]
            for role in documents:
                v=values[role];cells += [len(v),sum(x is None for x in v),sum(x=='' for x in v)]
            examples=[json.dumps(sorted(set(json.dumps(x,ensure_ascii=False) for x in values[role]))[:8],ensure_ascii=False) for role in documents]
            writer.writerow([name,field['name'],*cells,*examples])
(ROOT/'extended-checks.json').write_text(json.dumps(extended,indent=2),encoding='utf-8')

g,r = comparison['generated'],comparison['reference']
lines = ['# Comparison of the two requested SynerGEE MDB files', '', 'Review date: 26 October 2026 (Asia/Karachi).', '',
'**Finding:** The generated file matches the approved reference schema and has populated geometry and consistent database references. It still lacks material electrical-model inputs. Its small file size is primarily explained by reclaimable Access storage in the approved file, demonstrated by compacting working copies.', '',
'## Exact files and method', '',
'The exact `(1).mdb` download was inspected afresh. Existing project reports concern other downloads and were not treated as evidence for this file. Both sources were opened read-only through the installed 32-bit DAO engine. All non-system tables and rows, captured field definitions, indexes, relationships, table-object metadata and saved-query definitions were read. No saved code or queries were executed. Source hashes remained unchanged. Only new report files and disposable working copies were created; no application source was edited.', '',
'| Role | File | Bytes | SHA-256 |','|---|---|---:|---|']
for item in storage[:2]:
    lines.append(f"| {item['role']} | {Path(item['path']).name} | {item['bytes']:,} | `{item['sha256']}` |")
lines += ['', 'The files represent different transformer networks. Different IDs, customer counts, lengths and equipment can be legitimate. The reference approval is the user-provided status; native approval was not reproduced.', '',
'## Why the generated file is smaller', '',
'| File | Original bytes | Compacted working copy bytes | Space reclaimed |','|---|---:|---:|---:|']
for item in storage:
    lines.append(f"| {item['role']} | {item['bytes']:,} | {item['compacted_bytes']:,} | {item['reclaimed_bytes']:,} |")
lines += ['',
'The original gap is **2,641,920 bytes (66.49% of the approved file)**. Compacting the approved working copy reclaims **2,629,632 bytes (66.19%)**, equivalent to **99.53% of that original gap**. Both compacted network files are exactly **1,343,488 bytes**. The generated copy grows by 12,288 bytes during repacking; compaction is a rewrite and is not guaranteed to shrink an already small database.', '',
'All rows in all 50 user tables, including duplicates and NULL values, were compared as row multisets before and after compaction and preserved in both copies. The approved-schema fingerprints and captured relationships also remained unchanged. This demonstrates that the approved file does not require its original 3.97 MB to retain its user data and schema.', '',
'The application writer copies the empty MDB template and inserts mapped records. The shipped template is 1,310,720 bytes; this export is only 20,480 bytes larger. Both networks have few live records and most optional tables are empty. There are also missing electrical values in the export, but those omissions do not explain the approximately 2.64 MB original size gap. Increasing or padding the MDB would not make the electrical model complete.', '',
'Microsoft explains that Access compaction removes unused space rather than compressing the data: [Compact and repair a database](https://support.microsoft.com/en-gb/access/compact-and-repair-a-database?nochrome=true). The experiment used [DAO CompactDatabase](https://learn.microsoft.com/en-us/office/client-developer/access/desktop-database-reference/dbengine-compactdatabase-method-dao) on working copies only. The historical editing operations that created the excess allocation cannot be determined from this comparison.', '',
'## Database structure', '',
'| Check | Generated | Approved |','|---|---:|---:|',
'| User tables | 50 | 50 |','| Fields | 1,065 | 1,065 |','| Indexes | 133 | 133 |','| Relationships | 41 | 41 |','| Saved query definitions | 0 | 0 |','| DAO database version | 4.0 | 4.0 |', '',
'Both pass the existing canonical schema checker, with fingerprint `a2659f32f858dbc0dc25ab75756e7f6d117cd9216a4a8839d938cb014c5bc4cf`. Captured field types, sizes, required flags, zero-length flags, defaults, validation rules, attributes, index definitions and relationships match. Canonical checking also includes index-field attributes and rejects linked tables. Both have 59 table objects including 9 system objects; the user-table contents were fully compared/profiled, while system-table contents and Access UI objects were outside scope.', '',
'## Network and geometry', '',
'| Item | Generated | Approved |','|---|---:|---:|']
for label,name in [('Nodes','Node'),('Sections','InstSection'),('Feeders','InstFeeders'),('Primary transformers','InstPrimaryTransformers'),('Load records','Loads'),('Meter records','InstMeterPoints'),('Distributed generators','InstDGens')]:
    lines.append(f"| {label} | {g['row_counts'][name]} | {r['row_counts'][name]} |")
for label,gv,rv in [('Reachable nodes',g['network']['reachable_nodes'],r['network']['reachable_nodes']),('Nodes missing X or Y',0,0),('Sections missing length',0,0),('Total section length (m)',round(extended['generated']['length_total'],3),round(extended['reference']['length_total'],3))]:
    lines.append(f'| {label} | {gv} | {rv} |')
lines += ['',
'All 14 generated nodes are reachable through undirected section connections; its 14-node/13-edge graph is a tree. Neither file has unknown section endpoints or feeder references, self-loops, duplicate undirected edges or orphan loads. All inspected fully populated relationship keys resolve; no duplicate fully populated unique-index keys were found. Blank or partially blank relationship/index keys were excluded from those checks. The reference has one isolated unused node and 25 reachable nodes; all its sections are reachable.', '',
'All coordinates and lengths are populated. No stored section length is more than 0.1 m below the straight-line distance between endpoints. The generated network has two zero-length links with coincident endpoints: the transformer link `sac3694570168772e1b2264107f0999`, and survey link `s51f353d9d3292bf0998cb8d95af3e8`. The exporter deliberately creates the first as a logical terminal when source mapping is absent in Simple mode. Confirm the second against the survey. These checks do not establish correct CRS, physical route, phase continuity, voltage domains or device orientation.', '',
'## Electrical completeness and material differences', '',
'| Item | Generated | Approved reference | Interpretation |','|---|---|---|---|',
'| Transformer type | Empty string | `200 KVA` | Generated equipment-library identity is missing. Select the correct type for its actual transformer. |',
'| Impedance mode | `UseInstanceImpedance=0` | `UseInstanceImpedance=0` | Library resolution must be demonstrated; a blank type leaves the equipment specification unresolved. |',
'| Phase kW/kvar/kVA | All NULL in all 10 load rows | All 23 rows have phase values; kVA cells total 518 | Generated demand is unspecified, not a verified zero load. |',
'| Phase customers | All NULL in all 10 load rows | Numeric phase cells sum to approximately 173 | Survey customer allocation has not been retained in these model fields. Load-row count is not customer count. |',
'| Section load connection | NULL on all 13 | Populated on all 24 | Confirm intended connection and SynerGEE interpretation. |',
'| Section phases | Present on all 13 | Present on all 24 | Generated logical transformer link is RYB; remaining survey links are RYBN. |',
'| Conductors | Logical transformer link has no phase conductors; survey links have active-phase conductors | One active neutral reference is blank | Device-bearing link behavior and equipment references need native review; a zero-length device link is not automatically a missing physical line. |',
'| Construction configuration | Present on all sections | Present on all sections | Both use `12.5/7.2 kV cross arm C2-2`; the common name does not verify suitability for the surveyed circuit. |',
'| Model-year labels | NULL | 2015–2025 | Reference-specific metadata; do not copy automatically. |',
'| Meter record | None | One, with non-key fields NULL | Requirement depends on intended model. |', '',
'Reference load kW and kvar cells are populated with zeros, while its kVA values include nonzero demand. The 518 kVA sum is a raw sum of phase load cells, not a solved feeder demand or transformer loading result. The reference figures must not be copied into the new network.', '',
f"Generated conductor strings: `{extended['generated']['conductor_codes']}`. Reference strings: `{extended['reference']['conductor_codes']}`. Leading spaces are preserved in the evidence and must be checked against the actual library.", '',
'Both control records identify `SynerGEE Electric 5.0.0.324`, Metric, R/Y/B phases and `WGS 1984 UTM Zone 43N`. Both store frequency 60, feeder nominal voltage 11 kV, bus voltage 11.37, transformer connections YG/YG, `NomKvMode=C` and `SpecNomKv=4.16`. These are matching recorded settings, not proof they are correct for either physical installation. Confirm frequency, voltage interpretation, winding connections and orientation with the responsible engineer and installed library.', '',
'## Application behavior explaining the gaps', '',
'`scripts/mdb/export-reviewed-mdb.ps1` copies the template, retains its schema, writes mapped rows and explicitly writes omitted fields as NULL (or allowed empty text for required text fields). It reopens the MDB and checks schema, values, counts and essential references. Its output itself states that SynerGEE engineering validation remains pending.', '',
'`app/Services/Mdb/ExportPayloadBuilder.php:324` explicitly blanks all phase customer/kW/kvar/kVA fields when Simple mode has no phase-demand values. Its logical-terminal construction starts at line 112. `NetworkValidator.php:24` downgrades listed missing-engineering issues to warnings in Simple mode. These code paths explain how a structurally valid but electrically incomplete export is possible. The MDB alone does not prove which historical runtime options were active.', '',
'## Actions supported by the findings', '',
'1. Map the actual transformer to its reviewed equipment-library definition; confirm voltage ratio, connections, impedance and orientation.',
'2. Supply reviewed phase demand and customer allocation using documented measurements or an approved estimation method.',
'3. Verify section load connections, conductor and construction references, and the two zero-length links.',
'4. Regenerate from reviewed inputs and rerun completeness checks.',
'5. Open a copy in the target SynerGEE installation, resolve equipment references, inspect the map, run native model checks and load flow, and review warnings, voltages, loading and losses.', '',
'**Assessment:** schema and basic database/network integrity pass; geometry is populated; material electrical inputs remain unresolved; native SynerGEE acceptance and solver convergence were not tested. The small file size itself is not evidence of corruption or missing database structure.', '',
'## Evidence', '',
'- `schema-and-storage.json`: source identity, canonical schema and compacted-size experiment.',
'- `comparison.json`: relationship, unique-key, graph and completeness checks.',
'- `extended-checks.json`: independent schema comparison, compacted row preservation, lengths and equipment strings.',
'- `table-counts.csv`: all 50 user-table row counts.',
'- `field-completeness.csv`: NULL/empty-string counts and distinct examples for every one of the 1,065 columns.',
'- `*-inspection.json`: full user-table readbacks before and after compaction.',
'- `*-compacted.mdb` and `*-working-copy.mdb`: diagnostic copies only; they do not fill engineering gaps or represent a new approved export.', '']
(ROOT/'Comparison-report.md').write_text('\n'.join(lines),encoding='utf-8')
print(json.dumps({'schema_equal':schema_equal,'verification':verification,'lengths':{r:extended[r]['length_total'] for r in documents},'report':str(ROOT/'Comparison-report.md')},indent=2))
