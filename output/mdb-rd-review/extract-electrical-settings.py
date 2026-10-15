"""Extract exact saved electrical values from all nine original read-only DAO inspections."""
from pathlib import Path
import collections,csv,hashlib,json

BASE=Path(__file__).resolve().parent
OUT=BASE/'electrical-settings'
OUT.mkdir(exist_ok=True)
SOURCE=Path('C:/Users/sohai/OneDrive/Desktop/HAZECO')
references=[]
for path in sorted(BASE.glob('*.inspection.json')):
 if path.name in ['sample.inspection.json','template.inspection.json']:continue
 d=json.loads(path.read_text(encoding='utf-8-sig'))
 assert hashlib.sha256((SOURCE/d['file']).read_bytes()).hexdigest()==d['sha256']
 references.append(d)
assert len(references)==9
TABLES=['SAI_Control','InstFeeders','InstPrimaryTransformers','InstSection','Loads','InstMeterPoints','InstDGens','InstMymDGens']
tables={t:[] for t in TABLES}
for d in references:
 for table in d['tables']:
  if table['name'] in tables:
   tables[table['name']].extend({'file':d['file'],'row':i+1,'values':row} for i,row in enumerate(table['rows']))
def write(name,data):(OUT/name).write_text(json.dumps(data,indent=2,ensure_ascii=False),encoding='utf-8')
def key(value):return json.dumps(value,ensure_ascii=False,sort_keys=True)
def csvwrite(name,rows):
 with (OUT/name).open('w',newline='',encoding='utf-8-sig') as stream:
  writer=csv.DictWriter(stream,fieldnames=list(rows[0]));writer.writeheader();writer.writerows(rows)
summary={};longrows=[];common={}
for table,rows in tables.items():
 fields=next(t['fields'] for t in references[0]['tables'] if t['name']==table)
 summary[table]={};common[table]={}
 for field in fields:
  name=field['name'];counter=collections.Counter(key(r['values'][name]) for r in rows)
  variants=[{'value':json.loads(k),'row_count':v,
             'files':sorted({r['file'] for r in rows if key(r['values'][name])==k})} for k,v in counter.items()]
  summary[table][name]={'observed_rows':len(rows),'same_in_all_observed_rows':len(counter)==1,'variants':variants}
  if len(counter)==1:common[table][name]=json.loads(next(iter(counter)))
  for r in rows:
   longrows.append({'file':r['file'],'table':table,'row_number':r['row'],'field':name,
    'value_json':key(r['values'][name]),'dao_type':field['type'],'size':field['size']})
write('exact-values.json',{'source':'Saved values read through DAO; NULL, zero and empty text remain distinct',
 'references':[{'file':d['file'],'sha256':d['sha256']} for d in references],'tables':tables})
write('value-comparison.json',summary)
csvwrite('exact-values.csv',longrows)
csvwrite('field-comparison.csv',[{'table':table,'field':field,'observed_rows':v['observed_rows'],
 'same_in_all_rows':v['same_in_all_observed_rows'],'distinct_value_count':len(v['variants']),
 'value_distribution_json':json.dumps(v['variants'],ensure_ascii=False)} for table,fields in summary.items() for field,v in fields.items()])
identity={'FeederId','SubstationId','SectionId','FromNodeId','ToNodeId','UniqueDeviceId','Description','SectionLength_MUL','MappingLink'}
common_for_mapping={table:{field:value for field,value in fields.items() if field not in identity
                          and not field.startswith('MYM_YearDesc')}
                    for table,fields in common.items() if table in ['SAI_Control','InstFeeders','InstPrimaryTransformers','InstSection']}
conductors={field:[v['value'] for v in summary['InstSection'][field]['variants']]
 for field in ['PhaseConductorId','PhaseConductor2Id','PhaseConductor3Id','NeutralConductorId']}
types=[v['value'] for v in summary['InstPrimaryTransformers']['TransformerType']['variants']]
write('reference-profile.json',{'status':'EXTRACTED_REFERENCE_VALUES_NOT_APPLIED_TO_APPLICATION',
 'source_reference_count':9,'project_values_observed':{'frequency':common['SAI_Control']['Frequency'],
 'nominal_feeder_voltage_kv':common['InstFeeders']['NominalKvll'],'bus_voltage_level':common['InstFeeders']['BusVoltageLevel'],
 'projection_label':common['SAI_Control']['ProjectionFile'],'synergee_product':common['SAI_Control']['Product']},
 'common_table_values':common_for_mapping,'transformer_library_references':types,
 'raw_conductor_values_by_column':conductors,
 'excluded_from_common_mapping':['old feeder/substation/device identities','node IDs and topology','span lengths',
 'historical multi-year labels','per-customer demand and phase allocations'],
 'interpretation_items':['Frequency=60 in all source files; verify intended project frequency before reuse',
 'NomKvMode=C and SpecNomKv=4.16 occur together; confirm the field mode and selected transformer library behavior',
 'UseInstanceImpedance=0; saved PercentImpedance and PercentResistance are both zero. Do not interpret those alone as physical zero impedance',
 'PhaseConductor2Id values contain leading spaces: exact raw labels are preserved; library matching needs explicit handling',
 'Spacing fields are raw SynerGEE field values; confirm their displayed units before conversion',
 'Actual conductor/transformer electrical specifications are not included in a populated equipment-definition table in these files']})
transformers=[]
for r in tables['InstPrimaryTransformers']:
 transformers.append({'file':r['file'],**{k:r['values'][k] for k in ['UniqueDeviceId','TransformerType','ConnectedPhases',
 'TapSetting','HighSideConnectionCode','LowSideConnectionCode','UseInstanceImpedance','PercentImpedance',
 'PercentResistance','NomKvMode','SpecNomKv']}})
csvwrite('transformer-settings.csv',transformers)
md='''**Electrical settings extracted from the nine valid reference MDBs**

The supplied MDBs contain reusable saved electrical settings, construction settings and equipment reference names. The earlier assessment should distinguish settings already present in these references from the task of configuring them in the application for the new HAZECO survey. These files can provide a reference profile; they are not limited to schema examples.

All original hashes were rechecked before extraction. This extraction did not modify any original MDB or application configuration. It includes every field in the control, feeder, primary-transformer, section, load, meter and populated generator tables. NULL, empty text and zero remain separate values.

| Setting | Exact observed value | Coverage |
|---|---|---|
| Product | SynerGEE Electric 5.0.0.324 | 9/9 files |
| Frequency | 60 | 9/9 files |
| Nominal feeder line-line voltage | 11 kV (`NominalKvll`) | 9/9 files |
| Bus voltage level | 11.37 (`BusVoltageLevel`) | 9/9 files |
| Feeder connection code | YG | 9/9 files |
| Voltage factor | 11 | 9/9 files |
| Length units | Metric | 9/9 files |
| Phase labels | R, Y, B | 9/9 files |
| Projection label | WGS 1984 UTM Zone 43N | 9/9 files; ProjectionWKT is empty |
| Feeder multiplier | 1 | 9/9 files |
| Individual phase voltage mode | UseByPhVoltLevel = 0 | 9/9 files |
| Stored phase voltages | 11, 11, 11 | 9/9 files; their use is controlled by the mode flag |
| Stored phase voltage angles | 0, -120, 120 | 9/9 files |
| Transformer phases | RYB | 9/9 files |
| Transformer library types | 100 KVA (7 files), 200 KVA (2 files) | Exact type labels |
| Transformer tap setting | 1 | 9/9 files |
| HV/LV connection codes | YG / YG | 9/9 files |
| Transformer impedance mode | UseInstanceImpedance = 0 | 9/9 files |
| Stored instance impedance/resistance | 0 / 0 | 9/9 files; not evidence of physical zero impedance |
| Transformer nominal-voltage mode/value | NomKvMode = C; SpecNomKv = 4.16 | 9/9 files; interpret together |
| Construction configuration ID | 12.5/7.2 kV cross arm C2-2 | All 187 sections |
| Phase/phase spacing field | PhaseToPhaseSpacing_MUL = 121.9 | All 187 sections; raw stored units |
| Phase/neutral spacing field | PhaseToNeutralSpacing_MUL = 91.4 | All 187 sections; raw stored units |
| Average-height field | AveHeightAboveGround_MUL = 9.1 | All 187 sections |
| Neutral-grounding flag | NeutIsGrounded = 1 | All 187 sections |
| Distributed-load impedance/current components | 40 / 10 | All 187 sections; PercentDistLoadConstImpedance / PercentDistLoadConstCurrent |
| Spot-load impedance/current components | 40 / 30 | All 187 sections; PercentSpotLoadConstImpedance / PercentSpotLoadConstCurrent |

Observed conductor names include ANT, WASP, DOG, ABC 50 MM2 and GNAT. Exact column values are preserved in `reference-profile.json`; some phase-Y names have leading spaces. These names can seed equipment mappings, but any PVC designation absent from the references needs an additional matching library entry.

**Transformer-specific settings**

| Reference transformer | Saved library type |
|---|---|
| T-3030216724 | 200 KVA |
| T-3030216727 | 100 KVA |
| T-4101220409 | 100 KVA |
| T-4151220461 | 100 KVA |
| T-4171220510 | 100 KVA |
| T-4171220531 | 200 KVA |
| T-3150216178 | 100 KVA |
| T-4070621060 | 100 KVA |
| T-4221215879 | 100 KVA |

Both capacities in the new survey therefore have matching reference type names. Reuse their exact type names only with the corresponding library available in the target SynerGEE installation. Transformer identity, feeder/substation name, coordinates, topology and span lengths must come from the new survey/network, rather than the old reference model.

**What this resolves**

The files provide a basis for filling project control values, feeder settings, transformer mode/connection values, construction settings and equipment reference mappings. The current application supports those explicit settings, but this extraction has not registered an approved profile or changed live configuration.

It does not establish that every stored reference value is the desired setting for the new project. In particular, preserve and interpret frequency 60 and the NomKvMode/SpecNomKv combination. Do not classify the files as invalid from those values alone, and do not silently replace them with guessed alternatives. Some fields may be inactive or controlled by library/mode selections.

The MDBs contain instance/reference data, with no populated equipment-definition table holding the full specifications of ANT, WASP, 100 KVA or 200 KVA. To reproduce the analysis behavior, the target SynerGEE equipment library must resolve those names. Also, old customer demand and phase allocations describe old surveyed customers; they do not directly supply the demand values for the new survey. A documented load method remains a separate input.

**Extraction files**

- [reference-profile.json](reference-profile.json): common settings, equipment names and interpretation items, ready for configuration review.
- [transformer-settings.csv](transformer-settings.csv): electrical settings for each reference transformer.
- [field-comparison.csv](field-comparison.csv): every field's distinct values, occurrence counts and source files.
- [exact-values.csv](exact-values.csv) and [exact-values.json](exact-values.json): full saved values with file/table/row provenance.
- [value-comparison.json](value-comparison.json): complete machine-readable comparison.
'''
(OUT/'EXTRACTED_SETTINGS.md').write_text(md,encoding='utf-8')
assert all(v['same_in_all_observed_rows'] for k,v in summary['InstPrimaryTransformers'].items() if k not in ['SectionId','UniqueDeviceId','TransformerType'])
assert summary['SAI_Control']['Frequency']['variants'][0]['value']==60
assert set(types)=={'100 KVA','200 KVA'}
print(json.dumps({'references':len(references),'sections':len(tables['InstSection']),
 'fields_compared':sum(len(v) for v in summary.values()),'exact_field_values':len(longrows),
 'profile':str(OUT/'reference-profile.json'),'report':str(OUT/'EXTRACTED_SETTINGS.md'),
 'original_hashes_verified':True},indent=2))
