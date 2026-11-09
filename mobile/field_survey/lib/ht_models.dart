import 'models.dart';

Json htPayload(Json data) {
  final result = copyJson(data)..remove('review_note');
  result['capacity_kva'] = num.tryParse('${result['capacity_kva']}');
  for (final node in (result['nodes'] as List).cast<Json>()) {
    for (final key in ['latitude', 'longitude', 'pole_height']) {
      node[key] = num.tryParse('${node[key] ?? ''}');
    }
  }
  return result;
}

List<String> htErrors(Json data) {
  final errors = <String>[];
  for (final key in [
    'transformer_code',
    'make',
    'location',
    'mounting',
    'phase',
    'upstream_reference',
    'remarks',
  ]) {
    if ((data[key] ?? '').toString().trim().isEmpty) {
      errors.add('Complete ${key.replaceAll('_', ' ')}.');
    }
  }
  if ((num.tryParse('${data['capacity_kva']}') ?? 0) <= 0) {
    errors.add('Enter transformer capacity greater than zero.');
  }
  if ((data['photos'] as List? ?? []).isEmpty) {
    errors.add('Add at least one HT connection / transformer photograph.');
  }
  final nodes = (data['nodes'] as List).cast<Json>();
  if (nodes.length < 2) {
    errors.add(
      'Record the existing HT connection and the transformer waypoint.',
    );
  }
  if (data['kind'] == 'missing_transformer' && nodes.length != 2) {
    errors.add('Remove intermediate HT poles, or select HT extension.');
  }
  final seen = <String>{};
  for (var i = 0; i < nodes.length; i++) {
    final node = nodes[i];
    final waypoint = (node['waypoint'] ?? '').toString().trim();
    if (waypoint.isEmpty || !seen.add(waypoint)) {
      errors.add('Node ${i + 1}: enter a unique waypoint.');
    }
    final lat = (node['latitude'] ?? '').toString().trim();
    final lon = (node['longitude'] ?? '').toString().trim();
    if (lat.isNotEmpty || lon.isNotEmpty) {
      final a = num.tryParse(lat), b = num.tryParse(lon);
      if (a == null ||
          b == null ||
          !a.isFinite ||
          !b.isFinite ||
          a.abs() > 90 ||
          b.abs() > 180) {
        errors.add(
          'Node ${i + 1}: enter valid latitude and longitude, or leave both blank for GPX matching.',
        );
      }
    }
    if (i > 0 &&
        data['kind'] == 'ht_extension' &&
        ['r', 'y', 'b'].every(
          (p) => (node['conductor_$p'] ?? '').toString().trim().isEmpty,
        )) {
      errors.add('Node ${i + 1}: record a conductor for the incoming HT span.');
    }
  }
  return errors;
}

Json htNode() => {
  'waypoint': '',
  'pole_type': '',
  'pole_height': '',
  'latitude': '',
  'longitude': '',
  'conductor_r': '',
  'conductor_y': '',
  'conductor_b': '',
};
