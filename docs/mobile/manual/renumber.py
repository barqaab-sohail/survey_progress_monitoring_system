from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
p='output/manual/HAZECO_Field_Survey_User_Manual.docx'; d=Document(p)
# Restart each numbered list group by setting a fresh numbering instance at the first list paragraph.
numbering=d.part.numbering_part.element
abstract_id=None
for absn in numbering.findall(qn('w:abstractNum')):
 for lvl in absn.findall(qn('w:lvl')):
  numFmt=lvl.find(qn('w:numFmt')); lvlText=lvl.find(qn('w:lvlText'))
  if numFmt is not None and numFmt.get(qn('w:val'))=='decimal' and lvlText is not None and lvlText.get(qn('w:val'))=='%1.':
   abstract_id=int(absn.get(qn('w:abstractNumId'))); break
 if abstract_id is not None:break
next_id=max([int(n.get(qn('w:numId'))) for n in numbering.findall(qn('w:num'))]+[0])+1
prev_list=False
for para in d.paragraphs:
 is_list=para.style.name=='List Number'
 if is_list and not prev_list and abstract_id is not None:
  num=OxmlElement('w:num'); num.set(qn('w:numId'),str(next_id)); aid=OxmlElement('w:abstractNumId'); aid.set(qn('w:val'),str(abstract_id)); num.append(aid); numbering.append(num)
  pPr=para._p.get_or_add_pPr(); numPr=OxmlElement('w:numPr'); il=OxmlElement('w:ilvl'); il.set(qn('w:val'),'0'); ni=OxmlElement('w:numId'); ni.set(qn('w:val'),str(next_id)); numPr.extend([il,ni]); pPr.append(numPr); next_id+=1
 prev_list=is_list
d.save(p); print('numbered groups restarted')
