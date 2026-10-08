import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';
import 'models.dart';

class ApiFailure implements Exception {
  const ApiFailure(this.status, this.message);
  final int status;
  final String message;
  @override
  String toString() => message;
}

class FieldApi {
  FieldApi({http.Client? client}) : client = client ?? http.Client();
  final http.Client client;
  Uri endpoint(String url, String path) => Uri.parse('$url/api/v1/field$path');
  Map<String, String> headers([FieldSession? session]) => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    if (session != null) 'Authorization': 'Bearer ${session.token}',
  };

  Json read(http.Response response) {
    Json data;
    try {
      data = response.body.isEmpty
          ? <String, dynamic>{}
          : jsonDecode(response.body) as Json;
    } catch (_) {
      throw ApiFailure(
        response.statusCode,
        'The server did not return an API response. Check the application URL.',
      );
    }
    if (response.statusCode >= 400) {
      final errors = data['errors'];
      final details = errors is Map
          ? errors.entries
                .map(
                  (entry) => '${entry.key}: ${(entry.value as List).join(' ')}',
                )
                .join('\n')
          : '';
      throw ApiFailure(
        response.statusCode,
        '${data['message'] ?? 'Request failed (${response.statusCode}).'}${details.isEmpty ? '' : '\n$details'}',
      );
    }
    return data;
  }

  Future<FieldSession> login(String url, String email, String password) async {
    final data = read(
      await client
          .post(
            endpoint(url, '/login'),
            headers: headers(),
            body: jsonEncode({
              'email': email.trim(),
              'password': password,
              'device_name': 'HAZECO Android field survey',
            }),
          )
          .timeout(const Duration(seconds: 25)),
    );
    final user = data['user'] as Json;
    return FieldSession(
      url: url,
      token: data['token'],
      userId: user['id'],
      name: user['name'],
    );
  }

  Future<Json> bootstrap(FieldSession session) async => read(
    await client
        .get(endpoint(session.url, '/bootstrap'), headers: headers(session))
        .timeout(const Duration(seconds: 30)),
  );
  Future<void> logout(FieldSession session) async {
    read(
      await client
          .post(endpoint(session.url, '/logout'), headers: headers(session))
          .timeout(const Duration(seconds: 10)),
    );
  }

  Future<Json> sync(FieldSession session, Json data) async => read(
    await client
        .post(
          endpoint(session.url, '/surveys/sync'),
          headers: headers(session),
          body: jsonEncode(data),
        )
        .timeout(const Duration(seconds: 40)),
  );
  Future<Json> upload(FieldSession session, Json attachment) async {
    final file = File(attachment['path']);
    if (!await file.exists()) {
      throw const ApiFailure(
        422,
        'This photo file is missing from the phone. Select it again.',
      );
    }
    final extension = file.path.toLowerCase();
    final contentType = extension.endsWith('.png')
        ? MediaType('image', 'png')
        : MediaType('image', 'jpeg');
    final request =
        http.MultipartRequest(
            'POST',
            endpoint(
              session.url,
              '/surveys/${attachment['survey_uuid']}/attachments',
            ),
          )
          ..headers.addAll({
            'Accept': 'application/json',
            'Authorization': 'Bearer ${session.token}',
          })
          ..fields.addAll({
            'client_uuid': attachment['uuid'],
            'kind': attachment['kind'],
          })
          ..files.add(
            await http.MultipartFile.fromPath(
              'file',
              file.path,
              contentType: contentType,
            ),
          );
    final response = await client
        .send(request)
        .timeout(const Duration(seconds: 60));
    return read(
      await http.Response.fromStream(
        response,
      ).timeout(const Duration(seconds: 30)),
    );
  }
}
