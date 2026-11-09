import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:uuid/uuid.dart';
import 'field_controller.dart';
import 'models.dart';
import 'ht_models.dart';
import 'survey_editor.dart';
import 'feeder_picker.dart';

class HtUpdatesPage extends StatefulWidget {
  const HtUpdatesPage({super.key, required this.controller});
  final FieldController controller;
  @override
  State<HtUpdatesPage> createState() => _HtUpdatesPageState();
}

class _HtUpdatesPageState extends State<HtUpdatesPage> {
  FieldController get controller => widget.controller;
  Json? selected;
  bool opening = false;
  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: controller,
    builder: (context, _) {
      final feeders = controller.feeders
          .where(
            (f) => controller.teams.any(
              (t) =>
                  (f['team_ids'] as List).contains(t['id']) &&
                  teamGroupIdentifier(t) != null,
            ),
          )
          .toList();
      selected ??= feeders.firstOrNull;
      return Scaffold(
        appBar: AppBar(title: const Text('HT Network Updates')),
        body: ListView(
          padding: const EdgeInsets.all(18),
          children: [
            const Text(
              'Update missing HT connections and transformers. Save HT, then collect the LT Transformer Survey immediately. Independent web verification follows synchronization.',
            ),
            const SizedBox(height: 16),
            if (selected != null)
              FeederPicker(
                selectedId: selected!['id'],
                feeders: feeders,
                onSelected: (id) => setState(
                  () => selected = feeders.firstWhere(
                    (f) => '${f['id']}' == '$id',
                  ),
                ),
              ),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: selected == null || opening || controller.syncing
                  ? null
                  : () => edit(),
              icon: const Icon(Icons.add_location_alt_outlined),
              label: const Text('New HT Network Update'),
            ),
            const SizedBox(height: 18),
            for (final entry in controller.htRecords.where(
              (h) =>
                  selected == null || h['data']['feeder_id'] == selected!['id'],
            ))
              Card(
                child: ListTile(
                  leading: const Icon(Icons.electrical_services),
                  title: Text('HT · ${entry['data']['transformer_code']}'),
                  subtitle: Text(
                    '${entry['state']}\n${entry['data']['review_note'] ?? entry['error'] ?? ''}',
                  ),
                  isThreeLine: true,
                  onTap: opening ? null : () => edit(entry),
                  trailing: const Icon(Icons.chevron_right),
                ),
              ),
          ],
        ),
      );
    },
  );

  Future<void> edit([Json? entry]) async {
    if (opening || selected == null) return;
    setState(() => opening = true);
    try {
      final teams = controller.teams
          .where((t) => (selected!['team_ids'] as List).contains(t['id']))
          .toList();
      final team = surveyTeamForUser(controller.reference, teams)!;
      final result = await Navigator.push<Json>(
        context,
        MaterialPageRoute(
          builder: (_) => HtUpdateEditor(
            controller: controller,
            feeder: selected!,
            team: team,
            entry: entry,
          ),
        ),
      );
      await controller.reload();
      if (result != null && result['start_lt'] == true && mounted) {
        await startLt(result['data'] as Json);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => opening = false);
    }
  }

  Future<void> startLt(Json ht) async {
    final saved = await controller.store.surveys(controller.session!.scope);
    var record = saved
        .where((r) => r.data['ht_update_uuid'] == ht['client_uuid'])
        .firstOrNull;
    record ??= saved
        .where(
          (r) =>
              !r.locked &&
              r.data['ht_update_uuid'] == null &&
              r.data['feeder_id'] == ht['feeder_id'] &&
              transformerIdentity(r.data['transformer_code']) ==
                  transformerIdentity(ht['transformer_code']),
        )
        .firstOrNull;
    record ??= SurveyRecord.create(
      controller.session!,
      controller.team(ht['survey_team_id'])!,
      controller.feeder(ht['feeder_id'])!,
    );
    if (record.data['ht_update_uuid'] == null) {
      record.data['ht_update_uuid'] = ht['client_uuid'];
      record.data['transformer_code'] = ht['transformer_code'];
      record.header.addAll({
        'capacity_kva': ht['capacity_kva'],
        'transformer_make': ht['make'],
        'location': ht['location'],
        'mounting': ht['mounting'],
      });
      await controller.store.save(record);
    }
    if (!mounted) return;
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => SurveyEditor(controller: controller, record: record!),
      ),
    );
    await controller.reload();
    if (controller.autoSync) unawaited(controller.sync(manual: false));
  }
}

class HtUpdateEditor extends StatefulWidget {
  const HtUpdateEditor({
    super.key,
    required this.controller,
    required this.feeder,
    required this.team,
    this.entry,
    this.initialCode = '',
    this.ltSurveyId,
  });
  final FieldController controller;
  final Json feeder, team;
  final Json? entry;
  final String initialCode;
  final String? ltSurveyId;
  @override
  State<HtUpdateEditor> createState() => _HtUpdateEditorState();
}

class _HtUpdateEditorState extends State<HtUpdateEditor>
    with WidgetsBindingObserver {
  late Json data;
  late String state;
  String message = '';
  Timer? debounce;
  Future<void> writes = Future.value();
  bool saving = false, allowPop = false;
  bool get locked =>
      ['queued', 'conflict', 'verified', 'pending'].contains(state);
  Json get network =>
      (widget.controller.reference?['ht_networks']
              as Map?)?['${widget.feeder['id']}']
          as Json? ??
      {};
  List<Json> get features => ((network['features'] ?? []) as List).cast<Json>();
  List<Json> get nodes => (data['nodes'] as List).cast<Json>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    state = widget.entry?['state'] ?? 'draft';
    data = widget.entry == null
        ? {
            'client_uuid': const Uuid().v4(),
            'base_revision': 0,
            'feeder_id': widget.feeder['id'],
            'survey_team_id': widget.team['id'],
            'transformer_code': widget.initialCode,
            'kind': 'ht_extension',
            'capacity_kva': '',
            'make': '',
            'location': '',
            'mounting': 'D.Pole',
            'phase': '',
            'upstream_feature_id': null,
            'baseline_sha256': network['baseline_sha256'],
            'upstream_reference': '',
            'observed_on': dayString(DateTime.now()),
            'nodes': [htNode(), htNode()],
            'remarks': '',
            'photos': <String>[],
          }
        : copyJson(widget.entry!['data'] as Json);
    message = widget.entry?['error'] ?? '';
  }

  @override
  void dispose() {
    debounce?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.inactive) {
      unawaited(saveDraft());
    }
  }

  void changed() {
    if (locked) return;
    debounce?.cancel();
    debounce = Timer(
      const Duration(milliseconds: 500),
      () => unawaited(saveDraft().catchError((Object _) {})),
    );
  }

  Future<void> saveDraft() async {
    if (locked || saving) return;
    final snapshot = copyJson(data);
    writes = writes
        .catchError((Object _) {})
        .then(
          (_) => widget.controller.store.saveHt(
            widget.controller.session!.scope,
            {'data': snapshot, 'state': 'draft', 'error': ''},
          ),
        );
    try {
      await writes;
      if (mounted) setState(() => message = 'HT draft saved on this phone');
    } catch (e) {
      if (mounted) setState(() => message = 'Unable to save HT draft: $e');
      rethrow;
    }
  }

  Future<void> finish({required bool startLt}) async {
    if (saving) return;
    if (locked) {
      await close({'data': data, 'start_lt': startLt});
      return;
    }
    final errors = htErrors(data);
    if (errors.isNotEmpty) {
      setState(() => message = errors.join('\n'));
      return;
    }
    final code = transformerIdentity(data['transformer_code']);
    final duplicate =
        widget.controller.transformers.any(
          (t) =>
              t['feeder_id'] == data['feeder_id'] &&
              transformerIdentity(t['transformer_code']) == code,
        ) ||
        widget.controller.records.any(
          (r) =>
              r.id != widget.ltSurveyId &&
              r.data['feeder_id'] == data['feeder_id'] &&
              transformerIdentity(r.data['transformer_code']) == code &&
              r.data['ht_update_uuid'] != data['client_uuid'],
        ) ||
        widget.controller.htRecords.any(
          (h) =>
              h['data']['client_uuid'] != data['client_uuid'] &&
              h['data']['feeder_id'] == data['feeder_id'] &&
              transformerIdentity(h['data']['transformer_code']) == code,
        );
    if (duplicate) {
      setState(
        () => message =
            'This transformer already has a reference or saved LT survey. Open that survey, or ask the administrator to reconcile it.',
      );
      return;
    }
    setState(() => saving = true);
    try {
      debounce?.cancel();
      await writes;
      final snapshot = htPayload(data);
      await widget.controller.store.saveHt(widget.controller.session!.scope, {
        'data': data,
        'state': 'queued',
        'outbound': snapshot,
        'error': '',
      });
      state = 'queued';
      await widget.controller.reload();
      if (mounted) await close({'data': data, 'start_lt': startLt});
      if (widget.controller.autoSync) {
        unawaited(widget.controller.sync(manual: false));
      }
    } catch (e) {
      if (mounted) setState(() => message = '$e');
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  Widget field(
    Json target,
    String key,
    String label, {
    bool number = false,
    bool identity = false,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: EntryField(
      key: ValueKey('${identityHashCode(target)}:$key'),
      label: label,
      value: target[key],
      enabled:
          !locked &&
          !saving &&
          !(identity && (parseIntegerId(data['base_revision']) ?? 0) > 0),
      lines: key == 'remarks' ? 3 : 1,
      number: number,
      onChanged: (value) {
        target[key] = value;
        changed();
      },
    ),
  );

  Future<void> close([Json? result]) async {
    if (!mounted) return;
    setState(() => allowPop = true);
    await WidgetsBinding.instance.endOfFrame;
    if (mounted) Navigator.pop(context, result);
  }

  Future<void> photo(ImageSource source) async {
    try {
      final file = await ImagePicker().pickImage(
        source: source,
        maxWidth: 1200,
        maxHeight: 1200,
        imageQuality: 65,
        requestFullMetadata: false,
      );
      if (file == null) return;
      final bytes = await file.readAsBytes();
      if (bytes.length > 1024 * 1024) {
        throw const FormatException(
          'Choose a JPEG/PNG photograph smaller than 1 MB.',
        );
      }
      if (!mounted) return;
      setState(() => (data['photos'] as List).add(base64Encode(bytes)));
      await saveDraft();
    } catch (e) {
      if (mounted) setState(() => message = '$e');
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: allowPop,
    onPopInvokedWithResult: (didPop, result) async {
      if (didPop || saving) return;
      try {
        debounce?.cancel();
        await saveDraft();
        await close();
      } catch (_) {
        /* Keep the form open when storage fails. */
      }
    },
    child: Scaffold(
      appBar: AppBar(title: const Text('HT Network Update')),
      body: ListView(
        padding: const EdgeInsets.all(18),
        children: [
          Text(
            '${widget.feeder['feeder_code']} · ${widget.feeder['feeder_name']}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 10),
          Text(
            'HT status: $state. LT Transformer Survey can start after saving this update. Final network release requires independent verification.',
          ),
          if (data['review_note'] != null)
            Text(
              'Web review: ${data['review_note']}',
              style: const TextStyle(fontWeight: FontWeight.bold),
            ),
          if (message.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 12),
              child: Text(message),
            ),
          const SizedBox(height: 12),
          ChoiceField(
            label: 'HT update type',
            value: data['kind'],
            enabled: !locked && !saving,
            values: const ['ht_extension', 'missing_transformer'],
            labels: const {
              'ht_extension': 'HT extension + missing transformer',
              'missing_transformer': 'Missing transformer on existing HT',
            },
            onChanged: (v) {
              setState(() => data['kind'] = v);
              changed();
            },
          ),
          const SizedBox(height: 12),
          field(
            data,
            'transformer_code',
            'Transformer code / unique temporary reference',
            identity: true,
          ),
          field(
            data,
            'capacity_kva',
            'Transformer capacity (kVA)',
            number: true,
          ),
          field(data, 'make', 'Transformer make'),
          field(data, 'location', 'Transformer location'),
          ChoiceField(
            label: 'Transformer mounting',
            value: data['mounting'],
            enabled: !locked && !saving,
            values: const ['S.Pole', 'D.Pole', 'Pad'],
            labels: const {
              'S.Pole': 'S.Pole — Single pole',
              'D.Pole': 'D.Pole — Double pole',
              'Pad': 'Pad',
            },
            onChanged: (v) {
              setState(() => data['mounting'] = v);
              changed();
            },
          ),
          const SizedBox(height: 12),
          ChoiceField(
            label: 'Transformer HT phase',
            value: data['phase'] ?? '',
            enabled: !locked && !saving,
            values: const ['R', 'Y', 'B', 'R-Y', 'Y-B', 'R-B', 'R-Y-B'],
            onChanged: (v) {
              setState(() => data['phase'] = v);
              changed();
            },
          ),
          const SizedBox(height: 20),
          Text(
            'Existing HT connection',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const Text(
            'Select the actual upstream HT asset. Orange highlights your selection. Confirm the connection in the field.',
          ),
          if (features.isNotEmpty) ...[
            SizedBox(
              height: 260,
              child: InteractiveViewer(
                minScale: 0.5,
                maxScale: 12,
                child: CustomPaint(
                  size: const Size(600, 400),
                  painter: HtNetworkPainter(
                    features,
                    data['upstream_feature_id'],
                  ),
                ),
              ),
            ),
            DropdownButtonFormField<String>(
              isExpanded: true,
              initialValue:
                  features.any((f) => f['id'] == data['upstream_feature_id'])
                  ? data['upstream_feature_id']
                  : null,
              decoration: const InputDecoration(labelText: 'Client HT feature'),
              items: features
                  .map(
                    (f) => DropdownMenuItem<String>(
                      value: f['id'],
                      child: Text(
                        '${f['type']} · ${f['label']} · ${f['id']}',
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  )
                  .toList(),
              onChanged: locked
                  ? null
                  : (v) {
                      setState(() {
                        data['upstream_feature_id'] = v;
                        data['baseline_sha256'] = network['baseline_sha256'];
                      });
                      changed();
                    },
            ),
          ] else
            const Text(
              'Client HT geometry is unavailable offline. Record a clear upstream reference now; download feeder data and select the feature before web verification.',
            ),
          const SizedBox(height: 12),
          field(data, 'upstream_reference', 'Existing HT connection reference'),
          const Text(
            'Route order: existing HT connection first, new poles next, transformer last. For a missing transformer without an extension, record the connection and transformer only. Enter waypoint IDs; coordinates can be resolved from GPX during web review.',
          ),
          for (var i = 0; i < nodes.length; i++)
            Card(
              key: ObjectKey(nodes[i]),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      i == 0
                          ? '1. Existing HT connection'
                          : i == nodes.length - 1
                          ? '${i + 1}. Missing transformer'
                          : '${i + 1}. New HT pole',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 12),
                    field(
                      nodes[i],
                      'waypoint',
                      'GPS waypoint / pole reference',
                    ),
                    field(nodes[i], 'pole_type', 'Pole type'),
                    field(
                      nodes[i],
                      'pole_height',
                      'Pole height (ft)',
                      number: true,
                    ),
                    field(
                      nodes[i],
                      'latitude',
                      'Latitude (optional; GPX may resolve later)',
                      number: true,
                    ),
                    field(
                      nodes[i],
                      'longitude',
                      'Longitude (optional; GPX may resolve later)',
                      number: true,
                    ),
                    if (i > 0 && data['kind'] == 'ht_extension') ...[
                      for (final phase in ['r', 'y', 'b'])
                        field(
                          nodes[i],
                          'conductor_$phase',
                          'Incoming HT span conductor ${phase.toUpperCase()}',
                        ),
                    ],
                    if (!locked && i > 0 && i < nodes.length - 1)
                      TextButton(
                        onPressed: () {
                          setState(() => (data['nodes'] as List).removeAt(i));
                          changed();
                        },
                        child: const Text('Remove this HT pole'),
                      ),
                  ],
                ),
              ),
            ),
          if (!locked && data['kind'] == 'ht_extension')
            OutlinedButton.icon(
              onPressed: () {
                setState(
                  () => (data['nodes'] as List).insert(
                    nodes.length - 1,
                    htNode(),
                  ),
                );
                changed();
              },
              icon: const Icon(Icons.add),
              label: const Text('Add HT pole before transformer'),
            ),
          field(data, 'remarks', 'HT change reason and connection evidence'),
          Text(
            'HT evidence photographs (${(data['photos'] as List).length}/3)',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          for (var i = 0; i < (data['photos'] as List).length; i++)
            Row(
              children: [
                Expanded(
                  child: Image.memory(
                    base64Decode(data['photos'][i]),
                    height: 150,
                    errorBuilder: (_, error, stack) =>
                        const Text('Image could not be displayed'),
                  ),
                ),
                if (!locked)
                  IconButton(
                    onPressed: () {
                      setState(() => (data['photos'] as List).removeAt(i));
                      changed();
                    },
                    icon: const Icon(Icons.delete_outline),
                  ),
              ],
            ),
          if (!locked && (data['photos'] as List).length < 3)
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton.icon(
                  onPressed: () => photo(ImageSource.camera),
                  icon: const Icon(Icons.camera_alt),
                  label: const Text('HT photo'),
                ),
                OutlinedButton.icon(
                  onPressed: () => photo(ImageSource.gallery),
                  icon: const Icon(Icons.photo_library),
                  label: const Text('Choose HT photo'),
                ),
              ],
            ),
          const SizedBox(height: 16),
          if (!locked)
            OutlinedButton(
              onPressed: saving ? null : saveDraft,
              child: const Text('Save HT draft'),
            ),
          if (!['draft', 'conflict'].contains(state) || !locked)
            FilledButton.icon(
              onPressed: saving ? null : () => finish(startLt: true),
              icon: const Icon(Icons.assignment),
              label: Text(
                locked
                    ? 'Open / Start LT Transformer Survey'
                    : 'Save HT & Start LT Transformer Survey',
              ),
            ),
          if (!locked)
            TextButton(
              onPressed: saving ? null : () => finish(startLt: false),
              child: const Text('Submit HT Network Update only'),
            ),
          const SizedBox(height: 25),
        ],
      ),
    ),
  );
}

class HtNetworkPainter extends CustomPainter {
  HtNetworkPainter(this.features, this.selected);
  final List<Json> features;
  final String? selected;
  @override
  void paint(Canvas canvas, Size size) {
    final points = features
        .expand((f) => (f['coordinates'] as List).cast<List>())
        .toList();
    if (points.isEmpty) return;
    final xs = points.map((p) => (p[0] as num).toDouble()),
        ys = points.map((p) => (p[1] as num).toDouble());
    final minX = xs.reduce(math.min),
        maxX = xs.reduce(math.max),
        minY = ys.reduce(math.min),
        maxY = ys.reduce(math.max);
    final scale = math.min(
      (size.width - 24) / math.max(maxX - minX, 0.00001),
      (size.height - 24) / math.max(maxY - minY, 0.00001),
    );
    Offset pos(List p) => Offset(
      12 + ((p[0] as num) - minX) * scale,
      size.height - 12 - ((p[1] as num) - minY) * scale,
    );
    for (final f in features) {
      final paint = Paint()
        ..color = f['id'] == selected ? Colors.orange : const Color(0xff075b71)
        ..strokeWidth = f['id'] == selected ? 4 : 1.3;
      final coords = (f['coordinates'] as List).cast<List>();
      if (f['type'] == 'line') {
        for (var i = 1; i < coords.length; i++) {
          canvas.drawLine(pos(coords[i - 1]), pos(coords[i]), paint);
        }
      } else if (coords.isNotEmpty) {
        canvas.drawCircle(pos(coords.first), 2.5, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant HtNetworkPainter oldDelegate) =>
      oldDelegate.selected != selected || oldDelegate.features != features;
}
