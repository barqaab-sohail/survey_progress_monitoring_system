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
from unittest.mock import patch

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

    def test_optional_generated_transformer_tables_are_allowed_without_opening_the_allowlist(self):
        for table in ('InstMymLoads', 'InstMymMeter', 'InstMeterDmd', 'InstMymSwDevPos'):
            self.payload['tables'][table] = []
            with patch.object(worker, 'resolve_template', side_effect=RuntimeError('validated table allowlist')):
                with self.assertRaisesRegex(RuntimeError, 'validated table allowlist'):
                    worker.export_model(json.dumps(self.payload).encode())
        self.payload['tables']['arbitrary_table'] = []
        with self.assertRaisesRegex(ValueError, 'table allowlist'):
            worker.export_model(json.dumps(self.payload).encode())

    @unittest.skipUnless(Path(worker.POWERSHELL).is_file(), 'Windows Access worker unavailable')
    def test_switch_device_positions_write_all_years_and_reject_non_transformer_sections(self):
        section = self.payload['tables']['InstPrimaryTransformers'][0]['SectionId']
        self.payload['tables']['InstMymSwDevPos'] = [
            {'SectionId': section, 'DeviceCode': 'M', 'YearNumber': year, 'IsOpen': 0}
            for year in range(11)
        ]
        status, result = self.post()
        self.assertEqual(200, status, result)
        self.assertEqual(11, result['readback']['counts']['InstMymSwDevPos'])
        self.assertTrue(result['readback']['mapped_values_verified'])
        self.assertTrue(result['readback']['references_verified'])
        non_transformer = next(row['SectionId'] for row in self.payload['tables']['InstSection'] if row['SectionId'] != section)
        self.payload['idempotency_key'] = 'c' * 64
        for row in self.payload['tables']['InstMymSwDevPos']:
            row['SectionId'] = non_transformer
        status, result = self.post()
        self.assertEqual(503, status, result)
        self.assertIn('reference validation failed', result['error'])
        self.assertFalse((worker.ROOT / self.payload['idempotency_key'] / 'approved.mdb').exists())

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

    def add_v2_devices(self):
        self.payload['mapping_version'] = 'synergee-reviewed-v2'
        section = self.payload['tables']['InstSection'][0]['SectionId']
        source_load = self.payload['tables']['Loads'][0]
        yearly_loads = []
        phase_fields = [f'Phase{phase}{quantity}' for phase in (1, 2, 3)
                        for quantity in ('Customers', 'Kw', 'Kvar', 'Kva', 'Kwh')]
        for year in (0, 1):
            yearly_loads.append({
                'SectionId': source_load['SectionId'], 'IsSpotLoad': source_load['IsSpotLoad'],
                **{field: source_load.get(field) for field in phase_fields},
                'CapacityFactor': source_load['CapacityFactor'], 'YearNumber': year,
            })
        self.payload['tables'].update({
            'InstMeterPoints': [{'SectionId': section}],
            'InstDGens': [{'SectionId': section, 'DGenOutputPct': 100, 'DGenVoltSet': 120}],
            'InstMymDGens': [dict(SectionId=section, YearNumber=year, Phase1Kw=1.25 + year,
                                 Phase2Kw=2.5, Phase3Kw=3.75, Phase1Kvar=-0.5,
                                 Phase2Kvar=0, Phase3Kvar=0, CapacityFactor=1) for year in (0, 1)],
            'InstMymLoads': yearly_loads,
        })

    @unittest.skipUnless(Path(worker.POWERSHELL).is_file(), 'Windows Access worker unavailable')
    def test_v2_devices_and_multiple_years_are_written_and_read_back(self):
        self.add_v2_devices()
        status, result = self.post()
        self.assertEqual(200, status, result)
        self.assertTrue(result['readback']['mapped_values_verified'])
        self.assertTrue(result['readback']['references_verified'])
        self.assertEqual(1, result['readback']['counts']['InstMeterPoints'])
        self.assertEqual(1, result['readback']['counts']['InstDGens'])
        self.assertEqual(2, result['readback']['counts']['InstMymDGens'])
        self.assertEqual(2, result['readback']['counts']['InstMymLoads'])

    @unittest.skipUnless(Path(worker.POWERSHELL).is_file(), 'Windows Access worker unavailable')
    def test_v2_orphan_year_is_rejected_even_without_a_database_relationship(self):
        self.add_v2_devices()
        self.payload['tables']['InstDGens'] = []
        status, result = self.post()
        self.assertEqual(503, status, result)
        self.assertIn('reference validation failed', result['error'])
        self.assertFalse((worker.ROOT / self.payload['idempotency_key'] / 'approved.mdb').exists())

    def test_version_table_contract_cannot_silently_drop_devices(self):
        self.add_v2_devices()
        self.payload['mapping_version'] = 'synergee-reviewed-v1'
        self.assertEqual(422, self.post()[0])
        self.payload['mapping_version'] = 'synergee-reviewed-v2'
        del self.payload['tables']['InstMymDGens']
        self.assertEqual(422, self.post()[0])

    @unittest.skipUnless(Path(worker.POWERSHELL).is_file(), 'Windows Access worker unavailable')
    def test_v1_generated_meter_parent_and_configured_years_are_written_and_verified(self):
        section = self.payload['tables']['InstSection'][0]['SectionId']
        self.payload['tables']['InstMeterPoints'] = [{'SectionId': section, 'Description': 'Configured meter'}]
        self.payload['tables']['InstMymMeter'] = [
            {'SectionId': section, 'YearNumber': year, 'CalibSAIFI': 2.5} for year in (2, 3, 4)
        ]
        status, result = self.post()
        self.assertEqual(200, status, result)
        self.assertTrue(result['readback']['mapped_values_verified'])
        self.assertTrue(result['readback']['references_verified'])
        self.assertEqual(1, result['readback']['counts']['InstMeterPoints'])
        self.assertEqual(3, result['readback']['counts']['InstMymMeter'])


if __name__ == "__main__":
    unittest.main()
