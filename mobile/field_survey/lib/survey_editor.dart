import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'field_controller.dart';
import 'models.dart';

class SurveyEditor extends StatefulWidget {
  const SurveyEditor({
    super.key,
    required this.controller,
    required this.record,
  });
  final FieldController controller;
  final SurveyRecord record;
  @override
  State<SurveyEditor> createState() => _SurveyEditorState();
}

class _SurveyEditorState extends State<SurveyEditor>
    with WidgetsBindingObserver {
  late SurveyRecord record;
  Timer? debounce;
  Future<void> writeChain = Future.value();
  bool dirty = false, saving = false, submitting = false, allowPop = false;
  String saveLabel = 'Saved on this phone';
  int section = 0;
  int headerGeneration = 0;
  bool syncingHere = false;
  Map<String, String> errors = {};
  List<Json> photos = [];
  bool get editable => !record.locked && !submitting;
  FieldController get controller => widget.controller;

  @override
  void initState() {
    super.initState();
    record = widget.record;
    WidgetsBinding.instance.addObserver(this);
    controller.addListener(controllerChanged);
    unawaited(loadPhotos());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused) {
      unawaited(flush().catchError((Object _) {}));
    }
  }

  @override
  void dispose() {
    debounce?.cancel();
    controller.removeListener(controllerChanged);
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void controllerChanged() {
    if (mounted) {
      setState(() {});
      unawaited(loadPhotos());
    }
  }

  void notice(String message) {
    if (mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
    }
  }

  void touch([String? path]) {
    if (!editable) return;
    record.state = 'draft';
    record.error = '';
    record.data['status'] = 'draft';
    dirty = true;
    if (path != null) errors.remove(path);
    setState(() => saveLabel = 'Saving changes…');
    debounce?.cancel();
    debounce = Timer(
      const Duration(milliseconds: 500),
      () => unawaited(flush().catchError((Object _) {})),
    );
  }

  Future<void> flush() async {
    debounce?.cancel();
    if (!dirty || record.locked) {
      await writeChain;
      return;
    }
    final snapshot = record.clone();
    dirty = false;
    if (mounted) setState(() => saving = true);
    final next = writeChain
        .catchError((Object _) {})
        .then((_) => controller.store.save(snapshot));
    writeChain = next;
    try {
      await next;
      if (mounted) {
        setState(() {
          saving = false;
          saveLabel = dirty ? 'Saving changes…' : 'Saved on this phone';
        });
      }
    } catch (_) {
      dirty = true;
      if (mounted) {
        setState(() {
          saving = false;
          saveLabel = 'Save failed — retry before leaving';
        });
      }
      rethrow;
    }
  }

  Future<void> leave() async {
    if (submitting) return;
    try {
      await flush();
    } catch (_) {
      notice('Unable to save. Check phone storage and retry before leaving.');
      return;
    }
    if (!mounted) return;
    setState(() => allowPop = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) Navigator.pop(context);
    });
  }

  Future<void> saveDraft() async {
    try {
      await flush();
      notice('Draft saved on this phone. Submit when complete.');
    } catch (_) {
      notice('Unable to save. Check the phone has available storage.');
    }
  }

  Future<void> submit() async {
    FocusScope.of(context).unfocus();
    final found = SurveyValidation.errors(record, submitting: true);
    setState(() => errors = found);
    if (found.isNotEmpty) {
      if (found.keys.any((key) => key.startsWith('rows'))) {
        setState(() => section = 1);
      } else if (found.keys.any((key) => key.startsWith('solar'))) {
        setState(() => section = 2);
      } else {
        setState(() => section = 0);
      }
      notice('Review the highlighted fields before submitting.');
      return;
    }
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Submit this survey?'),
        content: Text(
          '${record.rows.length} S/E row${record.rows.length == 1 ? '' : 's'} will be queued. The form stays on this phone until the server confirms it.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Keep draft'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Submit'),
          ),
        ],
      ),
    );
    if (accepted != true || !mounted) return;
    setState(() => submitting = true);
    try {
      await flush();
      await controller.store.queue(record);
      if (!mounted) return;
      setState(() {
        submitting = false;
        saveLabel = 'Submitted · waiting to sync';
      });
      await controller.reload();
      notice('Survey queued. Sync when connected.');
      unawaited(syncHere());
    } catch (_) {
      if (mounted) setState(() => submitting = false);
      notice(
        'Unable to queue. Your form remains on this phone; retry after checking storage.',
      );
    }
  }

  Future<void> syncHere() async {
    if (syncingHere || controller.syncing) return;
    setState(() => syncingHere = true);
    try {
      await controller.sync();
      await writeChain;
      final fresh = await controller.store.survey(record.scope, record.id);
      if (mounted && fresh != null && !dirty) {
        setState(() {
          record = fresh;
          saveLabel = fresh.state == 'synced'
              ? 'Confirmed by server · saved here'
              : 'Saved on this phone';
        });
      }
      await loadPhotos();
      notice(controller.syncMessage);
    } catch (_) {
      notice(
        'Unable to refresh sync status. Saved surveys remain on this phone.',
      );
    } finally {
      if (mounted) setState(() => syncingHere = false);
    }
  }

  Future<void> loadPhotos() async {
    final items = await controller.store.attachments(record.scope, record.id);
    if (mounted) setState(() => photos = items);
  }

  Future<void> chooseTransformer() async {
    final items = controller.transformers
        .where((t) => t['feeder_id'] == record.data['feeder_id'])
        .toList();
    String query = '';
    final chosen = await showDialog<Json>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Imported transformers'),
          content: SizedBox(
            width: 460,
            height: 370,
            child: Column(
              children: [
                TextField(
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search),
                    hintText: 'Search code or location',
                  ),
                  onChanged: (value) =>
                      update(() => query = value.toLowerCase()),
                ),
                const SizedBox(height: 12),
                Expanded(
                  child: items.isEmpty
                      ? const Center(
                          child: Text(
                            'No imported transformers for this feeder. Enter the transformer code manually.',
                          ),
                        )
                      : ListView(
                          children: items
                              .where(
                                (t) =>
                                    '${t['transformer_code']} ${t['equipment_location'] ?? ''}'
                                        .toLowerCase()
                                        .contains(query),
                              )
                              .map(
                                (t) => ListTile(
                                  title: Text(t['transformer_code']),
                                  subtitle: Text(
                                    '${t['capacity_kva'] ?? 'Unknown'} kVA · ${t['equipment_location'] ?? ''}',
                                  ),
                                  onTap: () => Navigator.pop(context, t),
                                ),
                              )
                              .toList(),
                        ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Close'),
            ),
          ],
        ),
      ),
    );
    if (chosen != null) {
      setState(() {
        record.data['transformer_id'] = chosen['id'];
        headerGeneration++;
        record.data['transformer_code'] = chosen['transformer_code'];
        record.header['capacity_kva'] = chosen['capacity_kva'] ?? '';
        record.header['transformer_make'] = chosen['equipment_make'] ?? '';
        record.header['location'] = chosen['equipment_location'] ?? '';
        touch();
      });
    }
  }

  Future<void> addRow() async {
    if (record.rows.length >= 500) {
      notice('A survey supports up to 500 rows.');
      return;
    }
    final row = SurveyRecord.newRow(record.data['survey_date']);
    record.rows.add(row);
    touch();
    await editRow(record.rows.length - 1);
  }

  Future<void> editRow(int index) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => RowEditor(
          record: record,
          index: index,
          editable: editable,
          onChanged: () => touch(),
          onSave: flush,
          showRequired: errors.keys.any((key) => key.startsWith('rows.$index')),
        ),
      ),
    );
    if (mounted) setState(() {});
  }

  Future<void> deleteRow(int index) async {
    final accepted = await confirmRemoval('Remove row ${index + 1}?');
    if (accepted) {
      record.rows.removeAt(index);
      touch();
    }
  }

  Future<bool> confirmRemoval(String title) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(title),
          content: const Text('This removes the entry from your local draft.'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Remove'),
            ),
          ],
        ),
      ) ??
      false;
  Future<void> attach(ImageSource source, String kind) async {
    try {
      await flush();
      await controller.pickPhoto(record, source, kind);
      await loadPhotos();
    } on FormatException catch (e) {
      notice(e.message);
    } on Object {
      notice(
        'Unable to add the image. Check camera permission and available storage.',
      );
    }
  }

  Widget text(
    Json target,
    String key,
    String label, {
    String? path,
    bool number = false,
    int lines = 1,
    String? helper,
  }) => EntryField(
    key: ValueKey(
      '$headerGeneration:${record.revision}:$path:$key:${identityHashCode(target)}',
    ),
    label: label,
    value: target[key],
    enabled: editable,
    number: number,
    lines: lines,
    error: errors[path ?? key],
    helper: helper,
    onChanged: (value) {
      target[key] = value;
      if (key == 'transformer_code') record.data['transformer_id'] = null;
      touch(path ?? key);
    },
  );
  Widget choice(Json target, String key, String label, List<String> values) =>
      ChoiceField(
        key: ValueKey('${identityHashCode(target)}:$key'),
        label: label,
        value: target[key]?.toString() ?? '',
        enabled: editable,
        values: values,
        onChanged: (value) {
          target[key] = value;
          touch();
        },
      );
  Widget headerSection() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      SectionCard(
        title: 'Transformer',
        subtitle: 'Check imported values against the equipment in the field.',
        children: [
          if (editable)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: chooseTransformer,
                icon: const Icon(Icons.manage_search),
                label: const Text('Find imported transformer'),
              ),
            ),
          text(
            record.data,
            'transformer_code',
            'Transformer code',
            path: 'transformer_code',
          ),
          text(
            record.header,
            'capacity_kva',
            'Capacity (kVA)',
            path: 'header.capacity_kva',
            number: true,
          ),
          text(
            record.header,
            'transformer_make',
            'Transformer make',
            path: 'header.transformer_make',
          ),
          choice(record.header, 'mounting', 'Mounting', [
            '',
            'S.Pole',
            'D.Pole',
            'Pad',
          ]),
          choice(record.header, 'duty', 'Duty', [
            '',
            'General Duty',
            'Dedicated',
          ]),
          text(
            record.header,
            'location',
            'Location',
            path: 'header.location',
            lines: 2,
          ),
        ],
      ),
      SectionCard(
        title: 'Survey details',
        children: [
          DateEntry(
            label: 'Survey date',
            value: record.data['survey_date'],
            enabled: editable,
            onChanged: (value) {
              record.data['survey_date'] = value;
              touch('survey_date');
            },
          ),
          text(
            record.header,
            'inspectors',
            'Inspectors',
            path: 'header.inspectors',
            helper: 'Enter all inspector names.',
          ),
          text(
            record.header,
            'substation',
            'Substation',
            path: 'header.substation',
          ),
          text(record.header, 'division', 'Division', path: 'header.division'),
          text(
            record.header,
            'sub_division',
            'Sub-division',
            path: 'header.sub_division',
          ),
          text(
            record.header,
            'sub_division_code',
            'Sub-division code',
            path: 'header.sub_division_code',
          ),
          text(
            record.data,
            'remarks',
            'Overall remarks',
            path: 'remarks',
            lines: 3,
          ),
        ],
      ),
    ],
  );
  Widget rowsSection() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text(
        'S/E observations',
        style: Theme.of(
          context,
        ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      const Text(
        'Each printed S or E row is a separate observation. Waypoint identifiers keep their leading zeros.',
      ),
      const SizedBox(height: 14),
      if (errors['rows'] != null)
        Text(
          errors['rows']!,
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
      if (editable)
        FilledButton.tonalIcon(
          onPressed: addRow,
          icon: const Icon(Icons.add_location_alt_outlined),
          label: const Text('Add S/E row'),
        ),
      const SizedBox(height: 16),
      if (record.rows.isEmpty)
        const SectionCard(
          title: 'No observations yet',
          children: [Text('Add a row for each observation on the paper form.')],
        ),
      ...record.rows.asMap().entries.map((entry) {
        final index = entry.key, row = entry.value;
        final hasErrors = errors.keys.any(
          (key) => key.startsWith('rows.$index.'),
        );
        return Card(
          child: ListTile(
            onTap: () => editRow(index),
            leading: CircleAvatar(child: Text(row['se'] ?? 'S')),
            title: Text(
              'Row ${index + 1} · ${row['gps_waypoint'].toString().isEmpty ? 'Waypoint needed' : row['gps_waypoint']}',
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            subtitle: Text(
              'Group ${row['group'].toString().isEmpty ? '—' : row['group']} · ${row['date']}'
              '${hasErrors ? '\nReview missing or invalid values' : ''}',
            ),
            trailing: editable
                ? IconButton(
                    tooltip: 'Remove row',
                    onPressed: () => deleteRow(index),
                    icon: const Icon(Icons.delete_outline),
                  )
                : const Icon(Icons.chevron_right),
          ),
        );
      }),
    ],
  );
  Widget solarSection() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text(
        'Solar / net-metering',
        style: Theme.of(
          context,
        ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      const Text(
        'Optional. Record each consumer reference and installed PV capacity separately.',
      ),
      const SizedBox(height: 14),
      if (editable)
        FilledButton.tonalIcon(
          onPressed: () {
            if (record.solar.length >= 100) {
              notice('Maximum 100 solar installations per survey.');
              return;
            }
            record.solar.add({
              'consumer_reference': '',
              'installed_pv_kw': '',
              'remarks': '',
            });
            touch();
          },
          icon: const Icon(Icons.add),
          label: const Text('Add solar installation'),
        ),
      const SizedBox(height: 16),
      ...record.solar.asMap().entries.map(
        (entry) => SectionCard(
          title: 'Installation ${entry.key + 1}',
          children: [
            text(
              entry.value,
              'consumer_reference',
              'Consumer reference number',
              path: 'solar.${entry.key}.consumer_reference',
            ),
            text(
              entry.value,
              'installed_pv_kw',
              'Installed PV capacity (kW)',
              number: true,
              path: 'solar.${entry.key}.installed_pv_kw',
            ),
            text(
              entry.value,
              'remarks',
              'Remarks',
              lines: 2,
              path: 'solar.${entry.key}.remarks',
            ),
            if (editable)
              TextButton.icon(
                onPressed: () async {
                  if (await confirmRemoval(
                    'Remove installation ${entry.key + 1}?',
                  )) {
                    record.solar.removeAt(entry.key);
                    touch();
                  }
                },
                icon: const Icon(Icons.delete_outline),
                label: const Text('Remove installation'),
              ),
          ],
        ),
      ),
      if (record.solar.isEmpty)
        const SectionCard(
          title: 'No solar installations recorded',
          children: [Text('Leave this section empty when it does not apply.')],
        ),
    ],
  );
  Widget photosSection() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text(
        'Photos and sketches',
        style: Theme.of(
          context,
        ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 8),
      const Text(
        'Images are copied into the app storage and uploaded after the survey is confirmed. Photograph a sketch using the sketch button.',
      ),
      const SizedBox(height: 14),
      if (editable)
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            FilledButton.tonalIcon(
              onPressed: () => attach(ImageSource.camera, 'photo'),
              icon: const Icon(Icons.camera_alt_outlined),
              label: const Text('Take photo'),
            ),
            OutlinedButton.icon(
              onPressed: () => attach(ImageSource.gallery, 'photo'),
              icon: const Icon(Icons.photo_library_outlined),
              label: const Text('Choose image'),
            ),
            OutlinedButton.icon(
              onPressed: () => attach(ImageSource.camera, 'sketch'),
              icon: const Icon(Icons.draw_outlined),
              label: const Text('Photograph sketch'),
            ),
          ],
        ),
      const SizedBox(height: 16),
      ...photos.map(
        (photo) => Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: Image.file(
                    File(photo['path']),
                    height: 170,
                    width: double.infinity,
                    fit: BoxFit.cover,
                    errorBuilder: (_, _, _) => const SizedBox(
                      height: 90,
                      child: Center(child: Text('Image missing from phone')),
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  '${photo['kind'] == 'sketch' ? 'Sketch' : 'Photo'} · ${photo['state'] == 'synced'
                      ? 'Uploaded'
                      : photo['state'] == 'pending'
                      ? 'Waiting to upload'
                      : photo['state'] == 'uploading'
                      ? 'Uploading…'
                      : 'Needs attention'}',
                ),
                if ((photo['error'] as String).isNotEmpty)
                  Text(
                    photo['error'],
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                if (editable &&
                    !controller.syncing &&
                    photo['state'] != 'synced' &&
                    photo['state'] != 'uploading')
                  TextButton.icon(
                    onPressed: () async {
                      if (await confirmRemoval('Remove this image?')) {
                        if (controller.syncing) {
                          notice(
                            'Wait for sync to finish before removing an image.',
                          );
                          return;
                        }
                        final removed = await controller.store.deleteAttachment(
                          record.scope,
                          photo['uuid'],
                        );
                        if (!removed) {
                          notice(
                            'The image is being uploaded or already confirmed. It was kept.',
                          );
                        }
                        await loadPhotos();
                      }
                    },
                    icon: const Icon(Icons.delete_outline),
                    label: const Text('Remove image'),
                  ),
              ],
            ),
          ),
        ),
      ),
      if (photos.isEmpty)
        const SectionCard(
          title: 'No images attached',
          children: [Text('Photos and sketches are optional.')],
        ),
    ],
  );

  @override
  Widget build(BuildContext context) {
    final feeder = controller.feeder(record.data['feeder_id']);
    return PopScope(
      canPop: allowPop,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) unawaited(leave());
      },
      child: Scaffold(
        appBar: AppBar(
          leading: IconButton(
            onPressed: submitting ? null : leave,
            tooltip: 'Save and go back',
            icon: const Icon(Icons.arrow_back),
          ),
          title: Text(
            record.data['transformer_code'].toString().isEmpty
                ? 'New field survey'
                : record.data['transformer_code'],
          ),
          actions: [
            IconButton(
              tooltip: 'Save draft',
              onPressed: editable ? saveDraft : null,
              icon: const Icon(Icons.save_outlined),
            ),
          ],
        ),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  children: [
                    Icon(
                      saving ? Icons.sync : Icons.check_circle_outline,
                      size: 16,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        saveLabel,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: submitting || controller.syncing
                        ? null
                        : editable
                        ? submit
                        : record.state == 'conflict'
                        ? null
                        : syncHere,
                    icon: Icon(editable ? Icons.upload_outlined : Icons.sync),
                    label: Padding(
                      padding: const EdgeInsets.all(10),
                      child: Text(
                        submitting
                            ? 'Saving submission…'
                            : editable
                            ? 'Submit survey'
                            : record.state == 'conflict'
                            ? 'Administrator review needed'
                            : 'Retry / refresh sync',
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              '${feeder?['feeder_code'] ?? ''} · ${feeder?['feeder_name'] ?? 'Assigned feeder'}',
              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
            ),
            const SizedBox(height: 4),
            Text(
              controller.team(record.data['survey_team_id'])?['name'] ??
                  'Assigned team',
            ),
            const SizedBox(height: 12),
            if (record.locked)
              SectionCard(
                title: record.state == 'conflict'
                    ? 'Version conflict'
                    : 'Submitted survey',
                children: [
                  Text(
                    record.state == 'conflict'
                        ? 'Your version is preserved. Ask the project administrator to reconcile it before editing.'
                        : 'The queued copy is kept unchanged for safe retry. You can edit it after the server confirms the upload.',
                  ),
                ],
              ),
            if (record.error.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(
                  record.error,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: SegmentedButton<int>(
                segments: const [
                  ButtonSegment(
                    value: 0,
                    label: Text('Details'),
                    icon: Icon(Icons.description_outlined),
                  ),
                  ButtonSegment(
                    value: 1,
                    label: Text('S/E rows'),
                    icon: Icon(Icons.route_outlined),
                  ),
                  ButtonSegment(
                    value: 2,
                    label: Text('Solar'),
                    icon: Icon(Icons.solar_power_outlined),
                  ),
                  ButtonSegment(
                    value: 3,
                    label: Text('Photos'),
                    icon: Icon(Icons.photo_outlined),
                  ),
                ],
                selected: {section},
                onSelectionChanged: (values) =>
                    setState(() => section = values.first),
              ),
            ),
            const SizedBox(height: 20),
            switch (section) {
              0 => headerSection(),
              1 => rowsSection(),
              2 => solarSection(),
              _ => photosSection(),
            },
          ],
        ),
      ),
    );
  }
}

class RowEditor extends StatefulWidget {
  const RowEditor({
    super.key,
    required this.record,
    required this.index,
    required this.editable,
    required this.onChanged,
    required this.onSave,
    this.showRequired = false,
  });
  final SurveyRecord record;
  final int index;
  final bool editable, showRequired;
  final VoidCallback onChanged;
  final Future<void> Function() onSave;
  @override
  State<RowEditor> createState() => _RowEditorState();
}

class _RowEditorState extends State<RowEditor> {
  Json get row => widget.record.rows[widget.index];
  bool locating = false, allowPop = false;
  int gpsGeneration = 0;
  String? gpsError;
  String get prefix => 'rows.${widget.index}';
  Map<String, String> get errors =>
      SurveyValidation.errors(widget.record, submitting: widget.showRequired);
  void changed() {
    widget.onChanged();
    setState(() {});
  }

  Future<void> done() async {
    try {
      await widget.onSave();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Unable to save. Retry after checking phone storage.',
            ),
          ),
        );
      }
      return;
    }
    if (mounted) {
      setState(() => allowPop = true);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) Navigator.pop(context);
      });
    }
  }

  Future<void> gps() async {
    setState(() {
      locating = true;
      gpsError = null;
    });
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        throw const FormatException('Turn on phone location, then try again.');
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.deniedForever) {
        throw const FormatException(
          'Location permission is blocked. Allow location in the phone app settings.',
        );
      }
      if (permission == LocationPermission.denied) {
        throw const FormatException(
          'Location permission was not granted. You can still enter the waypoint manually.',
        );
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 30),
        ),
      );
      if (!mounted) return;
      row['latitude'] = position.latitude;
      row['longitude'] = position.longitude;
      row['gps_accuracy_m'] = position.accuracy;
      gpsGeneration++;
      changed();
    } on FormatException catch (e) {
      if (mounted) setState(() => gpsError = e.message);
    } on Object {
      if (mounted) {
        setState(
          () => gpsError =
              'Unable to get a GPS fix. Move outdoors or enter coordinates manually.',
        );
      }
    } finally {
      if (mounted) setState(() => locating = false);
    }
  }

  Widget field(
    String key,
    String label, {
    bool number = false,
    String? helper,
  }) => EntryField(
    key: ValueKey(
      '$key:${key == 'latitude' || key == 'longitude' ? gpsGeneration : ''}',
    ),
    label: label,
    value: row[key],
    enabled: widget.editable,
    number: number,
    helper: helper,
    error: errors['$prefix.$key'],
    onChanged: (value) {
      row[key] = value;
      if (key == 'latitude' || key == 'longitude') row['gps_accuracy_m'] = null;
      changed();
    },
  );

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: allowPop,
    onPopInvokedWithResult: (didPop, _) {
      if (!didPop) unawaited(done());
    },
    child: Scaffold(
      appBar: AppBar(
        title: Text('S/E row ${widget.index + 1}'),
        leading: IconButton(
          onPressed: done,
          icon: const Icon(Icons.arrow_back),
        ),
        actions: [
          TextButton(
            onPressed: done,
            child: const Text('Done', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SectionCard(
            title: 'Observation',
            subtitle: 'S/E codes are preserved as printed on the survey sheet.',
            children: [
              ChoiceField(
                label: 'S/E',
                value: row['se'],
                enabled: widget.editable,
                values: const ['S', 'E'],
                onChanged: (value) {
                  row['se'] = value;
                  changed();
                },
              ),
              field('group', 'Group identifier'),
              DateEntry(
                label: 'Row date',
                value: row['date'],
                enabled: widget.editable,
                onChanged: (value) {
                  row['date'] = value;
                  changed();
                },
              ),
              field(
                'gps_waypoint',
                'GPS waypoint identifier',
                helper:
                    'The printed GPS WP code; a phone GPS fix does not fill this identifier.',
              ),
              field(
                'phase',
                'Phase',
                helper: 'Enter the phase code recorded in the field.',
              ),
            ],
          ),
          SectionCard(
            title: 'Phone GPS',
            subtitle:
                'Optional. Accuracy indicates the uncertainty of the phone fix.',
            children: [
              if (widget.editable)
                FilledButton.tonalIcon(
                  onPressed: locating ? null : gps,
                  icon: const Icon(Icons.my_location),
                  label: Text(
                    locating ? 'Waiting for GPS…' : 'Capture current GPS',
                  ),
                ),
              if (gpsError != null)
                Text(
                  gpsError!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              field('latitude', 'Latitude', number: true),
              field('longitude', 'Longitude', number: true),
              Text(
                row['gps_accuracy_m'] == null
                    ? 'No accuracy recorded'
                    : 'Accuracy ±${parseNumber(row['gps_accuracy_m'])?.toStringAsFixed(1) ?? row['gps_accuracy_m']} m',
              ),
              if (widget.editable && row['gps_accuracy_m'] != null)
                TextButton(
                  onPressed: () {
                    row['latitude'] = null;
                    row['longitude'] = null;
                    row['gps_accuracy_m'] = null;
                    gpsGeneration++;
                    changed();
                  },
                  child: const Text('Clear phone GPS fix'),
                ),
            ],
          ),
          SectionCard(
            title: 'Conductors',
            subtitle:
                'A = Ant, W = Wasp, GN = Gnat. Each phase is recorded separately.',
            children: [
              ...{
                'conductor_r': 'R conductor',
                'conductor_y': 'Y conductor',
                'conductor_b': 'B conductor',
                'conductor_neutral': 'Neutral conductor',
              }.entries.map(
                (entry) => ConductorEntry(
                  label: entry.value,
                  value: row[entry.key],
                  enabled: widget.editable,
                  error: errors['$prefix.${entry.key}'],
                  onChanged: (value) {
                    row[entry.key] = value;
                    changed();
                  },
                ),
              ),
            ],
          ),
          SectionCard(
            title: 'Equipment / pole',
            children: [
              field('equipment_type', 'Equipment type'),
              ChoiceField(
                label: 'Pole class',
                value: row['pole_class'],
                enabled: widget.editable,
                values: const ['', 'S', 'PCO', 'PCS', 'RS', 'TS', 'WB'],
                onChanged: (value) {
                  row['pole_class'] = value;
                  changed();
                },
              ),
              const Text(
                'S Steel Structure · PCO PC Ordinary · PCS PC Spun\nRS Rail Steel · TS Tubular Steel · WB Wall Bracket',
                style: TextStyle(fontSize: 12),
              ),
              field(
                'pole_height_ft',
                'Pole height (ft)',
                number: true,
                helper:
                    'Feet are the application convention; confirm the field measurement unit.',
              ),
            ],
          ),
          SectionCard(
            title: 'Consumers',
            subtitle:
                'Blank means not recorded. Enter 0 only when no consumers were observed.',
            children: [
              ...consumerLabels.entries.map(
                (entry) => EntryField(
                  label: entry.value,
                  value: (row['consumers'] as Json)[entry.key],
                  enabled: widget.editable,
                  integer: true,
                  error: errors['$prefix.consumers.${entry.key}'],
                  onChanged: (value) {
                    (row['consumers'] as Json)[entry.key] = value;
                    changed();
                  },
                ),
              ),
              field(
                'intersection',
                'Int (separate field)',
                helper:
                    'Preserves the printed Int column; confirm its meaning with the survey lead.',
              ),
              EntryField(
                label: 'Row remarks',
                value: row['remarks'],
                enabled: widget.editable,
                lines: 3,
                error: errors['$prefix.remarks'],
                onChanged: (value) {
                  row['remarks'] = value;
                  changed();
                },
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class SectionCard extends StatelessWidget {
  const SectionCard({
    super.key,
    required this.title,
    required this.children,
    this.subtitle,
  });
  final String title;
  final String? subtitle;
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            title,
            style: Theme.of(
              context,
            ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
          ),
          if (subtitle != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                subtitle!,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          const SizedBox(height: 16),
          ...children.map(
            (child) => Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: child,
            ),
          ),
        ],
      ),
    ),
  );
}

class EntryField extends StatelessWidget {
  const EntryField({
    super.key,
    required this.label,
    this.value,
    required this.enabled,
    required this.onChanged,
    this.number = false,
    this.integer = false,
    this.lines = 1,
    this.error,
    this.helper,
  });
  final String label;
  final dynamic value;
  final bool enabled, number, integer;
  final int lines;
  final String? error, helper;
  final ValueChanged<String> onChanged;
  @override
  Widget build(BuildContext context) => TextFormField(
    initialValue: value?.toString() ?? '',
    enabled: enabled,
    maxLines: lines,
    keyboardType: integer
        ? TextInputType.number
        : number
        ? const TextInputType.numberWithOptions(decimal: true, signed: true)
        : lines > 1
        ? TextInputType.multiline
        : TextInputType.text,
    decoration: InputDecoration(
      labelText: label,
      errorText: error,
      helperText: helper,
      helperMaxLines: 3,
      errorMaxLines: 3,
    ),
    onChanged: onChanged,
  );
}

class ChoiceField extends StatelessWidget {
  const ChoiceField({
    super.key,
    required this.label,
    required this.value,
    required this.enabled,
    required this.values,
    required this.onChanged,
  });
  final String label, value;
  final bool enabled;
  final List<String> values;
  final ValueChanged<String> onChanged;
  @override
  Widget build(BuildContext context) => DropdownButtonFormField<String>(
    initialValue: values.contains(value) ? value : '',
    decoration: InputDecoration(labelText: label),
    isExpanded: true,
    items: values
        .map(
          (item) => DropdownMenuItem(
            value: item,
            child: Text(item.isEmpty ? 'Not recorded' : item),
          ),
        )
        .toList(),
    onChanged: enabled ? (item) => onChanged(item ?? '') : null,
  );
}

class DateEntry extends StatelessWidget {
  const DateEntry({
    super.key,
    required this.label,
    required this.value,
    required this.enabled,
    required this.onChanged,
  });
  final String label, value;
  final bool enabled;
  final ValueChanged<String> onChanged;
  @override
  Widget build(BuildContext context) => InkWell(
    onTap: enabled
        ? () async {
            final picked = await showDatePicker(
              context: context,
              initialDate:
                  (DateTime.tryParse(value)?.isAfter(DateTime.now()) ?? true)
                  ? DateTime.now()
                  : DateTime.parse(value),
              firstDate: DateTime(1900),
              lastDate: DateTime.now(),
            );
            if (picked != null) onChanged(dayString(picked));
          }
        : null,
    child: InputDecorator(
      decoration: InputDecoration(
        labelText: label,
        enabled: enabled,
        suffixIcon: const Icon(Icons.calendar_month_outlined),
      ),
      child: Text(value),
    ),
  );
}

class ConductorEntry extends StatefulWidget {
  const ConductorEntry({
    super.key,
    required this.label,
    required this.value,
    required this.enabled,
    required this.onChanged,
    this.error,
  });
  final String label, value;
  final bool enabled;
  final ValueChanged<String> onChanged;
  final String? error;
  @override
  State<ConductorEntry> createState() => _ConductorEntryState();
}

class _ConductorEntryState extends State<ConductorEntry> {
  late bool other;
  @override
  void initState() {
    super.initState();
    other =
        widget.value == 'Other' ||
        widget.value.isNotEmpty && !conductorChoices.contains(widget.value);
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      ChoiceField(
        label: widget.label,
        value: other ? 'Other' : widget.value,
        enabled: widget.enabled,
        values: const ['', ...conductorChoices],
        onChanged: (value) {
          setState(() => other = value == 'Other');
          widget.onChanged(value);
        },
      ),
      if (other)
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: EntryField(
            label: '${widget.label} description',
            value: widget.value == 'Other' ? '' : widget.value,
            enabled: widget.enabled,
            error: widget.error,
            onChanged: widget.onChanged,
          ),
        ),
    ],
  );
}
