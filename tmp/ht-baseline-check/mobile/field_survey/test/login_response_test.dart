import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:hazeco_field_survey/api_client.dart';
import 'package:hazeco_field_survey/sign_in_error.dart';

void main() {
  test(
    'rejected login shows one actionable message and preserves password input',
    () async {
      final api = FieldApi(
        client: MockClient((request) async {
          final body = jsonDecode(request.body);
          expect(body['email'], 'surveyor@example.test');
          expect(body['password'], ' password with spaces ');
          return http.Response(
            jsonEncode({
              'message': 'The provided credentials are incorrect.',
              'errors': {
                'email': ['The provided credentials are incorrect.'],
              },
            }),
            422,
          );
        }),
      );
      try {
        await api.login(
          'https://example.test',
          ' surveyor@example.test ',
          ' password with spaces ',
        );
        fail('Login must reject incorrect credentials.');
      } on ApiFailure catch (error) {
        expect(error.status, 422);
        expect(error.message, 'The provided credentials are incorrect.');
        final displayed = signInErrorMessage(error);
        expect(displayed, contains('reset your password'));
        expect(displayed, isNot(contains('email:')));
      }
    },
  );

  test(
    'validation keeps distinct field errors without repeating the summary',
    () {
      final api = FieldApi();
      expect(
        () => api.read(
          http.Response(
            jsonEncode({
              'message': 'Check the survey.',
              'errors': {
                'feeder_id': [
                  'Choose an assigned feeder.',
                  'Choose an assigned feeder.',
                ],
                'survey_date': ['Enter the survey date.'],
              },
            }),
            422,
          ),
        ),
        throwsA(
          isA<ApiFailure>().having(
            (e) => e.message,
            'message',
            'Check the survey.\nfeeder_id: Choose an assigned feeder.\nsurvey_date: Enter the survey date.',
          ),
        ),
      );
    },
  );
}
