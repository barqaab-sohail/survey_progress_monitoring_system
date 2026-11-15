import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/material.dart';
import 'package:hazeco_field_survey/survey_editor.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show newSurvey;

void main() {
  testWidgets(
    'changing the waypoint clears the checked box in the row editor',
    (tester) async {
      tester.view.physicalSize = const Size(800, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final r = newSurvey();
      final row = r.nextRow()
        ..['gps_waypoint'] = '002'
        ..['waypoint_check_required'] = true;
      r.rows.add(row);
      await tester.pumpWidget(
        MaterialApp(
          home: RowEditor(
            record: r,
            index: 0,
            editable: true,
            onChanged: () {},
            onSave: () async {},
          ),
        ),
      );
      final checkbox = find.widgetWithText(
        CheckboxListTile,
        'Waypoint 002 checked',
      );
      await tester.ensureVisible(checkbox);
      await tester.pumpAndSettle();
      await tester.tap(checkbox);
      await tester.pump();
      expect(waypointIsChecked(row), true);
      final input = find.byKey(const ValueKey('gps_waypoint:0'));
      await tester.ensureVisible(input);
      await tester.enterText(input, '003');
      await tester.pump();
      expect(row['gps_waypoint'], '003');
      expect(waypointIsChecked(row), false);
      expect(row['waypoint_confirmation'], isNull);
      expect(row['waypoint_check_required'], false);
      expect(find.text('Waypoint 003 checked'), findsNothing);
      expect(
        SurveyValidation.errors(
          r,
          submitting: true,
        ).containsKey('rows.0.waypoint_confirmation'),
        false,
      );
    },
  );

  test(
    'first historical S remains manual; subsequent rows suggest next waypoint without confirming it',
    () {
      final r = newSurvey();
      final first = r.nextRow();
      r.rows.add(first);
      expect(first['gps_waypoint'], '');
      expect(first['waypoint_check_required'], false);
      first['gps_waypoint'] = '104';
      final e = r.ensureEndRow(0);
      expect(r.rows[e]['gps_waypoint'], '');
      r.rows[e]['gps_waypoint'] = '001';
      final next = r.nextRow();
      r.rows.add(next);
      expect(next['gps_waypoint'], '002');
      expect(next['waypoint_check_required'], true);
      expect(waypointIsChecked(next), false);
      final nextE = r.ensureEndRow(2);
      expect(r.rows[nextE]['gps_waypoint'], '003');
      expect(r.ensureEndRow(2), nextE);
      expect(r.rows.length, 4);
    },
  );
  test(
    'confirmation covers group date and waypoint and survives payload creation',
    () {
      final r = newSurvey();
      final row = r.nextRow()
        ..['gps_waypoint'] = '002'
        ..['waypoint_check_required'] = true;
      r.rows.add(row);
      expect(
        SurveyValidation.errors(r, submitting: true),
        contains('rows.0.waypoint_confirmation'),
      );
      row['waypoint_confirmation'] = waypointConfirmation(row);
      expect(waypointIsChecked(row), true);
      expect(
        SurveyValidation.errors(
          r,
          submitting: true,
        ).containsKey('rows.0.waypoint_confirmation'),
        false,
      );
      final token = row['waypoint_confirmation'];
      row['gps_waypoint'] = '003';
      expect(waypointIsChecked(row), false);
      row['gps_waypoint'] = '002';
      row['group'] = '09';
      expect(waypointIsChecked(row), false);
      row['group'] = '01';
      expect(r.payload()['rows'][0]['waypoint_confirmation'], token);
    },
  );
  test('suggestions do not wrap 999 or carry across recording sessions', () {
    expect(
      suggestedNextWaypoint(
        {'gps_waypoint': '999', 'group': '01', 'date': '2026-11-15'},
        '01',
        '2026-11-15',
      ),
      '',
    );
    expect(
      suggestedNextWaypoint(
        {'gps_waypoint': '001', 'group': '01', 'date': '2026-11-14'},
        '01',
        '2026-11-15',
      ),
      '',
    );
  });
}
