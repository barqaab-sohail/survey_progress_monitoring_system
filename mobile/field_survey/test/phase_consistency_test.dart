import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/models.dart';
import 'package:hazeco_field_survey/survey_editor.dart';

SurveyRecord phaseSurvey() {
  const session = FieldSession(
    url: 'https://example.test',
    token: 'token',
    userId: 1,
    name: 'Test',
  );
  final record = SurveyRecord.create(
    session,
    {'id': 1, 'name': 'Team 01'},
    {'id': 1, 'feeder_name': 'Feeder'},
  );
  record.data['transformer_code'] = 'T-test';
  record.rows.add(
    SurveyRecord.newRow('2026-11-02')..addAll({
      'se': 'S',
      'gps_waypoint': '020',
      'conductor_r': '__none__',
      'conductor_y': 'A',
      'conductor_b': 'A',
    }),
  );
  final end = record.ensureEndRow(0);
  record.rows[end].addAll({
    'gps_waypoint': '021',
    'conductor_r': 'A',
    'consumer_phases': {
      'rs': {'R': '2', 'Y': '1', 'B': '0'},
    },
  });
  return record;
}

void main() {
  testWidgets(
    'phase fields use S conductors and retain conflicts until explicitly cleared',
    (tester) async {
      final record = phaseSurvey();
      await tester.pumpWidget(
        MaterialApp(
          home: RowEditor(
            record: record,
            index: 1,
            editable: true,
            onChanged: () {},
            onSave: () async {},
          ),
        ),
      );
      await tester.scrollUntilVisible(
        find.byKey(const ValueKey('rs-total')),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      final red = find
          .byWidgetPredicate(
            (widget) => widget is EntryField && widget.label == 'R',
          )
          .first;
      await tester.scrollUntilVisible(
        red,
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<EditableText>(
              find.descendant(of: red, matching: find.byType(EditableText)),
            )
            .readOnly,
        isTrue,
      );
      expect(
        tester
            .widget<EditableText>(
              find.descendant(
                of: find
                    .byWidgetPredicate(
                      (widget) => widget is EntryField && widget.label == 'Y',
                    )
                    .first,
                matching: find.byType(EditableText),
              ),
            )
            .readOnly,
        isFalse,
      );
      expect(record.rows[1]['consumer_phases']['rs']['R'], '2');
      final clear = find.text('Clear recorded R customer count');
      await tester.scrollUntilVisible(
        clear,
        180,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(clear);
      await tester.pumpAndSettle();
      expect(record.rows[1]['consumer_phases']['rs']['R'], '');
      expect(record.rows[1]['consumer_phases']['rs']['Y'], '1');
      expect(record.rows[0]['conductor_r'], '__none__');
    },
  );
  test(
    'customer counts follow paired S conductors; neither counts nor E conductors are rewritten',
    () {
      final record = phaseSurvey();
      final originalCounts = copyJson(
        record.rows[1]['consumer_phases'] as Json,
      );
      final errors = surveyPhaseConsistencyErrors(record);
      expect(
        errors.keys,
        containsAll(['rows.1.consumer_phases.rs.R', 'rows.0.conductor_r']),
      );
      expect(errors.values.first, contains('T-test, E 021'));
      expect(record.rows[1]['consumer_phases'], originalCounts);
      expect(record.rows[1]['conductor_r'], 'A');
      record.rows[0]['conductor_r'] = 'A';
      expect(surveyPhaseConsistencyErrors(record, submitting: true), isEmpty);
      record.rows[0]['conductor_r'] = '__none__';
      expect(surveyPhaseConsistencyErrors(record), isNotEmpty);
      (record.rows[1]['consumer_phases'] as Json)['rs']['R'] = '0';
      expect(surveyPhaseConsistencyErrors(record), isEmpty);
    },
  );

  test(
    'unknown and orphan drafts remain saveable, but submission needs a recorded customer conductor',
    () {
      final record = phaseSurvey();
      record.rows[0]['conductor_r'] = '';
      expect(surveyPhaseConsistencyErrors(record), isEmpty);
      expect(
        surveyPhaseConsistencyErrors(record, submitting: true),
        isNotEmpty,
      );
      expect(
        SurveyValidation.errors(record).keys,
        isNot(contains('rows.1.consumer_phases.rs.R')),
      );
      record.rows.removeAt(0);
      expect(surveyPhaseConsistencyErrors(record), isEmpty);
    },
  );
}
