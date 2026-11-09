import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';
import 'api_client.dart';
import 'local_store.dart';
import 'models.dart';

class FieldController extends ChangeNotifier {
  FieldController(
    this.store, {
    FieldApi? api,
    FlutterSecureStorage? secureStorage,
  }) : api = api ?? FieldApi(),
       secureStorage = secureStorage ?? const FlutterSecureStorage();
  final LocalStore store;
  final FieldApi api;
  final FlutterSecureStorage secureStorage;
  FieldSession? session;
  Json? reference;
  List<SurveyRecord> records = [];
  List<Json> htRecords = [];
  bool busy = false, syncing = false, foreground = true;
  bool autoSync = true;
  String syncMessage = '', startupMessage = '';
  bool needsLogin = false;
  bool capturing = false;
  Timer? retryTimer;

  List<Json> get teams => ((reference?['teams'] ?? []) as List).cast<Json>();
  List<Json> get feeders =>
      ((reference?['feeders'] ?? []) as List).cast<Json>();
  List<Json> get transformers =>
      ((reference?['transformers'] ?? []) as List).cast<Json>();
  bool get canUpdateHt =>
      (reference?['capabilities'] as Map?)?['ht_network_updates'] == true;
  bool get canUseVoiceInput =>
      session != null &&
      !needsLogin &&
      (reference?['capabilities'] as Map?)?['voice_form_filling'] == true;

  Future<void> authorizeVoiceInput() async {
    final current = session;
    if (current == null || !canUseVoiceInput) {
      throw const ApiFailure(
        403,
        'Voice form-filling is not enabled for your account.',
      );
    }
    try {
      await api.authorizeVoiceInput(current);
    } on ApiFailure catch (error) {
      if (session?.token == current.token && session?.scope == current.scope) {
        if (error.status == 403 && reference != null) {
          reference!['capabilities'] = {
            ...?reference!['capabilities'] as Map?,
            'voice_form_filling': false,
          };
          await store.cacheBootstrap(current.scope, reference!);
        }
        if (error.status == 401) needsLogin = true;
        notifyListeners();
      }
      rethrow;
    }
    if (session?.token != current.token ||
        session?.scope != current.scope ||
        !canUseVoiceInput) {
      throw const ApiFailure(
        403,
        'Voice access changed. Sign in and refresh your permissions.',
      );
    }
  }

  Future<Map<String, String>?> rememberedCredentials() async {
    final email = await secureStorage.read(key: 'remembered_field_email');
    final password = await secureStorage.read(key: 'remembered_field_password');
    if (email == null ||
        email.isEmpty ||
        password == null ||
        password.isEmpty) {
      return null;
    }
    return {'email': email, 'password': password};
  }

  List<Json> feedersFor(dynamic teamValue) {
    final teamId = teamValue is int ? teamValue : int.tryParse('$teamValue');
    if (teamId == null) return [];
    return feeders
        .where(
          (item) => (item['team_ids'] as List? ?? const []).any(
            (id) => id == teamId || int.tryParse('$id') == teamId,
          ),
        )
        .toList();
  }

  Json? feeder(dynamic id) => feeders
      .where((item) => item['id'] == id || '${item['id']}' == '$id')
      .firstOrNull;
  Json? team(dynamic id) => teams
      .where((item) => item['id'] == id || '${item['id']}' == '$id')
      .firstOrNull;

  Future<List<Json>> availableTransformers(SurveyRecord record) async {
    final saved = await store.surveys(record.scope);
    return transformers
        .where(
          (t) =>
              t['feeder_id'] == record.data['feeder_id'] &&
              !saved.any(
                (survey) =>
                    survey.id != record.id &&
                    sameTransformer(survey.data, {
                      'feeder_id': t['feeder_id'],
                      'transformer_id': t['id'],
                      'transformer_code': t['transformer_code'],
                    }),
              ),
        )
        .toList();
  }

  Future<void> initialize() async {
    await store.recoverInterruptedUploads();
    try {
      autoSync = (await store.setting('auto_sync')) != 'false';
      final saved = await secureStorage.read(key: 'field_session');
      if (saved != null) {
        session = FieldSession.fromJson(jsonDecode(saved) as Json);
        // Other-server notebooks remain stored, but are never sent to production.
        if (session!.url != applicationBaseUrl) {
          session = null;
          startupMessage =
              'Sign in to the HAZECO production application. Previously saved surveys remain on this phone.';
        }
      }
      if (session != null) {
        final cachedReference = await store.bootstrap(session!.scope);
        reference = cachedReference == null
            ? null
            : normalizeFieldBootstrap(cachedReference);
        try {
          // Refresh cached reference data at startup so account team changes
          // (including the group code shown in the notebook header) take effect.
          await refreshReference();
        } catch (_) {
          // Keep the cached reference for offline field work. A missing cache
          // still means there is no usable session bootstrap.
          if (reference == null) session = null;
        }
        await reload();
      }
    } catch (_) {
      startupMessage =
          'Unable to restore the sign-in session. Sign in again; saved surveys remain on this phone.';
    }
    await recoverPhoto();
    retryTimer = Timer.periodic(const Duration(seconds: 45), (_) {
      if (autoSync &&
          foreground &&
          session != null &&
          !needsLogin &&
          !syncing) {
        unawaited(sync(manual: false));
      }
    });
  }

  Future<void> login(
    String email,
    String password, {
    bool rememberCredentials = false,
  }) async {
    busy = true;
    notifyListeners();
    try {
      final next = await api.login(applicationBaseUrl, email, password);
      Json? downloaded;
      try {
        downloaded = await api.bootstrap(next);
        await store.cacheBootstrap(next.scope, downloaded);
      } catch (_) {
        downloaded = await store.bootstrap(next.scope);
        if (downloaded != null) normalizeFieldBootstrap(downloaded);
        if (downloaded == null) rethrow;
      }
      await secureStorage.write(
        key: 'field_session',
        value: jsonEncode(next.toJson()),
      );
      if (rememberCredentials) {
        await secureStorage.write(
          key: 'remembered_field_email',
          value: email.trim(),
        );
        await secureStorage.write(
          key: 'remembered_field_password',
          value: password,
        );
      } else {
        await secureStorage.delete(key: 'remembered_field_email');
        await secureStorage.delete(key: 'remembered_field_password');
      }
      await store.setSetting('last_url', applicationBaseUrl);
      session = next;
      reference = downloaded;
      needsLogin = false;
      syncMessage = '';
      await reload();
    } finally {
      busy = false;
      notifyListeners();
    }
    if (autoSync) unawaited(sync(manual: false));
  }

  Future<void> logout() async {
    if (syncing) {
      throw StateError(
        'Wait for the current sync to finish before signing out.',
      );
    }
    busy = true;
    notifyListeners();
    final current = session;
    try {
      if (current != null) {
        try {
          await api.logout(current);
        } catch (_) {
          /* Local sign-out also works offline. */
        }
      }
      await secureStorage.delete(key: 'field_session');
      session = null;
      reference = null;
      records = [];
      htRecords = [];
      syncMessage = '';
      needsLogin = false;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> reload() async {
    final current = session;
    if (current == null) return;
    final fetched = await store.surveys(current.scope);
    final fetchedHt = await store.htUpdates(current.scope);
    if (session?.scope != current.scope || session?.token != current.token) {
      return;
    }
    records = fetched;
    htRecords = fetchedHt;
    notifyListeners();
  }

  Future<void> deleteUnsentSurvey(SurveyRecord record) async {
    if (syncing || busy) {
      throw StateError('Wait for sync to finish before changing this survey.');
    }
    busy = true;
    notifyListeners();
    try {
      final paths = await store.deleteUnsentSurvey(record);
      for (final path in paths) {
        final file = File(path);
        if (await file.exists()) await file.delete();
      }
    } finally {
      busy = false;
      await reload();
    }
  }

  Future<void> editWaitingSurvey(SurveyRecord record) async {
    if (syncing || busy) {
      throw StateError('Wait for sync to finish before changing this survey.');
    }
    busy = true;
    notifyListeners();
    try {
      await store.reopenWaitingSurvey(record);
    } finally {
      busy = false;
      await reload();
    }
  }

  Future<void> refreshReference() async {
    final current = session;
    if (current == null) return;
    final downloaded = await api.bootstrap(current);
    await store.mergeHtReference(
      current.scope,
      ((downloaded['ht_updates'] ?? []) as List).cast<Json>(),
    );
    await store.cacheBootstrap(current.scope, downloaded);
    final cached = await store.bootstrap(current.scope);
    if (session?.scope != current.scope || session?.token != current.token) {
      return;
    }
    reference = cached == null ? null : normalizeFieldBootstrap(cached);
    notifyListeners();
  }

  Future<void> setAutoSync(bool value) async {
    autoSync = value;
    await store.setSetting('auto_sync', value ? 'true' : 'false');
    notifyListeners();
    if (value && foreground && session != null && !needsLogin) {
      unawaited(sync(manual: false));
    }
  }

  Future<void> sync({bool manual = true}) async {
    final current = session;
    if (syncing ||
        busy ||
        current == null ||
        (!manual && (!autoSync || needsLogin))) {
      return;
    }
    syncing = true;
    syncMessage = 'Sync in progress…';
    notifyListeners();
    var formCount = 0, photoCount = 0, failures = 0;
    var networkStopped = false;
    final failureReasons = <String>[];
    try {
      final localHt = canUpdateHt
          ? await store.htUpdates(current.scope)
          : <Json>[];
      if (canUpdateHt &&
          localHt.any((h) => ['pending', 'returned'].contains(h['state']))) {
        try {
          final status = await api.htStatuses(current);
          await store.mergeHtReference(
            current.scope,
            ((status['ht_updates'] ?? []) as List).cast<Json>(),
          );
        } on ApiFailure catch (e) {
          if (e.status == 401) {
            needsLogin = true;
            syncMessage =
                'Sign in again to update HT verification status. Saved HT and LT work is retained.';
            return;
          }
        } on Object {
          /* Cached HT status remains usable offline. */
        }
      }
      for (final ht
          in canUpdateHt ? await store.htUpdates(current.scope) : <Json>[]) {
        if (session?.scope != current.scope ||
            session?.token != current.token) {
          return;
        }
        if (ht['outbound'] == null || ht['state'] == 'conflict') continue;
        try {
          final reply = await api.syncHt(current, ht['outbound'] as Json);
          await store.acknowledgeHt(current.scope, ht, reply);
          formCount++;
        } on ApiFailure catch (error) {
          await store.rejectHt(
            current.scope,
            ht,
            error.message,
            correctable: error.status == 422,
            conflict: error.status == 409,
          );
          failures++;
          failureReasons.add('HT Network Update: ${error.message}');
          if (error.status == 401) {
            needsLogin = true;
            syncMessage =
                'Sign in again to synchronize HT and LT. Saved work remains on this phone.';
            return;
          }
        } on Object catch (error) {
          await store.rejectHt(current.scope, ht, _syncFailureReason(error));
          syncMessage =
              'HT sync could not finish. Saved HT and LT work remains on this phone. ${_syncFailureReason(error)}';
          return;
        }
      }
      final candidates = await store.surveys(current.scope);
      if (session?.scope != current.scope || session?.token != current.token) {
        syncMessage = 'Sync cancelled because the signed-in account changed.';
        return;
      }
      final forms = candidates
          .where((r) => r.outbound != null && r.state != 'conflict')
          .toList();
      if (forms.isEmpty) {
        var pendingPhotos = false;
        for (final record in candidates.where(
          (r) => r.revision > 0 && r.state != 'conflict',
        )) {
          pendingPhotos = (await store.attachments(
            current.scope,
            record.id,
          )).any((photo) => ['pending', 'error'].contains(photo['state']));
          if (pendingPhotos) break;
        }
        if (!pendingPhotos && formCount == 0 && failures == 0) {
          if (manual) {
            syncMessage =
                'Sync completed successfully. No pending uploads. Local drafts upload after you submit them.';
          } else {
            syncMessage = '';
          }
          return;
        }
      }
      for (final record in forms) {
        final htUuid = record.outbound?['ht_update_uuid'];
        if (htUuid != null) {
          final ht = (await store.htUpdates(
            current.scope,
          )).where((h) => h['data']['client_uuid'] == htUuid).firstOrNull;
          if (ht == null ||
              ht['outbound'] != null ||
              ['draft', 'conflict'].contains(ht['state'])) {
            failures++;
            failureReasons.add(
              'LT Transformer Survey waits for its HT Network Update to synchronize.',
            );
            continue;
          }
        }
        try {
          final reply = await api.sync(current, record.outbound!);
          final revision = parseIntegerId(reply['revision']);
          if (revision == null) {
            throw const FormatException(
              'The server did not return a valid survey revision. The saved copy remains on this phone.',
            );
          }
          await store.acknowledge(record, revision);
          formCount++;
        } on ApiFailure catch (error) {
          final conflict = error.status == 409;
          final message = conflict
              ? 'The server has a different version. Your survey is preserved on this phone. Ask the project administrator to reconcile it. ${error.message}'
              : error.message;
          await store.reject(
            record,
            message,
            conflict: conflict,
            correctable: error.status == 422,
          );
          failures++;
          failureReasons.add(error.message);
          if (error.status == 401) {
            needsLogin = true;
            networkStopped = true;
            break;
          }
        } on Object catch (error) {
          final reason = _syncFailureReason(error);
          await store.reject(
            record,
            '$reason This survey remains saved on the phone and can be retried.',
          );
          failures++;
          failureReasons.add(reason);
          networkStopped = true;
          break;
        }
      }
      if (!networkStopped) {
        for (final record in await store.surveys(current.scope)) {
          if (record.revision == 0 ||
              record.state != 'synced' ||
              record.state == 'conflict' ||
              record.outbound != null) {
            continue;
          }
          for (final photo in await store.attachments(
            current.scope,
            record.id,
          )) {
            if (!['pending', 'error'].contains(photo['state'])) continue;
            if (photo['row_id'] == null) {
              await store.attachmentState(
                photo['uuid'],
                'error',
                'Link this existing photo to an S/E row before uploading.',
              );
              failures++;
              continue;
            }
            if (!await store.claimAttachment(current.scope, photo['uuid'])) {
              continue;
            }
            try {
              await api.upload(current, photo);
              await store.attachmentState(photo['uuid'], 'synced', '');
              photoCount++;
            } on ApiFailure catch (error) {
              await store.attachmentState(
                photo['uuid'],
                error.status == 409 ? 'conflict' : 'error',
                error.message,
              );
              failures++;
              failureReasons.add(error.message);
              if (error.status == 401) {
                needsLogin = true;
                networkStopped = true;
                break;
              }
            } on Object catch (error) {
              final reason = _syncFailureReason(error);
              await store.attachmentState(
                photo['uuid'],
                'error',
                '$reason Photo preserved on the phone and can be retried.',
              );
              failures++;
              failureReasons.add(reason);
              networkStopped = true;
              break;
            }
          }
          if (networkStopped) break;
        }
      }
      if (failures > 0) {
        final reasons = failureReasons
            .map(
              (reason) => reason.replaceFirst(
                'No internet connection or server is unreachable: ',
                '',
              ),
            )
            .toSet()
            .take(3)
            .join(' | ');
        final noInternet = failureReasons.any(
          (reason) => reason.startsWith('No internet connection'),
        );
        syncMessage = noInternet
            ? 'No internet connection. Sync failed: $reasons Pending surveys and photos remain saved on this phone.'
            : needsLogin
            ? 'Sync failed: sign-in is required. $reasons Saved surveys and photos remain on this phone.'
            : 'Sync failed: $reasons Pending surveys and photos remain saved on this phone.';
      } else if (formCount + photoCount > 0) {
        syncMessage =
            'Sync completed successfully. $formCount survey${formCount == 1 ? '' : 's'} and $photoCount photo${photoCount == 1 ? '' : 's'} uploaded.';
      } else {
        syncMessage =
            'Sync completed successfully. No pending uploads. Local drafts upload after you submit them.';
      }
    } catch (error) {
      syncMessage =
          'Sync failed: ${_syncFailureReason(error)} Pending surveys and photos remain saved on this phone.';
    } finally {
      try {
        await reload();
      } catch (error) {
        if (failureReasons.isEmpty) {
          syncMessage =
              'Sync failed while refreshing saved surveys: ${_syncFailureReason(error)}';
        }
      } finally {
        syncing = false;
        notifyListeners();
      }
    }
  }

  String _syncFailureReason(Object error) {
    if (error is SocketException) {
      return 'No internet connection or server is unreachable: ${error.message}.';
    }
    if (error is TimeoutException) {
      return 'The server request timed out: ${error.message ?? 'no response received'}.';
    }
    final detail = error.toString().replaceFirst('Exception: ', '');
    final normalized = detail.toLowerCase();
    if (normalized.contains('failed host lookup') ||
        normalized.contains('network is unreachable') ||
        normalized.contains('no address associated') ||
        normalized.contains('connection refused')) {
      return 'No internet connection or server is unreachable: $detail.';
    }
    return 'Request failed: $detail.';
  }

  void resumed(bool value) {
    foreground = value;
    if (autoSync && value && session != null && !needsLogin) {
      unawaited(sync(manual: false));
    }
  }

  Future<void> pickPhoto(
    SurveyRecord record,
    ImageSource source,
    String kind, {
    String? rowId,
  }) async {
    if (capturing) throw StateError('A photo capture is already open.');
    if (rowId == null || !record.rows.any((row) => row['row_id'] == rowId)) {
      throw const FormatException('Open an S/E row before adding a photo.');
    }
    capturing = true;
    notifyListeners();
    final capture = {
      'uuid': const Uuid().v4(),
      'scope': record.scope,
      'survey_uuid': record.id,
      'kind': kind,
      'row_id': rowId,
    };
    try {
      await store.setSetting('pending_capture', jsonEncode(capture));
      final photo = await ImagePicker().pickImage(
        source: source,
        imageQuality: 85,
        maxWidth: 2400,
        requestFullMetadata: false,
      );
      if (photo != null) await retainPhoto(capture, photo);
    } finally {
      try {
        await store.setSetting('pending_capture', null);
      } finally {
        capturing = false;
        notifyListeners();
      }
    }
  }

  Future<void> retainPhoto(Json capture, XFile photo) async {
    if (await photo.length() > 10 * 1024 * 1024) {
      throw const FormatException(
        'The photo exceeds 10 MB. Select a smaller JPEG or PNG.',
      );
    }
    // Verify file magic rather than assigning a JPEG extension to HEIC bytes.
    final bytes = await File(photo.path).open();
    late List<int> prefix;
    try {
      prefix = await bytes.read(12);
    } finally {
      await bytes.close();
    }
    final jpeg =
        prefix.length >= 3 &&
        prefix[0] == 0xff &&
        prefix[1] == 0xd8 &&
        prefix[2] == 0xff;
    final png =
        prefix.length >= 8 &&
        prefix.take(8).join(',') == '137,80,78,71,13,10,26,10';
    if (!jpeg && !png) {
      throw const FormatException(
        'Choose a JPEG or PNG image. This image format is not supported.',
      );
    }
    final directory = Directory(
      p.join((await getApplicationSupportDirectory()).path, 'survey_photos'),
    );
    await directory.create(recursive: true);
    final path = p.join(
      directory.path,
      '${capture['uuid']}.${png ? 'png' : 'jpg'}',
    );
    await File(photo.path).copy(path);
    await store.addAttachment({
      ...capture,
      'path': path,
      'row_id': capture['row_id'],
      'state': 'pending',
      'error': '',
    });
  }

  Future<void> recoverPhoto() async {
    try {
      final saved = await store.setting('pending_capture');
      final lost = await ImagePicker().retrieveLostData();
      if (lost.isEmpty) {
        await store.setSetting('pending_capture', null);
        return;
      }
      if (saved != null && lost.files != null) {
        final capture = jsonDecode(saved) as Json;
        for (var i = 0; i < lost.files!.length; i++) {
          await retainPhoto({
            ...capture,
            'uuid': i == 0 ? capture['uuid'] : const Uuid().v4(),
          }, lost.files![i]);
        }
        startupMessage = 'Recovered a photo after Android restarted the app.';
      } else if (lost.exception != null) {
        startupMessage =
            'Photo capture was interrupted. Open the survey and take the photo again.';
      }
      await store.setSetting('pending_capture', null);
    } catch (_) {
      startupMessage =
          'Unable to recover the interrupted photo. Open the survey and select it again.';
    }
  }

  @override
  void dispose() {
    retryTimer?.cancel();
    api.client.close();
    super.dispose();
  }
}
