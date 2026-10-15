"""Prepare an explicitly incomplete, offline R&D payload. Never grants workflow approval."""
from pathlib import Path
import csv, hashlib, json, math
from pyproj import Transformer

OUT = Path(__file__).resolve().parent
ROOT = OUT.parent.parent
def read(name): return json.loads((OUT/name).read_text(encoding='utf-8-sig'))
def write(name, data): (OUT/name).write_text(json.dumps(data,indent=2),encoding='utf-8')
source = read('source-inspection.json')
registered = {t['transformer_code']:t for t in read('application-kmz-inspection.json')['transformers']}
gpx = {p['name']:p for p in source['gpx']['waypoints']}
projection = Transformer.from_crs(4326,32643,always_xy=True)
def xy(p):return projection.transform(p['longitude'],p['latitude'])
def distance(a,b):return math.dist(xy(a),xy(b))

matches=[]
for code,capacity,pages,first in [
 ('T-11131222104',100,[1,2],'001'),('T-11230617182',200,[3,4,5,6],'021'),
 ('T-11131222107',200,[7,8,9,10],'057'),('T-11030717217',100,[11],'091'),
 ('T-11030717223',200,[12,13],'101')]:
 t=registered[code]; nearest=min(gpx.values(),key=lambda p:distance(t,p))
 suffix=code[-3:]
 matches.append({'transformer_code':code,'pdf_pages':pages,'pdf_capacity_kva':capacity,
  'kmz_capacity_kva':t['capacity_kva'],'capacity_matches':capacity==t['capacity_kva'],
  'historical_identity':code[2:],'latitude':t['latitude'],'longitude':t['longitude'],
  'gpx_same_suffix_present':suffix in gpx,
  'same_suffix_distance_m':round(distance(t,gpx[suffix]),2) if suffix in gpx else None,
  'nearest_gpx':nearest['name'],'nearest_gpx_distance_m':round(distance(t,nearest),2),
  'source':'exact full equipment number in client KMZ; PDF header visually read'})
write('transformer-matches.json',matches)

# Page 11 S/E pairs read visually; ditto arrows apply only inside their stated branch.
# Consumer blanks are unknown, not zeros. Overwritten SC at 091 is left unresolved.
pairs=[('217','091','RYBN'),('091','092','RYBN'),('092','093','  BN'),
 ('091','094','RYBN'),('094','095','RYBN'),('095','096','RYBN'),
 ('096','097','RYBN'),('097','098','RYBN'),('098','099',' YBN'),('099','100',' YBN')]
counts={'091':{'RS':1,'SC':None},'092':{'RS':1,'SC':5},'093':{'RS':4,'SC':7},
 '094':{'RS':5,'SC':4,'LC':1},'095':{'RS':12,'SC':1},'096':{'RS':9,'SC':1},
 '097':{'RS':9},'098':{'RS':2},'099':{'RS':3},'100':{'RS':8}}
target=registered['T-11030717217']
def node_id(wp):return '11030717217' if wp=='217' else '01081026'+wp
coordinates={node_id('217'):target}|{node_id(f'{n:03}'):gpx[f'{n:03}'] for n in range(91,101)}
feeder='RD_EQUIV_SOURCE_000407'
tables={n:[] for n in ['SAI_Control','Node','InstFeeders','InstSection','InstPrimaryTransformers','Loads']}
tables['SAI_Control']=[{'LengthUnits':'Metric','Phase1':'R','Phase2':'Y','Phase3':'B',
 'Product':'SynerGEE Electric 5.0.0.324','ProjectionFile':'WGS 1984 UTM Zone 43N'}]
for identity,p in coordinates.items():
 x,y=xy(p)
 tables['Node'].append({'NodeId':identity,'X':x,'Y':y,'Description':
  ('R&D KMZ transformer root '+identity if identity=='11030717217' else 'R&D GPX 2026-10-08 '+p['name'])})
x,y=xy(target)
tables['Node'].append({'NodeId':feeder,'X':x,'Y':y,'Description':'R&D equivalent supply at transformer; NOT actual substation location'})
tables['InstFeeders']=[{'FeederId':feeder,'SubstationId':'Abbottabad','NominalKvll':11,
 'Note_':'R&D incomplete equivalent supply. 11kV from KMZ; no approved source electrical model.'}]
supply='RD_TRANSFORMER_LINK'
tables['InstSection'].append({'SectionId':supply,'FeederId':feeder,'FromNodeId':feeder,
 'ToNodeId':'11030717217','SectionPhases':'RYB','SectionLength_MUL':0,
 'Description':'R&D co-located transformer link; artificial topology, not a measured HT span'})
tables['InstPrimaryTransformers']=[{'SectionId':supply,'UniqueDeviceId':'T-11030717217',
 'ConnectedPhases':'RYB','TransformerType':'R&D-UNMAPPED-100KVA',
 'Note_':'100kVA verified PDF/KMZ; placeholder type is NOT an approved equipment library reference.'}]
transcription=[]
for i,(start,end,phases) in enumerate(pairs,1):
 identity=f'RD_P11_{i:02}'
 length=distance(coordinates[node_id(start)],coordinates[node_id(end)])
 tables['InstSection'].append({'SectionId':identity,'FeederId':feeder,
  'FromNodeId':node_id(start),'ToNodeId':node_id(end),'SectionPhases':phases,
  'SectionLength_MUL':length,'Description':f'R&D PDF p11 pair {i}, {start}->{end}; straight endpoint estimate'})
 description=f'R&D UNALLOCATED p11 WP {end}; '+', '.join(f'{k}={v if v is not None else "?"}' for k,v in counts[end].items())
 tables['Loads'].append({'SectionId':identity,'Description':description})
 transcription.append({'page':11,'pair':i,'section_id':identity,'start_identity':node_id(start),
  'end_identity':node_id(end),'phase_code_interpreted':phases,'consumer_counts_read':counts[end],
  'unlisted_consumer_categories':'blank/unknown; not confirmed zero',
  'conductor_marks':{'R':'-','Y':'-','B':'A','N':'GSL?'} if i==3 else
    {'R':'-' if i>=9 else 'A','Y':'A','B':'A','N':'A'},
  'remarks':'Half span Gnat; neutral GSL? needs confirmation' if i==3 else '',
  'endpoint_estimated_length_m':length,'confirmed_by_project_verifier':False})
write('sample-transcription.json',{'source_pdf':'A081026.pdf','page':11,'transformer_code':'T-11030717217',
 'capacity_kva':100,'survey_date':'2026-10-08','group':'01','historical_root_group':'11',
 'historical_root_date':'2017-07-03','review_status':'independent R&D reading; NOT operator/verifier approval',
 'ambiguities':['WP091 small commercial overwritten digit looks like 3; numeric value left null',
 '092->093 neutral conductor GSL? and half-span Gnat need clarification',
 'Ditto arrows interpreted within branch only; blank consumer categories remain unknown'], 'sections':transcription})
template=ROOT/'resources/mdb/synergee-empty.mdb'
revision_hash=hashlib.sha256((OUT/'sample-transcription.json').read_bytes()).hexdigest()
payload={'payload_version':1,'mapping_version':'synergee-reviewed-v1',
 'idempotency_key':hashlib.sha256(b'HAZECO offline R&D page11 sample v1').hexdigest(),
 'revision':{'id':None,'number':None,'sha256':revision_hash},
 'template':{'sha256':hashlib.sha256(template.read_bytes()).hexdigest(),'permitted_static_tables':[]},
 'engineering_acceptance':'INCOMPLETE_R&D_NOT_FOR_ANALYSIS_OR_CLIENT_DELIVERY',
 'workflow_approval':'NONE: offline low-level writer experiment; no application revision created or approved',
 'tables':tables,'settings':{'epsg':32643,'projection_basis':'R&D metric projection of supplied WGS84 coordinates'},
 'assumptions':['Artificial co-located source node and zero-length transformer link; not HT network reconstruction',
 'TransformerType required by schema: deliberately unmapped placeholder; 100kVA comes from PDF/KMZ',
 '10 Loads rows carry transcription descriptions only: all phase customers, kW, kvar and kVA are NULL',
 'Conductor library, configuration, frequency, voltage operating settings, impedance and connections are NULL',
 'No InstMeterPoints or PV generator records; this sample is limited to page 11',
 'Metric endpoint estimates are not measured wire length'],
 'sources':read('source-manifest.json')}
write('sample-input.json',payload)
with (OUT/'sample-sections.csv').open('w',newline='',encoding='utf-8-sig') as f:
 writer=csv.DictWriter(f,fieldnames=['section_id','start_identity','end_identity','phase_code_interpreted','endpoint_estimated_length_m'])
 writer.writeheader();writer.writerows({k:s[k] for k in writer.fieldnames} for s in transcription)
print(json.dumps({'transformer_matches':matches,'sample_counts':{k:len(v) for k,v in tables.items()},
 'sample_LT_length_m':sum(s['endpoint_estimated_length_m'] for s in transcription)},indent=2))
