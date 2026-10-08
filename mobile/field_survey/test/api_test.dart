import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:hazeco_field_survey/api_client.dart';
import 'models_test.dart' show testSession, readySurvey;

void main() {
  test(
    'requests use configured subpath, bearer token and stable JSON payload',
    () async {
      final api = FieldApi(
        client: MockClient((request) async {
          expect(
            request.url.toString(),
            'https://survey.example.test/api/v1/field/surveys/sync',
          );
          expect(request.headers['authorization'], 'Bearer test-token');
          final data = jsonDecode(request.body);
          expect(data['rows'][0]['gps_waypoint'], '0005');
          return http.Response(
            jsonEncode({'id': 1, 'revision': 2, 'status': 'submitted'}),
            200,
          );
        }),
      );
      expect(
        (await api.sync(
          testSession,
          readySurvey().payload(submitted: true),
        ))['revision'],
        2,
      );
    },
  );
  test(
    'conflicts and validation remain distinguishable with server field errors',
    () async {
      final api = FieldApi(
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({
              'message': 'Fix the survey',
              'errors': {
                'rows.0.gps_waypoint': ['Waypoint is required.'],
              },
            }),
            422,
          ),
        ),
      );
      await expectLater(
        api.sync(testSession, {}),
        throwsA(
          isA<ApiFailure>()
              .having((e) => e.status, 'status', 422)
              .having(
                (e) => e.message,
                'field message',
                contains('rows.0.gps_waypoint'),
              ),
        ),
      );
      final conflicts = FieldApi(
        client: MockClient(
          (_) async => http.Response('{"message":"Version changed"}', 409),
        ),
      );
      await expectLater(
        conflicts.sync(testSession, {}),
        throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 409)),
      );
    },
  );
}
