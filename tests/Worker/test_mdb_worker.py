"""Run with `python tests/Worker/test_mdb_worker.py`; uses an isolated loopback server."""
import hashlib
import hmac
import importlib.util
import json
from pathlib import Path
import tempfile
import threading
import time
import unittest
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("mdb_worker", BASE / "scripts/mdb/worker_server.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class WorkerProtocolTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="mdb-worker-test-")
        worker.ROOT = Path(self.temp.name).resolve()
        worker.REGISTRY = worker.ROOT / "registry.json"
        worker.SECRET = b"test-only-secret-at-least-thirty-two-characters"
        self.payload = json.loads((BASE / "tests/Fixtures/Mdb/noor-pur-writer.json").read_text())
        self.payload["template"]["sha256"] = hashlib.sha256((BASE / "resources/mdb/synergee-empty.mdb").read_bytes()).hexdigest()
        worker.REGISTRY.write_text(json.dumps({"fixture:schema-fixture": {"path": str(BASE / "resources/mdb/synergee-empty.mdb"), "sha256": self.payload["template"]["sha256"], "synergee_version": self.payload["template"]["synergee_version"], "permitted_static_tables": []}}))
        self.server = worker.ThreadingHTTPServer(("127.0.0.1", 0), worker.Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)
        self.temp.cleanup()

    def post(self, payload=None, authenticated=True):
        body = json.dumps(payload or self.payload).encode()
        stamp = str(int(time.time()))
        signature = hmac.new(worker.SECRET, (stamp + "\n" + hashlib.sha256(body).hexdigest()).encode(), hashlib.sha256).hexdigest()
        request = Request(f"http://127.0.0.1:{self.server.server_port}/v1/exports", data=body, headers={"Content-Type": "application/json", "Authorization": f"MDB-HMAC {stamp}:{signature if authenticated else '0' * 64}"})
        try:
            response = urlopen(request, timeout=30)
        except HTTPError as error:
            response = error
        data = response.read()
        self.assertEqual(hmac.new(worker.SECRET, data, hashlib.sha256).hexdigest(), response.headers["X-MDB-Signature"])
        return response.status, json.loads(data)

    def test_unsigned_request_cannot_generate_or_read_files(self):
        status, _ = self.post(authenticated=False)
        self.assertEqual(401, status)
        self.assertFalse((worker.ROOT / self.payload["idempotency_key"]).exists())

    def test_path_traversal_and_unknown_sql_table_are_rejected(self):
        self.payload["idempotency_key"] = "../../outside"
        self.assertEqual(422, self.post()[0])
        self.payload["idempotency_key"] = "b" * 64
        self.payload["tables"]["arbitrary_table"] = []
        self.assertEqual(422, self.post()[0])

    @unittest.skipUnless(Path(worker.POWERSHELL).is_file(), "Windows Access worker unavailable")
    def test_authenticated_export_is_readable_reused_and_bound_to_exact_payload(self):
        first_status, first = self.post()
        self.assertEqual(200, first_status, first)
        second_status, second = self.post()
        self.assertEqual(200, second_status, second)
        self.assertEqual(first["output_sha256"], second["output_sha256"])
        self.assertEqual(first["worker_completed_utc"], second["worker_completed_utc"])
        self.assertTrue(first["readback"]["mapped_values_verified"])
        self.assertEqual(22, first["readback"]["counts"]["Node"])
        self.payload["revision"]["sha256"] = "d" * 64
        self.assertEqual(422, self.post()[0])


if __name__ == "__main__":
    unittest.main()
