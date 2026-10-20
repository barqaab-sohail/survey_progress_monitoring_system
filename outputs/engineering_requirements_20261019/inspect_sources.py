from pathlib import Path
import json, hashlib, re
import xml.etree.ElementTree as ET
import fitz

out = Path(__file__).resolve().parent
root = Path('C:/Users/sohai/OneDrive/Desktop/HAZECO/Sample data')
evidence = []
for path in sorted(root.glob('*')):
    if path.suffix.lower() not in ('.pdf', '.gpx'):
        continue
    record = {'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
    if path.suffix.lower() == '.pdf':
        doc = fitz.open(path)
        record.update({'type':'survey PDF', 'pages':len(doc), 'extractable_text_characters':sum(len(p.get_text()) for p in doc)})
        record['text_excerpt'] = '\n'.join(p.get_text() for p in doc)[:1600]
        for page in {0, len(doc)-1}:
            image = doc[page].get_pixmap(matrix=fitz.Matrix(0.9,0.9))
            image.save(out / f'{path.stem}_page_{page+1}.png')
    else:
        doc = ET.parse(path).getroot()
        points = [node for node in doc.iter() if node.tag.rsplit('}',1)[-1] == 'wpt']
        coords = [(float(node.attrib['lat']),float(node.attrib['lon'])) for node in points]
        names = [next((c.text or '' for c in p if c.tag.rsplit('}',1)[-1]=='name'), '') for p in points]
        tags = sorted({c.tag.rsplit('}',1)[-1] for p in points for c in p})
        record.update({'type':'GPX','waypoints':len(points),'waypoint_fields':tags,'lat_lon_extent':[min(x for x,y in coords),min(y for x,y in coords),max(x for x,y in coords),max(y for x,y in coords)],'first_names':names[:6], 'duplicate_names':sorted({n for n in names if names.count(n)>1})})
    evidence.append(record)
(out/'survey_source_inventory.json').write_text(json.dumps(evidence,indent=2),encoding='utf-8')
print(json.dumps(evidence,indent=2))
