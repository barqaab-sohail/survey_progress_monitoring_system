import collections
import json
from pathlib import Path

folder = Path(__file__).resolve().parent
before = json.loads((folder.parent / 'instfeeders-step-review/r101-after.json').read_text(encoding='utf-8-sig'))
after = json.loads((folder / 'normal-r101-final-inspection.json').read_text(encoding='utf-8-sig'))
old = {table['name']: table for table in before['tables']}
new = {table['name']: table for table in after['tables']}
assert old.keys() == new.keys()
for name in old:
    assert old[name]['fields'] == new[name]['fields'], name
    assert old[name]['indexes'] == new[name]['indexes'], name
assert before['relationships'] == after['relationships']
old_nodes = {row['NodeId']: row for row in old['Node']['rows']}
nodes = {row['NodeId']: row for row in new['Node']['rows']}
for key, row in old_nodes.items():
    assert nodes[key] == row, key
added = sorted(set(nodes) - set(old_nodes))
assert added == ['81026028.1', '81026028.2', '81026028.3'], added
for key in added:
    for axis in ['X', 'Y']:
        assert abs(nodes[key][axis] - (nodes['81026028'][axis] + 0.01)) < 1e-8
    assert nodes[key]['Description'] == ''
assert old['InstFeeders']['rows'] == new['InstFeeders']['rows']
sections = new['InstSection']['rows']
index = {row['SectionId']: row for row in sections}
feeders = {row['FeederId'] for row in new['InstFeeders']['rows']}
assert len(index) == len(sections) == 91
for row in sections:
    assert row['FromNodeId'] in nodes and row['ToNodeId'] in nodes and row['FeederId'] in feeders
    assert row['SectionId'].startswith('000407') and isinstance(row['SectionId'], str)
    assert row['ExpFactRepair'] == 0 and row['PositionCode'] == 0

dependent = ['Loads', 'InstPrimaryTransformers', 'InstMeterPoints', 'InstDGens', 'InstMymDGens']
def business_values(rows):
    return collections.Counter(json.dumps({key: value for key, value in row.items() if key != 'SectionId'}, sort_keys=True) for row in rows)

for name in dependent:
    assert business_values(old[name]['rows']) == business_values(new[name]['rows']), name
    for row in new[name]['rows']:
        assert row['SectionId'] in index
for name in set(old) - {'Node', 'InstSection', *dependent}:
    assert old[name]['rows'] == new[name]['rows'], name
children = [row for row in sections if row['Description'] == 'Secondary Pole' and row['ToNodeId'].rsplit('.', 1)[0] == row['FromNodeId']]
assert len(children) == len({row['ToNodeId'] for row in children}) == 77
pv = [row for row in children if row['FromNodeId'] == '81026028']
assert len(pv) == 3
assert {row['SectionPhases'] for row in pv} == {'R  N', ' Y N', '  BN'}
assert {row['SectionLength_MUL'] for row in pv} == {14.62}
recorded = [row for row in children if row['FromNodeId'] == '81026029']
assert len(recorded) == 14 and {row['SectionPhases'] for row in recorded} == {'R  N'}
for description, length in [('Feeder', None), ('Transformer', 0)]:
    assert next(row for row in sections if row['Description'] == description)['SectionLength_MUL'] == length
assert index['00040708081026027']['SectionLength_MUL'] == 10
report = {
    'nodes': len(nodes), 'sections': len(sections), 'old_nodes_preserved_exactly': len(old_nodes),
    'added_nodes': added, 'consumer_pv_connections': len(children), 'pv_phases': sorted(row['SectionPhases'] for row in pv),
    'no_orphans': True, 'schema_indexes_relationships_unchanged': True,
    'instfeeders_unchanged': True, 'later_table_business_values_unchanged': True,
    'fourteen_recorded_r_customers_preserved': True,
    'remaining_source_review': 'Saved substation header requires the literal PDF spelling; user data was not edited.',
}
(folder / 'final-comparison.json').write_text(json.dumps(report, indent=2), encoding='utf8')
print(json.dumps(report, indent=2))
