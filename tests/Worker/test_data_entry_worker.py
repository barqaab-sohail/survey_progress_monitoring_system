"""Authenticated Data Entry MDB endpoint, including a real DAO round trip on Windows."""
import base64
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("data_entry_worker", BASE / "scripts/mdb/worker_server.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class DataEntryWorkerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="data-entry-worker-test-")
        worker.ROOT = Path(self.temp.name).resolve()
        worker.SECRET = b"test-only-data-entry-secret-at-least-thirty-two-characters"
        schema = worker.DATA_ENTRY_RESOURCES / "schema.json"
        fields = json.loads(schema.read_text())["tables"][0]["fields"]
        rows = []
        for number, designation in enumerate(["S", "E"], 1):
            row = {field["name"]: None for field in fields}
            row.update(ID1=number, S_E=designation, Equip_Number="T-test", Pole_Number="0108082500"+str(number),
                       GPS_NO=1080825000+number, Con_Size_A="Ant", RS_Con1=number-1, AMI_Meter_No="000123")
            rows.append(row)
        self.payload = {"version": 1, "rows": rows, "schema_sha256": worker.digest(schema.read_bytes().replace(b"\r\n", b"\n"))}
        self.server = worker.ThreadingHTTPServer(("127.0.0.1", 0), worker.Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)
        self.temp.cleanup()

    def post(self, authenticated=True):
        body = json.dumps(self.payload).encode()
        stamp = str(int(time.time()))
        signature = hmac.new(worker.SECRET, (stamp+"\n"+worker.digest(body)).encode(), hashlib.sha256).hexdigest()
        request = Request(f"http://127.0.0.1:{self.server.server_port}/v1/data-entry-exports", data=body,
                          headers={"Content-Type": "application/json", "Authorization": f"MDB-HMAC {stamp}:{signature if authenticated else '0'*64}"})
        try:
            response = urlopen(request, timeout=60)
        except HTTPError as error:
            response = error
        data = response.read()
        self.assertEqual(hmac.new(worker.SECRET, data, hashlib.sha256).hexdigest(), response.headers["X-MDB-Signature"])
        return response.status, json.loads(data), worker.digest(body)

    def test_unauthenticated_request_never_invokes_the_writer(self):
        with patch.object(worker.subprocess, "run") as run:
            self.assertEqual(401, self.post(authenticated=False)[0])
            run.assert_not_called()

    def test_mismatched_schema_and_incomplete_pairs_are_rejected_before_writing(self):
        with patch.object(worker.subprocess, "run") as run:
            original = self.payload["schema_sha256"]
            self.payload["schema_sha256"] = "0"*64
            self.assertEqual(422, self.post()[0])
            self.payload["schema_sha256"] = original
            self.payload["rows"].pop()
            self.assertEqual(422, self.post()[0])
            run.assert_not_called()

    def test_writer_failure_removes_private_request_files(self):
        with patch.object(worker.subprocess, "run", side_effect=RuntimeError("Writer failed")):
            self.assertEqual(503, self.post()[0])
        self.assertEqual([], list(worker.ROOT.iterdir()))

    def test_linux_and_windows_schema_line_endings_are_compatible(self):
        resources = worker.ROOT / "resources"
        resources.mkdir()
        source = (worker.DATA_ENTRY_RESOURCES / "schema.json").read_bytes().replace(b"\r\n", b"\n")
        (resources / "schema.json").write_bytes(source.replace(b"\n", b"\r\n"))
        with patch.object(worker, "DATA_ENTRY_RESOURCES", resources), patch.object(worker.subprocess, "run", side_effect=RuntimeError("Writer reached")) as run:
            status, result, _ = self.post()
            self.assertEqual(503, status)
            self.assertEqual("Writer reached", result["error"])
            run.assert_called_once()

    @unittest.skipUnless(os.name == "nt" and Path(worker.POWERSHELL).is_file(), "Windows Access runtime required")
    def test_real_mdb_has_validated_pairs_and_is_removed_from_worker_after_delivery(self):
        status, result, payload_hash = self.post()
        self.assertEqual(200, status, result)
        self.assertTrue(result["validated"])
        self.assertEqual(2, result["observations"])
        self.assertEqual(0, result["gps_points"])
        self.assertEqual(payload_hash, result["payload_sha256"])
        data = base64.b64decode(result["mdb_base64"], validate=True)
        self.assertEqual(result["sha256"], worker.digest(data))
        self.assertEqual(b"\x00\x01\x00\x00Standard Jet DB\x00", data[:20])
        self.assertEqual([], list(worker.ROOT.iterdir()))


if __name__ == "__main__":
    unittest.main()
