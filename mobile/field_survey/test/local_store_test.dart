import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show readySurvey;

void main() {
  sqfliteFfiInit();
  late LocalStore store;
  late Directory directory;
  late String path;
  setUp(() async {
    directory = await Directory.systemTemp.createTemp('hazeco-field-test-');
    path = '${directory.path}/surveys.db';
    store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
  });
  tearDown(() async {
    await store.database.close();
    await directory.delete(recursive: true);
  });
  test(
    'queue survives restart with immutable outgoing bytes and stable UUID',
    () async {
      final record = readySurvey();
      await store.save(record);
      await store.queue(record);
      final outbound = jsonEncode(record.outbound);
      final uuid = record.id;
      await store.database.close();
      store = await LocalStore.open(factory: databaseFactoryFfi, path: path);
      final restored = (await store.survey(record.scope, uuid))!;
      expect(restored.state, 'queued');
      expect(restored.locked, isTrue);
      expect(jsonEncode(restored.outbound), outbound);
      await store.reject(restored, 'offline');
      expect(
        jsonEncode((await store.survey(record.scope, uuid))!.outbound),
        outbound,
      );
      await store.acknowledge(restored, 1);
      final confirmed = (await store.survey(record.scope, uuid))!;
      expect(confirmed.revision, 1);
      expect(confirmed.id, uuid);
      expect(confirmed.state, 'synced');
      confirmed.data['remarks'] = 'Edited after server acknowledgement';
      confirmed.state = 'draft';
      await store.save(confirmed);
      await store.queue(confirmed);
      expect(confirmed.outbound!['base_revision'], 1);
      expect(confirmed.id, uuid);
    },
  );
  test(
    'stale autosave cannot erase queued copy or acknowledged revision',
    () async {
      final record = readySurvey();
      await store.save(record);
      final stale = record.clone();
      await store.queue(record);
      stale.data['remarks'] = 'stale';
      await expectLater(store.save(stale), throwsStateError);
      expect((await store.survey(record.scope, record.id))!.locked, isTrue);
      await store.acknowledge(record, 1);
      await expectLater(store.save(stale), throwsStateError);
      expect((await store.survey(record.scope, record.id))!.revision, 1);
    },
  );
  test(
    'scope isolates same UUID and cached assignments for user and server',
    () async {
      final first = readySurvey();
      await store.save(first);
      final otherUser = SurveyRecord(
        scope: 'https://survey.example.test|2',
        data: copyJson(first.data),
      );
      otherUser.data['remarks'] = 'user two';
      await store.save(otherUser);
      final otherServer = SurveyRecord(
        scope: 'https://other.example.test|1',
        data: copyJson(first.data),
      );
      await store.save(otherServer);
      await store.cacheBootstrap(first.scope, {
        'teams': [
          {'id': 4},
        ],
      });
      await store.cacheBootstrap(otherUser.scope, {
        'teams': [
          {'id': 99},
        ],
      });
      expect(await store.surveys(first.scope), hasLength(1));
      expect((await store.survey(first.scope, first.id))!.data['remarks'], '');
      expect((await store.bootstrap(otherUser.scope))!['teams'][0]['id'], 99);
      expect(await store.bootstrap(otherServer.scope), isNull);
    },
  );
  test(
    '409 preserves conflict; 422 allows correction without replacing identity',
    () async {
      final record = readySurvey();
      await store.save(record);
      await store.queue(record);
      final outbound = jsonEncode(record.outbound);
      await store.reject(record, 'different server revision', conflict: true);
      final conflicted = (await store.survey(record.scope, record.id))!;
      expect(conflicted.state, 'conflict');
      expect(jsonEncode(conflicted.outbound), outbound);
      await expectLater(store.save(conflicted), throwsStateError);
      final fixable = readySurvey();
      await store.save(fixable);
      await store.queue(fixable);
      final uuid = fixable.id;
      await store.reject(fixable, 'validation', correctable: true);
      expect(fixable.locked, isFalse);
      expect(fixable.id, uuid);
    },
  );
  test(
    'attachment upload claim blocks deletion and recovers process interruption',
    () async {
      final record = readySurvey();
      await store.save(record);
      final photo = {
        'uuid': 'photo-test',
        'scope': record.scope,
        'survey_uuid': record.id,
        'kind': 'photo',
        'path': '/app-private/photo.jpg',
        'state': 'pending',
        'error': '',
      };
      await store.addAttachment(photo);
      await store.addAttachment(photo);
      expect(await store.attachments(record.scope, record.id), hasLength(1));
      expect(await store.claimAttachment(record.scope, 'photo-test'), isTrue);
      expect(await store.deleteAttachment(record.scope, 'photo-test'), isFalse);
      await store.recoverInterruptedUploads();
      expect(
        (await store.attachments(record.scope, record.id)).first['state'],
        'error',
      );
      expect(await store.claimAttachment(record.scope, 'photo-test'), isTrue);
      await store.attachmentState('photo-test', 'synced', '');
      expect(await store.deleteAttachment(record.scope, 'photo-test'), isFalse);
    },
  );
}
