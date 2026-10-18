from docx import Document
p='output/manual/HAZECO_Field_Survey_User_Manual.docx'
d=Document(p)
# Remove the explicit page break immediately before the sign-in heading; its section naturally continues from page 3.
for i,para in enumerate(d.paragraphs):
 if para.text.startswith('3  Sign in and download assignments'):
  prev=d.paragraphs[i-1]
  if prev._p.xpath('.//w:br[@w:type="page"]'):
   prev._element.getparent().remove(prev._element)
  break
# Prevent rows in the two reference tables from breaking across pages.
for table in d.tables:
 for row in table.rows:
  trPr=row._tr.get_or_add_trPr()
  from docx.oxml import OxmlElement
  trPr.append(OxmlElement('w:cantSplit'))
d.save(p)
print('pagination adjusted')
