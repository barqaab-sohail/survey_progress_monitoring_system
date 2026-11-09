import 'dart:async';
import 'dart:io';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:hazeco_field_survey/api_client.dart';
import 'package:hazeco_field_survey/sign_in_error.dart';

void main() {
  test(
    'sign-in distinguishes network, TLS, storage and application failures',
    () {
      expect(
        signInErrorMessage(const SocketException('private detail')),
        contains('network/DNS'),
      );
      expect(
        signInErrorMessage(const HandshakeException('private detail')),
        contains('TLS certificate'),
      );
      expect(
        signInErrorMessage(TimeoutException('private detail')),
        contains('too long'),
      );
      expect(
        signInErrorMessage(http.ClientException('private detail')),
        contains('interrupted'),
      );
      expect(
        signInErrorMessage(
          PlatformException(code: 'storage', message: 'private detail'),
        ),
        contains('phone storage'),
      );
      expect(
        signInErrorMessage(StateError('private detail')),
        contains('StateError'),
      );
      expect(
        signInErrorMessage(StateError('private detail')),
        isNot(contains('private detail')),
      );
      expect(
        signInErrorMessage(const ApiFailure(401, 'Sign in again.')),
        'Sign in again.',
      );
      expect(
        signInErrorMessage(const FormatException('Invalid URL')),
        'Invalid URL',
      );
    },
  );
}
