import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

typedef Json = Map<String, dynamic>;
const applicationBaseUrl = 'https://hazeco.barqaab.pk';

// Keep the wire contract independent of local editing/display metadata. Stable
// row/PV IDs are part of the contract and must never be stripped for sync.
const surveyRowFields = {
  'row_id',
  'legacy_row_index',
  'pair_id',
  'entry_version',
  'se',
  'group',
  'date',
  'gps_waypoint',
  'latitude',
  'longitude',
  'gps_accuracy_m',
  'phase',
  'conductor_r',
  'conductor_y',
  'conductor_b',
  'conductor_neutral',
  'equipment_type',
  'pole_class',
  'pole_height_ft',
  'consumers',
  'intersection',
  'remarks',
};
const surveyPvFields = {
  'pv_id',
  'row_id',
  'consumer_reference',
  'service_load_kw',
  'installed_pv_kw',
  'meter_number',
  'remarks',
};

String dayString(DateTime date) => date.toIso8601String().substring(0, 10);
Json copyJson(Json value) => jsonDecode(jsonEncode(value)) as Json;

String? teamGroupIdentifier(Json? team) {
  if (team == null) return null;
  if (team.containsKey('group_identifier')) {
    return team['group_identifier'] as String?;
  }
  final numbers = <String>{};
  for (final value in [team['code'], team['name']]) {
    final match = RegExp(r'([0-9]+)\s*$').firstMatch((value ?? '').toString());
    if (match != null) {
      final digits = match[1]!.padLeft(2, '0');
      numbers.add(digits.substring(digits.length - 2));
    }
  }
  return numbers.length == 1 ? numbers.single : null;
}

String transformerIdentity(dynamic code) => (code ?? '')
    .toString()
    .replaceAll(RegExp('[‐‑‒–—−]'), '-')
    .replaceAll(RegExp(r'\s+'), '')
    .toUpperCase();

bool sameTransformer(Json a, Json b) =>
    a['feeder_id'] == b['feeder_id'] &&
    ((a['transformer_id'] != null &&
            a['transformer_id'] == b['transformer_id']) ||
        (transformerIdentity(a['transformer_code']).isNotEmpty &&
            transformerIdentity(a['transformer_code']) ==
                transformerIdentity(b['transformer_code'])));

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
    final group = teamGroupIdentifier(team);
    if (group == null) {
      throw const FormatException(
        'The assigned survey team needs a valid team number. Ask the administrator to correct its code/name.',
      );
    }
    return SurveyRecord(
      scope: session.scope,
      data: {
        'client_uuid': const Uuid().v4(),
        'base_revision': 0,
        'survey_team_id': team['id'],
        'team_group': group,
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

  static Json newRow(String date, {String? pairId}) => {
    'row_id': const Uuid().v4(),
    'pair_id': pairId ?? const Uuid().v4(),
    'entry_version': 2,
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
    'intersection': false,
    'remarks': '',
  };

  Json nextRow() {
    ensurePairIds();
    final previous = rows.lastOrNull;
    return newRow(
        previous?['date'] ?? dayString(DateTime.now()),
        pairId: previous != null && previous['se'] == 'S'
            ? previous['pair_id']
            : null,
      )
      ..['se'] = previous?['se'] == 'S' ? 'E' : 'S'
      ..['group'] = requiredTeamGroup;
  }

  String get requiredTeamGroup {
    final group = data['team_group'];
    if (group is! String || !RegExp(r'^[0-9]{2}$').hasMatch(group)) {
      throw const FormatException(
        'No valid assigned team number is available. Refresh assignments or contact the administrator before adding a row.',
      );
    }
    return group;
  }

  /// Link legacy adjacent pairs once; never rewrite observation values or a queued snapshot.
  bool ensurePairIds() {
    if (locked) return false;
    var changed = false;
    for (var i = 0; i < rows.length; i++) {
      if ((rows[i]['row_id'] ?? '').toString().isEmpty) {
        rows[i]['row_id'] = const Uuid().v4();
        rows[i]['legacy_row_index'] = i;
        changed = true;
      }
    }
    for (final indices in surveyPairGroups(rows)) {
      final saved = (rows[indices.first]['pair_id'] ?? '').toString();
      final id = saved.isEmpty ? const Uuid().v4() : saved;
      for (final index in indices) {
        if ((rows[index]['pair_id'] ?? '').toString().isEmpty) {
          rows[index]['pair_id'] = id;
          changed = true;
        }
      }
    }
    return changed;
  }

  int ensureEndRow(int startIndex) {
    if (rows[startIndex]['se'] != 'S') {
      throw StateError('Only an S row creates an E row.');
    }
    return ensurePartnerRow(startIndex);
  }

  int ensurePartnerRow(int startIndex) {
    ensurePairIds();
    final start = rows[startIndex];
    final partnerRole = start['se'] == 'S' ? 'E' : 'S';
    final existing = rows.indexWhere(
      (row) => row['pair_id'] == start['pair_id'] && row['se'] == partnerRole,
    );
    if (existing >= 0) return existing;
    if (rows.length >= 500) {
      throw StateError(
        'Maximum 500 rows. Remove an unused pair before adding E.',
      );
    }
    final insertAt = start['se'] == 'S' ? startIndex + 1 : startIndex;
    rows.insert(
      insertAt,
      newRow(
          start['date'] ?? dayString(DateTime.now()),
          pairId: start['pair_id'],
        )
        ..['se'] = partnerRole
        ..['group'] = requiredTeamGroup,
    );
    return insertAt;
  }

  void removePairAt(int index) {
    ensurePairIds();
    final id = rows[index]['pair_id'];
    rows.removeWhere((row) => row['pair_id'] == id);
  }

  bool get hasCompleteValidPair => hasCompletePairWithEquipment(null);

  bool hasCompletePairWithEquipment(Iterable<String>? equipmentCodes) {
    final errors = SurveyValidation.errors(
      this,
      submitting: true,
      equipmentCodes: equipmentCodes,
    );
    return surveyPairGroups(rows).any(
      (indices) =>
          isCompletePair(rows, indices) &&
          indices.every(
            (index) =>
                !errors.keys.any((key) => key.startsWith('rows.$index.')),
          ),
    );
  }

  void copyPreviousValues(int index) {
    if (index <= 0 || index >= rows.length) return;
    for (final key in [
      'conductor_r',
      'conductor_y',
      'conductor_b',
      'conductor_neutral',
      'equipment_type',
      'pole_class',
      'pole_height_ft',
    ]) {
      rows[index][key] = rows[index - 1][key] ?? '';
    }
    rows[index]['phase'] = phaseFromConductors(rows[index]);
  }

  Json payload({bool submitted = false}) {
    final result = copyJson(data);
    result.remove(
      'team_group',
    ); // The default is local; each row's edited group travels with the row.
    result['status'] = submitted ? 'submitted' : 'draft';
    final header = result['header'] as Json;
    header['capacity_kva'] = parseNumber(header['capacity_kva']);
    for (final row in (result['rows'] as List).cast<Json>()) {
      row['phase'] = phaseFromConductors(row);
      row['intersection'] = isIntersection(row['intersection']);
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
      if (item.containsKey('service_load_kw')) {
        item['service_load_kw'] = parseNumber(item['service_load_kw']);
      }
    }
    result['rows'] = (result['rows'] as List)
        .cast<Json>()
        .map(
          (row) => Map<String, dynamic>.fromEntries(
            row.entries.where((entry) => surveyRowFields.contains(entry.key)),
          ),
        )
        .toList();
    result['solar'] = (result['solar'] as List)
        .cast<Json>()
        .map(
          (pv) => Map<String, dynamic>.fromEntries(
            pv.entries.where((entry) => surveyPvFields.contains(entry.key)),
          ),
        )
        .toList();
    return result;
  }
}

List<List<int>> surveyPairGroups(List<Json> rows) {
  final groups = <String, List<int>>{};
  for (var i = 0; i < rows.length; i++) {
    final id = (rows[i]['pair_id'] ?? '').toString();
    final legacyEnd =
        id.isEmpty &&
        i > 0 &&
        rows[i]['se'] == 'E' &&
        rows[i - 1]['se'] == 'S' &&
        (rows[i - 1]['pair_id'] ?? '').toString().isEmpty;
    final key = id.isNotEmpty ? 'id:$id' : 'legacy:${legacyEnd ? i - 1 : i}';
    (groups[key] ??= []).add(i);
  }
  return groups.values.toList();
}

bool isCompletePair(List<Json> rows, List<int> indices) =>
    indices.length == 2 &&
    indices.where((i) => rows[i]['se'] == 'S').length == 1 &&
    indices.where((i) => rows[i]['se'] == 'E').length == 1;

bool hasConductorInput(Json row) => ['r', 'y', 'b', 'neutral'].any(
  (key) => ![
    '',
    '__none__',
    '+',
    '-',
    '–',
    '—',
    'x',
    '×',
    'other',
  ].contains((row['conductor_$key'] ?? '').toString().trim().toLowerCase()),
);

// Same default century window as web entry. Keep existing ISO dates untouched.
String displayEntryDate(String value) {
  if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value)) return value;
  return '${value.substring(8, 10)}${value.substring(5, 7)}${value.substring(2, 4)}';
}

String? parseEntryDate(String value, {int yearStart = 2000}) {
  if (!RegExp(r'^\d{6}$').hasMatch(value)) return null;
  final day = int.parse(value.substring(0, 2));
  final month = int.parse(value.substring(2, 4));
  var year = (yearStart ~/ 100) * 100 + int.parse(value.substring(4, 6));
  if (year < yearStart) year += 100;
  final date = DateTime(year, month, day);
  if (date.year != year || date.month != month || date.day != day) return null;
  return dayString(date);
}

num? parseNumber(dynamic value) {
  if (value == null || value.toString().trim().isEmpty) return null;
  return num.tryParse(value.toString());
}

bool isIntersection(dynamic value) =>
    value == true ||
    [
      '1',
      'true',
      'yes',
      'on',
      'int',
      'intersection',
      '✓',
      '✔',
      'x',
      '×',
      '+',
    ].contains((value ?? '').toString().trim().toLowerCase());

String phaseFromConductors(Json row) => ['r', 'y', 'b']
    .where(
      (key) => ![
        '',
        '__none__',
        '+',
        '-',
        '–',
        '—',
        'x',
        '×',
      ].contains((row['conductor_$key'] ?? '').toString().trim().toLowerCase()),
    )
    .map((key) => key.toUpperCase())
    .join();

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
    Iterable<String>? equipmentCodes,
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
        result['rows'] = 'Complete at least one S/E pair before submission.';
      }
      for (final pair in surveyPairGroups(record.rows)) {
        if (!isCompletePair(record.rows, pair)) {
          result['rows'] = 'Every pair needs exactly one S row and one E row.';
          for (final index in pair) {
            result['rows.$index.pair'] =
                'Incomplete or duplicate S/E pair. Complete the matching row.';
          }
        }
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
        for (final field in {
          'group': 'Group identifier',
          'date': 'Row date',
          'equipment_type': 'Equipment type',
          'pole_class': 'Pole class',
          'pole_height_ft': 'Pole height',
        }.entries) {
          requiredField('$prefix.${field.key}', row[field.key], field.value);
        }
        requiredField('$prefix.gps_waypoint', row['gps_waypoint'], 'Waypoint');
        if (!hasConductorInput(row)) {
          for (final conductor in ['r', 'y', 'b', 'neutral']) {
            result['$prefix.conductor_$conductor'] =
                'Enter at least one R, Y, B or N conductor.';
          }
        }
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
        'remarks': 1000,
      }.entries) {
        text('$prefix.${entry.key}', row[entry.key], entry.value);
      }
      for (final key in ['group', 'gps_waypoint']) {
        final value = (row[key] ?? '').toString();
        if (row['entry_version'] == 2 &&
            value.isNotEmpty &&
            !RegExp(r'^\d+$').hasMatch(value)) {
          result['$prefix.$key'] = 'Enter digits only; leading zeros are kept.';
        }
      }
      final group = (row['group'] ?? '').toString();
      if (group.isNotEmpty && !RegExp(r'^[0-9]{2}$').hasMatch(group)) {
        result['$prefix.group'] = 'Enter exactly two digits, for example 01.';
      }
      if (row['entry_version'] == 2 &&
          (row['equipment_type'] ?? '').toString().isNotEmpty &&
          !(equipmentCodes ?? const ['TR', 'SP', 'Ang', 'WB']).contains(
            row['equipment_type'],
          )) {
        result['$prefix.equipment_type'] = 'Select a listed equipment type.';
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
      if (item['row_id'] != null &&
          !record.rows.any((row) => row['row_id'] == item['row_id'])) {
        result['solar.$index.row_id'] =
            'This PV record must belong to a row in this survey.';
      }
      number(
        'solar.$index.installed_pv_kw',
        item['installed_pv_kw'],
        max:
            item.containsKey('service_load_kw') ||
                item.containsKey('meter_number')
            ? 1000000
            : 10000000,
      );
      number(
        'solar.$index.service_load_kw',
        item['service_load_kw'],
        max: 1000000,
      );
      text('solar.$index.consumer_reference', item['consumer_reference'], 255);
      text('solar.$index.meter_number', item['meter_number'], 255);
      text('solar.$index.remarks', item['remarks'], 4000);
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
