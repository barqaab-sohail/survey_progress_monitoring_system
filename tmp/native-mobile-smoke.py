import subprocess,sys,xml.etree.ElementTree as ET,json,sqlite3
from pathlib import Path
adb='C:/Users/sohai/AppData/Local/Android/Sdk/platform-tools/adb.exe'
def run(*args):return subprocess.check_output([adb,'-s','emulator-5554',*args])
action=sys.argv[1]
if action=='shot':
 p=Path('tmp/android-smoke');p.mkdir(exist_ok=True)
 target=p/(sys.argv[2]+'.png');target.write_bytes(run('exec-out','screencap','-p'));print(target.resolve())
elif action=='tree':
 run('shell','uiautomator','dump','/sdcard/mobile-smoke.xml')
 data=run('exec-out','cat','/sdcard/mobile-smoke.xml')
 root=ET.fromstring(data)
 for node in root.iter('node'):
  label=node.get('text') or node.get('content-desc')
  if label:print(repr(label),node.get('bounds'), 'editable='+node.get('class',''))
elif action=='tap':run('shell','input','tap',sys.argv[2],sys.argv[3])
elif action=='text':run('shell','input','text',sys.argv[2])
elif action=='back':run('shell','input','keyevent','KEYCODE_BACK')
elif action=='login':
 import time
 run('shell','input','tap','500','1200')
 for value in ['http://10.0.2.2:8011','mobile-smoke@example.test','MobileTest123!']:
  run('shell','input','text',value)
  run('shell','input','keyevent','KEYCODE_TAB' if value!='MobileTest123!' else 'KEYCODE_ENTER')
  time.sleep(.2)
elif action=='swipe':run('shell','input','swipe',*sys.argv[2:6],'500')
elif action in ['taplabel','fill']:
 run('shell','uiautomator','dump','/sdcard/mobile-smoke.xml')
 root=ET.fromstring(run('exec-out','cat','/sdcard/mobile-smoke.xml'))
 import re
 needle=sys.argv[2]
 matches=[n for n in root.iter('node') if needle in (n.get('text','') or n.get('content-desc','') or n.get('hint',''))]
 if not matches:raise RuntimeError('No matching UI label: '+needle)
 match=next((n for n in matches if n.get('clickable')=='true'),matches[-1])
 bounds=list(map(int,re.findall(r'\d+',match.get('bounds'))))
 print(match.get('text') or match.get('content-desc'), bounds)
 run('shell','input','tap',str((bounds[0]+bounds[2])//2),str((bounds[1]+bounds[3])//2))
 if action=='fill':
  run('shell','input','text',sys.argv[3])
  run('shell','input','keyevent','KEYCODE_BACK')
elif action=='db':
 p=Path('tmp/android-smoke');p.mkdir(exist_ok=True)
 target=p/'native-phone.sqlite'
 package='com.barqaab.hazeco.hazeco_field_survey'
 for suffix in ['', '-wal', '-shm']:
  result=subprocess.run([adb,'-s','emulator-5554','exec-out','run-as',package,'cat','databases/field_surveys.db'+suffix],capture_output=True)
  if result.returncode==0:Path(str(target)+suffix).write_bytes(result.stdout)
  elif not suffix:raise RuntimeError(result.stderr.decode())
 con=sqlite3.connect(target)
 for scope,uuid,state,data,outbound in con.execute('select scope,uuid,state,data,outbound from surveys'):
  d=json.loads(data)
  print(json.dumps({'uuid':uuid,'state':state,'transformer':d.get('transformer_code'),'inspectors':d.get('header',{}).get('inspectors'),'rows':d.get('rows'),'base_revision':d.get('base_revision'),'has_outbound':outbound is not None}))
 print('attachments',list(con.execute('select kind,state,path from attachments')))
 con.close()
