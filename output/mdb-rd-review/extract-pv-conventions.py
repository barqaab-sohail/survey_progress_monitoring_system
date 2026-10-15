"""Extract recorded PV phase/output conventions without inventing original input semantics."""
from pathlib import Path
import csv,hashlib,json,math

BASE=Path(__file__).resolve().parent
OUT=BASE/'pv-conventions';OUT.mkdir(exist_ok=True)
SOURCE=Path('C:/Users/sohai/OneDrive/Desktop/HAZECO')
records=[];counts=[]
for f in sorted(BASE.glob('*.inspection.json')):
 if f.name in ['sample.inspection.json','template.inspection.json']:continue
 d=json.loads(f.read_text(encoding='utf-8-sig'))
 assert hashlib.sha256((SOURCE/d['file']).read_bytes()).hexdigest()==d['sha256']
 tables={t['name']:t for t in d['tables']}
 gens=tables['InstDGens']['rows'];counts.append({'file':d['file'],'pv_generator_records':len(gens)})
 for g in gens:
  sid=g['SectionId'];sections=[r for r in tables['InstSection']['rows'] if r['SectionId']==sid]
  mym=[r for r in tables['InstMymDGens']['rows'] if r['SectionId']==sid]
  loads=[r for r in tables['Loads']['rows'] if r['SectionId']==sid]
  assert len(sections)==1 and len(mym)==1
  m=mym[0];kw=[m[f'Phase{i}Kw'] for i in [1,2,3]];q=[m[f'Phase{i}Kvar'] for i in [1,2,3]]
  records.append({'file':d['file'],'source_sha256':d['sha256'],'section_id':sid,
   'section_phases':sections[0]['SectionPhases'],'generator':g,'yearly_generator':m,
   'linked_load_records':loads,'generation_kw_total':sum(kw),'generation_kvar_total':sum(q),
   'equal_three_phase_generation':kw[0]==kw[1]==kw[2],
   'linked_load_kw_total':sum((l[f'Phase{i}Kw'] or 0) for l in loads for i in [1,2,3]),
   'linked_load_kva_total':sum((l[f'Phase{i}Kva'] or 0) for l in loads for i in [1,2,3]),
   'inferred_pv_power_factor_from_recorded_p_q':sum(kw)/math.hypot(sum(kw),sum(q)),
   'interpretation_limits':['Original installed PV capacity is not separately labelled in these records',
    'No consumer reference or original section/service-load wording on these load records',
    'Recorded balanced generation is a model convention, not proof of measured inverter connection phases']})
assert len(counts)==9 and len(records)==4
assert all(r['equal_three_phase_generation'] and r['section_phases']=='RYBN' for r in records)
assert all(r['generator']['DGenOutputPct']==100 and r['yearly_generator']['CapacityFactor']==1 for r in records)
assert all(r['generation_kvar_total']==0 for r in records)
profile={'status':'EXTRACTED_REFERENCE_CONVENTIONS_NOT_IMPLEMENTED_OR_ACTIVATED',
 'reference_file_count':9,'files_with_pv':3,'pv_records':4,
 'load_representation':{'load_table':'Loads','generator_tables':['InstDGens','InstMymDGens'],
   'association':'SectionId; demand and generation are separate records',
   'original_input_meaning':'Not recoverable: per-consumer/whole-span and gross/net/sanctioned/measured meaning are not labelled',
   'observed_linked_loads':'All four PV sections have Phase1/2/3Kw=0 and positive Phase1/2/3Kva; do not copy zeros as new consumer demand'},
 'phase_rule':{'observed':'All four records split generator kW equally across R/Y/B on RYBN sections',
   'candidate_use':'Use as a balanced three-phase model convention for matching RYB spans; connected-phase exceptions need an explicit rule',
   'not_established':'Actual per-consumer inverter phase connection'},
 'saved_generator_settings':{'DGenOutputPct':100,'DGenVoltSet':120,'YearNumber':0,
   'Phase1Kvar':0,'Phase2Kvar':0,'Phase3Kvar':0,'CapacityFactor':1},
 'reactive_power_interpretation':'Recorded positive generation kW with zero kvar corresponds to unity power factor in the stored P/Q values; PF is not stored as a separate field here',
 'capacity_interpretation':'100% output and CapacityFactor=1 describe the saved scenario. The files do not prove total model kW equals originally installed nameplate capacity.',
 'client_input_treatment':'The user says load and installed capacity will be supplied for each PV consumer. Preserve both values separately and associate each consumer with its surveyed section; establish gross/net and repeated-section-total meanings.',
 'export_development':['Add InstDGens and InstMymDGens to reviewed mapping, DAO writer and readback validation',
   'Preserve load value, installed capacity, consumer reference, section link and mapping provenance in snapshots',
   'Both generator tables have section-based primary keys; aggregate multiple PV consumers on one modeled section/year or define reviewed distinct connection sections',
   'Prevent adding the same service/section demand twice when it already appears in Loads']}
def write(name,obj):(OUT/name).write_text(json.dumps(obj,indent=2),encoding='utf-8')
write('reference-pv-records.json',{'file_counts':counts,'records':records})
write('pv-reference-profile.json',profile)
rows=[{'file':r['file'],'section_id':r['section_id'],'section_phases':r['section_phases'],
 'generation_R_kw':r['yearly_generator']['Phase1Kw'],'generation_Y_kw':r['yearly_generator']['Phase2Kw'],
 'generation_B_kw':r['yearly_generator']['Phase3Kw'],'generation_kw_total':r['generation_kw_total'],
 'generation_kvar_total':r['generation_kvar_total'],'DGenOutputPct':r['generator']['DGenOutputPct'],
 'CapacityFactor':r['yearly_generator']['CapacityFactor'],'YearNumber':r['yearly_generator']['YearNumber'],
 'linked_load_kw_total':r['linked_load_kw_total'],'linked_load_kva_total':r['linked_load_kva_total']} for r in records]
with (OUT/'pv-comparison.csv').open('w',newline='',encoding='utf-8-sig') as f:
 w=csv.DictWriter(f,fieldnames=list(rows[0]));w.writeheader();w.writerows(rows)
report='''**PV conventions recovered from the nine valid MDBs**

Three files contain four PV generators. All four link a generator and a load to the same InstSection through SectionId. Their stored generator conventions are consistent: equal R/Y/B kW on RYBN spans, output percentage 100, CapacityFactor 1, YearNumber 0 and zero kvar in each phase. The original MDBs were hash-checked and remain unchanged.

| Reference MDB | Section | Total stored PV generation kW | Each phase kW | Linked section load kVA |
|---|---|---:|---:|---:|
| Jodh Pur T-4151220461 | 1059082230825566 | 8.96 | 2.9866666667 | 11 |
| Jodh Pur T-4171220510 | 1059082230825622 | 5.65 | 1.8833333333 | 70 |
| Jodh Pur T-4171220531 | 1059082230825609 | 7.02 | 2.34 | 7 |
| Jodh Pur T-4171220531 | 1059082230825611 | 7.54 | 2.5133333333 | 55.8 |

These generation totals are the sum of stored phase kW, not separately identified installed nameplate capacities. All linked load rows have zero phase kW/kvar and positive kVA. The kVA column above must not be relabelled as client-provided kW or treated as a new measured demand.

**Answers to the three details**

1. **Load meaning: only the representation can be recovered.** Demand is stored in Loads, generation in InstDGens/InstMymDGens, and both reference the same SectionId. The load descriptions are null and do not retain the original consumer reference or client field wording. Therefore these files do not establish whether original “section load” was one consumer's load, all consumers on the span, gross demand, net import or a sanctioned rating. The user's new inputs are supplied per PV consumer; retain that provenance and clarify their precise demand meaning without duplicating a section total.
2. **PV allocation: the saved balanced model can be recovered.** All four generators have identical R, Y and B kW. This supplies a reference convention for reproducing balanced generation on an RYB-connected span. It does not prove actual inverter connection phases and does not establish a rule for a B-only or YB span; allocate only to connected phases under an explicit method for such cases.
3. **Generation assumptions: the saved settings can be recovered.** DGenOutputPct=100, CapacityFactor=1, YearNumber=0 and per-phase kvar=0. Positive kW with zero kvar corresponds to unity power factor in the stored P/Q values (an inference from the numbers, not an explicit PF field). DGenVoltSet=120 is also saved in every generator row; preserve its exact raw value and mode meaning rather than treating it as a new feeder voltage. The original time profile or observed generation is not recorded. The combination gives a reference scenario to reproduce, but does not prove nameplate capacity equals recorded model output.

**How this reduces the PV gap**

These files provide a concrete generator-table pattern, balanced allocation convention and saved output/reactive settings. The client-provided demand and installed-capacity values can populate the new records under an agreed interpretation of the two inputs and a stated capacity-to-model-output rule. Demand and PV generation should remain separate. The software exporter still needs generator mapping and verification support; no live configuration or MDB export code was changed by this extraction.

InstDGens has a primary key on SectionId; InstMymDGens has a primary key on SectionId and YearNumber. Multiple PV consumers on one section cannot be emitted as duplicate rows under those keys. Preserve per-consumer source inputs and either aggregate their modelled generation per section/year or use distinct, reviewed connection sections. Avoid double-counting demand already included in the section's Loads row.

Files: [PV comparison](pv-comparison.csv), [exact source records](reference-pv-records.json), [extracted PV profile](pv-reference-profile.json).
'''
(OUT/'PV_CONVENTIONS_REPORT.md').write_text(report,encoding='utf-8')
print(json.dumps({'source_files':9,'files_with_pv':3,'pv_records':len(records),
 'total_generation_kw':[round(r['generation_kw_total'],10) for r in records],
 'common_output_percent':100,'common_capacity_factor':1,'common_kvar':0,
 'all_equal_R_Y_B':True,'load_input_meaning_recoverable':False,'report':str(OUT/'PV_CONVENTIONS_REPORT.md')},indent=2))
