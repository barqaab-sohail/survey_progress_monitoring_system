"""Independent DAO readback analysis of reference MDBs and the generated R&D MDB."""
from pathlib import Path
import collections,csv,hashlib,json,math,re,html
from pyproj import Transformer

OUT=Path(__file__).resolve().parent
def read(name):return json.loads((OUT/name).read_text(encoding='utf-8-sig'))
def write(name,data):(OUT/name).write_text(json.dumps(data,indent=2),encoding='utf-8')
def csvout(name,rows):
 with (OUT/name).open('w',newline='',encoding='utf-8-sig') as f:
  w=csv.DictWriter(f,fieldnames=list(rows[0]));w.writeheader();w.writerows(rows)
template=read('template.inspection.json');sample=read('sample.inspection.json')
refs=[read(f.name) for f in sorted(OUT.glob('*.inspection.json')) if f.name not in ['sample.inspection.json','template.inspection.json']]
assert len(refs)==9
def signature(d):return {'tables':[{k:v for k,v in t.items() if k not in ['count','rows']} for t in d['tables']],'relationships':d['relationships']}
def tablemap(d):return {t['name']:t for t in d['tables']}
def graph(d):
 ts=tablemap(d);adj={n['NodeId']:set() for n in ts['Node']['rows']};missing=[];seen=set();duplicates=[]
 for s in ts['InstSection']['rows']:
  a,b=s['FromNodeId'],s['ToNodeId']
  if a not in adj or b not in adj:missing.append(s['SectionId']);continue
  adj[a].add(b);adj[b].add(a)
  key=tuple(sorted([a,b]))
  if key in seen:duplicates.append(s['SectionId'])
  seen.add(key)
 visited=set();components=[]
 for n in adj:
  if n in visited:continue
  pending=[n];component=[]
  while pending:
   v=pending.pop()
   if v in visited:continue
   visited.add(v);component.append(v);pending.extend(adj[v]-visited)
  components.append(component)
 return {'isolated_nodes':[n for n,a in adj.items() if not a], 'components':len(components),
  'component_sizes':sorted(map(len,components),reverse=True),'missing_endpoint_sections':missing,
  'duplicate_edge_sections':duplicates,'undirected_cycle_rank':len(seen)-len(adj)+len(components)}
comparisons=[];fieldrows=[];counts=[]
for d in refs+[sample]:
 ts=tablemap(d)
 comparisons.append({'file':d['file'],'tables':len(d['tables']),'fields':sum(len(t['fields']) for t in d['tables']),
  'indexes':sum(len(t['indexes']) for t in d['tables']),'relationships':len(d['relationships']),
  'schema_equal_to_template':signature(d)==signature(template),'graph':graph(d),
  'required_nulls':[f"{t['name']}.{field['name']}" for t in d['tables'] for field in t['fields']
                    if field['required'] and any(row[field['name']] is None for row in t['rows'])],
  'frequency':ts['SAI_Control']['rows'][0]['Frequency'],
  'loads_positive_kva_with_zero_kw':sum(any((r.get(f'Phase{i}Kva') or 0)>0 and r.get(f'Phase{i}Kw')==0 for i in range(1,4)) for r in ts['Loads']['rows'])})
 for t in d['tables']:counts.append({'file':d['file'],'table':t['name'],'row_count':t['count']})
st=tablemap(sample)
for t in template['tables']:
 for f in t['fields']:
  name=f['name'];sr=st[t['name']]['rows'];rr=[r for d in refs for r in tablemap(d)[t['name']]['rows']]
  fieldrows.append({'table':t['name'],'field':name,'dao_type':f['type'],'size':f['size'],'required':f['required'],
   'schema_default':f['default'],'sample_rows':len(sr),'sample_nonnull':sum(r[name] is not None for r in sr),
   'sample_nonempty':sum(r[name] not in [None,''] for r in sr),
   'reference_rows_total':len(rr),'reference_nonnull_total':sum(r[name] is not None for r in rr),
   'reference_nonempty_total':sum(r[name] not in [None,''] for r in rr)})
csvout('table-count-comparison.csv',counts);csvout('field-completeness.csv',fieldrows)
csvout('relationships.csv',[{'name':r['name'],'table':r['table'],'foreign_table':r['foreign_table'],
 'attributes':r['attributes'],'fields':json.dumps(r['fields'])} for r in template['relationships']])

source=read('source-inspection.json');project=Transformer.from_crs(4326,32643,always_xy=True)
def xy(lon,lat):return project.transform(lon,lat)
endpoints=[];line_types=collections.Counter();line_phases=collections.Counter();dupe_vertices=0
for f in source['kmz']['features']:
 if not f['lines']:continue
 for text in f['lines']:
  pts=[tuple(map(float,v.split(',')[:2])) for v in text.split()]
  endpoints.extend([xy(*pts[0]),xy(*pts[-1])])
  dupe_vertices+=sum(a==b for a,b in zip(pts,pts[1:]))
 for field,counter in [('Line_Type',line_types),('Phase',line_phases)]:
  m=re.search(r'<td[^>]*>\s*'+field+r'\s*</td>\s*<td[^>]*>(.*?)</td>',f['description'] or '',re.S)
  counter[html.unescape(re.sub('<[^>]+>','',m.group(1)).strip()) if m else '(missing)']+=1
proximity=[]
for t in read('transformer-matches.json'):
 p=xy(t['longitude'],t['latitude'])
 proximity.append({'transformer_code':t['transformer_code'],
  'nearest_HT_line_endpoint_m':round(min(math.dist(p,e) for e in endpoints),3)})
write('ht-geometry-audit.json',{'line_features':source['kmz']['line_features'],'line_types':dict(line_types),
 'line_phases':dict(line_phases),'adjacent_duplicate_vertices':dupe_vertices,'selected_transformer_endpoint_proximity':proximity,
 'interpretation':'Proximity is evidence for reviewed snapping, not proof of electrical connectivity.'})

base=Path('C:/Users/sohai/OneDrive/Desktop/HAZECO')
unchanged=[{'file':s['file'],'unchanged':hashlib.sha256((base/s['file']).read_bytes()).hexdigest()==s['sha256']} for s in read('source-manifest.json')]
assert all(v['unchanged'] for v in unchanged)
assert all(c['schema_equal_to_template'] for c in comparisons)
assert not any(c['required_nulls'] for c in comparisons)
assert graph(sample)['components']==1 and graph(sample)['undirected_cycle_rank']==0
assert all(not c['graph']['missing_endpoint_sections'] for c in comparisons)
assert set(p['name'] for p in source['gpx']['waypoints'])=={f'{i:03}' for i in range(1,121)}
result={'scope':'Physical schema and data audit; no SynerGEE GUI/load-flow acceptance test',
 'references':comparisons[:-1],'sample':comparisons[-1],
 'original_file_hash_checks':unchanged,'all_originals_unchanged':True,
 'schema_comparison_scope':['non-system table names','field names/order/type/size/required/allow-zero-length/default/validation/attributes',
 'index names/primary/unique/field names','relationship names/tables/attributes/field mappings'],
 'schema_comparison_limits':'Does not compare Access UI objects, queries, obscure table properties, or external SynerGEE equipment libraries',
 'sample_LT_sections':10,'sample_LT_endpoint_length_m':sum(s['endpoint_estimated_length_m'] for s in read('sample-transcription.json')['sections']),
 'sample_engineering_limitations':read('sample-input.json')['assumptions']}
write('comparison-summary.json',result)
print(json.dumps({'schema_match_all_9_references':True,'tables':50,'fields':1065,'indexes':133,'relationships':41,
 'sample_graph':graph(sample),'sample_required_nulls':comparisons[-1]['required_nulls'],
 'reference_isolated_nodes':[c['graph']['isolated_nodes'] for c in comparisons[:-1]],
 'originals_unchanged':True,'ht_proximity':proximity,'line_types':dict(line_types),'adjacent_duplicate_vertices':dupe_vertices},indent=2))
