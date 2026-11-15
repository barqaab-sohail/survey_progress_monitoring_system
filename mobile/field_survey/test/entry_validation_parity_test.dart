import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show newSurvey;

SurveyRecord example() {
  final r = newSurvey();
  r.header['capacity_kva'] = '100';
  r.rows.add(
    SurveyRecord.newRow(r.data['survey_date'])..addAll({
      'se': 'S',
      'group': '01',
      'gps_waypoint': '000',
      'pole_height_ft': '30',
      'conductor_r': 'ANT',
    }),
  );
  final e = r.ensureEndRow(0);
  r.copyPreviousValues(e);
  r.rows[e]['gps_waypoint'] = '001';
  return r;
}

void main() {
  test('height limits and approved values reject invalid offline entries', () {
    final r = example();
    for (final value in ['0', '29.99', '10000.01']) {
      r.rows[0]['pole_height_ft'] = value;
      expect(SurveyValidation.errors(r), contains('rows.0.pole_height_ft'));
    }
    for (final value in ['30', '10000']) {
      r.rows[0]['pole_height_ft'] = value;
      expect(
        SurveyValidation.errors(r).containsKey('rows.0.pole_height_ft'),
        false,
      );
    }
    r.rows[0]['conductor_r'] = 'invented-code';
    r.header['capacity_kva'] = '123.45';
    final errors = SurveyValidation.errors(r);
    expect(errors, contains('rows.0.conductor_r'));
    expect(errors, contains('header.capacity_kva'));
  });
  test(
    'pair mismatch and disconnected spans block submission but not draft network editing',
    () {
      final r = example();
      r.rows[1]['conductor_r'] = 'WASP';
      expect(
        SurveyValidation.errors(r, submitting: true),
        contains('rows.1.conductor_r'),
      );
      r.rows[1]['conductor_r'] = 'ANT';
      r.rows.add(
        SurveyRecord.newRow(r.data['survey_date'])..addAll({
          'se': 'S',
          'group': '01',
          'gps_waypoint': '099',
          'pole_height_ft': '30',
          'conductor_r': 'ANT',
        }),
      );
      final e = r.ensureEndRow(2);
      r.copyPreviousValues(e);
      r.rows[e]['gps_waypoint'] = '098';
      expect(surveyNetworkErrors(r), contains('rows.2.gps_waypoint'));
      r.rows[2]['gps_waypoint'] = '001';
      expect(surveyNetworkErrors(r), isEmpty);
      r.rows[e]['gps_waypoint'] = '001';
      expect(surveyNetworkErrors(r), contains('rows.2.gps_waypoint'));
    },
  );
  test(
    'PV requires either identifier and ST totals are derived from phases',
    () {
      final r = example();
      r.solar.add({'installed_pv_kw': '5'});
      expect(
        SurveyValidation.errors(r, submitting: true),
        contains('solar.0.consumer_reference'),
      );
      r.solar[0]['meter_number'] = 'M123';
      expect(
        SurveyValidation.errors(
          r,
          submitting: true,
        ).containsKey('solar.0.consumer_reference'),
        false,
      );
      r.rows[1]['consumer_phases'] = {
        'st': {'R': '2', 'Y': '3', 'B': '4'},
      };
      updatePhaseConsumerTotals(r.rows[1]);
      expect(r.rows[1]['consumers']['st'], 9);
      expect(r.payload()['rows'][1]['consumer_phases']['st']['R'], 2);
    },
  );
}
