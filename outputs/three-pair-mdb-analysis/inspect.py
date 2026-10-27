import json
from pathlib import Path
p=Path('outputs/three-pair-mdb-analysis'); m=json.loads((p/'manifest.json').read_text(encoding='utf-8-sig'))
for item in m:
 d=json.loads(Path(item['extract']).read_text(encoding='utf-8-sig'));t={z['name']:z for z in d['tables']};print('\nFILE',d['file'])
 if 'Field_Data' in t:
  r=t['Field_Data']['rows'];fields=['RS_Con','RS_Con1','RS_Con3','SC_Con','LC_Con','SI_Con','LI_Con','PB_Con','AG_Con','ST_Con','PV_Con','PV_Size','Sanction Load'];print('rows',len(r),'totals',{k:sum(z.get(k) or 0 for z in r) for k in fields})
  print('nonzero S',[(z['ID1'],z['GPS_NO'],{k:z[k] for k in fields if z.get(k)}) for z in r if z['S_E']=='S' and any(z.get(k) for k in fields)])
  print('PV',[(z['ID1'],z['GPS_NO'],{k:z[k] for k in fields if z.get(k)}) for z in r if z.get('PV_Con') or z.get('PV_Size')])
 else:
  loads=t['Loads']['rows'];print('counts',{k:t[k]['count'] for k in ['InstSection','Loads','Node','InstDGens','InstMeterPoints']});print('customers',sum(z.get(f'Phase{i}Customers') or 0 for z in loads for i in [1,2,3]),'kva',sum(z.get(f'Phase{i}Kva') or 0 for z in loads for i in [1,2,3]))
  print('meters',t['InstMeterPoints']['rows']);print('PV',t['InstDGens']['rows'],t['InstMymDGens']['rows'])
