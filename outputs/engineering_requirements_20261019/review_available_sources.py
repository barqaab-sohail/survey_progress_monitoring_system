from pathlib import Path
import json, math, hashlib
import pymupdf
from PIL import Image, ImageDraw
import xml.etree.ElementTree as ET

out = Path(__file__).resolve().parent
workspace = out.parents[1]
inventory = json.loads((out/'survey_source_inventory.json').read_text())
app = json.loads((out/'application_source_inventory.json').read_text())
paths = [(Path('C:/Users/sohai/OneDrive/Desktop/HAZECO/Sample data')/r['file'], r) for r in inventory]
paths += [(workspace/r['path'], r) for r in app]
for idx, (path, record) in enumerate(paths, 1):
    if path.suffix.lower() == '.pdf':
        doc = pymupdf.open(path)
        record['extractable_text_characters'] = sum(len(p.get_text()) for p in doc)
        for start in range(0,len(doc),12):
            count=min(12,len(doc)-start)
            canvas=Image.new('RGB',(1050,math.ceil(count/3)*270),'#e5e7eb')
            draw=ImageDraw.Draw(canvas)
            for offset in range(count):
                p=doc[start+offset]
                pix=p.get_pixmap(matrix=pymupdf.Matrix(0.6,0.6))
                im=Image.frombytes('RGB',(pix.width,pix.height),pix.samples)
                im.thumbnail((340,245))
                x=(offset%3)*350+(350-im.width)//2; y=(offset//3)*270+20
                canvas.paste(im,(x,y)); draw.text((x,y-15),f'Page {start+offset+1}',fill='black')
            canvas.save(out/f'contact_{idx}_{start+1}.png')
        if 'path' in record:
            doc[0].get_pixmap(matrix=pymupdf.Matrix(1,1)).save(out/f'application_pdf_{idx}_first.png')
    else:
        root=ET.parse(path).getroot()
        pts=[p for p in root.iter() if p.tag.rsplit('}',1)[-1]=='wpt']
        record['all_leaf_tags']=sorted({p.tag.rsplit('}',1)[-1] for p in root.iter() if len(p)==0})
        coords=[(float(p.attrib['lat']),float(p.attrib['lon'])) for p in pts]
        record['lat_lon_extent']=[min(x for x,y in coords),min(y for x,y in coords),max(x for x,y in coords),max(y for x,y in coords)]
(out/'reviewed_source_inventory.json').write_text(json.dumps([r for p,r in paths],indent=2),encoding='utf-8')
print('Survey PDF pages:',sum(r.get('pages',0) for p,r in paths))
print('GPX points:',sum(r.get('waypoints',0) for p,r in paths))
print('Contact sheets:',len(list(out.glob('contact_*.png'))))
