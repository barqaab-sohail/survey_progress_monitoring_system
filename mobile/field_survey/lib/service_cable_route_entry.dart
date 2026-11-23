import 'package:flutter/material.dart';
import 'models.dart';
import 'service_cable_route.dart';

/// Records the actual full path from the E pole through turns to the meter.
class ServiceCableRouteEntry extends StatelessWidget {
  const ServiceCableRouteEntry({
    super.key,
    required this.points,
    required this.onChanged,
    this.pole,
  });
  final List<Json> points;
  final Json? pole;
  final ValueChanged<List<Json>> onChanged;

  Future<Json?> pointDialog(
    BuildContext context,
    String title,
    Json? point,
  ) async {
    final form = GlobalKey<FormState>();
    final lat = TextEditingController(text: '${point?['latitude'] ?? ''}');
    final lon = TextEditingController(text: '${point?['longitude'] ?? ''}');
    final waypoint = TextEditingController(text: '${point?['waypoint'] ?? ''}');
    final result = await showDialog<Json>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: SingleChildScrollView(
          child: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text(
                  'Enter decimal-degree coordinates from the surveyed GPS point.',
                ),
                TextFormField(
                  controller: waypoint,
                  maxLength: 100,
                  decoration: const InputDecoration(
                    labelText: 'Waypoint / turn reference (optional)',
                  ),
                ),
                for (final axis in ['latitude', 'longitude'])
                  TextFormField(
                    controller: axis == 'latitude' ? lat : lon,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                      signed: true,
                    ),
                    decoration: InputDecoration(
                      labelText: axis == 'latitude' ? 'Latitude' : 'Longitude',
                    ),
                    validator: (value) {
                      final n = num.tryParse(value ?? '');
                      final max = axis == 'latitude' ? 90 : 180;
                      if (n == null || !n.isFinite || n < -max || n > max) {
                        return 'Enter valid $axis.';
                      }
                      if (num.tryParse(lat.text) == 0 &&
                          num.tryParse(lon.text) == 0) {
                        return 'Coordinates cannot both be zero.';
                      }
                      return null;
                    },
                  ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              if (form.currentState!.validate()) {
                Navigator.pop(context, <String, dynamic>{
                  'latitude': num.parse(lat.text),
                  'longitude': num.parse(lon.text),
                  if (waypoint.text.trim().isNotEmpty)
                    'waypoint': waypoint.text.trim(),
                });
              }
            },
            child: const Text('Use GPS point'),
          ),
        ],
      ),
    );
    // Wait until the dialog's closing animation has released the controllers.
    await Future<void>.delayed(const Duration(milliseconds: 300));
    lat.dispose();
    lon.dispose();
    waypoint.dispose();
    return result;
  }

  @override
  Widget build(BuildContext context) {
    final length = serviceCableRouteLength(points);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Actual service cable route',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const Text(
              'Record the pole and meter endpoints, then add a GPS point at every bend or street turn. Keep points in cable route order.',
            ),
            if (points.isEmpty)
              OutlinedButton.icon(
                icon: const Icon(Icons.route),
                label: const Text('Set pole and meter GPS coordinates'),
                onPressed: () async {
                  final start =
                      await pointDialog(context, 'Starting E pole GPS point', {
                        'latitude': pole?['latitude'],
                        'longitude': pole?['longitude'],
                        'waypoint': pole?['gps_waypoint'],
                      });
                  if (start == null || !context.mounted) return;
                  final end = await pointDialog(
                    context,
                    'Consumer meter GPS point',
                    null,
                  );
                  if (end != null) onChanged([start, end]);
                },
              ),
            for (var i = 0; i < points.length; i++)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(
                  i == 0
                      ? '1. Starting E pole'
                      : i == points.length - 1
                      ? '${i + 1}. Consumer meter'
                      : '${i + 1}. Bend / street turn',
                ),
                subtitle: Text(
                  '${points[i]['waypoint'] ?? ''} ${points[i]['latitude']}, ${points[i]['longitude']}',
                ),
                onTap: () async {
                  final point = await pointDialog(
                    context,
                    'Edit route GPS point ${i + 1}',
                    points[i],
                  );
                  if (point != null) onChanged([...points]..[i] = point);
                },
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (i > 0 && i < points.length - 1) ...[
                      IconButton(
                        tooltip: 'Move turn earlier',
                        icon: const Icon(Icons.arrow_upward),
                        onPressed: i > 1
                            ? () {
                                final changed = [...points];
                                final temp = changed[i - 1];
                                changed[i - 1] = changed[i];
                                changed[i] = temp;
                                onChanged(changed);
                              }
                            : null,
                      ),
                      IconButton(
                        tooltip: 'Move turn later',
                        icon: const Icon(Icons.arrow_downward),
                        onPressed: i < points.length - 2
                            ? () {
                                final changed = [...points];
                                final temp = changed[i + 1];
                                changed[i + 1] = changed[i];
                                changed[i] = temp;
                                onChanged(changed);
                              }
                            : null,
                      ),
                      IconButton(
                        tooltip: 'Remove turn',
                        icon: const Icon(Icons.delete_outline),
                        onPressed: () => onChanged([...points]..removeAt(i)),
                      ),
                    ] else
                      const Icon(Icons.edit_outlined),
                  ],
                ),
              ),
            if (points.length >= 2) ...[
              OutlinedButton.icon(
                icon: const Icon(Icons.add_location_alt_outlined),
                label: const Text('Add bend / street turn'),
                onPressed: points.length >= 500
                    ? null
                    : () async {
                        final point = await pointDialog(
                          context,
                          'Bend / street turn GPS point',
                          null,
                        );
                        if (point != null && context.mounted) {
                          onChanged(
                            [...points]..insert(points.length - 1, point),
                          );
                        }
                      },
              ),
              Text(
                length == null
                    ? serviceCableRouteError(points) ??
                          'Complete the mapped route.'
                    : 'Mapped route length: ${length.toStringAsFixed(2)} m (${points.length - 1} segments)',
              ),
              TextButton(
                onPressed: () => onChanged([]),
                child: const Text('Clear mapped route'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
