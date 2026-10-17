import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show readySurvey;

void main() {
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
