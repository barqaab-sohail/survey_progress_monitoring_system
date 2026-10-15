"""Verify and package completed review artifacts; never modifies supplied source files."""
from pathlib import Path
import hashlib,json,zipfile

OUT=Path(__file__).resolve().parent
def read(path):
 b=path.read_bytes()
 return json.loads(b.decode('utf-16' if b[:2] in [b'\xff\xfe',b'\xfe\xff'] else 'utf-8-sig'))
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
for name in ['writer-result.json','build-result.json']:
 p=OUT/name;p.write_text(json.dumps(read(p),indent=2),encoding='utf-8')
writer=read(OUT/'writer-result.json');sample=read(OUT/'sample.inspection.json')
payload=read(OUT/'sample-input.json')
assert writer['output_sha256']==sha(OUT/sample['file'])==sample['sha256']
assert writer['payload_sha256']==sha(OUT/'sample-input.json')
assert writer['readback']['mapped_values_verified']
assert all(read(OUT/'comparison-summary.json')[k] for k in ['all_originals_unchanged'])
tables={t['name']:t for t in sample['tables']}
for t in ['Node','InstFeeders','InstSection','InstPrimaryTransformers','Loads','SAI_Control']:
 assert tables[t]['count']==len(payload['tables'][t])
for row in tables['InstSection']['rows']:
 value=row['SectionPhases']
 assert all(c in [' ', 'RYBN'[i]] for i,c in enumerate(value)),repr(value)
assert all(r[f'Phase{i}{f}'] is None for r in tables['Loads']['rows'] for i in range(1,4) for f in ['Customers','Kw','Kvar','Kva'])
for p in OUT.glob('*.inspection.json'):
 if p.name in ['sample.inspection.json','template.inspection.json']:continue
 ts={t['name']:t for t in read(p)['tables']}
 assert all(v is None for r in ts['InstMeterPoints']['rows'] for k,v in r.items() if k!='SectionId')
code_paths=['app/Services/Mdb/ExportPayloadBuilder.php','app/Services/Mdb/GenerationInputService.php',
 'app/Services/TransformerKmzImportService.php','app/Services/Mdb/SurveyWaypoint.php',
 'app/Services/Mdb/NetworkValidator.php','app/Jobs/Mdb/ProcessSourceFile.php',
 'scripts/mdb-workflow/inspect_pdf.py','scripts/mdb/export-reviewed-mdb.ps1']
code=[{'path':str(p),'sha256':sha(OUT.parent.parent/p)} for p in code_paths]
(OUT/'code-evidence-hashes.json').write_text(json.dumps(code,indent=2))
files=sorted(p for p in OUT.rglob('*') if p.is_file() and p.suffix in ['.json','.csv','.md','.ps1','.py','.php','.mdb'] and p.name!='deliverable-manifest.json')
files.append(OUT/'pdf-pages/page-11.png')
manifest=[{'file':p.relative_to(OUT).as_posix(),'bytes':p.stat().st_size,'sha256':sha(p)} for p in files]
(OUT/'deliverable-manifest.json').write_text(json.dumps(manifest,indent=2))
files.append(OUT/'deliverable-manifest.json')
archive=OUT/'HAZECO_MDB_RD_REVIEW.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 for p in files:z.write(p,p.relative_to(OUT))
with zipfile.ZipFile(archive) as z:assert z.testzip() is None
print(json.dumps({'archive':str(archive),'bytes':archive.stat().st_size,'files':len(files),
 'sample_sha256':sha(OUT/sample['file']),'sample_bytes':(OUT/sample['file']).stat().st_size,
 'checks':'output and input hashes, mapped counts, positional phases, NULL phase demand, reference meter completeness, ZIP CRC passed'},indent=2))
