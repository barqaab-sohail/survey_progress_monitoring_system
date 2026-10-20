"""Real DAO compatibility tests. Optional reference directory: DATA_ENTRY_MDB_REFERENCE_DIR."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
PS = Path(os.environ.get('DATA_ENTRY_MDB_POWERSHELL', r'C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe'))


@unittest.skipUnless(os.name == 'nt' and PS.is_file(), 'Windows DAO runtime required')
class DataEntryMdbTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)

    def inspect(self, source, name):
        output = self.directory / (name + '.json')
        result = subprocess.run([str(PS), '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
                                 str(ROOT / 'output/mdb-rd-review/inspect-mdb.ps1'),
                                 '-InputPath', str(source), '-OutputPath', str(output)], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(output.read_text(encoding='utf-8-sig'))

    def write(self, payload, name='result', template=None):
        source = self.directory / (name + '-payload.json')
        output = self.directory / (name + '.mdb')
        source.write_text(json.dumps(payload), encoding='utf-8')
        result = subprocess.run([str(PS), '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
                                 str(ROOT / 'scripts/mdb/export-data-entry.ps1'), '-InputPath', str(source),
                                 '-TemplatePath', str(template or ROOT / 'resources/mdb/data-entry/empty.mdb'),
                                 '-SchemaPath', str(ROOT / 'resources/mdb/data-entry/schema.json'), '-OutputPath', str(output)],
                                capture_output=True, text=True)
        return result, output

    def schema(self, inspection):
        return {'tables': [{k: t[k] for k in ['name', 'fields', 'indexes']} for t in inspection['tables']],
                'relationships': inspection['relationships']}

    def payload(self):
        schema = json.loads((ROOT / 'resources/mdb/data-entry/schema.json').read_text())
        rows = []
        for number, designation in enumerate(['S', 'E'], 1):
            row = {f['name']: None for f in schema['tables'][0]['fields']}
            row.update(ID1=number, S_E=designation, Equip_Number='T-test', GPS_NO=1080825000+number,
                       Pole_Number='0108082500'+str(number), Sequence=0, Con_Size_A='Ant', Con_Size_B='',
                       RS_Con1=number-1, PV_Size=5, AMI_Meter_No='000123', Remarks='Unicode اردو')
            rows.append(row)
        return {'version': 1, 'rows': rows}

    def test_template_is_clean_and_roundtrip_preserves_all_69_fields(self):
        template = self.inspect(ROOT / 'resources/mdb/data-entry/empty.mdb', 'template')
        contract = json.loads((ROOT / 'resources/mdb/data-entry/schema.json').read_text())
        self.assertEqual(contract, self.schema(template))
        self.assertEqual([0, 0], [t['count'] for t in template['tables']])
        payload = self.payload()
        result, output = self.write(payload)
        self.assertEqual(0, result.returncode, result.stderr)
        readback = self.inspect(output, 'readback')
        self.assertEqual(contract, self.schema(readback))
        self.assertEqual(payload['rows'], readback['tables'][0]['rows'])
        self.assertEqual(0, readback['tables'][1]['count'])

    def test_invalid_pairs_coordinates_and_overlong_values_fail_without_partial_files(self):
        for name in ['pair', 'coordinate', 'length', 'identity']:
            with self.subTest(name=name):
                payload = self.payload()
                if name == 'pair': payload['rows'][1]['S_E'] = 'S'
                if name == 'coordinate': payload['rows'][1]['x'] = 31.5
                if name == 'length': payload['rows'][1]['Remarks'] = 'X' * 201
                if name == 'identity': payload['rows'][1]['Equip_Number'] = 'other-transformer'
                result, output = self.write(payload, name)
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(output.exists())

    def test_reference_schemas_and_every_reference_value_roundtrip(self):
        folder = os.environ.get('DATA_ENTRY_MDB_REFERENCE_DIR')
        if not folder: self.skipTest('Set DATA_ENTRY_MDB_REFERENCE_DIR to compare the three supplied files')
        contract = json.loads((ROOT / 'resources/mdb/data-entry/schema.json').read_text())
        for name in ['A0808', 'A1308', 'A1408']:
            source = Path(folder) / ('data_entry_database_' + name + '.mdb')
            before = hashlib.sha256(source.read_bytes()).hexdigest()
            reference = self.inspect(source, name)
            self.assertEqual(contract, self.schema(reference))
            # Storage order is not a pairing contract. Use IDs for this raw writer fidelity test;
            # mapper tests separately prove explicit source pairs and deterministic ordering.
            rows = sorted(reference['tables'][0]['rows'], key=lambda r: r['ID1'])
            self.assertTrue(all(rows[i]['S_E'] == ('S' if i % 2 == 0 else 'E') for i in range(len(rows))))
            for i, row in enumerate(rows, 1): row['ID1'] = i
            result, output = self.write({'version': 1, 'rows': rows}, name+'-roundtrip')
            self.assertEqual(0, result.returncode, result.stderr)
            actual = self.inspect(output, name+'-readback')
            self.assertEqual(rows, actual['tables'][0]['rows'])
            self.assertEqual(before, hashlib.sha256(source.read_bytes()).hexdigest())


if __name__ == '__main__':
    unittest.main()
