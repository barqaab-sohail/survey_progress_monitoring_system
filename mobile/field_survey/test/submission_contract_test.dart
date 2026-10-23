import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show readySurvey;

void main() {
  test(
    'RS and SC phases survive sync with automatic totals and blank distinction',
    () {
      final record = readySurvey();
      final row = record.rows.first;
      row['consumer_phases'] = <String, dynamic>{
        'rs': <String, dynamic>{'R': '2', 'Y': '', 'B': '3'},
        'sc': <String, dynamic>{'R': '0', 'Y': '', 'B': ''},
      };
      row['consumers']['si'] = '8';
      updatePhaseConsumerTotals(row);
      expect(row['consumers']['rs'], 5);
      expect(row['consumers']['sc'], 0);
      final wire = record.payload()['rows'][0];
      expect(wire['consumer_phases']['rs'], {'R': 2, 'Y': null, 'B': 3});
      expect(wire['consumers']['si'], 8);
      row['consumer_phases']['rs']['R'] = '';
      row['consumer_phases']['rs']['B'] = '';
      updatePhaseConsumerTotals(row);
      expect(record.payload()['rows'][0]['consumers'].containsKey('rs'), false);
      row['consumer_phases']['sc']['R'] = '-1';
      expect(
        SurveyValidation.errors(record),
        contains('rows.0.consumer_phases.sc.R'),
      );
    },
  );

  test('mobile serializes the same full submission accepted by the API', () {
    final fixture =
        jsonDecode(
              File(
                '../../tests/Fixtures/FieldSurvey/mobile-submission.json',
              ).readAsStringSync(),
            )
            as Json;
    final record = readySurvey();
    record.data.addAll(copyJson(fixture));
    record.data['team_group'] = '01';
    record.header['capacity_kva'] = '100';
    for (final row in record.rows) {
      row['pole_height_ft'] = row['pole_height_ft'].toString();
      for (final key in (row['consumers'] as Json).keys) {
        row['consumers'][key] = row['consumers'][key].toString();
      }
      row['local_display_label'] = 'Not an API field';
    }
    record.solar.single['service_load_kw'] = '2.25';
    record.solar.single['installed_pv_kw'] = '3.5';
    record.solar.single['local_expanded'] = true;
    expect(SurveyValidation.errors(record, submitting: true), isEmpty);
    expect(record.payload(submitted: true), fixture);
    expect(record.rows.first['local_display_label'], 'Not an API field');
    expect(record.solar.single['local_expanded'], true);
  });

  test(
    'every edited group is exactly two ASCII digits with zeros retained',
    () {
      final record = readySurvey();
      for (final group in ['0', '001', '0a', ' 01', '01 ', '٠١']) {
        record.rows.first['group'] = group;
        expect(
          SurveyValidation.errors(record),
          contains('rows.0.group'),
          reason: group,
        );
      }
      for (final group in ['00', '07', '99']) {
        record.rows.first['group'] = group;
        expect(SurveyValidation.errors(record, submitting: true), isEmpty);
        expect(record.payload(submitted: true)['rows'][0]['group'], group);
      }
    },
  );
}
