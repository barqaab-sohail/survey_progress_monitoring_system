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
  bool busy = false, syncing = false, foreground = true;
  String syncMessage = '', startupMessage = '';
  bool needsLogin = false;
  bool capturing = false;
  Timer? retryTimer;

  List<Json> get teams => ((reference?['teams'] ?? []) as List).cast<Json>();
  List<Json> get feeders =>
      ((reference?['feeders'] ?? []) as List).cast<Json>();
  List<Json> get transformers =>
      ((reference?['transformers'] ?? []) as List).cast<Json>();
  List<Json> feedersFor(int teamId) => feeders
      .where((item) => (item['team_ids'] as List).contains(teamId))
      .toList();
  Json? feeder(int id) => feeders.where((item) => item['id'] == id).firstOrNull;
  Json? team(int id) => teams.where((item) => item['id'] == id).firstOrNull;

  Future<void> initialize() async {
    await store.recoverInterruptedUploads();
    try {
      final saved = await secureStorage.read(key: 'field_session');
      if (saved != null) {
        session = FieldSession.fromJson(jsonDecode(saved) as Json);
        reference = await store.bootstrap(session!.scope);
        if (reference == null) {
          try {
            await refreshReference();
          } catch (_) {
            session = null;
          }
        }
        await reload();
      }
    } catch (_) {
      startupMessage =
          'Unable to restore the sign-in session. Sign in again; saved surveys remain on this phone.';
    }
    await recoverPhoto();
    retryTimer = Timer.periodic(const Duration(seconds: 45), (_) {
      if (foreground && session != null && !needsLogin && !syncing) {
        unawaited(sync(manual: false));
      }
    });
  }

  Future<void> login(String url, String email, String password) async {
    busy = true;
    notifyListeners();
    try {
      final next = await api.login(url, email, password);
      Json? downloaded;
      try {
        downloaded = await api.bootstrap(next);
        await store.cacheBootstrap(next.scope, downloaded);
      } catch (_) {
        downloaded = await store.bootstrap(next.scope);
        if (downloaded == null) rethrow;
      }
      await secureStorage.write(
        key: 'field_session',
        value: jsonEncode(next.toJson()),
      );
      await store.setSetting('last_url', url);
      session = next;
      reference = downloaded;
      needsLogin = false;
      syncMessage = '';
      await reload();
    } finally {
      busy = false;
      notifyListeners();
    }
    unawaited(sync(manual: false));
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
    if (session?.scope != current.scope || session?.token != current.token) {
      return;
    }
    records = fetched;
    notifyListeners();
  }

  Future<void> refreshReference() async {
    final current = session;
    if (current == null) return;
    final downloaded = await api.bootstrap(current);
    await store.cacheBootstrap(current.scope, downloaded);
    final cached = await store.bootstrap(current.scope);
    if (session?.scope != current.scope || session?.token != current.token) {
      return;
    }
    reference = cached;
    notifyListeners();
  }

  Future<void> sync({bool manual = true}) async {
    final current = session;
    if (syncing || busy || current == null || needsLogin && !manual) return;
    final candidates = await store.surveys(current.scope);
    final forms = candidates
        .where((r) => r.outbound != null && r.state != 'conflict')
        .toList();
    if (!manual && forms.isEmpty) {
      var pendingPhotos = false;
      for (final record in candidates.where(
        (r) => r.revision > 0 && r.state != 'conflict',
      )) {
        pendingPhotos = (await store.attachments(current.scope, record.id)).any(
          (photo) => photo['state'] == 'pending' || photo['state'] == 'error',
        );
        if (pendingPhotos) break;
      }
      if (!pendingPhotos) return;
    }
    // No await between the final guard and lock; simultaneous resume/manual
    // requests cannot send the same form concurrently.
    if (syncing ||
        busy ||
        session?.scope != current.scope ||
        session?.token != current.token) {
      return;
    }
    syncing = true;
    notifyListeners();
    var formCount = 0, photoCount = 0, failures = 0;
    var networkStopped = false;
    try {
      for (final record in forms) {
        try {
          final reply = await api.sync(current, record.outbound!);
          await store.acknowledge(record, reply['revision'] as int);
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
          if (error.status == 401) {
            needsLogin = true;
            networkStopped = true;
            break;
          }
        } on Object {
          await store.reject(
            record,
            'Connection interrupted. This survey remains queued on the phone. Retry Sync when connected.',
          );
          failures++;
          networkStopped = true;
          break;
        }
      }
      if (!networkStopped) {
        for (final record in await store.surveys(current.scope)) {
          if (record.revision == 0 ||
              record.state == 'conflict' ||
              record.outbound != null) {
            continue;
          }
          for (final photo in await store.attachments(
            current.scope,
            record.id,
          )) {
            if (!['pending', 'error'].contains(photo['state'])) continue;
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
              if (error.status == 401) {
                needsLogin = true;
                networkStopped = true;
                break;
              }
            } on Object {
              await store.attachmentState(
                photo['uuid'],
                'error',
                'Connection interrupted. Photo preserved on the phone.',
              );
              failures++;
              networkStopped = true;
              break;
            }
          }
          if (networkStopped) break;
        }
      }
      syncMessage = needsLogin
          ? 'Sign in again to sync. Saved surveys and photos remain on this phone.'
          : networkStopped
          ? 'Connection unavailable. Pending work is saved on this phone.'
          : failures > 0
          ? '$formCount survey${formCount == 1 ? '' : 's'} and $photoCount photo${photoCount == 1 ? '' : 's'} synced. $failures ${failures == 1 ? 'item needs' : 'items need'} attention.'
          : formCount + photoCount > 0
          ? '$formCount survey${formCount == 1 ? '' : 's'} and $photoCount photo${photoCount == 1 ? '' : 's'} synced.'
          : 'No pending uploads. Local drafts are uploaded after you submit them.';
    } finally {
      syncing = false;
      await reload();
    }
  }

  void resumed(bool value) {
    foreground = value;
    if (value && session != null) unawaited(sync(manual: false));
  }

  Future<void> pickPhoto(
    SurveyRecord record,
    ImageSource source,
    String kind,
  ) async {
    if (capturing) throw StateError('A photo capture is already open.');
    capturing = true;
    notifyListeners();
    final capture = {
      'uuid': const Uuid().v4(),
      'scope': record.scope,
      'survey_uuid': record.id,
      'kind': kind,
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
