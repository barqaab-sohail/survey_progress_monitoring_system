import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/api_client.dart';
import 'package:hazeco_field_survey/field_controller.dart';
import 'package:hazeco_field_survey/ht_models.dart';
import 'package:hazeco_field_survey/ht_update_page.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show readySurvey, testSession;

Json htData() => {
  'client_uuid': '85d55283-10ad-49d1-aaf6-86d9319c04d9',
  'base_revision': 0,
  'feeder_id': 2,
  'survey_team_id': 4,
  'transformer_code': 'NEW-001',
  'kind': 'ht_extension',
  'capacity_kva': '100',
  'make': 'PEL',
  'location': 'New street',
  'mounting': 'D.Pole',
  'phase': 'R-Y-B',
  'observed_on': '2026-11-09',
  'upstream_reference': 'Existing junction',
  'upstream_feature_id': null,
  'baseline_sha256': null,
  'nodes': [
    htNode()..['waypoint'] = 'J1',
    htNode()..addAll({'waypoint': 'T1', 'conductor_r': 'RABBIT'}),
  ],
  'remarks': 'Extension observed',
  'photos': ['evidence'],
};

void main() {
  sqfliteFfiInit();
  late Directory directory;
  late LocalStore store;
  late String path;
  setUp(() async {
    directory = await Directory.systemTemp.createTemp('ht-workflow-');
    path = '${directory.path}/phone.db';
    store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
  });
  tearDown(() async {
    await store.database.close();
    await directory.delete(recursive: true);
  });

  test(
    'HT outbox survives restart, is immutable and isolated by account',
    () async {
      final data = htData();
      final queued = {
        'data': data,
        'state': 'queued',
        'outbound': htPayload(data),
      };
      await store.saveHt(testSession.scope, queued);
      await store.database.close();
      store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
      final entry = (await store.htUpdates(testSession.scope)).single;
      expect(jsonEncode(entry['outbound']), jsonEncode(queued['outbound']));
      expect(await store.htUpdates('another-account'), isEmpty);
      await expectLater(
        store.saveHt(testSession.scope, {'data': data, 'state': 'draft'}),
        throwsStateError,
      );
      await store.rejectHt(testSession.scope, entry, 'Offline');
      expect(
        (await store.htUpdates(testSession.scope)).single['outbound'],
        queued['outbound'],
      );
      await store.acknowledgeHt(testSession.scope, entry, {
        'client_uuid': data['client_uuid'],
        'revision': 1,
        'status': 'pending',
        'payload': htPayload(data),
      });
      await store.mergeHtReference(testSession.scope, [
        {
          'client_uuid': data['client_uuid'],
          'revision': 2,
          'status': 'returned',
          'review_note': 'Confirm route',
          'payload': htPayload(data),
        },
      ]);
      final returned = (await store.htUpdates(testSession.scope)).single;
      expect(returned['state'], 'returned');
      expect(returned['data']['base_revision'], 2);
      expect(returned['data']['review_note'], 'Confirm route');
      returned['data']['remarks'] = 'Route confirmed';
      returned['state'] = 'draft';
      await store.saveHt(testSession.scope, returned);
      await store.mergeHtReference(testSession.scope, [
        {
          'client_uuid': data['client_uuid'],
          'revision': 2,
          'status': 'returned',
          'payload': htPayload(data),
        },
      ]);
      expect(
        (await store.htUpdates(testSession.scope)).single['data']['remarks'],
        'Route confirmed',
      );
    },
  );

  test('HT uploads before linked LT and LT survives rejected HT', () async {
    final data = htData();
    final record = readySurvey();
    record.header.addAll({
      'substation': 'Grid',
      'sub_division': 'SD',
      'sub_division_code': '001',
      'transformer_make': 'PEL',
      'location': 'Street',
      'mounting': 'D.Pole',
      'duty': 'General Duty',
    });
    for (var i = 0; i < record.rows.length; i++) {
      record.rows[i]['gps_waypoint'] = i == 0 ? '005' : '006';
    }
    record.data['ht_update_uuid'] = data['client_uuid'];
    await store.save(record);
    await store.queue(record);
    await store.saveHt(testSession.scope, {
      'data': data,
      'state': 'queued',
      'outbound': htPayload(data),
    });
    final order = <String>[];
    var failHt = true;
    final controller = FieldController(
      store,
      api: FieldApi(
        client: MockClient((request) async {
          if (request.url.path.endsWith('/ht-updates/sync')) {
            order.add('HT');
            if (failHt) {
              return http.Response(
                jsonEncode({'message': 'Duplicate needs review'}),
                422,
              );
            }
            return http.Response(
              jsonEncode({
                'client_uuid': data['client_uuid'],
                'revision': 1,
                'status': 'pending',
                'payload': htPayload(data),
              }),
              200,
            );
          }
          order.add('LT');
          return http.Response(
            jsonEncode({'client_uuid': record.id, 'revision': 1}),
            200,
          );
        }),
      ),
    )..session = testSession;
    await controller.sync();
    expect(order, ['HT']);
    expect((await store.survey(record.scope, record.id))!.outbound, isNotNull);
    final retry = (await store.htUpdates(testSession.scope)).single;
    retry['state'] = 'queued';
    retry['outbound'] = htPayload(retry['data']);
    await store.saveHt(testSession.scope, retry);
    failHt = false;
    order.clear();
    await controller.sync();
    expect(order, ['HT', 'LT']);
    expect((await store.survey(record.scope, record.id))!.state, 'synced');
    controller.dispose();
  });

  test(
    'HT validation requires route evidence and permits unresolved GPX coordinates',
    () {
      final data = htData();
      expect(htErrors(data), isEmpty);
      expect(htPayload(data)['nodes'][0]['latitude'], isNull);
      data['nodes'][1]['longitude'] = '73';
      expect(htErrors(data).join(), contains('latitude and longitude'));
      data['photos'] = [];
      expect(htErrors(data).join(), contains('photograph'));
    },
  );

  testWidgets(
    'HT screen clearly identifies HT and links immediate LT collection',
    (tester) async {
      final controller = FieldController(store)..session = testSession;
      await tester.pumpWidget(
        MaterialApp(
          home: HtUpdateEditor(
            controller: controller,
            feeder: {
              'id': 2,
              'feeder_code': '000412',
              'feeder_name': 'BAGNOTER',
            },
            team: {'id': 4},
          ),
        ),
      );
      expect(find.text('HT Network Update'), findsOneWidget);
      expect(
        find.textContaining('LT Transformer Survey can start'),
        findsOneWidget,
      );
      expect(find.text('HT update type'), findsOneWidget);
      expect(
        find.text('Transformer code / unique temporary reference'),
        findsOneWidget,
      );
      await tester.pumpWidget(const SizedBox.shrink());
      controller.dispose();
    },
  );
}
