from PIL import Image, ImageOps, ImageDraw, ImageFont
from pathlib import Path
p=Path('docs/mobile/manual/screenshots/01-login.png')
im=Image.open(p).convert('RGB')
print(im.size)
