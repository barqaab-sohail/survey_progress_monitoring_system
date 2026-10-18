from pathlib import Path
import fitz
from PIL import Image, ImageDraw
pdf=fitz.open('output/pdf/HAZECO_Field_Survey_User_Manual.pdf')
out=Path('docs/mobile/manual/rendered'); out.mkdir(parents=True,exist_ok=True)
print('pages',len(pdf))
for i,page in enumerate(pdf):
 pix=page.get_pixmap(matrix=fitz.Matrix(0.9,0.9),alpha=False)
 pix.save(out/f'page-{i+1:02d}.png')
 text=page.get_text()
 print(i+1, len(text), text[:85].replace('\n',' | '), '...end...', text[-80:].replace('\n',' | '))
# contact sheet
ims=[Image.open(p).convert('RGB') for p in sorted(out.glob('page-*.png'))]
thumbs=[]
for im in ims:
 im.thumbnail((306,396)); thumbs.append(im.copy())
cols=3; rows=(len(thumbs)+cols-1)//cols
sheet=Image.new('RGB',(cols*326,rows*426),'#d8dfe5')
d=ImageDraw.Draw(sheet)
for i,im in enumerate(thumbs):
 x=(i%cols)*326+10; y=(i//cols)*426+20
 sheet.paste(im,(x,y)); d.text((x,y-18),f'Page {i+1}',fill='#0b213a')
sheet.save(out/'contact-sheet.png')
