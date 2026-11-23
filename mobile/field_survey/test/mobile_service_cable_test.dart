import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/models.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/service_cable_entry.dart';
import 'package:hazeco_field_survey/service_cable_route.dart';
import 'package:hazeco_field_survey/service_cable_route_entry.dart';
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
  test('mapped route sums every turn and rejects invalid paths', () {
    final route = [
      {'latitude': 34.0, 'longitude': 73.0},
      {'latitude': 34.001, 'longitude': 73.0, 'waypoint': 'Street turn'},
      {'latitude': 34.001, 'longitude': 73.001},
    ];
    final first = serviceCableRouteLength(route.sublist(0, 2))!;
    final second = serviceCableRouteLength(route.sublist(1))!;
    expect(serviceCableRouteLength(route), closeTo(first + second, 0.000001));
    expect(
      serviceCableRouteLength(route),
      greaterThan(serviceCableRouteLength([route.first, route.last])!),
    );
    expect(serviceCableRouteLength([]), isNull);
    expect(serviceCableRouteError([route.first]), isNotNull);
    expect(serviceCableRouteError([route.first, route.first]), isNotNull);
    expect(
      serviceCableRouteError([
        route.first,
        {'latitude': 0, 'longitude': 0},
      ]),
      isNotNull,
    );
    expect(
      serviceCableRouteError([
        route.first,
        {'latitude': 91, 'longitude': 73},
      ]),
      isNotNull,
    );
  });
  testWidgets(
    'route editor records endpoints and a turn, and removes the turn',
    (tester) async {
      var points = <Json>[];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: StatefulBuilder(
                builder: (context, update) => ServiceCableRouteEntry(
                  points: points,
                  onChanged: (value) => update(() => points = value),
                ),
              ),
            ),
          ),
        ),
      );
      Future<void> point(String lat, String lon, String reference) async {
        await tester.pumpAndSettle();
        final fields = find.byType(TextFormField);
        await tester.enterText(fields.at(0), reference);
        await tester.enterText(fields.at(1), lat);
        await tester.enterText(fields.at(2), lon);
        await tester.tap(find.text('Use GPS point'));
        await tester.pumpAndSettle();
        await tester.pump(const Duration(milliseconds: 350));
      }

      await tester.tap(find.text('Set pole and meter GPS coordinates'));
      await point('34', '73', 'Pole');
      await point('34.001', '73.001', 'Meter');
      expect(points.length, 2);
      await tester.ensureVisible(find.text('Add bend / street turn'));
      await tester.tap(find.text('Add bend / street turn'));
      await point('34.001', '73', 'Turn');
      expect(points.map((p) => p['waypoint']), ['Pole', 'Turn', 'Meter']);
      expect(find.textContaining('Mapped route length:'), findsOneWidget);
      await tester.ensureVisible(find.byTooltip('Remove turn'));
      await tester.tap(find.byTooltip('Remove turn'));
      await tester.pump();
      expect(points.map((p) => p['waypoint']), ['Pole', 'Meter']);
      expect(tester.takeException(), isNull);
    },
  );
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
            'route_points': [
              {'latitude': 34.0, 'longitude': 73.0, 'waypoint': 'Pole'},
              {'latitude': 34.001, 'longitude': 73.0, 'waypoint': 'Turn'},
              {'latitude': 34.001, 'longitude': 73.001, 'waypoint': 'Meter'},
            ],
            'route_length_m': 203.37,
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
