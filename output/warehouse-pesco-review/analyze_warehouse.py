import json
import gzip
from pathlib import Path

root = Path(__file__).resolve().parent
inspection_path = root / 'warehouse-inspection.json'
if inspection_path.exists():
    warehouse = json.loads(inspection_path.read_text(encoding='utf-8-sig'))
else:
    with gzip.open(root / 'warehouse-inspection.json.gz', 'rt', encoding='utf-8-sig') as source:
        warehouse = json.load(source)
print('SHA256:', warehouse['sha256'])
for table in warehouse['tables']:
    print(f"{table['name']}: {table['count']} rows; " + ', '.join(f['name'] for f in table['fields']))
print('RELATIONSHIPS:', json.dumps(warehouse['relationships']))
summary = {k: v for k, v in warehouse.items() if k != 'tables'}
summary['tables'] = [{k: v for k, v in t.items() if k != 'rows'} | {'samples': t['rows'][:2]} for t in warehouse['tables']]
(root / 'warehouse-summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')

tables = {t['name']: t for t in warehouse['tables']}
focus = {
    'SAI_Equ_Control': None,
    'DevTransformers': ['TransformerName', 'TransformerRatedKva', 'HighSideRatedKv', 'LowSideRatedKv', 'PercentImpedance', 'PercentResistance', 'NoLoadLosses', 'HighVoltageConnectionCode', 'LowVoltageConnectionCode', 'Description'],
    'DevConductors': ['ConductorName', 'ActualImpedance', 'PosSequenceResistance_PerLUL', 'PosSequenceReactance_PerLUL', 'ContinuousCurrentRating', 'ConductorType', 'Description'],
    'DevConfig': ['ConfigName', 'Position1_X_MUL', 'Position1_Y_MUL', 'Neutral_X_MUL', 'Neutral_Y_MUL'],
    'DevCustClass': ['Class', 'PercentConstImpedance', 'PercentConstCurrent', 'Description'],
    'DevGenerators': ['GeneratorName', 'GeneratorType', 'KwRating', 'KvRating', 'Description'],
}
catalog = {}
for name, fields in focus.items():
    rows = tables[name]['rows']
    catalog[name] = [{k: v for k, v in r.items() if fields is None or k in fields} for r in rows]
(root / 'key-equipment.json').write_text(json.dumps(catalog, indent=2), encoding='utf-8')
print('CONTROL', json.dumps(catalog['SAI_Equ_Control']))
print('TRANSFORMERS', json.dumps(catalog['DevTransformers']))
print('CONDUCTOR IDS', json.dumps([r['ConductorName'] for r in catalog['DevConductors']]))
print('CONFIGURATION IDS', json.dumps([r['ConfigName'] for r in catalog['DevConfig']]))
print('CUSTOMER CLASSES', json.dumps(catalog['DevCustClass']))
print('GENERATOR IDS', json.dumps(catalog['DevGenerators']))

app_root = root.parent.parent
defaults = json.loads((app_root / 'resources/mdb/engineering-defaults.json').read_text(encoding='utf-8-sig'))
references = defaults['configuration']['equipment_references']
id_fields = {'conductors': ('DevConductors', 'ConductorName'), 'transformers': ('DevTransformers', 'TransformerName'), 'configurations': ('DevConfig', 'ConfigName')}
comparison = {}
for kind, (table, field) in id_fields.items():
    ids = [r[field] for r in tables[table]['rows']]
    comparison[kind] = {'count': len(ids), 'unique_ids': len(set(ids)), 'existing_reference_matches': [v for v in references[kind] if v in ids], 'existing_reference_missing': [v for v in references[kind] if v not in ids]}
comparison['printed_conductor_options'] = {v: v in {r['ConductorName'] for r in tables['DevConductors']['rows']} for v in ['ANT', 'WASP', 'GNAT', '2/0 AWG', 'PVC 7/0.052', 'PVC 19/0.052', 'PVC 19/0.083', 'PVC 0.083', 'PVC 37/0.083', 'ABC 50mm2', 'ABC 95mm2']}
comparison['units'] = catalog['SAI_Equ_Control'][0]
comparison['candidate_11kv_415v_transformers'] = [r for r in catalog['DevTransformers'] if r['HighSideRatedKv'] == 11 and r['LowSideRatedKv'] == 0.415]
comparison['table_count'] = len(tables)
comparison['total_rows'] = sum(t['count'] for t in tables.values())
comparison['user_relationship_count'] = sum(not r['table'].startswith('MSys') for r in warehouse['relationships'])
(root / 'compatibility-review.json').write_text(json.dumps(comparison, indent=2), encoding='utf-8')
print('COMPATIBILITY', json.dumps({k: v for k, v in comparison.items() if k not in ['candidate_11kv_415v_transformers', 'units']}))
