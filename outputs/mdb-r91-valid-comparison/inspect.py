import json
from pathlib import Path
from collections import Counter,defaultdict
p=Path('outputs/mdb-r91-valid-comparison'); ds=[json.loads((p/f).read_text(encoding='utf-8-sig')) for f in ['generated.json','reference.json']]; a,b=[{t['name']:t for t in d['tables']} for d in ds]
print('SCHEMA differences',[n for n in a if a[n]['fields']!=b[n]['fields'] or a[n]['indexes']!=b[n]['indexes']]);print('relationships equal',ds[0]['relationships']==ds[1]['relationships'])
print('counts',[(n,a[n]['count'],b[n]['count']) for n in a if a[n]['count'] or b[n]['count']])
for label,t in [('generated',a),('approved',b)]:
 print(label)
 print('transformer', {k:t['InstPrimaryTransformers']['rows'][0][k] for k in ['UniqueDeviceId','TransformerType','ConnectedPhases','SpecNomKv','NomKvMode','HighSideConnectionCode','LowSideConnectionCode','UseInstanceImpedance']})
 print('feeder',{k:t['InstFeeders']['rows'][0][k] for k in ['FeederId','SubstationId','NominalKvll','BusVoltageLevel']})
 print('control',{k:t['SAI_Control']['rows'][0][k] for k in ['Frequency','LengthUnits','ProjectionFile','Product']})
 loads=t['Loads']['rows'];s=t['InstSection']['rows'];nodes=t['Node']['rows']
 print('load totals',{q:sum(r.get(f'Phase{i}{q}') or 0 for r in loads for i in [1,2,3]) for q in ['Customers','Kva','Kw','Kvar']})
 print('phase totals',{q:[sum(r.get(f'Phase{i}{q}') or 0 for r in loads) for i in [1,2,3]] for q in ['Customers','Kva']})
 print('zero loads',sum(all(not r.get(f'Phase{i}{q}') for i in [1,2,3] for q in ['Customers','Kva']) for r in loads),'loads per section',dict(Counter(Counter(r['SectionId'] for r in loads).values())))
 print('length',sum(r['SectionLength_MUL'] or 0 for r in s),'zeros',sum(r['SectionLength_MUL']==0 for r in s),'phases',dict(Counter(r['SectionPhases'] for r in s)))
 print('generation',t['InstDGens']['rows'],t['InstMymDGens']['rows'])
 print('meter',t['InstMeterPoints']['rows'])
 print('section first',json.dumps(s[:1]))
 print('load first',json.dumps(loads[:1]))
