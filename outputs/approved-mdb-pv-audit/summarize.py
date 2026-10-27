import json
from pathlib import Path
p=Path('outputs/approved-mdb-pv-audit');manifest=json.loads((p/'manifest.json').read_text(encoding='utf-8-sig'))
lines=['# Approved SynerGEE MDB PV audit','','All MDBs found under the supplied HAZECO folder were inspected except data-entry databases. This includes the main folder, One Complete sample, and Sample data. Files were read-only, and SHA-256 hashes were unchanged. Repeated copies are listed separately.','','| Source | PV generator records | Modelled kW (Year 0) |','|---|---:|---:|']
results=[]
for item in manifest:
 d=json.loads(Path(item['extract']).read_text(encoding='utf-8-sig'));t={x['name']:x for x in d['tables']}
 gen=t['InstDGens']['rows'];year=t['InstMymDGens']['rows'];kw=sum((r.get(f'Phase{i}Kw') or 0) for r in year if r['YearNumber']==0 for i in [1,2,3])
 src=Path(item['source']);rel=str(src).split('HAZECO\\')[-1]
 lines.append(f'| {rel} | {len(gen)} | {kw:.2f} |')
 result=dict(source=item['source'],sha256=d['sha256'],generators=gen,year_values=year,year_zero_kw=kw,other_generator_rows=t['InstGenerators']['count']);results.append(result)
 print(rel,'PV',len(gen),'kW',round(kw,2))
lines+=['','PV-labelled records use InstDGens.DGenDesc values beginning PVGEN-. Their phase kW values are in InstMymDGens, YearNumber 0. InstGenerators is empty in these files; checking that table alone misses PV entries. Generator records identify modelled PV connection points, not necessarily a complete count of individually metered PV customers.','','## PV connection details','','| Source | Section | Description | Year 0 phase kW (R / Y / B) | Total kW |','|---|---|---|---|---:|']
for r in results:
 for g in r['generators']:
  for y in r['year_values']:
   if y['SectionId']==g['SectionId'] and y['YearNumber']==0:
    v=[y.get(f'Phase{i}Kw') or 0 for i in [1,2,3]]
    lines.append(f'| {Path(r["source"]).name} | {g["SectionId"]} | {g["DGenDesc"]} | '+ ' / '.join(f'{z:.6f}' for z in v)+f' | {sum(v):.2f} |')
(p/'PV-Audit.md').write_text('\n'.join(lines)+'\n',encoding='utf-8');(p/'pv-summary.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
print('FILES',len(results),'WITH PV',sum(bool(r['generators']) for r in results))
