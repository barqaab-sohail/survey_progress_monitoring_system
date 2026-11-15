from http.server import BaseHTTPRequestHandler,HTTPServer
from pathlib import Path
import time
class H(BaseHTTPRequestHandler):
 def do_GET(self):
  b=Path('tmp/expected-bootstrap.gz').read_bytes()
  self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Encoding','gzip');self.send_header('Content-Length',str(len(b)));self.end_headers()
  for i in range(0,len(b),1024):
   self.wfile.write(b[i:i+1024]);self.wfile.flush();time.sleep(.01)
 def log_message(self,*a):pass
HTTPServer(('127.0.0.1',8765),H).serve_forever()
