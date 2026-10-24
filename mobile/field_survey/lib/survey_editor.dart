import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:uuid/uuid.dart';
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
  Iterable<String>? get equipmentCodes =>
      (controller.feeder(
                record.data['feeder_id'],
              )?['entry_lookups']?['equipment_types']
              as Map?)
          ?.keys
          .cast<String>();

  @override
  void initState() {
    super.initState();
    record = widget.record;
    WidgetsBinding.instance.addObserver(this);
    controller.addListener(controllerChanged);
    if (editable) {
      record.data['team_group'] = teamGroupIdentifier(
        controller.team(record.data['survey_team_id']),
      );
    }
    if (editable && record.ensurePairIds()) {
      // Save linkage without turning a viewed synced survey into an edited draft.
      dirty = true;
    }
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
    } catch (error) {
      dirty = true;
      if (mounted) {
        setState(() {
          saving = false;
          saveLabel = error is FormatException
              ? error.message
              : 'Save failed — retry before leaving';
        });
      }
      rethrow;
    }
  }

  Future<void> leave() async {
    if (submitting) return;
    try {
      await flush();
    } catch (error) {
      notice(
        error is FormatException
            ? error.message
            : 'Unable to save. Check phone storage and retry before leaving.',
      );
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
    } catch (error) {
      notice(
        error is FormatException
            ? error.message
            : 'Unable to save. Check the phone has available storage.',
      );
    }
  }

  Future<void> submit() async {
    FocusScope.of(context).unfocus();
    final found = SurveyValidation.errors(
      record,
      submitting: true,
      equipmentCodes: equipmentCodes,
    );
    setState(() => errors = found);
    if (found.isNotEmpty) {
      if (found.keys.any((key) => key.startsWith('rows'))) {
        setState(() => section = 1);
      } else if (found.keys.any((key) => key.startsWith('solar'))) {
        setState(() => section = 1);
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
      if (!mounted) return;
      unawaited(controller.sync(manual: false));
      final createNew = await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (dialogContext) => AlertDialog(
          title: const Text('Survey saved'),
          content: const Text(
            'Your submitted survey is saved on this phone. Any pending photos remain attached to it and will sync with the survey.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext, false),
              child: const Text('Go to Home'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(dialogContext, true),
              child: const Text('Create New Survey'),
            ),
          ],
        ),
      );
      if (!mounted) return;
      Navigator.of(context).pop(createNew == true);
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
    final items = await controller.availableTransformers(record);
    if (!mounted) return;
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
    final group = teamGroupIdentifier(
      controller.team(record.data['survey_team_id']),
    );
    if (group == null) {
      notice(
        'No valid assigned team number. Refresh assignments or ask the administrator to correct the team.',
      );
      return;
    }
    record.data['team_group'] = group;
    final rowErrors = SurveyValidation.errors(
      record,
      submitting: true,
      equipmentCodes: equipmentCodes,
    );
    for (var i = 0; i < record.rows.length; i++) {
      if (rowErrors.keys.any((key) => key.startsWith('rows.$i.'))) {
        var target = i;
        if (record.rows[i]['se'] == 'E' &&
            rowErrors.containsKey('rows.$i.pair')) {
          try {
            target = record.ensurePartnerRow(i);
          } on StateError catch (error) {
            notice(error.message);
            return;
          }
          touch();
        }
        await editRow(target);
        return;
      }
    }
    if (record.rows.length >= 499) {
      notice('A survey supports up to 500 rows.');
      return;
    }
    final row = record.nextRow();
    record.rows.add(row);
    touch();
    await editRow(record.rows.length - 1);
  }

  Future<void> editRow(int index) async {
    int? nextIndex = index;
    while (nextIndex != null && mounted) {
      final currentIndex = nextIndex;
      nextIndex = await Navigator.push<int>(
        context,
        MaterialPageRoute(
          builder: (_) => RowEditor(
            record: record,
            index: currentIndex,
            equipmentOptions:
                (controller.feeder(
                          record.data['feeder_id'],
                        )?['entry_lookups']?['equipment_types']
                        as Map?)
                    ?.cast<String, String>() ??
                const {},
            yearStart:
                controller.feeder(
                  record.data['feeder_id'],
                )?['entry_settings']?['two_digit_year_start'] ??
                2000,
            editable: editable,
            onChanged: () => touch(),
            onSave: flush,
            showRequired: true,
            extrasBuilder: (refresh) => Column(
              children: [
                solarSection(
                  rowId: record.rows[currentIndex]['row_id'],
                  refresh: refresh,
                ),
                photosSection(
                  rowId: record.rows[currentIndex]['row_id'],
                  refresh: refresh,
                ),
              ],
            ),
          ),
        ),
      );
    }
    if (mounted) setState(() {});
  }

  Future<void> deleteRow(int index) async {
    final pairId = record.rows[index]['pair_id'];
    final rowIds = record.rows
        .where((row) => row['pair_id'] == pairId)
        .map((row) => row['row_id'])
        .toSet();
    final attached = await controller.store.attachments(
      record.scope,
      record.id,
    );
    if (record.solar.any(
          (pv) => pv['row_id'] != null && rowIds.contains(pv['row_id']),
        ) ||
        attached.any(
          (photo) =>
              photo['row_id'] != null && rowIds.contains(photo['row_id']),
        )) {
      notice(
        'This pair has linked PV or photos. Remove its pending media first; rows with uploaded photos must be retained.',
      );
      return;
    }
    final accepted = await confirmRemoval(
      'Remove this S/E pair?',
      message:
          'Both S and E observations in this pair will be removed from the local draft. Other pairs are kept.',
    );
    if (accepted) {
      record.removePairAt(index);
      touch();
    }
  }

  Future<bool> confirmRemoval(
    String title, {
    String message = 'This removes the entry from your local draft.',
  }) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(title),
          content: Text(message),
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
  Future<void> attach(
    ImageSource source,
    String kind, {
    String? rowId,
    VoidCallback? refresh,
  }) async {
    try {
      await flush();
      await controller.pickPhoto(record, source, kind, rowId: rowId);
      await loadPhotos();
      refresh?.call();
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
    bool numericKeyboard = false,
    int lines = 1,
    String? helper,
    VoidCallback? refresh,
  }) => EntryField(
    key: ValueKey(
      '$headerGeneration:${record.revision}:$path:$key:${identityHashCode(target)}',
    ),
    label: label,
    value: target[key],
    enabled: editable,
    number: number,
    numericKeyboard: numericKeyboard,
    lines: lines,
    error: errors[path ?? key],
    helper: helper,
    onChanged: (value) {
      target[key] = value;
      if (key == 'transformer_code') record.data['transformer_id'] = null;
      touch(path ?? key);
      refresh?.call();
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
            yearStart:
                controller.feeder(
                  record.data['feeder_id'],
                )?['entry_settings']?['two_digit_year_start'] ??
                2000,
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
  Widget rowsSection() {
    final rowErrors = SurveyValidation.errors(
      record,
      submitting: true,
      equipmentCodes: equipmentCodes,
    );
    return Column(
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
        if (rowErrors['rows'] != null)
          Text(
            rowErrors['rows']!,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        if (editable)
          FilledButton.tonalIcon(
            onPressed: addRow,
            icon: const Icon(Icons.add_location_alt_outlined),
            label: const Text('Add / complete S/E pair'),
          ),
        const SizedBox(height: 16),
        if (record.rows.isEmpty)
          const SectionCard(
            title: 'No observations yet',
            children: [
              Text('Add a row for each observation on the paper form.'),
            ],
          ),
        ...record.rows.asMap().entries.map((entry) {
          final index = entry.key, row = entry.value;
          final hasErrors =
              rowErrors.keys.any((key) => key.startsWith('rows.$index.')) ||
              record.solar.asMap().entries.any(
                (pv) =>
                    pv.value['row_id'] == row['row_id'] &&
                    rowErrors.keys.any(
                      (key) => key.startsWith('solar.${pv.key}.'),
                    ),
              );
          return Card(
            shape: hasErrors
                ? RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                    side: BorderSide(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  )
                : null,
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
                      tooltip: 'Remove pair',
                      onPressed: () => deleteRow(index),
                      icon: const Icon(Icons.delete_outline),
                    )
                  : const Icon(Icons.chevron_right),
            ),
          );
        }),
        if (record.solar.any((pv) => pv['row_id'] == null) ||
            photos.any((photo) => photo['row_id'] == null))
          ExpansionTile(
            title: const Text('Previously saved unlinked records'),
            subtitle: const Text('Retained without assigning them to a row.'),
            children: [
              if (record.solar.any((pv) => pv['row_id'] == null))
                solarSection(),
              if (photos.any((photo) => photo['row_id'] == null))
                photosSection(),
            ],
          ),
      ],
    );
  }

  Widget solarSection({String? rowId, VoidCallback? refresh}) => Column(
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
        'Optional. Record the consumer reference or meter number, service load and solar capacity.',
      ),
      if (rowId == null)
        const Text(
          'Open an S/E row to add or view linked PV. Existing unlinked observations are retained below.',
        ),
      const SizedBox(height: 14),
      if (editable && rowId != null)
        FilledButton.tonalIcon(
          onPressed: () {
            if (record.solar.length >= 100) {
              notice('Maximum 100 solar installations per survey.');
              return;
            }
            record.solar.add({
              'pv_id': const Uuid().v4(),
              'row_id': rowId,
              'consumer_reference': '',
              'service_load_kw': '',
              'installed_pv_kw': '',
              'meter_number': '',
              'remarks': '',
            });
            touch();
            refresh?.call();
          },
          icon: const Icon(Icons.add),
          label: const Text('Add solar installation'),
        ),
      const SizedBox(height: 16),
      ...record.solar
          .asMap()
          .entries
          .where((entry) => entry.value['row_id'] == rowId)
          .map(
            (entry) => SectionCard(
              title: 'Installation ${entry.key + 1}',
              children: [
                text(
                  entry.value,
                  'consumer_reference',
                  'Consumer reference number',
                  numericKeyboard: true,
                  path: 'solar.${entry.key}.consumer_reference',
                  refresh: refresh,
                ),
                text(
                  entry.value,
                  'service_load_kw',
                  'PV Service Load (kW)',
                  number: true,
                  path: 'solar.${entry.key}.service_load_kw',
                  refresh: refresh,
                ),
                text(
                  entry.value,
                  'installed_pv_kw',
                  'PV Solar Capacity (kW)',
                  number: true,
                  path: 'solar.${entry.key}.installed_pv_kw',
                  refresh: refresh,
                ),
                text(
                  entry.value,
                  'meter_number',
                  'Meter Number',
                  numericKeyboard: true,
                  path: 'solar.${entry.key}.meter_number',
                  refresh: refresh,
                ),
                text(
                  entry.value,
                  'remarks',
                  'Remarks',
                  lines: 2,
                  path: 'solar.${entry.key}.remarks',
                  refresh: refresh,
                ),
                if (editable)
                  TextButton.icon(
                    onPressed: () async {
                      if (await confirmRemoval(
                        'Remove installation ${entry.key + 1}?',
                      )) {
                        record.solar.removeAt(entry.key);
                        touch();
                        refresh?.call();
                      }
                    },
                    icon: const Icon(Icons.delete_outline),
                    label: const Text('Remove installation'),
                  ),
              ],
            ),
          ),
      if (record.solar.where((pv) => pv['row_id'] == rowId).isEmpty)
        const SectionCard(
          title: 'No solar installations recorded',
          children: [Text('Leave this section empty when it does not apply.')],
        ),
    ],
  );
  Widget photosSection({String? rowId, VoidCallback? refresh}) => Column(
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
      if (rowId == null)
        const Text(
          'Open an S/E row to add or view linked photos. Legacy unlinked images stay here until you explicitly choose a row.',
        ),
      const SizedBox(height: 14),
      if (editable && rowId != null)
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            FilledButton.tonalIcon(
              onPressed: () => attach(
                ImageSource.camera,
                'photo',
                rowId: rowId,
                refresh: refresh,
              ),
              icon: const Icon(Icons.camera_alt_outlined),
              label: const Text('Take photo'),
            ),
            OutlinedButton.icon(
              onPressed: () => attach(
                ImageSource.gallery,
                'photo',
                rowId: rowId,
                refresh: refresh,
              ),
              icon: const Icon(Icons.photo_library_outlined),
              label: const Text('Choose image'),
            ),
            OutlinedButton.icon(
              onPressed: () => attach(
                ImageSource.camera,
                'sketch',
                rowId: rowId,
                refresh: refresh,
              ),
              icon: const Icon(Icons.draw_outlined),
              label: const Text('Photograph sketch'),
            ),
          ],
        ),
      const SizedBox(height: 16),
      ...photos
          .where((photo) => photo['row_id'] == rowId)
          .map(
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
                          child: Center(
                            child: Text('Image missing from phone'),
                          ),
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
                        rowId == null &&
                        ['pending', 'error'].contains(photo['state']))
                      TextButton(
                        onPressed: () async {
                          final chosen = await showDialog<String>(
                            context: context,
                            builder: (context) => SimpleDialog(
                              title: const Text('Link this photo to a row'),
                              children: record.rows
                                  .map(
                                    (row) => SimpleDialogOption(
                                      onPressed: () =>
                                          Navigator.pop(context, row['row_id']),
                                      child: Text(
                                        '${row['se']} ? GPS ${row['gps_waypoint']} ? ${row['date']}',
                                      ),
                                    ),
                                  )
                                  .toList(),
                            ),
                          );
                          if (chosen != null) {
                            await flush();
                            if (await controller.store.linkAttachment(
                              record,
                              photo['uuid'],
                              chosen,
                            )) {
                              touch();
                            }
                            await loadPhotos();
                            refresh?.call();
                          }
                        },
                        child: const Text('Link this existing photo to a row'),
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
                            final removed = await controller.store
                                .deleteAttachment(record.scope, photo['uuid']);
                            if (!removed) {
                              notice(
                                'The image is being uploaded or already confirmed. It was kept.',
                              );
                            }
                            await loadPhotos();
                            refresh?.call();
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
      if (photos.where((photo) => photo['row_id'] == rowId).isEmpty)
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
                if (!editable ||
                    (section == 1 &&
                        record.hasCompletePairWithEquipment(equipmentCodes)))
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
              _ => rowsSection(),
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
    this.extrasBuilder,
    this.equipmentOptions = const {},
    this.yearStart = 2000,
  });
  final SurveyRecord record;
  final int index;
  final Widget Function(VoidCallback refresh)? extrasBuilder;
  final bool editable, showRequired;
  final Map<String, String> equipmentOptions;
  final int yearStart;
  final VoidCallback onChanged;
  final Future<void> Function() onSave;
  @override
  State<RowEditor> createState() => _RowEditorState();
}

class _RowEditorState extends State<RowEditor> {
  Json get row => widget.record.rows[widget.index];
  bool allowPop = false;
  bool finishing = false;
  int copyGeneration = 0;
  String get prefix => 'rows.${widget.index}';
  Map<String, String> get errors => SurveyValidation.errors(
    widget.record,
    submitting: true,
    equipmentCodes: widget.equipmentOptions.isEmpty
        ? null
        : widget.equipmentOptions.keys,
  );
  void changed() {
    row['phase'] = phaseFromConductors(row);
    widget.onChanged();
    setState(() {});
  }

  Future<void> done({bool complete = true}) async {
    if (finishing) return;
    finishing = true;
    int? nextIndex;
    try {
      if (complete && widget.editable) {
        final rowErrors = errors.keys.where(
          (key) => key.startsWith('$prefix.') && key != '$prefix.pair',
        );
        if (rowErrors.isNotEmpty) {
          await widget.onSave();
          if (mounted) {
            setState(() {});
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text(
                  'Complete the highlighted row fields. Your entered values are saved.',
                ),
              ),
            );
          }
          finishing = false;
          return;
        }
        if (row['se'] == 'S') {
          final count = widget.record.rows.length;
          final end = widget.record.ensureEndRow(widget.index);
          if (count != widget.record.rows.length) widget.onChanged();
          if (SurveyValidation.errors(
            widget.record,
            submitting: true,
            equipmentCodes: widget.equipmentOptions.isEmpty
                ? null
                : widget.equipmentOptions.keys,
          ).keys.any((key) => key.startsWith('rows.$end.'))) {
            nextIndex = end;
          }
        }
      }
      await widget.onSave();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              error is StateError
                  ? error.message
                  : error is FormatException
                  ? error.message
                  : 'Unable to save. Retry after checking phone storage.',
            ),
          ),
        );
      }
      finishing = false;
      return;
    }
    if (mounted) {
      setState(() => allowPop = true);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) Navigator.pop(context, nextIndex);
      });
    }
  }

  Widget field(
    String key,
    String label, {
    bool number = false,
    bool digits = false,
    String? helper,
  }) => EntryField(
    key: ValueKey('$key:$copyGeneration'),
    label: label,
    value: row[key],
    enabled: widget.editable,
    number: number,
    digits: digits,
    maxLength: key == 'group' ? 2 : null,
    helper: helper,
    error: errors['$prefix.$key'],
    onChanged: (value) {
      row[key] = value;
      if (digits) row['entry_version'] = 2;
      changed();
    },
  );

  Widget phaseConsumerFields(String category, String label) {
    final phases = (row['consumer_phases'] as Json?)?[category] as Json? ?? {};
    final hasPhases = phases.values.any(
      (value) => (value ?? '').toString().trim().isNotEmpty,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final phase in ['R', 'Y', 'B'])
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: EntryField(
                    label: phase,
                    value: phases[phase],
                    enabled: widget.editable,
                    integer: true,
                    error: errors['$prefix.consumer_phases.$category.$phase'],
                    onChanged: (value) {
                      final all =
                          row.putIfAbsent(
                                'consumer_phases',
                                () => <String, dynamic>{},
                              )
                              as Json;
                      final counts =
                          all.putIfAbsent(category, () => <String, dynamic>{})
                              as Json;
                      counts[phase] = value;
                      updatePhaseConsumerTotals(row);
                      changed();
                    },
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 12),
        EntryField(
          key: ValueKey('$category-total'),
          label: 'Total',
          value: row['consumers'][category],
          enabled: widget.editable,
          readOnly: hasPhases,
          integer: true,
          helper: hasPhases ? 'Calculated from R, Y and B.' : null,
          error: errors['$prefix.consumers.$category'],
          onChanged: (value) {
            row['consumers'][category] = value;
            changed();
          },
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: allowPop,
    onPopInvokedWithResult: (didPop, _) {
      if (!didPop) unawaited(done(complete: false));
    },
    child: Scaffold(
      appBar: AppBar(
        title: Text('S/E row ${widget.index + 1}'),
        leading: IconButton(
          onPressed: () => done(complete: false),
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
                value: row['se'] ?? '',
                enabled: widget.editable && !['S', 'E'].contains(row['se']),
                error: errors['$prefix.se'],
                helper:
                    'S/E is assigned by the pair. Complete S to open E automatically.',
                values: const ['S', 'E'],
                onChanged: (value) {
                  row['se'] = value;
                  changed();
                },
              ),
              field(
                'group',
                'Group identifier',
                digits: true,
                helper:
                    'Defaults to your survey team. Enter exactly two digits; your edits are saved.',
              ),
              DateEntry(
                label: 'Row date',
                yearStart: widget.yearStart,
                value: row['date'] ?? '',
                error: errors['$prefix.date'],
                enabled: widget.editable,
                onChanged: (value) {
                  row['date'] = value;
                  changed();
                },
              ),
              field(
                'gps_waypoint',
                'GPS waypoint identifier',
                digits: true,
                helper:
                    'Enter the waypoint identifier used to link the GPX file.',
              ),
            ],
          ),
          SectionCard(
            title: 'Conductors',
            subtitle:
                'A = Ant, W = Wasp, GN = Gnat. Each phase is recorded separately.',
            children: [
              OutlinedButton.icon(
                onPressed: widget.editable && widget.index > 0
                    ? () {
                        widget.record.copyPreviousValues(widget.index);
                        copyGeneration++;
                        changed();
                      }
                    : null,
                icon: const Icon(Icons.copy),
                label: const Text('Copy Previous Values'),
              ),
              Text(
                widget.index == 0
                    ? 'No previous row to copy.'
                    : 'Copies conductors, equipment, pole class and height only.',
              ),
              ...{
                'conductor_r': 'R conductor',
                'conductor_y': 'Y conductor',
                'conductor_b': 'B conductor',
                'conductor_neutral': 'Neutral conductor',
              }.entries.map(
                (entry) => ConductorEntry(
                  key: ValueKey('${entry.key}:$copyGeneration'),
                  label: entry.value,
                  value: row[entry.key] ?? '',
                  enabled: widget.editable,
                  error: errors['$prefix.${entry.key}'],
                  onChanged: (value) {
                    row[entry.key] = value;
                    changed();
                  },
                ),
              ),
              TextFormField(
                key: ValueKey('phase:${phaseFromConductors(row)}'),
                initialValue: phaseFromConductors(row),
                readOnly: true,
                decoration: const InputDecoration(
                  labelText: 'Phase',
                  helperText:
                      'Automatic from R, Y and B conductors. Neutral is excluded.',
                ),
              ),
            ],
          ),
          SectionCard(
            title: 'Equipment / pole',
            children: [
              CheckboxListTile(
                title: const Text('Int'),
                subtitle: const Text(
                  'Check when this observation is at an intersection.',
                ),
                value: isIntersection(row['intersection']),
                controlAffinity: ListTileControlAffinity.leading,
                contentPadding: EdgeInsets.zero,
                onChanged: widget.editable
                    ? (value) {
                        row['intersection'] = value ?? false;
                        changed();
                      }
                    : null,
              ),
              ChoiceField(
                key: ValueKey('equipment:$copyGeneration'),
                error: errors['$prefix.equipment_type'],
                label: 'Equipment type',
                value: row['equipment_type'] ?? '',
                enabled: widget.editable,
                values: {
                  '',
                  ...(widget.equipmentOptions.isEmpty
                      ? ['TR', 'SP', 'Ang', 'WB']
                      : widget.equipmentOptions.keys),
                  if ((row['equipment_type'] ?? '').isNotEmpty)
                    row['equipment_type'] as String,
                }.toList(),
                labels: widget.equipmentOptions,
                onChanged: (value) {
                  row['equipment_type'] = value;
                  changed();
                },
              ),
              ChoiceField(
                key: ValueKey('pole:$copyGeneration'),
                error: errors['$prefix.pole_class'],
                label: 'Pole class',
                value: row['pole_class'] ?? '',
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
                (entry) => ['rs', 'sc'].contains(entry.key)
                    ? phaseConsumerFields(entry.key, entry.value)
                    : EntryField(
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
          if (widget.extrasBuilder != null)
            widget.extrasBuilder!(() => setState(() {})),
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

class EntryField extends StatefulWidget {
  const EntryField({
    super.key,
    required this.label,
    this.value,
    required this.enabled,
    required this.onChanged,
    this.number = false,
    this.numericKeyboard = false,
    this.integer = false,
    this.digits = false,
    this.readOnly = false,
    this.lines = 1,
    this.error,
    this.helper,
    this.maxLength,
  });
  final String label;
  final dynamic value;
  final bool enabled, number, numericKeyboard, integer, digits, readOnly;
  final int lines;
  final int? maxLength;
  final String? error, helper;
  final ValueChanged<String> onChanged;
  @override
  State<EntryField> createState() => _EntryFieldState();
}

class _EntryFieldState extends State<EntryField> {
  late final TextEditingController controller;

  @override
  void initState() {
    super.initState();
    controller = TextEditingController(text: widget.value?.toString() ?? '');
  }

  @override
  void didUpdateWidget(covariant EntryField oldWidget) {
    super.didUpdateWidget(oldWidget);
    final text = widget.value?.toString() ?? '';
    // Refresh copied/calculated values without replacing the focused field.
    if (controller.text != text) {
      controller.value = TextEditingValue(
        text: text,
        selection: TextSelection.collapsed(offset: text.length),
      );
    }
  }

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextFormField(
    controller: controller,
    enabled: widget.enabled,
    readOnly: widget.readOnly,
    maxLines: widget.lines,
    inputFormatters: [
      if (widget.digits) FilteringTextInputFormatter.digitsOnly,
      if (widget.maxLength != null)
        LengthLimitingTextInputFormatter(widget.maxLength),
    ],
    keyboardType: widget.numericKeyboard || widget.integer || widget.digits
        ? TextInputType.number
        : widget.number
        ? const TextInputType.numberWithOptions(decimal: true, signed: true)
        : widget.lines > 1
        ? TextInputType.multiline
        : TextInputType.text,
    decoration: InputDecoration(
      labelText: widget.label,
      errorText: widget.error,
      helperText: widget.helper,
      helperMaxLines: 3,
      errorMaxLines: 3,
    ),
    onChanged: widget.onChanged,
  );
}

class ChoiceField extends StatelessWidget {
  const ChoiceField({
    super.key,
    required this.label,
    required this.value,
    required this.enabled,
    required this.values,
    this.labels = const {},
    this.error,
    this.helper,
    required this.onChanged,
  });
  final String label, value;
  final bool enabled;
  final List<String> values;
  final Map<String, String> labels;
  final String? error, helper;
  final ValueChanged<String> onChanged;
  @override
  Widget build(BuildContext context) => DropdownButtonFormField<String>(
    initialValue: values.contains(value) ? value : null,
    decoration: InputDecoration(
      labelText: label,
      errorText: error,
      errorMaxLines: 3,
      helperText: helper,
      helperMaxLines: 3,
    ),
    isExpanded: true,
    items: values
        .map(
          (item) => DropdownMenuItem(
            value: item,
            child: Text(item.isEmpty ? '' : labels[item] ?? item),
          ),
        )
        .toList(),
    onChanged: enabled ? (item) => onChanged(item ?? '') : null,
  );
}

class DateEntry extends StatefulWidget {
  const DateEntry({
    super.key,
    required this.label,
    required this.value,
    required this.enabled,
    required this.onChanged,
    this.yearStart = 2000,
    this.error,
  });
  final String label, value;
  final bool enabled;
  final int yearStart;
  final String? error;
  final ValueChanged<String> onChanged;
  @override
  State<DateEntry> createState() => _DateEntryState();
}

class _DateEntryState extends State<DateEntry> {
  late final TextEditingController controller;
  String? error;
  @override
  void initState() {
    super.initState();
    controller = TextEditingController(text: displayEntryDate(widget.value));
  }

  @override
  void didUpdateWidget(covariant DateEntry oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.value != widget.value &&
        controller.text != displayEntryDate(widget.value)) {
      controller.text = displayEntryDate(widget.value);
    }
  }

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextFormField(
    controller: controller,
    enabled: widget.enabled,
    keyboardType: TextInputType.number,
    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
    decoration: InputDecoration(
      labelText: '${widget.label} (DDMMYY)',
      hintText: '171026',
      errorText: error ?? widget.error,
      helperText:
          'Six digits: day, month, year. Earlier dates can be typed directly.',
    ),
    onChanged: (value) {
      final iso = parseEntryDate(value, yearStart: widget.yearStart);
      setState(
        () => error = iso == null
            ? 'Enter a valid six-digit date (DDMMYY).'
            : iso.compareTo(dayString(DateTime.now())) > 0
            ? 'Date cannot be in the future.'
            : null,
      );
      // Persist incomplete input too, so validation cannot silently send an old date.
      widget.onChanged(iso ?? value);
    },
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
        error: widget.error,
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
