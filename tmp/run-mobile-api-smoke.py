import json,urllib.request,urllib.error,uuid
base='http://127.0.0.1:8011/api/v1/field'
def request(path,payload=None,token=None):
 headers={'Content-Type':'application/json','Accept':'application/json'}
 if token:headers['Authorization']='Bearer '+token
 body=None if payload is None else json.dumps(payload).encode()
 r=urllib.request.urlopen(urllib.request.Request(base+path,data=body,headers=headers),timeout=30)
 return r.status,json.loads(r.read())
status,auth=request('/login',{'email':'mobile-smoke@example.test','password':'MobileTest123!','device_name':'Root contract smoke'})
print('Login:',status)
token=auth['token']
status,boot=request('/bootstrap',token=token)
print('Bootstrap:',status,'teams',len(boot['teams']),'feeders',len(boot['feeders']),'transformers',len(boot['transformers']))
data=json.load(open('docs/mobile/EXAMPLE_PAYLOAD.json',encoding='utf-8'))
data['client_uuid']=str(uuid.uuid4())
data['survey_team_id']=boot['teams'][0]['id'];data['feeder_id']=boot['feeders'][0]['id']
status,first=request('/surveys/sync',data,token)
print('Sync:',status,'revision',first['revision'])
status,retry=request('/surveys/sync',data,token)
print('Retry:',status,'revision',retry['revision'],'same_id',first['id']==retry['id'])
data['remarks']='Updated synthetic interoperability test';data['base_revision']=first['revision']
status,update=request('/surveys/sync',data,token)
print('Update:',status,'revision',update['revision'])
data['remarks']='Stale synthetic edit';data['base_revision']=0
try:request('/surveys/sync',data,token)
except urllib.error.HTTPError as e:print('Stale update:',e.code)
