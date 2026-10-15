from pathlib import Path
import hashlib,json,zipfile,xml.etree.ElementTree as ET,re,collections
import pymupdf
from PIL import Image,ImageDraw
base=Path('C:/Users/sohai/OneDrive/Desktop/HAZECO')
out=Path(__file__).parent
render=out/'pdf-pages';render.mkdir(exist_ok=True)
manifest=[{'file':p.name,'bytes':p.stat().st_size,'sha256':hashlib.sha256(p.read_bytes()).hexdigest()} for p in sorted(base.iterdir()) if p.is_file()]
(out/'source-manifest.json').write_text(json.dumps(manifest,indent=2))
doc=pymupdf.open(base/'A081026.pdf')
thumbs=[]
for i,p in enumerate(doc):
 pix=p.get_pixmap(matrix=pymupdf.Matrix(1.7,1.7));pix.save(render/f'page-{i+1:02}.png')
 im=Image.frombytes('RGB',[pix.width,pix.height],pix.samples)
 im.thumbnail((500,700));thumbs.append(im)
sheet=Image.new('RGB',(500*4,740*((len(thumbs)+3)//4)),'white');draw=ImageDraw.Draw(sheet)
for i,im in enumerate(thumbs):
 x=(i%4)*500;y=(i//4)*740;sheet.paste(im,(x,y+25));draw.text((x+5,y+5),f'PAGE {i+1}',fill='black')
sheet.save(render/'all-pages.png')
root=ET.parse(base/'Waypoints_2026-10-08.gpx').getroot()
points=[dict(name=p.findtext('{*}name'),latitude=float(p.attrib['lat']),longitude=float(p.attrib['lon']),time=p.findtext('{*}time'),elevation=p.findtext('{*}ele'),symbol=p.findtext('{*}sym')) for p in root.findall('{*}wpt')]
with zipfile.ZipFile(base/'NAWAN SHEHR (000407).kmz') as z:
 kmls={n:z.read(n) for n in z.namelist() if n.lower().endswith('.kml')}
features=[]
for name,content in kmls.items():
 # Read a normalized in-memory copy; preserve the original archive and its hash.
 content=re.sub(rb'\s+xsi:schemaLocation\s*=\s*"[^"]*"',b'',content)
 kr=ET.fromstring(content)
 for p in kr.findall('.//{*}Placemark'):
  features.append({'name':p.findtext('{*}name'),'description':p.findtext('{*}description'),'points':[c.text for c in p.findall('.//{*}Point/{*}coordinates')],'lines':[c.text for c in p.findall('.//{*}LineString/{*}coordinates')],'extended':{v.attrib.get('name'):v.findtext('{*}value') or v.text for v in p.findall('.//{*}Data')+p.findall('.//{*}SimpleData')}})
summary={'pdf':{'pages':len(doc),'text_lengths':[len(p.get_text()) for p in doc]},'gpx':{'count':len(points),'tracks':len(root.findall('{*}trk')),'routes':len(root.findall('{*}rte')),'duplicate_names':[n for n,c in collections.Counter(p['name'] for p in points).items() if c>1],'waypoints':points},'kmz':{'kml_files':list(kmls),'placemarks':len(features),'point_features':sum(bool(f['points']) for f in features),'line_features':sum(bool(f['lines']) for f in features),'features':features}}
(out/'source-inspection.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps({k:{kk:vv for kk,vv in v.items() if kk not in ['waypoints','features']} for k,v in summary.items()},indent=2))
