import 'dart:async';
import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/api_client.dart';
import 'package:hazeco_field_survey/field_controller.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/models.dart';
import 'models_test.dart' show readySurvey, testSession;

class DelayedStore extends LocalStore {
  DelayedStore(super.database);
  Future<List<SurveyRecord>>? delayed;
  @override
  Future<List<SurveyRecord>> surveys(String scope) async {
    final next = delayed;
    delayed = null;
    return next ?? super.surveys(scope);
  }
}

void main() {
  sqfliteFfiInit();
  late LocalStore store;
  setUp(() async {
    store = await LocalStore.open(
      factory: databaseFactoryFfi,
      path: inMemoryDatabasePath,
    );
  });
  tearDown(() async {
    await store.database.close();
  });
  const otherSession = FieldSession(
    url: 'https://other.example.test',
    token: 'other-token',
    userId: 2,
    name: 'Other Inspector',
  );

  test(
    'late assignment response cannot replace another signed-in account',
    () async {
      final response = Completer<http.Response>();
      final controller = FieldController(
        store,
        api: FieldApi(client: MockClient((_) => response.future)),
      );
      controller.session = testSession;
      final refresh = controller.refreshReference();
      controller.session = otherSession;
      controller.reference = {
        'teams': [
          {'id': 99, 'name': 'Other team'},
        ],
      };
      response.complete(
        http.Response(
          jsonEncode({
            'teams': [
              {'id': 4, 'name': 'Previous team'},
            ],
          }),
          200,
        ),
      );
      await refresh;
      expect(controller.reference!['teams'][0]['id'], 99);
      expect((await store.bootstrap(testSession.scope))!['teams'][0]['id'], 4);
      controller.dispose();
    },
  );
  test('late notebook load cannot show previous user records', () async {
    final delayed = DelayedStore(store.database);
    final response = Completer<List<SurveyRecord>>();
    delayed.delayed = response.future;
    final controller = FieldController(delayed);
    controller.session = testSession;
    final load = controller.reload();
    controller.session = otherSession;
    controller.records = [];
    response.complete([readySurvey()]);
    await load;
    expect(controller.records, isEmpty);
    controller.dispose();
  });
  test(
    'account change during sync preflight never sends previous account data',
    () async {
      final record = readySurvey();
      await store.save(record);
      await store.queue(record);
      final delayed = DelayedStore(store.database);
      final response = Completer<List<SurveyRecord>>();
      delayed.delayed = response.future;
      var requests = 0;
      final controller = FieldController(
        delayed,
        api: FieldApi(
          client: MockClient((_) async {
            requests++;
            return http.Response('{"revision":1}', 200);
          }),
        ),
      );
      controller.session = testSession;
      final sync = controller.sync();
      controller.session = otherSession;
      response.complete([record]);
      await sync;
      expect(requests, 0);
      expect((await store.survey(record.scope, record.id))!.state, 'queued');
      controller.dispose();
    },
  );
  test(
    'network failure retains outgoing copy then retries at same revision and UUID',
    () async {
      final record = readySurvey();
      await store.save(record);
      await store.queue(record);
      var calls = 0;
      final bodies = <String>[];
      final controller = FieldController(
        store,
        api: FieldApi(
          client: MockClient((request) async {
            calls++;
            bodies.add(request.body);
            if (calls == 1) throw Exception('connection dropped');
            return http.Response('{"revision":1}', 200);
          }),
        ),
      );
      controller.session = testSession;
      await controller.sync();
      expect((await store.survey(record.scope, record.id))!.state, 'error');
      await controller.sync();
      expect(bodies[1], bodies[0]);
      final restored = (await store.survey(record.scope, record.id))!;
      expect(restored.state, 'synced');
      expect(restored.revision, 1);
      controller.dispose();
    },
  );
}
