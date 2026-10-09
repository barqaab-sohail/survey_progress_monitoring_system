import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/survey_editor.dart';
import 'models_test.dart' show readySurvey;

void main() {
  testWidgets(
    'row form retains waypoint entry and excludes phone GPS controls',
    (tester) async {
      final record = readySurvey();
      var changed = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: RowEditor(
            record: record,
            index: 0,
            editable: true,
            onChanged: () => changed++,
            onSave: () async {},
          ),
        ),
      );
      final group = find.widgetWithText(TextFormField, 'Group identifier');
      await tester.enterText(group, '0007');
      await tester.pump();
      expect(record.rows.first['group'], '0007');
      final waypoint = find.widgetWithText(
        TextFormField,
        'GPS waypoint identifier',
      );
      await tester.enterText(waypoint, '0');
      await tester.pump();
      final stateBefore = tester.state<FormFieldState>(waypoint);
      await tester.enterText(waypoint, '0008');
      await tester.pump();
      expect(tester.state<FormFieldState>(waypoint), same(stateBefore));
      expect(record.rows.first['gps_waypoint'], '0008');
      expect(find.text('Phone GPS'), findsNothing);
      expect(find.text('Capture current GPS'), findsNothing);
      expect(find.widgetWithText(TextFormField, 'Latitude'), findsNothing);
      expect(find.widgetWithText(TextFormField, 'Longitude'), findsNothing);
      expect(record.rows.first['latitude'], isNull);
      expect(record.rows.first['longitude'], isNull);
      expect(changed, greaterThanOrEqualTo(3));
    },
  );
  testWidgets(
    'consumer form displays all printed codes and preserves blank counts',
    (tester) async {
      final record = readySurvey();
      await tester.pumpWidget(
        MaterialApp(
          home: RowEditor(
            record: record,
            index: 0,
            editable: true,
            onChanged: () {},
            onSave: () async {},
          ),
        ),
      );
      await tester.scrollUntilVisible(
        find.text('RS · 1-phase residential'),
        600,
        scrollable: find.byType(Scrollable).first,
      );
      final residential = find.widgetWithText(
        TextFormField,
        'RS · 1-phase residential',
      );
      await tester.enterText(residential, '0');
      await tester.pump();
      expect(record.rows.first['consumers']['rs'], '0');
      expect(record.rows.first['consumers'].containsKey('rl'), isFalse);
      await tester.enterText(residential, '1.5');
      await tester.pump();
      expect(record.rows.first['consumers']['rs'], '1.5');
      expect(find.text('Enter a whole number of 0 or more.'), findsOneWidget);
      await tester.enterText(residential, '0');
      await tester.pump();
      await tester.scrollUntilVisible(
        find.text('Intersection'),
        -450,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Intersection'), findsOneWidget);
      await tester.drag(find.byType(ListView), const Offset(0, 200));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      expect(record.rows.first['intersection'], isTrue);
      expect(record.rows.first['consumers']['rs'], '0');
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      expect(record.rows.first['intersection'], isFalse);
    },
  );
}
