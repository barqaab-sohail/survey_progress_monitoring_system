import 'dart:async';
import 'dart:io';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:sqflite/sqflite.dart';
import 'api_client.dart';

/// User-facing diagnostics without exposing passwords, tokens or server bodies.
String signInErrorMessage(Object error) {
  if (error is ApiFailure) return error.message;
  if (error is FormatException) return error.message;
  if (error is HandshakeException || error is TlsException) {
    return 'Secure connection failed (TLS certificate). Check the phone date/time. '
        'The server certificate and its full certificate chain must be valid.';
  }
  if (error is TimeoutException) {
    return 'The server took too long to respond. Try again using Wi-Fi or mobile data.';
  }
  if (error is SocketException) {
    return 'The phone could not reach the server (network/DNS). Open the application '
        'URL in Chrome on this phone, then try another network.';
  }
  if (error is http.ClientException) {
    return 'The connection to the API was interrupted. Try another network and sign in again.';
  }
  if (error is PlatformException ||
      error is DatabaseException ||
      error is FileSystemException) {
    return 'Sign-in could not finish because phone storage is unavailable. '
        'Restart the app and check free storage. Keep app data to preserve offline surveys.';
  }
  return 'Sign-in could not finish (${error.runtimeType}). '
      'Report this message so the application error can be checked.';
}
