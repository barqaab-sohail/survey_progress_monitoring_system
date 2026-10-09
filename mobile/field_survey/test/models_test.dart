import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/models.dart';

const testSession = FieldSession(
  url: 'https://survey.example.test',
  token: 'test-token',
  userId: 1,
  name: 'Test Inspector',
);
SurveyRecord newSurvey() => SurveyRecord.create(
  testSession,
  {'id': 4, 'name': 'Test team'},
  {'id': 7, 'feeder_name': 'Test feeder', 'division_name': 'Test division'},
);
SurveyRecord readySurvey() {
  final record = newSurvey();
  record.data['transformer_code'] = '001234';
  record.header['capacity_kva'] = '100';
  record.rows.add(
    SurveyRecord.newRow(record.data['survey_date'])..['gps_waypoint'] = '0005',
  );
  return record;
}

void main() {
  test('phase follows R Y B conductor presence and ignores neutral', () {
    for (var mask = 0; mask < 8; mask++) {
      final row = SurveyRecord.newRow('2026-01-01');
      var expected = '';
      for (var i = 0; i < 3; i++) {
        final key = ['r', 'y', 'b'][i];
        if (mask & (1 << i) != 0) {
          row['conductor_$key'] = ' GN ';
          expected += key.toUpperCase();
        }
      }
      row['conductor_neutral'] = 'A';
      expect(phaseFromConductors(row), expected);
    }
    final record = readySurvey();
    record.rows.first.addAll({
      'phase': '3',
      'conductor_r': 'A',
      'conductor_y': 'W',
      'conductor_b': '+',
      'conductor_neutral': 'GN',
    });
    expect(record.payload(submitted: true)['rows'][0]['phase'], 'RY');
    record.rows.first['conductor_y'] = '  ';
    expect(record.payload(submitted: true)['rows'][0]['phase'], 'R');
    record.rows.first['conductor_r'] = '×';
    expect(record.payload(submitted: true)['rows'][0]['phase'], '');
  });
  test(
    'blank draft is valid locally; submitting requires paper essentials',
    () {
      final record = newSurvey();
      expect(SurveyValidation.errors(record), isEmpty);
      expect(
        SurveyValidation.errors(record, submitting: true).keys,
        containsAll(['transformer_code', 'header.capacity_kva', 'rows']),
      );
      expect(SurveyValidation.errors(readySurvey(), submitting: true), isEmpty);
    },
  );
  test(
    'payload preserves identifiers, both S/E rows, Int, missing and zero counts',
    () {
      final record = readySurvey();
      record.rows.first['consumers'] = {'rs': '0', 'rl': '', 'ag': '12'};
      record.rows.first['intersection'] = true;
      record.rows.add(
        SurveyRecord.newRow(record.data['survey_date'])
          ..['gps_waypoint'] = '0005'
          ..['se'] = 'E',
      );
      final payload = record.payload(submitted: true);
      expect(payload['transformer_code'], '001234');
      expect(payload['rows'], hasLength(2));
      expect(payload['rows'][0]['consumers'], {'rs': 0, 'ag': 12});
      expect(payload['rows'][0]['intersection'], isTrue);
      expect(payload['rows'][1]['gps_waypoint'], '0005');
      expect(payload['header']['capacity_kva'], 100);
      expect(record.rows.first['consumers']['rl'], '');
    },
  );
  test(
    'submit rejects future dates, invalid numeric counts and out-of-bounds GPS',
    () {
      final record = readySurvey();
      record.data['survey_date'] = dayString(
        DateTime.now().add(const Duration(days: 1)),
      );
      record.rows.first['consumers'] = {'rs': '1.5', 'st': '-1'};
      record.rows.first['latitude'] = '91';
      record.rows.first['pole_height_ft'] = '1000001';
      record.header['capacity_kva'] = 'NaN';
      expect(
        SurveyValidation.errors(record, submitting: true).keys,
        containsAll([
          'survey_date',
          'rows.0.consumers.rs',
          'rows.0.consumers.st',
          'rows.0.latitude',
          'rows.0.pole_height_ft',
          'header.capacity_kva',
        ]),
      );
    },
  );
  test(
    'server URL retains subdirectory, normalizes API suffix and restricts HTTP',
    () {
      expect(
        normalizeServerUrl('https://EXAMPLE.test/monitor/'),
        'https://example.test/monitor',
      );
      expect(
        normalizeServerUrl('https://example.test/monitor/api/v1/field/'),
        'https://example.test/monitor',
      );
      expect(
        normalizeServerUrl('http://10.0.2.2:8000', debug: true),
        'http://10.0.2.2:8000',
      );
      expect(
        () => normalizeServerUrl('http://192.168.1.4:8000', debug: false),
        throwsFormatException,
      );
      expect(
        () => normalizeServerUrl('http://public.example.test', debug: true),
        throwsFormatException,
      );
      expect(
        () => normalizeServerUrl('https://user:secret@example.test'),
        throwsFormatException,
      );
    },
  );
}
