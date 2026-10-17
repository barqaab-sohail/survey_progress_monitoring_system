import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/api_client.dart';
import 'package:hazeco_field_survey/field_controller.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show readySurvey, newSurvey, testSession;

void main() {
  sqfliteFfiInit();
  late LocalStore store;
  late Directory directory;
  late String path;
  setUp(() async {
    directory = await Directory.systemTemp.createTemp('hazeco-row-links-');
    path = '${directory.path}/surveys.db';
    store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
  });
  tearDown(() async {
    await store.database.close();
    await directory.delete(recursive: true);
  });

  test(
    'assigned team supplies every new group and preserves existing groups',
    () {
      expect(teamGroupIdentifier({'name': 'Survey Team 01'}), '01');
      expect(teamGroupIdentifier({'name': 'Survey Team 12'}), '12');
      expect(
        teamGroupIdentifier({'code': 'ST101', 'name': 'Survey Team 01'}),
        '01',
      );
      expect(
        teamGroupIdentifier({'code': 'ST12', 'name': 'Survey Team 01'}),
        isNull,
      );
      expect(teamGroupIdentifier({'name': 'Survey Team'}), isNull);
      expect(
        teamGroupIdentifier({
          'name': 'Survey Team 01',
          'group_identifier': null,
        }),
        isNull,
      );
      expect(
        () => SurveyRecord.create(
          testSession,
          {'id': 4, 'name': 'Team'},
          {'id': 7},
        ),
        throwsFormatException,
      );
      final record = readySurvey(paired: false);
      record.rows.first['group'] = '07';
      expect(record.rows.first['group'], '07');
      record.ensureEndRow(0);
      expect(record.rows[1]['group'], '01');
      expect(record.nextRow()['group'], '01');
      expect(record.clone().rows.first['group'], '07');
      expect(record.payload(submitted: false), isNot(contains('team_group')));
    },
  );

  test(
    'PV and photo row links survive save, reorder, restart and immutable queue',
    () async {
      final record = readySurvey();
      final start = record.rows.first['row_id'];
      final end = record.rows[1]['row_id'];
      record.rows.first['group'] = '07';
      record.rows[1]['group'] = '09';
      record.solar.add({
        'pv_id': 'pv-stable',
        'row_id': start,
        'meter_number': '001-A/9',
        'installed_pv_kw': '3',
      });
      await store.save(record);
      await store.addAttachment({
        'uuid': 'photo',
        'scope': record.scope,
        'survey_uuid': record.id,
        'row_id': end,
        'kind': 'photo',
        'path': 'photo.jpg',
        'state': 'pending',
        'error': '',
      });
      // Move the complete pair as a unit, preserving IDs rather than list offsets.
      final extra = record.nextRow();
      record.rows.add(extra);
      record.ensureEndRow(2);
      record.copyPreviousValues(2);
      record.rows[2]['gps_waypoint'] = '007';
      record.copyPreviousValues(3);
      record.rows[3]['gps_waypoint'] = '008';
      final firstPair = record.rows.sublist(0, 2);
      record.rows.removeRange(0, 2);
      record.rows.addAll(firstPair);
      await store.save(record);
      await store.queue(record);
      await store.database.close();
      store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
      final restored = (await store.survey(record.scope, record.id))!;
      expect(restored.rows[2]['row_id'], start);
      expect(restored.outbound!['solar'][0]['row_id'], start);
      expect(restored.outbound!['solar'][0]['meter_number'], '001-A/9');
      expect(restored.rows.map((row) => row['group']), [
        '01',
        '01',
        '07',
        '09',
      ]);
      expect((restored.outbound!['rows'] as List).map((row) => row['group']), [
        '01',
        '01',
        '07',
        '09',
      ]);
      expect(
        (await store.attachments(record.scope, record.id)).single['row_id'],
        end,
      );
      expect(jsonEncode(restored.outbound), jsonEncode(record.outbound));
      await store.acknowledge(restored, 1);
      final confirmed = (await store.survey(record.scope, record.id))!;
      expect(confirmed.rows.map((row) => row['group']), [
        '01',
        '01',
        '07',
        '09',
      ]);
      expect(confirmed.solar.single['row_id'], start);
      expect(
        (await store.attachments(record.scope, record.id)).single['row_id'],
        end,
      );
    },
  );

  test(
    'saved transformers are excluded after reopening and duplicate drafts rejected',
    () async {
      final record = readySurvey()..data['transformer_id'] = 23;
      await store.save(record);
      await store.database.close();
      store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
      final controller = FieldController(store)
        ..reference = {
          'transformers': [
            {'id': 23, 'feeder_id': 7, 'transformer_code': '001234'},
            {'id': 24, 'feeder_id': 7, 'transformer_code': 'UNUSED'},
          ],
        };
      final newRecord = newSurvey();
      expect(
        (await controller.availableTransformers(newRecord)).map((t) => t['id']),
        [24],
      );
      expect(await store.surveys(record.scope), hasLength(1));
      expect(await controller.availableTransformers(record), hasLength(2));
      newRecord.data['transformer_code'] = ' 00 1234 ';
      await expectLater(store.save(newRecord), throwsFormatException);
      await store.save(record); // Editing the saved survey stays available.
      await store.queue(record);
      await store.acknowledge(record, 1);
      expect(
        (await controller.availableTransformers(newRecord)).map((t) => t['id']),
        [24],
      );
      controller.dispose();
    },
  );

  test(
    'SQLite v1 upgrade retains unlinked photos and explicit mapping is scoped',
    () async {
      final record = readySurvey();
      await store.save(record);
      await store.addAttachment({
        'uuid': 'legacy',
        'scope': record.scope,
        'survey_uuid': record.id,
        'kind': 'photo',
        'path': 'kept.jpg',
        'state': 'pending',
        'error': '',
      });
      await store.database.execute(
        'ALTER TABLE attachments DROP COLUMN row_id',
      );
      await store.database.setVersion(1);
      await store.database.close();
      store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
      expect(
        (await store.attachments(record.scope, record.id)).single['row_id'],
        isNull,
      );
      expect(
        (await store.survey(record.scope, record.id))!.rows.first['group'],
        '01',
      );
      final other = readySurvey()..data['transformer_code'] = 'OTHER';
      expect(
        await store.linkAttachment(other, 'legacy', other.rows.first['row_id']),
        false,
      );
      expect(
        await store.linkAttachment(
          record,
          'legacy',
          record.rows.first['row_id'],
        ),
        true,
      );
      expect(
        await store.linkAttachment(record, 'legacy', record.rows[1]['row_id']),
        false,
      );
      expect(
        (await store.attachments(record.scope, record.id)).single['row_id'],
        record.rows.first['row_id'],
      );
    },
  );

  test(
    'sync acknowledges row links before photos and holds photos while rows are drafts',
    () async {
      final record = readySurvey();
      final calls = <String>[];
      final file = File('${directory.path}/sync.jpg');
      await file.writeAsBytes([1, 2, 3]);
      final controller = FieldController(
        store,
        api: FieldApi(
          client: MockClient((request) async {
            calls.add(request.url.path);
            if (request.url.path.endsWith('/surveys/sync')) {
              expect(
                jsonDecode(request.body)['rows'][0]['row_id'],
                record.rows.first['row_id'],
              );
              return http.Response('{"revision":1}', 201);
            }
            expect(request.body, contains(record.rows.first['row_id']));
            return http.Response('{}', 201);
          }),
        ),
      )..session = testSession;
      await store.save(record);
      await store.addAttachment({
        'uuid': 'linked',
        'scope': record.scope,
        'survey_uuid': record.id,
        'row_id': record.rows.first['row_id'],
        'kind': 'photo',
        'path': file.path,
        'state': 'pending',
        'error': '',
      });
      await controller.sync();
      expect(calls, isEmpty); // Local drafts cannot upload photo links yet.
      await store.queue(record);
      await controller.sync();
      expect(calls, [
        '/api/v1/field/surveys/sync',
        '/api/v1/field/surveys/${record.id}/attachments',
      ]);
      expect(
        (await store.attachments(record.scope, record.id)).single['state'],
        'synced',
      );
      final edited = (await store.survey(record.scope, record.id))!;
      edited.state = 'draft';
      edited.rows.add(edited.nextRow());
      await store.save(edited);
      await store.addAttachment({
        'uuid': 'new-row',
        'scope': record.scope,
        'survey_uuid': record.id,
        'row_id': edited.rows.last['row_id'],
        'kind': 'photo',
        'path': file.path,
        'state': 'pending',
        'error': '',
      });
      await controller.sync();
      expect(calls, hasLength(2));
      expect(
        (await store.attachments(record.scope, record.id)).last['state'],
        'pending',
      );
      controller.dispose();
    },
  );

  test('photo multipart uploads transmit the stable row identifier', () async {
    final file = File('${directory.path}/photo.jpg');
    await file.writeAsBytes([1, 2, 3]);
    var body = '';
    final api = FieldApi(
      client: MockClient((request) async {
        body = request.body;
        return http.Response('{"row_id":"stable-row"}', 201);
      }),
    );
    await api.upload(testSession, {
      'path': file.path,
      'survey_uuid': 'survey',
      'uuid': 'photo',
      'kind': 'photo',
      'row_id': 'stable-row',
    });
    expect(body, contains('name="row_id"'));
    expect(body, contains('stable-row'));
  });
}
