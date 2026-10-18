from PIL import Image, ImageDraw, ImageFont
from pathlib import Path
import math
p=Path('docs/mobile/manual/screenshots/01-login.png')
im=Image.open(p).convert('RGB')
d=ImageDraw.Draw(im)
font=ImageFont.truetype('C:/Windows/Fonts/arial.ttf', 34)
small=ImageFont.truetype('C:/Windows/Fonts/arial.ttf', 28)
# Highlight target field/button edges, plus numbered markers.
def marker(n, xy, label_xy, label):
    x,y=xy
    d.ellipse((x-31,y-31,x+31,y+31),fill='#d94835',outline='white',width=4)
    box=d.textbbox((0,0),str(n),font=font); tw=box[2]-box[0]; th=box[3]-box[1]
    d.text((x-tw/2,y-th/2-3),str(n),font=font,fill='white')
    bx,by=label_xy
    bb=d.textbbox((bx,by),label,font=small)
    d.rounded_rectangle((bb[0]-8,bb[1]-7,bb[2]+8,bb[3]+8),radius=9,fill='#0b213a')
    d.text((bx,by),label,font=small,fill='white')
# UI coordinates are from latest 1080x1920 clean emulator capture.
d.rounded_rectangle((62,971,1019,1108),radius=12,outline='#d94835',width=6)
d.rounded_rectangle((62,1143,1019,1281),radius=12,outline='#d94835',width=6)
d.rounded_rectangle((62,1330,1019,1457),radius=35,outline='#d94835',width=6)
marker(1,(1000,982),(765,930),'Account email')
marker(2,(1000,1155),(760,1097),'Password')
marker(3,(1000,1341),(555,1295),'Sign in and download')
out=Path('docs/mobile/manual/screenshots/01-login-annotated.png')
im.save(out,optimize=True)
