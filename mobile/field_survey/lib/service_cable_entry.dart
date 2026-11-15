import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:uuid/uuid.dart';
import 'models.dart';

class MobileServiceCableEntry extends StatefulWidget {
  const MobileServiceCableEntry({
    super.key,
    required this.record,
    required this.enabled,
    required this.onChanged,
    this.initialPoleId,
  });
  final SurveyRecord record;
  final String? initialPoleId;
  final bool enabled;
  final VoidCallback onChanged;
  @override
  State<MobileServiceCableEntry> createState() =>
      _MobileServiceCableEntryState();
}

class _MobileServiceCableEntryState extends State<MobileServiceCableEntry> {
  final gps = TextEditingController(),
      meter = TextEditingController(),
      reference = TextEditingController(),
      length = TextEditingController();
  final gpsFocus = FocusNode();
  String? poleId, editingId;
  String category = 'rs', phase = '', conductor = 'PVC 7/0.052', message = '';
  bool failed = false;
  int consumer = 1, page = 0;
  List<Json> get poles => widget.record.rows
      .where((r) => r['se'] == 'E' && r['row_id'] != null)
      .toList();
  Json? get pole => poles.where((p) => p['row_id'] == poleId).firstOrNull;
  List<Json> get cables => widget.record.serviceCables;
  List<Json> get visibleCables =>
      cables.where((c) => c['pole_row_id'] == poleId).toList();
  int count(String key) =>
      int.tryParse('${pole?['consumers']?[key] ?? 0}') ?? 0;
  int nextConsumer(String key) {
    final used = cables
        .where(
          (c) =>
              c['pole_row_id'] == poleId &&
              c['category'] == key &&
              c['cable_id'] != editingId,
        )
        .map((c) => c['consumer_number'])
        .toSet();
    var n = 1;
    while (used.contains(n)) {
      n++;
    }
    return n;
  }

  @override
  void initState() {
    super.initState();
    poleId =
        poles
            .where((p) => p['row_id'] == widget.initialPoleId)
            .firstOrNull?['row_id'] ??
        poles
            .where(
              (p) => (p['consumers'] as Map? ?? {}).values.any(
                (v) => (num.tryParse('$v') ?? 0) > 0,
              ),
            )
            .firstOrNull?['row_id'] ??
        poles.firstOrNull?['row_id'];
    seed();
  }

  void seed() {
    editingId = null;
    final available = consumerLabels.keys.where(
      (c) => count(c) >= nextConsumer(c),
    );
    if (count(category) < nextConsumer(category) && available.isNotEmpty) {
      category = available.first;
    }
    consumer = nextConsumer(category);
    final p = pole;
    var number = int.tryParse('${p?['gps_waypoint']}') ?? 0;
    for (final c in cables) {
      if (c['group'] == p?['group'] && c['date'] == p?['date']) {
        final n = int.tryParse('${c['gps_waypoint']}') ?? 0;
        if (n > number) {
          number = n;
        }
      }
    }
    final used = [...widget.record.rows, ...cables]
        .where((r) => r['group'] == p?['group'] && r['date'] == p?['date'])
        .map((r) => int.tryParse('${r['gps_waypoint']}'))
        .toSet();
    do {
      number++;
    } while (number <= 999 && used.contains(number));
    gps.text = number <= 999 ? '$number'.padLeft(3, '0') : '';
    meter.clear();
    reference.clear();
    length.clear();
    phase = '';
    conductor = ['rs', 'sc', 'st'].contains(category)
        ? 'PVC 7/0.052'
        : 'PVC 19/0.052';
  }

  void edit(Json c) {
    setState(() {
      editingId = c['cable_id'];
      poleId = c['pole_row_id'];
      category = c['category'];
      consumer = c['consumer_number'];
      gps.text = c['gps_waypoint'];
      meter.text = c['meter_number'] ?? '';
      reference.text = c['consumer_reference'] ?? '';
      length.text = '${c['measured_length_m'] ?? ''}';
      phase = c['phase'] ?? '';
      conductor = c['conductor_code'] ?? 'PVC 7/0.052';
      message = '';
    });
    gpsFocus.requestFocus();
  }

  void save() {
    if (!widget.enabled || pole == null) {
      return;
    }
    final original = editingId == null
        ? null
        : cables.where((c) => c['cable_id'] == editingId).firstOrNull;
    final candidate = <String, dynamic>{
      ...?original,
      'cable_id': editingId ?? const Uuid().v4(),
      'pole_row_id': poleId,
      'category': category,
      'consumer_number': consumer,
      'group': original?['group'] ?? pole!['group'],
      'date': original?['date'] ?? pole!['date'],
      'gps_waypoint': gps.text.trim(),
      'meter_number': meter.text.trim().isEmpty ? null : meter.text.trim(),
      'consumer_reference': reference.text.trim().isEmpty
          ? null
          : reference.text.trim(),
      'phase': phase.isEmpty ? null : phase,
      'conductor_code': conductor,
      'measured_length_m': length.text.trim().isEmpty
          ? null
          : num.tryParse(length.text.trim()),
      'length_evidence': length.text.trim().isEmpty
          ? null
          : 'Measured during mobile field survey',
    };
    if (length.text.trim().isNotEmpty &&
        candidate['measured_length_m'] == null) {
      setState(() {
        message = 'Enter a valid measured length.';
        failed = true;
      });
      return;
    }
    final trial = widget.record.clone();
    final updated =
        trial.serviceCables.where((c) => c['cable_id'] != editingId).toList()
          ..add(candidate);
    trial.data['service_cables'] = updated;
    final errors = serviceCableErrors(trial);
    if (errors.isNotEmpty) {
      setState(() {
        message = errors.values.toSet().join('\n');
        failed = true;
      });
      return;
    }
    final index = cables.indexWhere((c) => c['cable_id'] == editingId);
    final saved = [...cables];
    if (index < 0) {
      saved.add(candidate);
    } else {
      saved[index] = candidate;
    }
    widget.record.data['service_cables'] = saved;
    widget.onChanged();
    setState(() {
      seed();
      message =
          'Meter ${candidate['gps_waypoint']} added to this survey. Next waypoint is suggested; check it before saving.';
      failed = false;
    });
    gpsFocus.requestFocus();
  }

  @override
  void dispose() {
    for (final c in [gps, meter, reference, length]) {
      c.dispose();
    }
    gpsFocus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final list = visibleCables;
    final pages = (list.length / 20).ceil().clamp(1, 100000);
    if (page >= pages) {
      page = pages - 1;
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Consumer service cables (${cables.length})',
          style: Theme.of(context).textTheme.titleLarge,
        ),
        const SizedBox(height: 8),
        const Text(
          'Select the E pole once, then record its meter waypoints. These describe existing consumers; they do not increase consumer totals. GPX matching is completed on the web application.',
        ),
        const SizedBox(height: 16),
        if (serviceCableErrors(widget.record).isNotEmpty)
          Text(
            serviceCableErrors(widget.record).values.toSet().join('\n'),
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        if (poles.isEmpty)
          const Text(
            'Add an E pole with consumer counts before recording meters.',
          ),
        if (poles.isNotEmpty) ...[
          DropdownButtonFormField<String>(
            key: ValueKey('pole:$poleId'),
            initialValue: poleId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Starting E pole'),
            items: poles
                .map(
                  (p) => DropdownMenuItem<String>(
                    value: p['row_id'],
                    child: Text('E ${p['gps_waypoint']} · ${p['date']}'),
                  ),
                )
                .toList(),
            onChanged: widget.enabled
                ? (v) => setState(() {
                    poleId = v;
                    page = 0;
                    seed();
                    message = '';
                  })
                : null,
          ),
          const SizedBox(height: 12),
          Text(
            'Group ${pole?['group'] ?? '-'} · Date ${pole?['date'] ?? '-'} · ${visibleCables.length} meters entered',
          ),
          if (widget.enabled) ...[
            const SizedBox(height: 16),
            DropdownButtonFormField<String>(
              key: ValueKey('category:$category'),
              initialValue: category,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Consumer category'),
              items: consumerLabels.entries
                  .map(
                    (c) => DropdownMenuItem(
                      value: c.key,
                      child: Text(
                        '${c.key.toUpperCase()} · ${count(c.key)} consumers',
                      ),
                    ),
                  )
                  .toList(),
              onChanged: (v) => setState(() {
                category = v!;
                consumer = nextConsumer(category);
                conductor = ['rs', 'sc', 'st'].contains(category)
                    ? 'PVC 7/0.052'
                    : 'PVC 19/0.052';
                phase = '';
              }),
            ),
            const SizedBox(height: 12),
            Text(
              'Consumer $consumer of ${count(category)} · ${category.toUpperCase()}',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: gps,
              focusNode: gpsFocus,
              keyboardType: TextInputType.number,
              maxLength: 3,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(
                labelText: 'Meter GPS waypoint',
                helperText: 'Three digits. Confirm the suggested waypoint.',
              ),
              onSubmitted: (_) => save(),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: meter,
              decoration: const InputDecoration(
                labelText: 'Meter number (if recorded)',
              ),
              maxLength: 255,
            ),
            const SizedBox(height: 10),
            DropdownButtonFormField<String>(
              key: ValueKey('phase:$phase'),
              initialValue: phase,
              decoration: const InputDecoration(
                labelText: 'Recorded meter phase',
              ),
              items:
                  [
                        '',
                        ...(['rs', 'sc', 'st'].contains(category)
                            ? ['R', 'Y', 'B']
                            : ['RYB']),
                      ]
                      .map(
                        (p) => DropdownMenuItem(
                          value: p,
                          child: Text(p.isEmpty ? 'Not recorded' : p),
                        ),
                      )
                      .toList(),
              onChanged: (v) => setState(() => phase = v!),
            ),
            ExpansionTile(
              title: const Text('Cable details (optional)'),
              children: [
                TextField(
                  controller: reference,
                  decoration: const InputDecoration(
                    labelText: 'Consumer reference',
                  ),
                  maxLength: 255,
                ),
                DropdownButtonFormField<String>(
                  key: ValueKey('cable:$conductor'),
                  initialValue: conductor,
                  decoration: const InputDecoration(labelText: 'Service cable'),
                  items: {conductor, 'PVC 7/0.052', 'PVC 19/0.052'}
                      .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                      .toList(),
                  onChanged: (v) => setState(() => conductor = v!),
                ),
                TextField(
                  controller: length,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'Measured length (m)',
                    helperText:
                        'Leave blank to use the E-to-meter GPX distance later.',
                  ),
                ),
              ],
            ),
            FilledButton.icon(
              onPressed: count(category) >= consumer ? save : null,
              icon: const Icon(Icons.save_outlined),
              label: Text(
                editingId == null
                    ? 'Save & add next meter'
                    : 'Update & add next meter',
              ),
            ),
            if (count(category) < consumer)
              const Padding(
                padding: EdgeInsets.all(8),
                child: Text(
                  'All consumers in this category are mapped. Choose another category or E pole.',
                ),
              ),
            if (editingId != null)
              TextButton(
                onPressed: () => setState(seed),
                child: const Text('Cancel edit'),
              ),
          ],
          if (message.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 12),
              child: Text(
                message,
                style: TextStyle(
                  color: failed ? Theme.of(context).colorScheme.error : null,
                ),
              ),
            ),
          const SizedBox(height: 16),
          ...list
              .skip(page * 20)
              .take(20)
              .map(
                (c) => Card(
                  child: ListTile(
                    title: Text(
                      'GPS ${c['gps_waypoint']} · ${c['category'].toString().toUpperCase()} ${c['consumer_number']}',
                    ),
                    subtitle: Text(
                      'Meter ${c['meter_number'] ?? 'not recorded'} · ${c['phase'] ?? 'phase not recorded'}',
                    ),
                    onTap: widget.enabled ? () => edit(c) : null,
                    trailing: widget.enabled
                        ? IconButton(
                            tooltip: 'Delete meter',
                            icon: const Icon(Icons.delete_outline),
                            onPressed: () {
                              widget.record.data['service_cables'] = cables
                                  .where(
                                    (item) => item['cable_id'] != c['cable_id'],
                                  )
                                  .toList();
                              widget.onChanged();
                              setState(seed);
                            },
                          )
                        : null,
                  ),
                ),
              ),
          if (pages > 1)
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                TextButton(
                  onPressed: page > 0 ? () => setState(() => page--) : null,
                  child: const Text('Previous'),
                ),
                Text('${page + 1} / $pages'),
                TextButton(
                  onPressed: page + 1 < pages
                      ? () => setState(() => page++)
                      : null,
                  child: const Text('Next'),
                ),
              ],
            ),
        ],
      ],
    );
  }
}
