from pathlib import Path
import hashlib, json, xml.etree.ElementTree as ET
import fitz
base=Path(r'W:\2120 HAZECO T&D Losses Project\HAZECO Data\MEPCO 1st Data Package\Sample Data')
out=Path('tmp/mdb-inspection')
gpx=base/'B2308.gpx'
root=ET.parse(gpx).getroot()
points=root.findall('{*}wpt')
names=[point.findtext('{*}name') for point in points]
pdf=base/'B2308.pdf'
doc=fitz.open(pdf)
for page in [0,1,26]:
    doc[page].get_pixmap(matrix=fitz.Matrix(1.5,1.5)).save(out/f'workflow-pdf-page-{page+1}.png')
summary={'gpx_sha256':hashlib.sha256(gpx.read_bytes()).hexdigest(),'pdf_sha256':hashlib.sha256(pdf.read_bytes()).hexdigest(),'waypoints':len(points),'tracks':len(root.findall('{*}trk')),'routes':len(root.findall('{*}rte')),'names':names,'missing_878_879':[x for x in ['878','879'] if x not in names],'duplicates':sorted({x for x in names if names.count(x)>1}),'pdf_pages':len(doc),'pdf_text_lengths':[len(p.get_text()) for p in doc]}
(out/'workflow-source-inspection.json').write_text(json.dumps(summary,indent=2))
print(json.dumps(summary,indent=2))
