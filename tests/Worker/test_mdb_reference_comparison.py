import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('comparison', ROOT / 'scripts/mdb/compare-references.py')
comparison = importlib.util.module_from_spec(spec)
spec.loader.exec_module(comparison)


class ReferenceComparisonTest(unittest.TestCase):
    def fixture(self):
        return {'file': 'synthetic.mdb', 'sha256': 'a' * 64, 'relationships': [], 'tables': [
            {'name': 'InstSection', 'fields': [{'name': 'SectionId', 'type': 10}], 'indexes': [],
             'count': 1, 'rows': [{'SectionId': 'one', 'SectionPhases': ' Y N', 'PhaseConductor2Id': ' ANT'}]},
            {'name': 'InstMymDGens', 'fields': [], 'indexes': [], 'count': 1, 'rows': [{'SectionId': 'missing'}]},
        ]}

    def test_whitespace_orphans_and_schema_drift_are_reported_without_model_rows(self):
        template = self.fixture()
        reference = copy.deepcopy(template)
        reference['tables'][0]['fields'][0]['type'] = 4
        result = comparison.compare(template, [reference])
        self.assertEqual(['InstSection'], result['references'][0]['schema_differences'])
        self.assertEqual(1, result['references'][0]['orphan_devices']['InstMymDGens'])
        self.assertEqual({' Y N': 1}, result['phase_codes'])
        self.assertEqual([' ANT'], result['equipment_references_observed_not_approved']['conductors'])
        self.assertNotIn('tables', result['references'][0])

    def test_table_enumeration_order_does_not_create_schema_differences(self):
        template = self.fixture()
        reference = copy.deepcopy(template)
        reference['tables'].reverse()
        self.assertEqual([], comparison.compare(template, [reference])['references'][0]['schema_differences'])


if __name__ == '__main__':
    unittest.main()
