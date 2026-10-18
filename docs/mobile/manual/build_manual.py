from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.section import WD_SECTION
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.enum.style import WD_STYLE_TYPE
from pathlib import Path

OUT=Path('output/manual/HAZECO_Field_Survey_User_Manual.docx')
IMG=Path('docs/mobile/manual/screenshots/01-login-annotated.png')
doc=Document()
sec=doc.sections[0]
sec.top_margin=Inches(.68); sec.bottom_margin=Inches(.68); sec.left_margin=Inches(.78); sec.right_margin=Inches(.78)
# Palette
NAVY='0B213A'; TEAL='08788C'; MUTED='4F5B66'; LIGHT='EAF1F5'; RED='A72F2B'; GREEN='1B7251'; GOLD='855D09'
styles=doc.styles
styles['Normal'].font.name='Arial'; styles['Normal']._element.rPr.rFonts.set(qn('w:eastAsia'),'Arial'); styles['Normal'].font.size=Pt(10.5); styles['Normal'].font.color.rgb=RGBColor.from_string('202A33')
styles['Normal'].paragraph_format.space_after=Pt(5); styles['Normal'].paragraph_format.line_spacing=1.08
for name,size,color in [('Title',32,NAVY),('Heading 1',21,NAVY),('Heading 2',14,TEAL),('Heading 3',11,NAVY)]:
 s=styles[name]; s.font.name='Arial'; s._element.rPr.rFonts.set(qn('w:eastAsia'),'Arial'); s.font.size=Pt(size); s.font.bold=True; s.font.color.rgb=RGBColor.from_string(color); s.paragraph_format.space_before=Pt(10); s.paragraph_format.space_after=Pt(5)
# header/footer
header=sec.header.paragraphs[0]; header.text='HAZECO  |  FIELD SURVEY USER MANUAL'; header.style='Caption'; header.runs[0].font.color.rgb=RGBColor.from_string(TEAL); header.runs[0].font.bold=True
footer=sec.footer.paragraphs[0]; footer.alignment=WD_ALIGN_PARAGRAPH.CENTER
r=footer.add_run('Field team guide  •  Android  |  Page '); r.font.size=Pt(9); r.font.color.rgb=RGBColor.from_string(MUTED)
fld=OxmlElement('w:fldSimple'); fld.set(qn('w:instr'),'PAGE'); footer._p.append(fld)

def shade(cell,fill):
 tcPr=cell._tc.get_or_add_tcPr(); shd=OxmlElement('w:shd'); shd.set(qn('w:fill'),fill); tcPr.append(shd)
def cell_text(cell,text,bold=False,color=None,size=9.5):
 cell.text=''; p=cell.paragraphs[0]; p.paragraph_format.space_after=Pt(2); r=p.add_run(text); r.bold=bold; r.font.name='Arial'; r.font.size=Pt(size)
 if color:r.font.color.rgb=RGBColor.from_string(color)

def para(text='',style=None,boldlead=None):
 p=doc.add_paragraph(style=style)
 if boldlead and text.startswith(boldlead):
  p.add_run(boldlead).bold=True; p.add_run(text[len(boldlead):])
 else:p.add_run(text)
 return p

def bullet(text): return para(text,'List Bullet')
def numbered(text): return para(text,'List Number')
def note(label,text,color=GOLD):
 p=doc.add_paragraph(); p.paragraph_format.left_indent=Inches(.18); p.paragraph_format.space_before=Pt(3); p.paragraph_format.space_after=Pt(8)
 a=p.add_run(label+'  '); a.bold=True; a.font.color.rgb=RGBColor.from_string(color); p.add_run(text)

def fig(path,caption,width=5.85):
 p=doc.add_paragraph(); p.alignment=WD_ALIGN_PARAGRAPH.CENTER; p.add_run().add_picture(str(path),width=Inches(width))
 c=doc.add_paragraph(caption); c.alignment=WD_ALIGN_PARAGRAPH.CENTER; c.style='Caption'; c.paragraph_format.space_after=Pt(8)

def page(): doc.add_page_break()

# Cover
p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(105); p.alignment=WD_ALIGN_PARAGRAPH.LEFT
r=p.add_run('HAZECO'); r.font.name='Arial'; r.font.size=Pt(17); r.bold=True; r.font.color.rgb=RGBColor.from_string(TEAL)
p=doc.add_paragraph(style='Title'); p.paragraph_format.space_before=Pt(10); p.add_run('Field Survey\nUser Manual')
p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(4); r=p.add_run('A practical guide for Android field survey teams'); r.font.size=Pt(16); r.font.color.rgb=RGBColor.from_string(MUTED)
p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(25); p.add_run('From first sign-in to confirmed synchronization').bold=True
p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(225); r=p.add_run('Prepared for field use  |  Version checked against the Android app build dated 18 October 2026'); r.font.size=Pt(9); r.font.color.rgb=RGBColor.from_string(MUTED)
page()

# front
h=doc.add_heading('How to use this guide',1)
para('Use this manual in order the first time. During fieldwork, save often, check every S/E observation, and synchronize only when the phone has a working connection. The app keeps drafts and photographs on the phone until the server confirms receipt.')
note('Safety for your data','Do not uninstall the app, clear its storage, or change phones while drafts, waiting surveys, or photos remain unsynced. Confirm the Home screen shows Synced before replacing the phone.',RED)
para('Screens shown here were captured from the current Android app build. The login image uses empty fields. No account, password, personal record, or production survey data is shown. Later workflow screens are described by their exact current labels because viewing them requires a live assigned account.')
doc.add_heading('Contents',1)
contents=[('1','Quick start'),('2','Install and open the app'),('3','Sign in and download assignments'),('4','Choose a team and feeder'),('5','Create the transformer survey'),('6','Enter S/E observations and survey data'),('7','Add consumers, meters, references, and photos'),('8','Save a draft and continue later'),('9','Review and submit'),('10','Choose what to do after submission'),('11','Work offline'),('12','Synchronize and confirm uploads'),('13','Common problems and fixes'),('14','Field checklist and good practice')]
t=doc.add_table(rows=0,cols=2); t.alignment=WD_TABLE_ALIGNMENT.LEFT; t.autofit=False; t.columns[0].width=Inches(.45); t.columns[1].width=Inches(6.2)
for n,title in contents:
 c=t.add_row().cells; c[0].width=Inches(.45); c[1].width=Inches(6.2); cell_text(c[0],n,True,TEAL,10); cell_text(c[1],title,False,None,10)


page(); doc.add_heading('1  Quick start',1)
for s in ['Install and open HAZECO Field Survey.','Sign in with your assigned account while online; let assignments download.','Choose your assigned survey team and feeder, then select Create survey.','Enter transformer details. Open S/E rows and add a paired S and E observation for each required observation.','Record the GPS waypoint identifier for each row. Add consumer counts and any solar/meter details that apply.','Take or choose photos while the correct S/E row is open.','Save as a draft when unfinished. Review highlighted errors before submitting.','After submission, choose Create New Survey to continue on the same feeder or Go to Home to choose another.','When online, tap Sync now. Wait for a completion message and confirm the survey and each photo show Synced.']:
 numbered(s)
note('Remember','“Saved on this phone” means local. “Synced” means the server confirmed receipt.',TEAL)

doc.add_heading('2  Install and open the app',1)
numbered('Obtain the current HAZECO Field Survey APK from your survey lead or approved organization channel. Check that it is the intended current version before installing.')
numbered('On Android, open the APK. If Android asks, allow installation from the app used to open this file, then choose Install. The phone may show a security notice for an app installed outside the Play Store; install only the APK supplied by your organization.')
numbered('Tap Open, or find HAZECO Field Survey in the app list.')
note('Keep existing work','When updating the app, install the approved update over the existing app. Do not uninstall or clear storage if the phone may contain unsynchronized surveys or photos.',RED)

doc.add_heading('3  Sign in and download assignments',1)
para('The first sign-in needs internet access and an account assigned to an active survey team. The app connects to the organization’s configured service; there is no server-address field.')
fig(IMG,'Figure 1. Current sign-in screen. Numbered marks identify the email, password, and sign-in button.',5.5)
for s in ['Enter your assigned account email in the Email field (1). Check spelling and spaces.','Enter your password in Password (2). Use the eye icon only if needed to check typing; hide it again before leaving the phone.','Tap Sign in and download assignments (3). Wait while the app signs in and loads teams, feeders, and reference lists.','When the Home screen appears, confirm your team is assigned. Your password is not saved by the app.']:
 numbered(s)
note('Account help','If sign-in fails, check the email, password, and internet connection. If the account works on the web but the app does not, ask the project administrator to check mobile access and synchronization. Do not send a password in a message or screenshot.',RED)

doc.add_heading('4  Choose a team and feeder',1)
para('On Home, tap New survey. In Start a field survey, select the assigned survey team. Tap the feeder button to open Assigned feeders; search by feeder code or name, tap the correct feeder, then choose Create survey.')
bullet('Match the feeder on the work order or field sheet before entering observations.')
bullet('Only feeders assigned to the selected team are listed. If the correct feeder is missing, stop and ask the administrator to update the assignment.')
bullet('A saved draft keeps its original team and feeder. Use Go to Home to select a different feeder for another survey.')

doc.add_heading('5  Create the transformer survey',1)
para('The Details tab contains transformer and survey information. If the transformer is in the imported list, use Find imported transformer and select the matching asset. Check every filled value in the field; imported values can be changed. Otherwise, enter the details from the survey sheet.')
bullet('Transformer code: copy carefully, including any leading zeros.')
bullet('Capacity (kVA), transformer make, mounting, duty, and location: enter or select what is observed. Capacity is required at submission.')
bullet('Survey date, inspectors, substation, division, sub-division, and sub-division code: complete the fields used by your team. Inspector names are required.')
bullet('Overall remarks: add useful site context; do not use it to replace required structured fields.')

doc.add_heading('6  Enter S/E observations and survey data',1)
para('Tap S/E rows, then Add / complete S/E pair. The app opens an S row. Complete it and tap Done; the matching E row opens automatically. Complete E and tap Done. Each observation remains its own row.')
para('Enter values as they appear on the paper sheet. Use the same survey team group identifier for the pair. The app normally fills the team’s two-digit group number. If it is missing or wrong, stop and ask the team lead rather than guessing.')
bullet('Row date: enter the observation date in the displayed date control.')
bullet('GPS waypoint identifier: enter the waypoint ID used with the separately recorded GPX track. Leading zeros are kept.')
bullet('Conductors: enter R, Y, B, and Neutral (N) separately. At least one conductor must be present to submit. Phase is calculated from R/Y/B; neutral is excluded.')
bullet('Equipment type and Pole class: select the observed values. Use the displayed abbreviations as printed; ask the lead if unsure.')
bullet('Pole height (ft): enter the measured height using the field unit agreed by your team.')
bullet('Int: tick only if this observation is at an intersection.')
bullet('Consumers: enter each displayed customer category. A blank means not recorded; enter 0 only when none were observed.')
bullet('Row remarks: record relevant detail that does not fit another field.')
note('Copy Previous Values','For later rows, this button copies conductors, equipment type, pole class, and pole height only. It does not copy the waypoint, consumers, date, intersection, or remarks. Check every copied value before continuing.',GOLD)

doc.add_heading('GPS coordinates and waypoint IDs',2)
para('This app records a GPS waypoint identifier on each S/E row. It does not capture the phone’s latitude/longitude and does not ask for location permission. Record coordinates with the separate GPS/GPX method approved by your project, use the corresponding waypoint identifier in the app, and submit the GPX through the organization’s separate web workflow. Do not enter latitude/longitude into the waypoint field.')
note('Coordinate capture','If your task requires coordinates and no approved GPS/GPX tool is available, contact the field lead before leaving the site. The mobile app alone cannot record those coordinates.',RED)

doc.add_heading('7  Consumers, meter/reference numbers, and photographs',1)
doc.add_heading('Solar or net-metering records',2)
para('Open the applicable S/E row and choose Add solar installation under Solar / net-metering. Enter the Consumer reference number and Meter Number in their fields; the app opens a numeric keyboard for both. Enter service load (kW), solar capacity (kW), and remarks when applicable. These number fields retain the app’s existing stored values; keep identifiers exactly as recorded, especially leading zeros.')
bullet('Do not invent a meter or consumer reference when it is missing. Leave it blank if it is optional and unavailable, or follow the survey lead’s instruction.')
bullet('Solar installations are optional. Leave the section empty when it does not apply.')

doc.add_heading('Photos and sketches',2)
para('Open the correct S/E row before adding media so each image is linked to the right observation. Choose Take photo to use the camera, Choose image to select an existing picture, or Photograph sketch to capture a paper sketch. Allow camera access when Android asks. Review the image and confirm it appears under that row.')
bullet('Take clear, well-lit images. Include enough context to identify the transformer or observation without photographing unrelated people or private documents.')
bullet('Photos remain on the phone until the submitted survey is acknowledged by the server. They upload afterward. Keep the app open while synchronization runs.')
bullet('JPEG and PNG images up to 10 MB are supported. If an image is too large or fails, choose a smaller image or retake it at lower resolution.')

doc.add_heading('8  Save a draft and continue later',1)
para('The editor saves changes automatically while you work. The bottom of the screen shows the save state. Tap the save icon and choose Save draft when you want an immediate save. You can also use the back arrow to save and return to Home.')
numbered('Check that the status says Saved on this phone before leaving the editor.')
numbered('On Home, find the survey in the list. Use the Drafts filter or search by transformer code, feeder, or date.')
numbered('Tap the survey card to resume. Continue on Details or S/E rows; existing entries and attached photos are retained.')
note('Offline drafts','A draft stays on this phone and is not uploaded just because it was saved. Complete and submit it, then synchronize when connected.',TEAL)

doc.add_heading('9  Review, validate, and submit',1)
para('Before submitting, compare the app with the field sheet. Review both Details and S/E rows, expand each row, and check all entered values and photos. Submission checks required fields and highlights missing or invalid entries.')
para('The submission checks include transformer code, capacity, inspector names, at least one complete S/E pair, one S and one E in every pair, row date, group identifier, waypoint identifier, equipment type, pole class, pole height, and at least one R/Y/B/Neutral conductor for each row. Any entered numbers and references must pass validation.')
numbered('Correct every highlighted field. Recheck numeric values, group and waypoint identifiers, leading zeros, and consumer counts.')
numbered('Tap Submit survey. Review the confirmation showing the number of S/E rows to be queued.')
numbered('Choose Submit to save the outgoing survey on the phone. Choose Keep draft if you are not ready.')
numbered('Wait for the “Survey saved” confirmation. The submitted form is stored on the phone even if the network is unavailable; it is not yet server-confirmed.')
note('Review carefully','A submitted survey is locked while it waits for server confirmation. Make corrections before submission. If a correction is needed after sync, follow your project’s process for updating a synced survey.',GOLD)

doc.add_heading('10  After submission: same feeder or Home',1)
para('The “Survey saved” popup offers two choices after the submitted survey has been saved locally:')
bullet('Create New Survey: starts a blank survey under the same team and feeder. The previous survey’s field values and attachments are not copied into the new survey. The submitted survey and its attachments remain saved separately.')
bullet('Go to Home: returns to the survey list. Tap New survey to select a team or change feeder.')
para('These choices do not wait for upload completion. The saved survey and pending photos remain queued on the phone and can synchronize later.')

doc.add_heading('11  Work without internet',1)
para('First sign-in and initial assignment download require internet. After you have signed in and downloaded assignments, the app can save drafts and submitted surveys on the phone without an internet connection.')
numbered('Before going offline, sign in, download assignments, and confirm the correct team and feeder are available.')
numbered('During fieldwork, create and complete surveys as usual. Save drafts; photos are copied into app storage and remain attached locally.')
numbered('Submit completed surveys when ready. The app queues the submission on the phone; no server confirmation occurs offline.')
numbered('When internet returns, open the app and use Sync now. Keep the app open until surveys and photos finish.')
note('Protect offline work','Avoid app uninstall, Clear storage, factory reset, or phone replacement until every survey and attachment is confirmed Synced.',RED)

doc.add_heading('12  Sync and confirm every upload',1)
para('On Home, review the Sync card and tap Sync now when the phone has a stable connection. The control disables during a sync to prevent repeat taps. Automatic retries may also run while the app is open.')
para('Read the status message beneath Saved here. Sync when connected. Use the survey list filters All, Waiting, Synced, or Attention to review records. Open a survey and check each attachment status as well.')
# status reference table
rows=[('Sync in progress…','Wait. Keep the app open and avoid tapping again.','0B5B75'),('Sync completed successfully. N surveys and M photos uploaded.','Server confirmed these uploads. Check the survey cards and each photo says Synced.','1B7251'),('Sync completed successfully. No pending uploads.','Nothing was waiting to upload. Drafts still need submission.','1B7251'),('No internet connection. Sync failed: …','Move to a reliable connection and tap Sync now again. Work stays on phone.','855D09'),('Sync failed: sign-in is required…','Sign in again with the same assigned account; retry after login. Contact the administrator if the message repeats.','A72F2B'),('Sync failed: [reason]…','Read the reason, follow the troubleshooting table, and retry. Keep the phone copy.','A72F2B')]
t=doc.add_table(rows=1,cols=3); t.alignment=WD_TABLE_ALIGNMENT.CENTER; t.style='Light Shading Accent 1'
for c,txt in zip(t.rows[0].cells,['Message','What to do','Status']): cell_text(c,txt,True,'FFFFFF',9); shade(c,NAVY)
for msg,action,col in rows:
 cells=t.add_row().cells; cell_text(cells[0],msg,True,None,8.7); cell_text(cells[1],action,False,None,8.7); cell_text(cells[2],'Wait' if 'progress' in msg else 'Confirm' if 'successfully' in msg else 'Retry',True,col,8.7)

doc.add_heading('How to know synchronization is complete',2)
numbered('On Home, choose the Synced filter. Confirm the submitted survey appears there and has no Waiting or Attention state.')
numbered('Open the survey. Check its server-confirmed status. Open Photos and sketches for each S/E row and confirm every image says Synced, not Pending, Uploading, or Error.')
numbered('If the sync card reports counts, compare uploaded survey/photo counts with the items you expected to send. Retry if any item remains pending or failed.')
numbered('Do not treat a successful form upload as proof that its photos uploaded. Forms and attachments synchronize separately.')
note('Confirmation rule','Only a server-confirmed Synced status for the survey and every attachment means the full job is synchronized.',TEAL)

doc.add_heading('13  Common problems and fixes',1)
issues=[
('Email or password rejected','Check account spelling and keyboard case. Re-enter the password. If web login works but mobile does not, ask the administrator to check mobile sign-in and server access.'),
('No team or feeder appears','Refresh assignments while online. Confirm the account is on an active assigned team. Ask the administrator to assign the feeder; do not choose a different feeder just to continue.'),
('Submit shows missing fields','Read each highlighted label. Complete required transformer details, both S and E rows, waypoint ID, conductor, equipment/pole values, date, and group. Correct invalid values and submit again.'),
('Group identifier missing or wrong','Do not guess. Ask the team lead/administrator to correct the team’s two-digit number. Existing entries remain on the phone.'),
('Need GPS coordinates','The app only stores a GPS waypoint identifier; it does not capture phone coordinates. Use the approved separate GPS/GPX process and enter the matching waypoint ID.'),
('Camera will not open','Allow camera access in the Android prompt or Android Settings > Apps > HAZECO Field Survey > Permissions. Reopen the editor and try again. If camera access is unavailable, use Choose image.'),
('Photo will not attach or upload','Check available phone storage and connection. Use a JPEG/PNG under 10 MB. Confirm the image is attached to an S/E row. Retry Sync now; the app keeps failed local photos for retry.'),
('No internet / timeout','Move to a stable Wi-Fi or mobile data area, confirm Android shows connectivity, reopen the app, and tap Sync now. Pending work remains saved on the phone.'),
('Sign in again to synchronize','Use the same assigned account and retry. If the app repeatedly signs in but sync asks again, contact the project administrator and include the exact message; do not send your password.'),
('Version conflict or server error','Keep the phone copy. Do not delete or repeatedly recreate the survey. Capture the error text and ask the project administrator to reconcile or correct the server-side issue.'),
('It says no pending uploads, but a draft is present','Drafts are not uploaded until submitted. Open the draft, finish validation, submit, then sync.'),
('Attachment says pending while survey says synced','Wait for photos to upload; they are a separate step. Keep the app open, tap Sync now when idle, then confirm each attachment says Synced.'),
('Not enough storage / save failed','Free phone space without deleting app data, retry Save draft, and verify the Saved on this phone status before leaving.')]
t=doc.add_table(rows=1,cols=2); t.style='Light Shading Accent 1'; t.alignment=WD_TABLE_ALIGNMENT.CENTER
for c,x in zip(t.rows[0].cells,['Problem','Steps to resolve']):cell_text(c,x,True,'FFFFFF',9);shade(c,NAVY)
for a,b in issues:
 cells=t.add_row().cells; cells[0].width=Inches(1.75); cells[1].width=Inches(4.8); cell_text(cells[0],a,True,None,8.5);cell_text(cells[1],b,False,None,8.5)

doc.add_heading('14  Field checklist and good practice',1)
doc.add_heading('Before leaving the office',2)
for s in ['Install the organization-approved current APK.','Sign in and download assigned teams/feeders while online.','Check the phone has free storage and the camera works.','Agree the GPS/GPX method and waypoint numbering with the team lead.','Carry the paper survey sheet and confirm the feeder assignment.']: bullet(s)
doc.add_heading('At each site',2)
for s in ['Verify the feeder, transformer code, and location before entering data.','Record observations directly from the field sheet; preserve leading zeros.','Complete and check both S and E observations. Never copy values without inspecting them.','Link each photo to the correct S/E row; avoid sensitive or unrelated imagery.','Save drafts and verify the app says Saved on this phone before moving on.']:bullet(s)
doc.add_heading('At the end of the shift',2)
for s in ['Connect to stable internet and tap Sync now.','Wait for the success message; verify each expected survey and every photo says Synced.','Retry failures and report repeated sign-in, validation, conflict, or upload errors to the lead.','Keep the phone and app data until unsynced work is resolved.']:bullet(s)

# core properties
props=doc.core_properties; props.title='HAZECO Field Survey User Manual'; props.subject='Android field survey workflow for HAZECO field teams'; props.author='HAZECO Field Survey'; props.keywords='field survey, Android, offline, synchronization, user manual'
# keep headings with next
for p in doc.paragraphs:
 if p.style.name.startswith('Heading'):p.paragraph_format.keep_with_next=True
# table text margins and repeat first row
for table in doc.tables:
 for row in table.rows:
  for cell in row.cells:
   cell.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
   tc=cell._tc; tcPr=tc.get_or_add_tcPr(); mar=OxmlElement('w:tcMar')
   for side,val in [('top','70'),('start','95'),('bottom','70'),('end','95')]:
    el=OxmlElement('w:'+side); el.set(qn('w:w'),val); el.set(qn('w:type'),'dxa'); mar.append(el)
   tcPr.append(mar)
# add update-fields setting for TOC/PAGE fields
settings=doc.settings.element; upd=OxmlElement('w:updateFields'); upd.set(qn('w:val'),'true'); settings.append(upd)
doc.save(OUT)
print(OUT.resolve(), OUT.stat().st_size)

