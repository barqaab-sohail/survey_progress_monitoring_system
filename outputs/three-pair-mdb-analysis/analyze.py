import json,math,csv
from pathlib import Path
p=Path('outputs/three-pair-mdb-analysis');man=json.loads((p/'manifest.json').read_text(encoding='utf-8-sig'));ds=[json.loads(Path(x['extract']).read_text(encoding='utf-8-sig')) for x in man]
pairs=[]; links=[]
for code in ['2211117034','2301017107','8160421110']:
 de=next(d for d in ds if d['file']=='data_entry_database_'+code+'.mdb');sy=next(d for d in ds if code in d['file'] and d['file'].lower().startswith('valid'))
 rows=sorted(next(t['rows'] for t in de['tables'] if t['name']=='Field_Data'),key=lambda r:r['ID1']);tabs={t['name']:t for t in sy['tables']};sections={s['SectionId']:s for s in tabs['InstSection']['rows']};loads={s['SectionId']:s for s in tabs['Loads']['rows']};records=[]
 for i in range(0,len(rows),2):
  s,e=rows[i:i+2];assert(s['S_E'],e['S_E'])==('S','E');sid=str(e['Feeder_Code'])+str(e['GPS_NO']);sec=sections[sid];load=loads[sid];one=e['RS_Con1'] or 0;three=e['RS_Con3'] or 0;n=one+three
  cs=[load.get(f'Phase{j}Customers') for j in [1,2,3]];kv=[load.get(f'Phase{j}Kva') for j in [1,2,3]];active=[j for j,ph in enumerate('RYB') if ph in sec['SectionPhases']]
  assert math.isclose(sum(x or 0 for x in cs),n,abs_tol=1e-8);assert math.isclose(sum(x or 0 for x in kv),one*3+three*5,abs_tol=1e-8)
  assert all(math.isclose(cs[j],n/len(active),abs_tol=1e-8) for j in active)
  records.append(dict(section_id=sid,phases=sec['SectionPhases'],single_phase=one,three_phase=three,approved_phase_customers=cs,approved_phase_kva=kv))
  links.append(dict(pair=code,start_row=s['ID1'],end_row=e['ID1'],section_id=sid,source_start=str(s['GPS_NO']),source_end=str(e['GPS_NO']),approved_from=sec['FromNodeId'],approved_to=sec['ToNodeId'],single_phase=one,three_phase=three,approved_kva=one*3+three*5,application_kva=one*2+three*5))
 meter=tabs['InstMeterPoints']['rows'][0];ms=sections[meter['SectionId']];tr=tabs['InstPrimaryTransformers']['rows'][0]
 assert ms['FromNodeId']==sections[tr['SectionId']]['ToNodeId'];assert all(v is None for k,v in meter.items() if k!='SectionId')
 pairs.append(dict(transformer=code,source_files=[de['file'],sy['file']],source_hashes=[de['sha256'],sy['sha256']],single_phase=sum(r['single_phase'] for r in records),three_phase=sum(r['three_phase'] for r in records),meter_section=ms['SectionId'],records=records))
Path('tests/Fixtures/approved-section-load-pairs.json').write_text(json.dumps(pairs,indent=2)+'\n',encoding='utf-8')
with (p/'Section-Links.csv').open('w',newline='',encoding='utf-8-sig') as f:
 w=csv.DictWriter(f,fieldnames=list(links[0]));w.writeheader();w.writerows(links)
print('All',len(links),'pairs reconcile. Summaries:',[(r['transformer'],len(r['records']),r['single_phase'],r['three_phase'],r['single_phase']*2+r['three_phase']*5) for r in pairs])

