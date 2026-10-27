import json
from pathlib import Path
p=Path('outputs/mdb-r91-comparison')
a,b=[json.loads((p/f).read_text(encoding='utf-8-sig')) for f in ['generated.json','reference.json']]
ta,tb=[{t['name']:t for t in d['tables']} for d in [a,b]]
print('TABLE COUNTS',[(n,ta[n]['count'],tb[n]['count']) for n in ta if ta[n]['count'] or tb[n]['count']])
print('SCHEMA DIFFERENCES', [n for n in ta if ta[n]['fields']!=tb[n]['fields'] or ta[n]['indexes']!=tb[n]['indexes']])
print('RELATIONSHIPS EQUAL',a['relationships']==b['relationships'])
for n in ['SAI_Control','InstFeeders','InstPrimaryTransformers']:
 print('\n'+n)
 for tag,ts in [('GENERATED',ta),('REFERENCE',tb)]:
  print(tag,json.dumps(ts[n]['rows'],ensure_ascii=False))
for n in ['InstSection','Loads','Node']:
 print('\n'+n)
 for tag,ts in [('GENERATED',ta),('REFERENCE',tb)]:
  print(tag,'FIRST',json.dumps(ts[n]['rows'][:2],ensure_ascii=False))
  if n=='Loads':
   print('TOTALS',{q:sum(r.get('Phase'+str(i)+q) or 0 for r in ts[n]['rows'] for i in [1,2,3]) for q in ['Customers','Kva','Kw','Kvar']})
   print('NULLS',{q:sum(r.get('Phase'+str(i)+q) is None for r in ts[n]['rows'] for i in [1,2,3]) for q in ['Customers','Kva','Kw','Kvar']})
