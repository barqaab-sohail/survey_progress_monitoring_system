import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/survey_editor.dart';
import 'models_test.dart' show readySurvey;

void main() {
  testWidgets(
    'row form saves leading zeros and a multi-character GPS coordinate without losing focus',
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
      await tester.scrollUntilVisible(
        find.text('Latitude'),
        350,
        scrollable: find.byType(Scrollable).first,
      );
      final latitude = find.widgetWithText(TextFormField, 'Latitude');
      await tester.enterText(latitude, '3');
      await tester.pump();
      final stateBefore = tester.state<FormFieldState>(latitude);
      await tester.enterText(latitude, '33.25');
      await tester.pump();
      expect(tester.state<FormFieldState>(latitude), same(stateBefore));
      expect(record.rows.first['latitude'], '33.25');
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
        find.text('Int (separate field)'),
        450,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Int (separate field)'), findsOneWidget);
    },
  );
}
