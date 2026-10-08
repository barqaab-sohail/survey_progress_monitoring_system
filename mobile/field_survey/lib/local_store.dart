import 'dart:convert';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';
import 'models.dart';

class LocalStore {
  LocalStore(this.database);
  final Database database;
  static Future<LocalStore> open({
    DatabaseFactory? factory,
    String? path,
  }) async {
    final database = await (factory ?? databaseFactory).openDatabase(
      path ?? p.join(await getDatabasesPath(), 'field_surveys.db'),
      options: OpenDatabaseOptions(
        version: 1,
        onCreate: (db, _) async {
          await db.execute(
            'CREATE TABLE surveys (scope TEXT NOT NULL, uuid TEXT NOT NULL, data TEXT NOT NULL, state TEXT NOT NULL, error TEXT NOT NULL DEFAULT "", updated_at TEXT NOT NULL, outbound TEXT, PRIMARY KEY(scope,uuid))',
          );
          await db.execute(
            'CREATE TABLE bootstrap (scope TEXT PRIMARY KEY, data TEXT NOT NULL, downloaded_at TEXT NOT NULL)',
          );
          await db.execute(
            'CREATE TABLE attachments (uuid TEXT PRIMARY KEY, scope TEXT NOT NULL, survey_uuid TEXT NOT NULL, kind TEXT NOT NULL, path TEXT NOT NULL, state TEXT NOT NULL, error TEXT NOT NULL DEFAULT "")',
          );
          await db.execute(
            'CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
          );
        },
      ),
    );
    return LocalStore(database);
  }

  Future<Json?> bootstrap(String scope) async {
    final rows = await database.query(
      'bootstrap',
      where: 'scope = ?',
      whereArgs: [scope],
    );
    if (rows.isEmpty) return null;
    final data = jsonDecode(rows.first['data'] as String) as Json;
    data['_downloaded_at'] = rows.first['downloaded_at'];
    return data;
  }

  Future<void> cacheBootstrap(String scope, Json data) async {
    await database.insert('bootstrap', {
      'scope': scope,
      'data': jsonEncode(data),
      'downloaded_at': DateTime.now().toIso8601String(),
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  SurveyRecord decode(Json row) => SurveyRecord(
    scope: row['scope'],
    data: jsonDecode(row['data']) as Json,
    state: row['state'],
    error: row['error'],
    updatedAt: row['updated_at'],
    outbound: row['outbound'] == null
        ? null
        : jsonDecode(row['outbound']) as Json,
  );
  Future<List<SurveyRecord>> surveys(String scope) async =>
      (await database.query(
        'surveys',
        where: 'scope = ?',
        whereArgs: [scope],
        orderBy: 'updated_at DESC',
      )).map((row) => decode(row)).toList();
  Future<SurveyRecord?> survey(String scope, String id) async {
    final rows = await database.query(
      'surveys',
      where: 'scope = ? AND uuid = ?',
      whereArgs: [scope, id],
    );
    return rows.isEmpty ? null : decode(rows.first);
  }

  Future<void> put(DatabaseExecutor executor, SurveyRecord record) async {
    record.updatedAt = DateTime.now().toIso8601String();
    await executor.insert('surveys', {
      'scope': record.scope,
      'uuid': record.id,
      'data': jsonEncode(record.data),
      'state': record.state,
      'error': record.error,
      'updated_at': record.updatedAt,
      'outbound': record.outbound == null ? null : jsonEncode(record.outbound),
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<void> ensureEditable(
    Transaction transaction,
    SurveyRecord record,
  ) async {
    final rows = await transaction.query(
      'surveys',
      where: 'scope = ? AND uuid = ?',
      whereArgs: [record.scope, record.id],
    );
    if (rows.isEmpty) return;
    final stored = decode(rows.first);
    if (stored.outbound != null ||
        stored.state == 'conflict' ||
        stored.revision != record.revision) {
      throw StateError(
        'This survey changed during sync. Reopen it before editing; the saved version is preserved.',
      );
    }
  }

  Future<void> save(SurveyRecord record) async {
    await database.transaction((transaction) async {
      await ensureEditable(transaction, record);
      await put(transaction, record);
    });
  }

  Future<void> queue(SurveyRecord record) async {
    final queued = record.clone();
    queued.data['status'] = 'submitted';
    queued.outbound = queued.payload(submitted: true);
    queued.state = 'queued';
    queued.error = '';
    await database.transaction((transaction) async {
      await ensureEditable(transaction, record);
      await put(transaction, queued);
    });
    record.data = queued.data;
    record.outbound = queued.outbound;
    record.state = queued.state;
    record.error = queued.error;
  }

  Future<void> acknowledge(SurveyRecord record, int revision) async {
    // Outbound forms are immutable until acknowledgement, so a retry sends
    // identical UUID, base revision and content even after process death.
    await database.transaction((transaction) async {
      final rows = await transaction.query(
        'surveys',
        where: 'scope = ? AND uuid = ?',
        whereArgs: [record.scope, record.id],
      );
      if (rows.isEmpty) throw StateError('The queued survey is missing.');
      final latest = decode(rows.first);
      if (jsonEncode(latest.outbound) != jsonEncode(record.outbound)) {
        throw StateError(
          'The queued copy changed. Its data has been preserved.',
        );
      }
      latest.data['base_revision'] = revision;
      latest.state = 'synced';
      latest.error = '';
      latest.outbound = null;
      await put(transaction, latest);
      record.data = latest.data;
      record.state = latest.state;
      record.error = latest.error;
      record.outbound = null;
    });
  }

  Future<void> reject(
    SurveyRecord record,
    String message, {
    bool conflict = false,
    bool correctable = false,
  }) async {
    await database.transaction((transaction) async {
      final rows = await transaction.query(
        'surveys',
        where: 'scope = ? AND uuid = ?',
        whereArgs: [record.scope, record.id],
      );
      if (rows.isEmpty) return;
      final latest = decode(rows.first);
      if (jsonEncode(latest.outbound) != jsonEncode(record.outbound)) return;
      latest.error = message;
      latest.state = conflict ? 'conflict' : 'error';
      if (correctable) latest.outbound = null;
      await put(transaction, latest);
      record.error = latest.error;
      record.state = latest.state;
      record.outbound = latest.outbound;
    });
  }

  Future<void> deleteDraft(SurveyRecord record) async {
    if (record.revision != 0 || record.outbound != null) {
      throw StateError('Only an unsubmitted local draft can be deleted.');
    }
    await database.delete(
      'surveys',
      where: 'scope = ? AND uuid = ?',
      whereArgs: [record.scope, record.id],
    );
    await database.delete(
      'attachments',
      where: 'scope = ? AND survey_uuid = ?',
      whereArgs: [record.scope, record.id],
    );
  }

  Future<List<Json>> attachments(String scope, String surveyId) async =>
      (await database.query(
        'attachments',
        where: 'scope = ? AND survey_uuid = ?',
        whereArgs: [scope, surveyId],
      )).map((row) => Map<String, dynamic>.from(row)).toList();
  Future<void> addAttachment(Json data) async {
    await database.insert(
      'attachments',
      data,
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  Future<void> attachmentState(String id, String state, String error) async {
    await database.update(
      'attachments',
      {'state': state, 'error': error},
      where: 'uuid = ?',
      whereArgs: [id],
    );
  }

  Future<bool> claimAttachment(String scope, String id) async =>
      await database.update(
        'attachments',
        {'state': 'uploading', 'error': ''},
        where: 'uuid = ? AND scope = ? AND state IN (?,?)',
        whereArgs: [id, scope, 'pending', 'error'],
      ) ==
      1;

  Future<void> recoverInterruptedUploads() async {
    await database.update(
      'attachments',
      {
        'state': 'error',
        'error': 'Upload interrupted. Photo preserved; retry Sync.',
      },
      where: 'state = ?',
      whereArgs: ['uploading'],
    );
  }

  Future<bool> deleteAttachment(String scope, String id) async {
    return await database.delete(
          'attachments',
          where: 'uuid = ? AND scope = ? AND state IN (?,?,?)',
          whereArgs: [id, scope, 'pending', 'error', 'conflict'],
        ) ==
        1;
  }

  Future<void> setSetting(String key, String? value) async {
    if (value == null) {
      await database.delete('settings', where: 'key = ?', whereArgs: [key]);
    } else {
      await database.insert('settings', {
        'key': key,
        'value': value,
      }, conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }

  Future<String?> setting(String key) async {
    final result = await database.query(
      'settings',
      where: 'key = ?',
      whereArgs: [key],
    );
    return result.isEmpty ? null : result.first['value'] as String;
  }
}
