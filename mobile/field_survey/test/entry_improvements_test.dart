import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/feeder_picker.dart';
import 'package:hazeco_field_survey/models.dart';
import 'package:hazeco_field_survey/survey_editor.dart';
import 'models_test.dart' show readySurvey;

void main() {
  testWidgets('equipment choices and copied fields update the visible phase', (
    tester,
  ) async {
    final record = readySurvey();
    record.rows.first.addAll({
      'conductor_y': 'W',
      'equipment_type': 'SP',
      'pole_height_ft': '31',
    });
    record.rows.add(record.nextRow());
    await tester.pumpWidget(
      MaterialApp(
        home: RowEditor(
          record: record,
          index: 1,
          editable: true,
          equipmentOptions: const {
            'TR': 'Transformer',
            'SP': 'SP',
            'CUSTOM': 'Project equipment',
          },
          onChanged: () {},
          onSave: () async {},
        ),
      ),
    );
    await tester.scrollUntilVisible(
      find.text('Copy Previous Values'),
      400,
      scrollable: find.byType(Scrollable).first,
    );
    await Scrollable.ensureVisible(
      tester.element(find.text('Copy Previous Values')),
      alignment: 0.5,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Copy Previous Values'));
    await tester.pump();
    expect(record.rows[1]['equipment_type'], 'SP');
    expect(record.rows[1]['se'], 'E');
    expect(record.rows[1]['phase'], 'Y');
    await tester.scrollUntilVisible(
      find.widgetWithText(TextFormField, 'Phase'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(
      tester
          .widget<TextFormField>(find.widgetWithText(TextFormField, 'Phase'))
          .initialValue,
      'Y',
    );
    final equipment = find.widgetWithText(
      DropdownButtonFormField<String>,
      'Equipment type',
    );
    await tester.scrollUntilVisible(
      equipment,
      250,
      scrollable: find.byType(Scrollable).first,
    );
    await Scrollable.ensureVisible(tester.element(equipment), alignment: 0.5);
    await tester.pumpAndSettle();
    await tester.tap(equipment);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Project equipment').last);
    await tester.pumpAndSettle();
    expect(record.rows[1]['equipment_type'], 'CUSTOM');
    expect(find.widgetWithText(TextFormField, 'Equipment type'), findsNothing);
  });
  test(
    'new rows alternate without modifying old rows and copy exclusions hold',
    () {
      final record = readySurvey();
      final first = record.rows.first;
      first.addAll({
        'group': '001',
        'date': '2024-02-29',
        'conductor_r': 'A',
        'equipment_type': 'TR',
        'pole_class': 'PCO',
        'pole_height_ft': '36',
        'intersection': true,
        'latitude': 30,
        'remarks': 'Do not copy',
      });
      final original = copyJson(first);
      record.copyPreviousValues(0);
      expect(first, original);
      final next = record.nextRow();
      expect(next['se'], 'E');
      expect(next['group'], '001');
      expect(next['date'], '2024-02-29');
      expect(next['gps_waypoint'], '');
      record.rows.add(next);
      next['gps_waypoint'] = '017';
      record.copyPreviousValues(1);
      expect(next['conductor_r'], 'A');
      expect(next['phase'], 'R');
      expect(next['equipment_type'], 'TR');
      expect(next['pole_height_ft'], '36');
      expect(next['gps_waypoint'], '017');
      expect(next['se'], 'E');
      expect(next['intersection'], false);
      expect(next['latitude'], isNull);
      expect(next['consumers'], isEmpty);
      expect(next['remarks'], '');
      expect(first, original);
      expect(record.nextRow()['se'], 'S');
      next['conductor_r'] = '';
      expect(record.payload()['rows'][1]['phase'], '');
    },
  );

  test('dates validate actual calendars and identifiers remain strings', () {
    expect(parseEntryDate('171026'), '2026-10-17');
    expect(parseEntryDate('290224'), '2024-02-29');
    expect(parseEntryDate('290223'), isNull);
    expect(parseEntryDate('310426'), isNull);
    expect(parseEntryDate('17102026'), isNull);
    expect(parseEntryDate('1710x6'), isNull);
    expect(parseEntryDate('311299', yearStart: 1950), '1999-12-31');
    final record = readySurvey();
    record.rows.first.addAll({'group': '001', 'gps_waypoint': '017'});
    record.solar.add({
      'consumer_reference': '',
      'meter_number': '001-AB/09',
      'service_load_kw': '2.25',
      'installed_pv_kw': '3.5',
      'remarks': 'Meter only',
    });
    expect(SurveyValidation.errors(record, submitting: true), isEmpty);
    final payload = record.payload(submitted: true);
    expect(payload['rows'][0]['group'], '001');
    expect(payload['rows'][0]['gps_waypoint'], '017');
    expect(payload['solar'][0]['meter_number'], '001-AB/09');
    expect(payload['solar'][0]['service_load_kw'], 2.25);
    record.rows.first['group'] = '00x';
    expect(SurveyValidation.errors(record), contains('rows.0.group'));
    record.rows.first.remove('entry_version'); // Pre-upgrade saved row.
    expect(SurveyValidation.errors(record), isNot(contains('rows.0.group')));
  });

  testWidgets('feeder search matches partial/full code and shows name', (
    tester,
  ) async {
    int selected = 1;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SizedBox(
            width: 400,
            child: FeederPicker(
              feeders: [
                {'id': 1, 'feeder_code': '407', 'feeder_name': 'Nawan Shehra'},
                {'id': 2, 'feeder_code': '508', 'feeder_name': 'Other feeder'},
              ],
              selectedId: selected,
              onSelected: (value) => selected = value,
            ),
          ),
        ),
      ),
    );
    await tester.enterText(find.byType(TextField), '40');
    await tester.pumpAndSettle();
    expect(find.text('407 · Nawan Shehra').hitTestable(), findsOneWidget);
    expect(find.text('508 · Other feeder').hitTestable(), findsNothing);
    await tester.enterText(find.byType(TextField), '508');
    await tester.pumpAndSettle();
    await tester.tap(find.text('508 · Other feeder').hitTestable());
    await tester.pumpAndSettle();
    expect(selected, 2);
  });

  testWidgets(
    'date input preserves existing date and reports invalid manual dates',
    (tester) async {
      String stored = '2024-02-29';
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: DateEntry(
              label: 'Row date',
              value: stored,
              enabled: true,
              onChanged: (value) => stored = value,
            ),
          ),
        ),
      );
      expect(find.text('290224'), findsOneWidget);
      expect(stored, '2024-02-29');
      await tester.enterText(find.byType(TextFormField), '290223');
      await tester.pump();
      expect(
        find.text('Enter a valid six-digit date (DDMMYY).'),
        findsOneWidget,
      );
      expect(stored, '290223');
      await tester.enterText(find.byType(TextFormField), '17102026');
      await tester.pump();
      expect(
        stored,
        '17102026',
      ); // Never truncate an eight-digit date to another year.
      expect(
        find.text('Enter a valid six-digit date (DDMMYY).'),
        findsOneWidget,
      );
      await tester.enterText(find.byType(TextFormField), '010124');
      await tester.pump();
      expect(stored, '2024-01-01');
    },
  );

  testWidgets('pasted identifiers filter non-digits and retain zeros', (
    tester,
  ) async {
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
    final input = find.widgetWithText(TextFormField, 'Group identifier');
    await tester.enterText(input, '00a1-');
    await tester.pump();
    expect(record.rows.first['group'], '001');
    final editable = tester.widget<TextField>(
      find.descendant(of: input, matching: find.byType(TextField)),
    );
    expect(editable.keyboardType, TextInputType.number);
  });
}
