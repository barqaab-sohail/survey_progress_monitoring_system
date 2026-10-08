import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

typedef Json = Map<String, dynamic>;

String dayString(DateTime date) => date.toIso8601String().substring(0, 10);
Json copyJson(Json value) => jsonDecode(jsonEncode(value)) as Json;

class FieldSession {
  const FieldSession({
    required this.url,
    required this.token,
    required this.userId,
    required this.name,
  });
  final String url, token, name;
  final int userId;
  String get scope => '$url|$userId';
  Json toJson() => {
    'url': url,
    'token': token,
    'user_id': userId,
    'name': name,
  };
  factory FieldSession.fromJson(Json json) => FieldSession(
    url: json['url'],
    token: json['token'],
    userId: json['user_id'],
    name: json['name'],
  );
}

class SurveyRecord {
  SurveyRecord({
    required this.scope,
    required this.data,
    this.state = 'draft',
    this.error = '',
    this.updatedAt = '',
    this.outbound,
  });
  final String scope;
  Json data;
  String state, error, updatedAt;
  Json? outbound;
  String get id => data['client_uuid'];
  int get revision => data['base_revision'] as int? ?? 0;
  List<Json> get rows => (data['rows'] as List).cast<Json>();
  List<Json> get solar => (data['solar'] as List).cast<Json>();
  Json get header => data['header'] as Json;
  bool get locked => outbound != null || state == 'conflict';
  SurveyRecord clone() => SurveyRecord(
    scope: scope,
    data: copyJson(data),
    state: state,
    error: error,
    updatedAt: updatedAt,
    outbound: outbound == null ? null : copyJson(outbound!),
  );

  factory SurveyRecord.create(FieldSession session, Json team, Json feeder) {
    return SurveyRecord(
      scope: session.scope,
      data: {
        'client_uuid': const Uuid().v4(),
        'base_revision': 0,
        'survey_team_id': team['id'],
        'feeder_id': feeder['id'],
        'transformer_id': null,
        'transformer_code': '',
        'survey_date': dayString(DateTime.now()),
        'status': 'draft',
        'header': {
          'substation': feeder['grid_station_name'] ?? '',
          'division': feeder['division_name'] ?? '',
          'sub_division': feeder['sub_division_name'] ?? '',
          'sub_division_code': feeder['sub_division_code'] ?? '',
          'transformer_make': '',
          'inspectors': session.name,
          'location': '',
          'capacity_kva': '',
          'mounting': '',
          'duty': '',
        },
        'rows': <Json>[],
        'solar': <Json>[],
        'remarks': '',
      },
    );
  }

  static Json newRow(String date) => {
    'se': 'S',
    'group': '',
    'date': date,
    'gps_waypoint': '',
    'latitude': null,
    'longitude': null,
    'gps_accuracy_m': null,
    'phase': '',
    'conductor_r': '',
    'conductor_y': '',
    'conductor_b': '',
    'conductor_neutral': '',
    'equipment_type': '',
    'pole_class': '',
    'pole_height_ft': '',
    'consumers': <String, dynamic>{},
    'intersection': '',
    'remarks': '',
  };

  Json payload({bool submitted = false}) {
    final result = copyJson(data);
    result['status'] = submitted ? 'submitted' : 'draft';
    final header = result['header'] as Json;
    header['capacity_kva'] = parseNumber(header['capacity_kva']);
    for (final row in (result['rows'] as List).cast<Json>()) {
      for (final key in [
        'latitude',
        'longitude',
        'gps_accuracy_m',
        'pole_height_ft',
      ]) {
        row[key] = parseNumber(row[key]);
      }
      final counts = row['consumers'] as Json;
      for (final key in counts.keys.toList()) {
        if (counts[key].toString().trim().isEmpty) {
          counts.remove(key);
        } else {
          counts[key] = int.parse(counts[key].toString());
        }
      }
    }
    for (final item in (result['solar'] as List).cast<Json>()) {
      item['installed_pv_kw'] = parseNumber(item['installed_pv_kw']);
    }
    return result;
  }
}

num? parseNumber(dynamic value) {
  if (value == null || value.toString().trim().isEmpty) return null;
  return num.tryParse(value.toString());
}

const consumerLabels = <String, String>{
  'rs': 'RS · 1-phase residential',
  'rl': 'RL · 3-phase residential',
  'sc': 'SC · 1-phase commercial',
  'lc': 'LC · Commercial (LC)',
  'si': 'SI · 1-phase industrial',
  'li': 'LI · 3-phase industrial',
  'pb': 'PB · Public buildings',
  'ag': 'AG · Agriculture',
  'st': 'ST · Street lights',
};
const conductorChoices = [
  'A',
  'W',
  'GN',
  '2/0 AWG',
  'PVC 7/0.052',
  'PVC 19/0.052',
  'PVC 19/0.083',
  'USAID 50mm2',
  'USAID 95mm2',
  'Ang',
  'Int',
  'Other',
];

class SurveyValidation {
  static Map<String, String> errors(
    SurveyRecord record, {
    bool submitting = false,
  }) {
    final result = <String, String>{};
    final data = record.data;
    void requiredField(String path, dynamic value, String label) {
      if (value == null || value.toString().trim().isEmpty) {
        result[path] = '$label is required.';
      }
    }

    void text(String path, dynamic value, int limit) {
      if ((value?.toString().length ?? 0) > limit) {
        result[path] = 'Maximum $limit characters.';
      }
    }

    void number(
      String path,
      dynamic value, {
      double min = 0,
      double? max,
      bool positive = false,
      bool integer = false,
    }) {
      if (value == null || value.toString().trim().isEmpty) return;
      final parsed = num.tryParse(value.toString());
      if (parsed == null ||
          !parsed.isFinite ||
          parsed < min ||
          (max != null && parsed > max) ||
          (positive && parsed <= 0) ||
          (integer && !RegExp(r'^\d+$').hasMatch(value.toString()))) {
        result[path] = integer
            ? 'Enter a whole number of 0 or more.'
            : positive
            ? 'Enter a number greater than 0.'
            : 'Enter a valid number ($min${max == null ? ' or more' : ' to $max'}).';
      }
    }

    void date(String path, dynamic value) {
      final raw = value?.toString() ?? '';
      final parsed = DateTime.tryParse(raw);
      if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(raw) ||
          parsed == null ||
          dayString(parsed) != raw ||
          raw.compareTo(dayString(DateTime.now())) > 0) {
        result[path] = 'Choose a valid date.';
      }
    }

    requiredField('survey_team_id', data['survey_team_id'], 'Survey team');
    requiredField('feeder_id', data['feeder_id'], 'Feeder');
    date('survey_date', data['survey_date']);
    text('transformer_code', data['transformer_code'], 100);
    text('remarks', data['remarks'], 2000);
    for (final key in [
      'substation',
      'division',
      'sub_division',
      'sub_division_code',
      'transformer_make',
      'inspectors',
      'location',
    ]) {
      text('header.$key', record.header[key], key == 'location' ? 500 : 200);
    }
    number(
      'header.capacity_kva',
      record.header['capacity_kva'],
      positive: true,
      max: 10000000,
    );
    if (submitting) {
      requiredField(
        'transformer_code',
        data['transformer_code'],
        'Transformer code',
      );
      requiredField(
        'header.capacity_kva',
        record.header['capacity_kva'],
        'Capacity',
      );
      requiredField(
        'header.inspectors',
        record.header['inspectors'],
        'Inspector names',
      );
      if (record.rows.isEmpty) {
        result['rows'] = 'Add at least one S/E survey row.';
      }
    }
    if (record.rows.length > 500) {
      result['rows'] = 'Maximum 500 rows per survey.';
    }
    for (var index = 0; index < record.rows.length; index++) {
      final row = record.rows[index];
      final prefix = 'rows.$index';
      if (!['S', 'E'].contains(row['se'])) {
        result['$prefix.se'] = 'Choose S or E.';
      }
      date('$prefix.date', row['date']);
      if (submitting) {
        requiredField('$prefix.gps_waypoint', row['gps_waypoint'], 'Waypoint');
      }
      for (final entry in {
        'group': 20,
        'gps_waypoint': 50,
        'phase': 30,
        'conductor_r': 100,
        'conductor_y': 100,
        'conductor_b': 100,
        'conductor_neutral': 100,
        'equipment_type': 50,
        'pole_class': 50,
        'intersection': 100,
        'remarks': 1000,
      }.entries) {
        text('$prefix.${entry.key}', row[entry.key], entry.value);
      }
      number('$prefix.latitude', row['latitude'], min: -90, max: 90);
      number('$prefix.longitude', row['longitude'], min: -180, max: 180);
      number('$prefix.gps_accuracy_m', row['gps_accuracy_m'], max: 100000000);
      number('$prefix.pole_height_ft', row['pole_height_ft'], max: 1000000);
      for (final key in consumerLabels.keys) {
        number(
          '$prefix.consumers.$key',
          (row['consumers'] as Json)[key],
          integer: true,
          max: 1000000,
        );
      }
    }
    if (record.solar.length > 100) {
      result['solar'] = 'Maximum 100 solar installations.';
    }
    for (var index = 0; index < record.solar.length; index++) {
      final item = record.solar[index];
      number(
        'solar.$index.installed_pv_kw',
        item['installed_pv_kw'],
        max: 10000000,
      );
      text('solar.$index.consumer_reference', item['consumer_reference'], 100);
      text('solar.$index.remarks', item['remarks'], 1000);
    }
    return result;
  }
}

/// Production requires HTTPS. Debug HTTP is restricted to local development hosts.
String normalizeServerUrl(String raw, {bool debug = kDebugMode}) {
  final uri = Uri.tryParse(raw.trim());
  if (uri == null ||
      !uri.hasAuthority ||
      uri.host.isEmpty ||
      uri.userInfo.isNotEmpty ||
      uri.hasQuery ||
      uri.hasFragment ||
      !['http', 'https'].contains(uri.scheme)) {
    throw const FormatException(
      'Enter the application URL, for example https://survey.example.org.',
    );
  }
  final host = uri.host.toLowerCase();
  final octets = host.split('.').map(int.tryParse).toList();
  final privateIp =
      octets.length == 4 &&
      octets.every((e) => e != null && e >= 0 && e <= 255) &&
      (octets[0] == 10 ||
          octets[0] == 127 ||
          (octets[0] == 192 && octets[1] == 168) ||
          (octets[0] == 172 && octets[1]! >= 16 && octets[1]! <= 31));
  if (uri.scheme == 'http' &&
      !(debug &&
          (privateIp ||
              host == 'localhost' ||
              host == '::1' ||
              host.endsWith('.local')))) {
    throw const FormatException(
      'Use HTTPS. Debug builds allow HTTP only for a local development server.',
    );
  }
  var path = uri.path.replaceFirst(RegExp(r'/+$'), '');
  if (path.endsWith('/api/v1/field')) {
    path = path.substring(0, path.length - 13);
  }
  return uri.replace(path: path).toString();
}
