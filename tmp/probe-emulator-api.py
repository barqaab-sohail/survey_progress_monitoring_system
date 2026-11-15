import subprocess,json,pathlib,gzip
root=pathlib.Path(__file__).parent
adb='C:/Users/sohai/AppData/Local/Android/sdk/platform-tools/adb.exe'
token=json.loads((root/'mobile-diagnostic-token.json').read_text())['token']
req=f'GET /api/v1/field/bootstrap?compact=1 HTTP/1.1\r\nHost: 10.0.2.2:8000\r\nAccept: application/json\r\nAccept-Encoding: gzip\r\nAuthorization: Bearer {token}\r\nConnection: close\r\n\r\n'
f=root/'mobile-probe-request.txt';f.write_bytes(req.encode())
try:
 subprocess.run([adb,'push',str(f),'/data/local/tmp/hazeco-probe-request'],capture_output=True,check=True)
 try: result=subprocess.run([adb,'shell','sh','-c',"'toybox nc -q 5 -w 5 -W 5 10.0.2.2 8000 < /data/local/tmp/hazeco-probe-request > /data/local/tmp/hazeco-probe-response'"],capture_output=True,timeout=12)
 except subprocess.TimeoutExpired: result=None
 if result: print('remote result',result.returncode,result.stderr.decode(errors='replace')[:200])
 subprocess.run([adb,'pull','/data/local/tmp/hazeco-probe-response',str(root/'emulator-bootstrap-http.bin')],capture_output=True)
 b=(root/'emulator-bootstrap-http.bin').read_bytes()
 print('emulator received bytes:',len(b))
 if b:
  h,body=b.split(b'\r\n\r\n',1)
  if b'content-encoding: gzip' in h.lower():
   try: body=gzip.decompress(body)
   except Exception as e: print('gzip failure', str(e));body=b''
  try: d=json.loads(body);print('Emulator HTTP body JSON valid:',len(body))
  except Exception as e: print('Emulator JSON error:',str(e))
finally:
 f.unlink(missing_ok=True)
 subprocess.run([adb,'shell','rm','-f','/data/local/tmp/hazeco-probe-request','/data/local/tmp/hazeco-probe-response'],capture_output=True)
