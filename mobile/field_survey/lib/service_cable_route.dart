import 'dart:math' as math;

/// Checks the full surveyed path, including the pole and meter endpoints.
String? serviceCableRouteError(dynamic value) {
  if (value == null) return null;
  if (value is! List) return 'Record route points as an ordered list.';
  if (value.isEmpty) return null;
  if (value.length < 2 || value.length > 500) {
    return 'A mapped route needs 2 to 500 GPS points, including pole and meter.';
  }
  num? previousLat, previousLon;
  for (var i = 0; i < value.length; i++) {
    final point = value[i];
    if (point is! Map) {
      return 'Enter valid coordinates for route point ${i + 1}.';
    }
    final lat = num.tryParse('${point['latitude']}');
    final lon = num.tryParse('${point['longitude']}');
    if (lat == null ||
        lon == null ||
        !lat.isFinite ||
        !lon.isFinite ||
        lat < -90 ||
        lat > 90 ||
        lon < -180 ||
        lon > 180 ||
        (lat == 0 && lon == 0)) {
      return 'Enter valid coordinates for route point ${i + 1}.';
    }
    if ((point['waypoint'] ?? '').toString().length > 100) {
      return 'Route waypoint names must be 100 characters or fewer.';
    }
    if (lat == previousLat && lon == previousLon) {
      return 'Consecutive route points must have different coordinates.';
    }
    previousLat = lat;
    previousLon = lon;
  }
  return null;
}

/// Sums the measured path segments; never substitutes an endpoint estimate.
double? serviceCableRouteLength(dynamic value) {
  if (value is! List ||
      value.isEmpty ||
      serviceCableRouteError(value) != null) {
    return null;
  }
  double radians(num n) => n * math.pi / 180;
  var length = 0.0;
  for (var i = 1; i < value.length; i++) {
    final a = value[i - 1] as Map, b = value[i] as Map;
    final lat1 = radians(num.parse('${a['latitude']}'));
    final lat2 = radians(num.parse('${b['latitude']}'));
    final dLat = lat2 - lat1;
    final dLon = radians(
      num.parse('${b['longitude']}') - num.parse('${a['longitude']}'),
    );
    final h =
        math.pow(math.sin(dLat / 2), 2) +
        math.cos(lat1) * math.cos(lat2) * math.pow(math.sin(dLon / 2), 2);
    length += 6371008.8 * 2 * math.asin(math.sqrt(h.clamp(0, 1)));
  }
  return length;
}
