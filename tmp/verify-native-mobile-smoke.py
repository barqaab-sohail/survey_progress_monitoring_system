import hashlib,json,sqlite3,subprocess
from pathlib import Path
import requests

root=Path(__file__).resolve().parent.parent
db=root/'tmp/mobile-smoke-isolated.sqlite'
assert db.is_file() and db.parent == root/'tmp'
con=sqlite3.connect(db)
uuid='64133425-b9af-4765-81d6-2b1bb0071510'
rows=con.execute('select id,revision,status,rows from field_surveys where client_uuid=?',(uuid,)).fetchall()
assert len(rows)==1
survey_id,revision,status,observations=rows[0]
assert revision==1 and status=='submitted'
observation=json.loads(observations)[0]
assert observation['gps_waypoint']=='000123' and observation['group']=='01'
assert 33.899 < observation['latitude'] < 33.901 and observation['gps_accuracy_m']==5
attachments=con.execute('select client_uuid,path,byte_length,sha256 from field_survey_attachments where field_survey_id=?',(survey_id,)).fetchall()
assert len(attachments)==1
attachment_uuid,path,size,digest=attachments[0]
server_file=(root/'storage/app/private'/path).resolve()
assert server_file.is_relative_to(root/'storage/app/private/field-surveys'/uuid)
assert len(server_file.read_bytes())==size
assert hashlib.sha256(server_file.read_bytes()).hexdigest()==digest
adb='C:/Users/sohai/AppData/Local/Android/Sdk/platform-tools/adb.exe'
local=sqlite3.connect(root/'tmp/android-smoke/native-phone.sqlite')
phone_path,phone_state=local.execute('select path,state from attachments where uuid=?',(attachment_uuid,)).fetchone()
assert phone_state=='synced'
phone_bytes=subprocess.check_output([adb,'-s','emulator-5554','exec-out','run-as','com.barqaab.hazeco.hazeco_field_survey','cat',phone_path])
assert hashlib.sha256(phone_bytes).hexdigest()==digest
base='http://127.0.0.1:8011/api/v1/field'
login=requests.post(base+'/login',json={'email':'mobile-smoke@example.test','password':'MobileTest123!','device_name':'Final synthetic verification'},timeout=20)
login.raise_for_status()
headers={'Authorization':'Bearer '+login.json()['token']}
download=requests.get(base+'/attachments/'+attachment_uuid,headers=headers,timeout=20)
assert download.status_code==200 and hashlib.sha256(download.content).hexdigest()==digest
assert requests.get(base+'/attachments/'+attachment_uuid,timeout=20).status_code==401
assert requests.post(base+'/logout',headers=headers,timeout=20).status_code==204
print('PASS: one server record at revision1; leading-zero identifiers and simulated GPS preserved.')
print('PASS: phone/server/private download photo hashes match; unauthenticated download is blocked.')
for table in ['survey_daily_entries','mdb_daily_entries','mdb_processing_daily_entries']:
 assert con.execute('select count(*) from '+table).fetchone()[0]==0
print('PASS: no original progress/MDB entries created in the isolated fixture.')
