"""Build a source-backed equipment crosswalk, preserving unresolved mappings explicitly."""
from pathlib import Path
import csv,hashlib,json,xml.etree.ElementTree as ET,collections

BASE=Path(__file__).resolve().parent
OUT=BASE/'equipment-mappings';OUT.mkdir(exist_ok=True)
SOURCE=Path('C:/Users/sohai/OneDrive/Desktop/HAZECO')
manifest=json.loads((BASE/'source-manifest.json').read_text(encoding='utf-8-sig'))
for s in manifest:
 assert hashlib.sha256((SOURCE/s['file']).read_bytes()).hexdigest()==s['sha256']
profile=json.loads((BASE/'electrical-settings/reference-profile.json').read_text(encoding='utf-8'))
root=ET.parse(SOURCE/'Waypoints_2026-10-08.gpx').getroot()
gpx={p.findtext('{*}name'):{'waypoint':p.findtext('{*}name'),'latitude':float(p.attrib['lat']),
 'longitude':float(p.attrib['lon']),'elevation':p.findtext('{*}ele'),'time':p.findtext('{*}time'),
 'comment':p.findtext('{*}cmt'),'symbol':p.findtext('{*}sym')} for p in root.findall('{*}wpt')}
assert len(gpx)==120
def write(name,data):(OUT/name).write_text(json.dumps(data,indent=2,ensure_ascii=False),encoding='utf-8')
def csvwrite(name,rows):
 with (OUT/name).open('w',newline='',encoding='utf-8-sig') as f:
  w=csv.DictWriter(f,fieldnames=list(rows[0]));w.writeheader();w.writerows(rows)
def target_ids(canonical):return {column:[v for v in values if v.strip()==canonical]
 for column,values in profile['raw_conductor_values_by_column'].items()}
conductor_fields=['PhaseConductorId','PhaseConductor2Id','PhaseConductor3Id','NeutralConductorId']
crosswalk=[]
def add(kind,code,meaning,target,status,evidence,note='',fields=None):
 crosswalk.append({'kind':kind,'pdf_code_or_value':code,'source_meaning':meaning,
 'mdb_target_table':'InstSection' if kind in ['conductor','construction'] else 'InstPrimaryTransformers' if kind=='transformer' else None,
 'mdb_fields':fields or (conductor_fields if kind=='conductor' else ['TransformerType'] if kind=='transformer' else []),
 'target_reference':target,'reference_exact_conductor_ids':target_ids(target) if kind=='conductor' and target else {},
 'status':status,'evidence':evidence,'notes':note})
add('conductor','A','Ant','ANT','reference_backed_candidate',
 'PDF printed legend; handwritten A e.g. pages 1 and 11; reference MDB conductor IDs',
 'Choose the exact library ID; preserve phase-Y leading spaces when matching the reference implementation.')
add('conductor','W','Wasp','WASP','reference_backed_candidate',
 'PDF printed legend; handwritten W e.g. pages 3, 7 and 12; reference MDB conductor IDs')
add('conductor','GN','Gnat','GNAT','reference_backed_candidate',
 'PDF printed legend; handwritten GN e.g. pages 7 and 12; GNAT exists in reference MDB B/neutral fields',
 'Exact GNAT IDs were not observed in the reference R or Y column. Confirm use in the target library rather than manufacture a spaced ID.')
for mark in ['PVC 7/0.052','PVC 19/0.052','PVC 19/0.083','PVC 7/0.083']:
 add('conductor',mark,'PVC conductor designation',None,'unmatched_in_supplied_mdbs',
  'PDF printed legend; no PVC conductor ID in the nine reference MDBs',
  'Legend option only; this record does not assert an installed occurrence. Match exact designation to a target-library entry.')
add('conductor','PVC 37/0.083?','Handwritten PVC designation',None,'unmatched_and_transcription_review',
 'PDF pages 7 (063->064) and 8 (065->066); handwritten reading appears 37/0.083',
 'Preserve the uncertain reading. The legend does not resolve this automatically; do not substitute ABC 50 MM2 or GNAT.')
add('conductor','ABC 50 mm2','ABC cable option in legend','ABC 50 MM2','legend_reference_backed_candidate',
 'PDF printed legend; exact ABC 50 MM2 reference MDB ID',
 'No installed ABC occurrence is asserted by this crosswalk.')
add('conductor','ABC 95 mm2','ABC cable option in legend',None,'unmatched_in_supplied_mdbs',
 'PDF printed legend; reference MDBs contain ABC 50 MM2 but no ABC 95 entry')
add('conductor','GSL?','Unclear handwritten neutral conductor',None,'unresolved_transcription',
 'PDF page 11 span 092->093; remark Half span Gnat',
 'Do not treat GSL? as GN automatically. Resolve mixed-material span and required segmentation.')
add('conductor','-','Conductor absent in that phase position',None,'absence_marker',
 'PDF pages 3, 8 and 11 have dashes for unused phases',
 'An absence is not a material. Use NULL only for the confirmed disconnected phase; preserve active-phase unknowns.')
for capacity in [100,200]:
 add('transformer',str(capacity)+' kVA',str(capacity)+' kVA surveyed transformer',str(capacity)+' KVA',
  'reference_backed_candidate','PDF transformer headers; same TransformerType text in reference MDBs',
  'Capacity supplies a candidate type name, not make-specific impedance, connection, tap or loss specifications.')
for code,meaning in [('S','Steel Structure'),('PCO','PC Ordinary'),('PCS','PC Spun'),('RS','Rail Steel'),('TS','Tubular Steel'),('WB','Wall Bracket')]:
 add('pole_class',code,meaning,None,'source_attribute_preserved',
  'PDF printed equipment/pole-class legend',
  'Preserve source pole_class; no exact SynerGEE construction ID can be derived from this abbreviation alone.')
for code in ['TR','SP']:
 add('equipment_type',code,'Raw handwritten equipment-type code',None,'source_attribute_preserved',
  'PDF Type column; TR on transformer-root rows, SP on surveyed support rows',
  'Preserve raw type. Do not confuse SP in the Type column with PC Spun/PCS in Pole Class.')
add('construction','Pole class + height','Survey construction observations',
 '12.5/7.2 kV cross arm C2-2','reference_configuration_candidate_only',
 'This ConfigurationId is used by all 187 sections in the valid reference MDBs',
 'The PDF/GPX do not specify full cross-arm geometry or prove this same configuration applies to every new span.',
 ['ConfigurationId'])
write('equipment-crosswalk.json',{'status':'EXTRACTED_CANDIDATES_NOT_APPLIED_TO_APPLICATION',
 'sources':['A081026.pdf','Waypoints_2026-10-08.gpx','nine reference MDB inspections'],
 'mapping_scope':'equipment vocabulary plus selected example bindings; not a complete transcription of every survey row',
 'gpx_role':'Coordinates, elevation, timestamps, labels/comments/symbols; no electrical equipment definitions',
 'crosswalk':crosswalk})
csvwrite('equipment-crosswalk.csv',[{'kind':r['kind'],'pdf_code_or_value':r['pdf_code_or_value'],
 'source_meaning':r['source_meaning'],'mdb_target_table':r['mdb_target_table'],
 'mdb_fields':'; '.join(r['mdb_fields']),'target_reference':r['target_reference'],
 'status':r['status'],'evidence':r['evidence'],'notes':r['notes']} for r in crosswalk])
csvwrite('gpx-waypoints.csv',list(gpx.values()))

# These selected examples were visually read, not produced by unreliable handwritten OCR.
examples=[]
for page,wp,material,pole_class,height,method in [
 (1,'001','A','S',36,'First E row: conductor ditto marks refer to root S row'),
 (3,'018','W','S',36,'First E row: conductor/pole ditto marks refer to root S row'),
 (7,'056','W','S',36,'First E row: conductor ditto marks refer to root S row'),
 (8,'066','PVC 37/0.083?','TS',31,'First E row: incoming PVC designation read from S=065 row'),
 (11,'091','A','S',36,'First E row: incoming conductor inherited from root S row'),
 (12,'101','W','S',None,'First E row: incoming conductor ditto; handwritten pole-height entry unclear')]:
 p=gpx[wp]
 examples.append({'pdf':'A081026.pdf','pdf_page':page,'group':'01','survey_date':'2026-10-08',
 'gpx_name':wp,'complete_survey_identity':'01081026'+wp,'incoming_span_conductor_label':material,
 'candidate_conductor_id':{'A':'ANT','W':'WASP'}.get(material),
 'pole_class':pole_class,'pole_height_raw':height,'pole_height_unit':'not established by this extraction',
 'latitude':p['latitude'],'longitude':p['longitude'],'elevation':p['elevation'],
 'gpx_time':p['time'],'reading_method':method,
 'status':'independent reading; operator verification pending; not engineering approval'})
write('example-pdf-gpx-bindings.json',examples)
csvwrite('example-pdf-gpx-bindings.csv',examples)
write('gpx-field-inventory.json',{'waypoint_count':len(gpx),'child_fields':dict(collections.Counter(
 x.tag.rsplit('}',1)[-1] for pt in root.findall('{*}wpt') for x in pt)),
 'equipment_definition_fields_found':[], 'coordinate_attribute_fields':['lat','lon']})
report='''**Equipment mappings from the survey PDF, GPX and valid MDB references**

Equipment assignments can be extracted from the PDF and attached to the GPX coordinates. Exact MDB equipment-reference names come from the valid reference MDBs. These three sources have different roles: the PDF identifies surveyed equipment/materials and phases; the GPX locates the waypoint; the MDBs provide observed target-library names. The GPX does not provide conductor ratings, transformer parameters, pole construction definitions or equipment-library IDs.

The actual GPX has 120 waypoints with latitude/longitude, elevation, time, name, comment and symbol. Its comments are timestamp text and its symbols are generic waypoint symbols. It contains no equipment definitions. All original file hashes were verified before this extraction; no original or live application configuration was changed.

| Survey label | Meaning from source | Candidate MDB reference | Evidence/status |
|---|---|---|---|
| A | Ant | ANT | PDF legend + reference MDB names |
| W | Wasp | WASP | PDF legend + reference MDB names |
| GN | Gnat | GNAT | PDF legend + reference MDB names; exact per-column IDs need explicit library matching |
| 100 kVA | Transformer capacity | 100 KVA | PDF header + valid reference TransformerType |
| 200 kVA | Transformer capacity | 200 KVA | PDF header + valid reference TransformerType |
| ABC 50 mm2 | Cable option printed in legend | ABC 50 MM2 | Reference-backed legend option; no installed occurrence asserted |
| PVC variants | Insulated conductor designation | Unresolved | No PVC reference name in the supplied MDBs |
| GSL? | Unclear neutral mark on page 11 | Unresolved | Handwriting and mixed-conductor remark require review |

These are reference-backed candidate names, not new electrical specifications. Capacity alone does not establish that one transformer make has the same impedance/loss characteristics as another with the same rating. The target SynerGEE library must resolve the selected names.

**Construction and equipment observations**

The printed pole-class legend defines S=Steel Structure, PCO=PC Ordinary, PCS=PC Spun, RS=Rail Steel, TS=Tubular Steel and WB=Wall Bracket. Preserve these observations in pole_class and distinguish them from the raw Type codes TR and SP. Pole-height readings can also be preserved, with their unit established at project level.

The valid reference sections all use `12.5/7.2 kV cross arm C2-2` as their `ConfigurationId`. That provides a reusable construction candidate, but a pole class/height alone does not prove a matching cross-arm arrangement. Do not convert raw pole height directly into the MDB average-height field without the relevant unit and construction rules.

The full crosswalk distinguishes legend options from observed handwritten material marks. DOG is present in the reference inventory but is not asserted as an installed conductor in this PDF. ABC 95 and PVC legend entries have no target match in the supplied MDBs. Pages 7–8 have a handwritten PVC designation that appears to read `37/0.083`; keep that reading uncertain rather than silently replacing it with a different conductor. The page-11 092→093 neutral mark and “Half span Gnat” remark need a separate resolution.

**How to use this in the application**

1. Read/verify each PDF S/E pair and assign its complete survey identity and selected GPX source. Keep historical transformer-root identities separate from new same-number waypoint labels.
2. Save conductor marks per R/Y/B/neutral position, construction/pole observations and the transformer header. Apply ditto marks only within their verified span/branch context.
3. Use the extracted crosswalk to select ANT/WASP/GNAT and 100 KVA/200 KVA reference types. Preserve the exact target strings; some reference phase-Y conductor IDs have leading spaces. Select a matching construction ID explicitly.
4. Bind each point to the GPX by name/source/date. The supplied examples demonstrate new survey points, not a proposed rebinding of historical roots.
5. Leave unknown active-phase materials unresolved. A dash for a confirmed absent phase is different from a blank/unclear conductor on a connected phase. Add exact missing PVC library references and resolve ambiguous marks before final export.

The application already stores the relevant survey observations and accepts explicit equipment IDs in export mapping. This extraction does not implement or activate an automatic crosswalk in the application. It provides the concrete mapping data and identifies the exceptions that such an implementation must handle.

**Files**

- [equipment-crosswalk.csv](equipment-crosswalk.csv) and [equipment-crosswalk.json](equipment-crosswalk.json): equipment vocabulary, target candidates, provenance and unresolved items.
- [gpx-waypoints.csv](gpx-waypoints.csv): all 120 coordinates and source point metadata.
- [example-pdf-gpx-bindings.csv](example-pdf-gpx-bindings.csv) and [example-pdf-gpx-bindings.json](example-pdf-gpx-bindings.json): six visually read examples with equipment observations and actual GPX coordinates.
- [gpx-field-inventory.json](gpx-field-inventory.json): confirms the actual GPX's coordinate/metadata fields and absence of equipment definitions.

This is a crosswalk extraction, not a complete transcription of all thirteen survey pages into finalized MDB sections. The PDF worker currently requires manual handwritten verification.
'''
(OUT/'EQUIPMENT_MAPPING_REPORT.md').write_text(report,encoding='utf-8')
assert all(c['target_reference'] in profile['transformer_library_references'] for c in crosswalk if c['kind']=='transformer')
assert all(any(v.strip()==c['target_reference'] for vs in profile['raw_conductor_values_by_column'].values() for v in vs)
 for c in crosswalk if c['kind']=='conductor' and c['target_reference'])
assert all(e['gpx_name'] in gpx and e['complete_survey_identity'].endswith(e['gpx_name']) for e in examples)
print(json.dumps({'crosswalk_entries':len(crosswalk),'gpx_waypoints':len(gpx),'example_bindings':len(examples),
 'equipment_definitions_in_gpx':False,'originals_unchanged':True,'report':str(OUT/'EQUIPMENT_MAPPING_REPORT.md')},indent=2))
