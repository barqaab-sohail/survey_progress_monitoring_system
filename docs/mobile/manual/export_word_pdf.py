from pathlib import Path
from win32com.client import DispatchEx
p=Path('output/manual/HAZECO_Field_Survey_User_Manual.docx').resolve()
pdf=Path('output/pdf/HAZECO_Field_Survey_User_Manual.pdf').resolve()
word=DispatchEx('Word.Application')
word.Visible=False
word.DisplayAlerts=0
doc=word.Documents.Open(str(p), ReadOnly=False, AddToRecentFiles=False)
# Update fields including page numbers and TOC if present.
doc.Fields.Update()
for toc in doc.TablesOfContents: toc.Update()
doc.Fields.Update()
doc.Save()
doc.ExportAsFixedFormat(str(pdf),17,OpenAfterExport=False,OptimizeFor=0,Range=0,Item=0,IncludeDocProps=True,KeepIRM=True,CreateBookmarks=1,DocStructureTags=True,BitmapMissingFonts=True,UseISO19005_1=False)
doc.Close(False)
word.Quit()
print(pdf,pdf.stat().st_size)
