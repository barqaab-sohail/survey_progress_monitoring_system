import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/models.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/service_cable_entry.dart';
import 'models_test.dart' show newSurvey;

SurveyRecord meterSurvey() {
  final r = newSurvey();
  for (final n in [1, 2]) {
    r.rows.add(
      SurveyRecord.newRow(r.data['survey_date'])..addAll({
        'se': 'E',
        'group': '01',
        'gps_waypoint': '00$n',
        'consumers': {'rs': n == 2 ? '23' : '0'},
      }),
    );
  }
  return r;
}

void main() {
  testWidgets(
    'meter entry selects a consumer pole and advances waypoint and slot without changing counts',
    (tester) async {
      final r = meterSurvey();
      var changes = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: MobileServiceCableEntry(
                record: r,
                enabled: true,
                onChanged: () => changes++,
              ),
            ),
          ),
        ),
      );
      expect(find.text('Consumer 1 of 23 · RS'), findsOneWidget);
      final save = find.text('Save & add next meter');
      await tester.ensureVisible(save);
      await tester.tap(save);
      await tester.pump();
      expect(r.serviceCables.length, 1);
      expect(r.serviceCables[0]['gps_waypoint'], '003');
      expect(r.serviceCables[0]['pole_row_id'], r.rows[1]['row_id']);
      expect(find.text('Consumer 2 of 23 · RS'), findsOneWidget);
      await tester.ensureVisible(save);
      await tester.tap(save);
      await tester.pump();
      expect(r.serviceCables.length, 2);
      expect(r.serviceCables[1]['gps_waypoint'], '004');
      expect(r.serviceCables[1]['consumer_number'], 2);
      expect(changes, 2);
      expect(r.rows[1]['consumers']['rs'], '23');
      expect(serviceCableErrors(r), isEmpty);
      final duplicate = r.clone();
      duplicate.serviceCables[1]['gps_waypoint'] = '003';
      expect(
        serviceCableErrors(duplicate),
        contains('service_cables.1.gps_waypoint'),
      );
    },
  );
  testWidgets(
    'locked surveys display stored connections without editable controls',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: MobileServiceCableEntry(
                record: meterSurvey(),
                enabled: false,
                onChanged: () {},
              ),
            ),
          ),
        ),
      );
      expect(find.text('Save & add next meter'), findsNothing);
      expect(find.byType(TextField), findsNothing);
    },
  );
  test(
    'meter IDs and pole links survive offline storage and payload creation',
    () async {
      sqfliteFfiInit();
      final dir = await Directory.systemTemp.createTemp('hazeco-meter-test-');
      final store = await LocalStore.open(
        factory: databaseFactoryFfi,
        path: '${dir.path}/test.db',
      );
      try {
        final r = meterSurvey();
        r.data['service_cables'] = [
          {
            'cable_id': 'ec71375f-c44f-4f16-9c27-6f1060102100',
            'pole_row_id': r.rows[1]['row_id'],
            'category': 'rs',
            'consumer_number': 1,
            'group': '01',
            'date': r.data['survey_date'],
            'gps_waypoint': '003',
          },
        ];
        await store.save(r);
        final loaded = await store.survey(r.scope, r.id);
        expect(loaded!.serviceCables, r.serviceCables);
        expect(loaded.payload()['service_cables'], r.serviceCables);
      } finally {
        await store.database.close();
        await dir.delete(recursive: true);
      }
    },
  );
}
