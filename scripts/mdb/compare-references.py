"""Compare inspect-reference.ps1 JSON exports without copying model rows into the report.

python scripts/mdb/compare-references.py --template template.json --output report.json reference1.json ...
"""
import argparse
from collections import Counter, defaultdict
import json
from pathlib import Path


def read(path):
    return json.loads(Path(path).read_text(encoding='utf-8-sig'))


def schema(document):
    tables = {}
    for table in document['tables']:
        tables[table['name']] = {
            'fields': sorted(table['fields'], key=lambda value: value['name']),
            'indexes': sorted(table['indexes'], key=lambda value: value['name']),
        }
    return tables


def compare(template, references):
    expected = schema(template)
    relationships = sorted(template['relationships'], key=lambda value: value['name'])
    result = {'template_sha256': template['sha256'], 'references': [], 'phase_codes': {},
              'equipment_references_observed_not_approved': {}, 'control_values_observed_not_defaults': {}}
    phases = Counter()
    equipment = defaultdict(set)
    controls = defaultdict(set)
    for document in references:
        actual = schema(document)
        tables = {table['name']: table for table in document['tables']}
        differences = sorted(name for name in expected.keys() | actual.keys() if expected.get(name) != actual.get(name))
        record = {'file': document['file'], 'sha256': document['sha256'],
                  'table_count': len(tables), 'relationship_count': len(document['relationships']),
                  'schema_differences': differences,
                  'relationships_match': sorted(document['relationships'], key=lambda value: value['name']) == relationships,
                  'populated_tables': {name: table['count'] for name, table in tables.items() if table['count']},
                  'orphan_devices': {}}
        sections = {row['SectionId'] for row in tables.get('InstSection', {}).get('rows', [])}
        generators = {row['SectionId'] for row in tables.get('InstDGens', {}).get('rows', [])}
        for name in ('InstMeterPoints', 'InstDGens', 'InstMymDGens'):
            parent = generators if name == 'InstMymDGens' else sections
            record['orphan_devices'][name] = sum(row['SectionId'] not in parent for row in tables.get(name, {}).get('rows', []))
        for row in tables.get('InstSection', {}).get('rows', []):
            phases[row['SectionPhases']] += 1
            for name in ('PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id', 'NeutralConductorId'):
                if row.get(name):
                    equipment['conductors'].add(row[name])
            if row.get('ConfigurationId'):
                equipment['configurations'].add(row['ConfigurationId'])
        for row in tables.get('InstPrimaryTransformers', {}).get('rows', []):
            if row.get('TransformerType'):
                equipment['transformers'].add(row['TransformerType'])
        for row in tables.get('SAI_Control', {}).get('rows', []):
            for name in ('Frequency', 'Product', 'LengthUnits', 'ProjectionFile'):
                controls[name].add(row.get(name))
        result['references'].append(record)
    result['phase_codes'] = dict(sorted(phases.items()))
    result['equipment_references_observed_not_approved'] = {name: sorted(values) for name, values in sorted(equipment.items())}
    result['control_values_observed_not_defaults'] = {name: sorted(values, key=str) for name, values in sorted(controls.items())}
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--template', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('references', nargs='+')
    args = parser.parse_args()
    output = Path(args.output).resolve()
    inputs = [Path(path).resolve() for path in [args.template, *args.references]]
    if output in inputs or output.suffix.lower() != '.json':
        parser.error('Output must be a separate JSON report.')
    report = compare(read(args.template), [read(path) for path in args.references])
    output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    print(f"Compared {len(report['references'])} reference MDBs; report: {output}")
