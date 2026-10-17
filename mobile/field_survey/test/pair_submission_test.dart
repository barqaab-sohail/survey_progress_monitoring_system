import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/api_client.dart';
import 'package:hazeco_field_survey/field_controller.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/main.dart';
import 'package:hazeco_field_survey/models.dart';
import 'package:hazeco_field_survey/survey_editor.dart';
import 'models_test.dart' show readySurvey;

// UI tests use synchronous in-memory persistence; real SQLite has separate checks.
class WidgetSurveyStore extends LocalStore {
  WidgetSurveyStore(super.database);
  final saved = <String, SurveyRecord>{};
  @override
  Future<void> save(SurveyRecord record) async {
    saved[record.id] = record.clone();
  }

  @override
  Future<SurveyRecord?> survey(String scope, String id) async =>
      saved[id]?.clone();
  @override
  Future<List<Json>> attachments(String scope, String surveyId) async => [];
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();
  late LocalStore store;
  late FieldController controller;
  setUp(() async {
    FlutterSecureStorage.setMockInitialValues({});
    store = await LocalStore.open(
      factory: databaseFactoryFfi,
      path: inMemoryDatabasePath,
    );
    controller = FieldController(store);
  });
  tearDown(() async {
    controller.dispose();
    await store.database.close();
  });

  test(
    'an incomplete legacy E can gain its missing S without losing values',
    () {
      final record = readySurvey();
      record.rows.removeAt(0);
      record.rows.first.remove('pair_id');
      record.rows.first.remove('date');
      final originalEnd = copyJson(record.rows.first);
      expect(record.ensurePartnerRow(0), 0);
      expect(record.rows.first['se'], 'S');
      final savedEnd = copyJson(record.rows[1])..remove('pair_id');
      expect(savedEnd, originalEnd);
      expect(record.ensureEndRow(0), 1);
      expect(record.rows, hasLength(2));
    },
  );

  test(
    'partners have stable IDs and creation is idempotent across edits/reload/deletion',
    () {
      final record = readySurvey(paired: false);
      final first = copyJson(record.rows.first);
      final end = record.ensureEndRow(0);
      final second = copyJson(record.rows[end]);
      expect(end, 1);
      expect(second['se'], 'E');
      expect(second['pair_id'], first['pair_id']);
      expect(second['group'], '01'); // Assigned team, not the legacy S group.
      expect(second['date'], first['date']);
      expect(second['gps_waypoint'], '');
      expect(second['equipment_type'], '');
      record.rows.first['group'] = '017';
      expect(record.ensureEndRow(0), 1);
      expect(record.rows, hasLength(2));
      expect(record.rows[1], second); // Reopening S never resets the E draft.
      record.rows.add(record.nextRow());
      record.ensureEndRow(2);
      final otherIds = record.rows.skip(2).map((r) => r['pair_id']).toList();
      final restored = record.clone();
      restored.removePairAt(
        1,
      ); // Deleting E explicitly removes its own pair only.
      expect(restored.rows.map((r) => r['pair_id']).toList(), otherIds);
      expect(restored.rows.map((r) => r['se']).toList(), ['S', 'E']);
    },
  );

  test(
    'legacy values stay intact and pair IDs cannot merge neighboring orphans',
    () {
      final record = readySurvey();
      for (final row in record.rows) {
        row.remove('pair_id');
      }
      final legacyValues = record.rows.map(copyJson).toList();
      expect(record.ensurePairIds(), true);
      expect(record.ensurePairIds(), false);
      for (var i = 0; i < 2; i++) {
        final values = copyJson(record.rows[i])..remove('pair_id');
        expect(values, legacyValues[i]);
      }
      expect(record.hasCompleteValidPair, true);
      record.rows[1]['pair_id'] = SurveyRecord.newRow('2026-01-01')['pair_id'];
      expect(record.hasCompleteValidPair, false);
      expect(
        SurveyValidation.errors(record, submitting: true),
        contains('rows.1.pair'),
      );
    },
  );

  test(
    'every mandatory row field is enforced, neutral and zero height/IDs are valid',
    () {
      final record = readySurvey();
      record.rows[1].addAll({
        'group': '00',
        'gps_waypoint': '0',
        'pole_height_ft': '0',
      });
      expect(SurveyValidation.errors(record, submitting: true), isEmpty);
      for (final key in [
        'se',
        'group',
        'date',
        'gps_waypoint',
        'equipment_type',
        'pole_class',
        'pole_height_ft',
      ]) {
        final value = record.rows[1].remove(key);
        expect(
          SurveyValidation.errors(record, submitting: true),
          contains('rows.1.$key'),
          reason: key,
        );
        record.rows[1][key] = value;
      }
      record.rows[1]['conductor_neutral'] = '__none__';
      expect(
        SurveyValidation.errors(record, submitting: true),
        contains('rows.1.conductor_neutral'),
      );
      record.rows[1]['conductor_r'] = 'W';
      expect(SurveyValidation.errors(record, submitting: true), isEmpty);
      record.rows.add(copyJson(record.rows[1]));
      expect(
        SurveyValidation.errors(record, submitting: true),
        contains('rows.2.pair'),
      );
    },
  );

  test(
    'incomplete drafts persist but cannot be queued even after one complete pair',
    () async {
      final record = readySurvey();
      record.rows.add(record.nextRow());
      expect(record.hasCompleteValidPair, true);
      await store.save(record);
      await expectLater(store.queue(record), throwsStateError);
      final restored = (await store.survey(record.scope, record.id))!;
      expect(restored.rows, hasLength(3));
      expect(restored.outbound, isNull);
      expect(restored.state, 'draft');
    },
  );

  test('queue validation uses cached project equipment choices', () async {
    final record = readySurvey();
    for (final row in record.rows) {
      row['equipment_type'] = 'CUSTOM';
    }
    expect(record.hasCompleteValidPair, false);
    expect(record.hasCompletePairWithEquipment(['CUSTOM']), true);
    await store.cacheBootstrap(record.scope, {
      'feeders': [
        {
          'id': record.data['feeder_id'],
          'entry_lookups': {
            'equipment_types': {'CUSTOM': 'Project equipment'},
          },
        },
      ],
    });
    await store.save(record);
    await store.queue(record);
    expect(
      (await store.survey(
        record.scope,
        record.id,
      ))!.outbound!['rows'][1]['equipment_type'],
      'CUSTOM',
    );
  });

  test('login uses fixed production URL despite an old saved URL', () async {
    final urls = <String>[];
    controller.dispose();
    controller = FieldController(
      store,
      api: FieldApi(
        client: MockClient((request) async {
          urls.add(request.url.toString());
          if (request.url.path.endsWith('/login')) {
            return http.Response(
              jsonEncode({
                'token': 'test-token',
                'user': {'id': 1, 'name': 'Inspector'},
              }),
              200,
            );
          }
          return http.Response(
            '{"teams":[],"feeders":[],"transformers":[]}',
            200,
          );
        }),
      ),
    );
    await store.setSetting('last_url', 'https://old.example.test');
    await controller.login('inspector@example.test', 'test-password');
    expect(urls, contains('$applicationBaseUrl/api/v1/field/login'));
    expect(urls, contains('$applicationBaseUrl/api/v1/field/bootstrap'));
    expect(urls.every((url) => url.startsWith('$applicationBaseUrl/')), true);
  });

  testWidgets('PV entry and photo actions belong to the opened row', (
    tester,
  ) async {
    final record = readySurvey();
    record.solar.add({'consumer_reference': 'LEGACY', 'installed_pv_kw': '1'});
    final uiStore = WidgetSurveyStore(store.database);
    controller.dispose();
    controller = FieldController(uiStore)
      ..reference = {
        'teams': [
          {'id': 4, 'name': 'Survey Team 01'},
        ],
      };
    await uiStore.save(record);
    await tester.pumpWidget(
      MaterialApp(
        home: SurveyEditor(controller: controller, record: record),
      ),
    );
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<SegmentedButton<int>>(find.byType(SegmentedButton<int>))
          .segments,
      hasLength(2),
    );
    expect(find.text('Solar'), findsNothing);
    expect(find.text('Photos'), findsNothing);
    await tester.tap(find.text('S/E rows'));
    await tester.pumpAndSettle();
    expect(find.text('Previously saved unlinked records'), findsOneWidget);
    await tester.tap(find.textContaining('Row 1 ·'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Group identifier'),
      '07',
    );
    await tester.pump();
    expect(record.rows.first['group'], '07');
    final add = find.text('Add solar installation');
    await tester.scrollUntilVisible(
      add,
      500,
      scrollable: find.byType(Scrollable).first,
    );
    await Scrollable.ensureVisible(tester.element(add), alignment: 0.5);
    await tester.pumpAndSettle();
    await tester.tap(add);
    await tester.pumpAndSettle();
    expect(record.solar, hasLength(2));
    expect(record.solar[0], isNot(contains('row_id')));
    expect(record.solar[1]['row_id'], record.rows.first['row_id']);
    final meter = find.widgetWithText(TextFormField, 'Meter Number');
    await tester.scrollUntilVisible(
      meter,
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await Scrollable.ensureVisible(tester.element(meter), alignment: 0.5);
    await tester.pumpAndSettle();
    await tester.enterText(meter, '001-A/9');
    await tester.pump(const Duration(milliseconds: 600));
    await tester.scrollUntilVisible(
      find.text('Take photo'),
      350,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Choose image'), findsOneWidget);
    expect(record.solar[1]['meter_number'], '001-A/9');
    await tester.tap(find.byIcon(Icons.arrow_back));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Row 2 ·'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Group identifier'),
      '09',
    );
    await tester.pump();
    await tester.scrollUntilVisible(
      add,
      500,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.widgetWithText(TextFormField, 'Meter Number'), findsNothing);
    await Scrollable.ensureVisible(tester.element(add), alignment: 0.5);
    await tester.pumpAndSettle();
    await tester.tap(add);
    await tester.pumpAndSettle();
    expect(record.solar[2]['row_id'], record.rows[1]['row_id']);
    await tester.tap(find.byIcon(Icons.arrow_back));
    await tester.pumpAndSettle();
    expect(uiStore.saved[record.id]!.solar[1]['meter_number'], '001-A/9');
    expect(uiStore.saved[record.id]!.rows.map((row) => row['group']), [
      '07',
      '09',
    ]);
    await tester.tap(find.textContaining('Row 1 ·'));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<TextFormField>(
            find.widgetWithText(TextFormField, 'Group identifier'),
          )
          .initialValue,
      '07',
    );
    await tester.tap(find.byIcon(Icons.arrow_back));
    await tester.pumpAndSettle();
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('login screen does not request a server URL', (tester) async {
    await tester.pumpWidget(
      MaterialApp(home: LoginPage(controller: controller)),
    );
    expect(find.widgetWithText(TextFormField, 'Application URL'), findsNothing);
    expect(find.widgetWithText(TextFormField, 'Email'), findsOneWidget);
  });

  testWidgets(
    'S completion opens E automatically and submission is only in S/E stage',
    (tester) async {
      final record = readySurvey(paired: false);
      final uiStore = WidgetSurveyStore(store.database);
      controller.dispose();
      controller = FieldController(uiStore)
        ..reference = {
          'teams': [
            {'id': 4, 'name': 'Survey Team 01'},
          ],
        };
      await uiStore.save(record);
      await tester.pumpWidget(
        MaterialApp(
          home: SurveyEditor(controller: controller, record: record),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Submit survey'), findsNothing);
      await tester.tap(find.text('S/E rows'));
      await tester.pumpAndSettle();
      expect(find.text('Submit survey'), findsNothing);
      final rowTile = find.widgetWithText(ListTile, 'Row 1 · 0005');
      await tester.scrollUntilVisible(
        rowTile,
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await Scrollable.ensureVisible(tester.element(rowTile), alignment: 0.5);
      await tester.pumpAndSettle();
      await tester.tap(rowTile);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();
      expect(record.rows, hasLength(2));
      expect(find.text('S/E row 2'), findsOneWidget);
      expect(record.rows[1]['se'], 'E');
      expect(record.rows[1]['pair_id'], record.rows[0]['pair_id']);
      await tester.enterText(
        find.widgetWithText(TextFormField, 'GPS waypoint identifier'),
        '017',
      );
      final copy = find.text('Copy Previous Values');
      await tester.scrollUntilVisible(
        copy,
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await Scrollable.ensureVisible(tester.element(copy), alignment: 0.5);
      await tester.pumpAndSettle();
      await tester.tap(copy);
      await tester.pump();
      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();
      expect(find.text('Submit survey'), findsOneWidget);
      expect(
        (await uiStore.survey(
          record.scope,
          record.id,
        ))!.rows[1]['gps_waypoint'],
        '017',
      );
      // Switching away from the S/E stage hides submission even for a completed pair.
      await tester.drag(find.byType(ListView).first, const Offset(0, 900));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Details'));
      await tester.pumpAndSettle();
      expect(find.text('Submit survey'), findsNothing);
      await tester.tap(find.text('S/E rows'));
      await tester.pumpAndSettle();
      final addPair = find.text('Add / complete S/E pair');
      await tester.scrollUntilVisible(
        addPair,
        -250,
        scrollable: find.byType(Scrollable).first,
      );
      await Scrollable.ensureVisible(tester.element(addPair), alignment: 0.5);
      await tester.pumpAndSettle();
      await tester.tap(addPair);
      await tester.pumpAndSettle();
      expect(find.text('S/E row 3'), findsOneWidget);
      await tester.tap(
        find.byIcon(Icons.arrow_back),
      ); // Keep this unfinished draft.
      await tester.pumpAndSettle();
      expect(
        find.text('Submit survey'),
        findsOneWidget,
      ); // A complete first pair exists.
      await tester.tap(find.text('Submit survey'));
      await tester.pumpAndSettle();
      expect(find.text('Submit this survey?'), findsNothing);
      expect(
        find.text('Review the highlighted fields before submitting.'),
        findsOneWidget,
      );
      expect(record.outbound, isNull);
      expect(record.rows, hasLength(3));
      await tester.pumpWidget(const SizedBox());
    },
  );

  testWidgets(
    'Done keeps an incomplete row open, shows errors and retains zero values',
    (tester) async {
      final record = readySurvey(paired: false);
      record.rows.first['group'] = '';
      var saves = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: RowEditor(
            record: record,
            index: 0,
            editable: true,
            onChanged: () {},
            onSave: () async {
              saves++;
            },
          ),
        ),
      );
      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();
      expect(find.text('Group identifier is required.'), findsOneWidget);
      expect(find.text('S/E row 1'), findsOneWidget);
      expect(record.rows, hasLength(1));
      expect(record.rows.first['pole_height_ft'], '0');
      expect(record.rows.first['gps_waypoint'], '0005');
      expect(saves, 1);
    },
  );

  test(
    'restoring an old host keeps its saved notebook and does not call that host',
    () async {
      final record = readySurvey();
      await store.save(record);
      FlutterSecureStorage.setMockInitialValues({
        'field_session': jsonEncode({
          'url': 'https://survey.example.test',
          'token': 'old-token',
          'user_id': 1,
          'name': 'Inspector',
        }),
      });
      controller.dispose();
      var requests = 0;
      controller = FieldController(
        store,
        api: FieldApi(
          client: MockClient((_) async {
            requests++;
            return http.Response('{}', 500);
          }),
        ),
      );
      await controller.initialize();
      expect(controller.session, isNull);
      expect(requests, 0);
      expect((await store.survey(record.scope, record.id))!.rows, record.rows);
    },
  );

  testWidgets(
    'pair deletion requires confirmation and retains the other pair',
    (tester) async {
      final record = readySurvey();
      final other = readySurvey();
      record.rows.addAll(other.rows);
      final uiStore = WidgetSurveyStore(store.database);
      controller.dispose();
      controller = FieldController(uiStore)
        ..reference = {
          'teams': [
            {'id': 4, 'name': 'Survey Team 01'},
          ],
        };
      await tester.pumpWidget(
        MaterialApp(
          home: SurveyEditor(controller: controller, record: record),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('S/E rows'));
      await tester.pumpAndSettle();
      final remove = find.byTooltip('Remove pair').first;
      await tester.scrollUntilVisible(
        remove,
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await Scrollable.ensureVisible(tester.element(remove), alignment: 0.5);
      await tester.pumpAndSettle();
      await tester.tap(remove);
      await tester.pumpAndSettle();
      expect(find.text('Remove this S/E pair?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(record.rows, hasLength(4));
      await tester.tap(remove);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Remove'));
      await tester.pumpAndSettle();
      expect(record.rows, other.rows);
      await tester.pumpWidget(const SizedBox());
    },
  );
}
