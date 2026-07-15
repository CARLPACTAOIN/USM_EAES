import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

import '../domain/event_summary.dart';
import '../domain/offline_scan.dart';
import '../domain/roster_student.dart';
import 'scanner_contracts.dart';

class ScannerLocalDatabase implements ScannerLocalStore {
  ScannerLocalDatabase._();

  static final ScannerLocalDatabase instance = ScannerLocalDatabase._();

  Database? _database;

  Future<Database> get database async {
    final existing = _database;
    if (existing != null) {
      return existing;
    }

    final path = p.join(await getDatabasesPath(), 'eaes_scanner.db');
    final db = await openDatabase(
      path,
      version: 3,
      onCreate: _onCreate,
      onUpgrade: _onUpgrade,
    );

    _database = db;
    return db;
  }

  Future<void> _onCreate(Database database, int version) async {
    await database.execute('''
      CREATE TABLE roster_students (
        student_id TEXT PRIMARY KEY,
        qr_code_value TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        program_code TEXT,
        student_id_number TEXT,
        updated_at TEXT NOT NULL
      )
    ''');

    await database.execute('''
      CREATE TABLE offline_scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL,
        event_participation_scope_id TEXT,
        event_day_id TEXT,
        student_id TEXT,
        qr_code_value TEXT NOT NULL,
        scan_type TEXT NOT NULL,
        scanned_at TEXT NOT NULL,
        device_id TEXT NOT NULL,
        manual_entry INTEGER NOT NULL DEFAULT 0,
        dedup_key TEXT NOT NULL UNIQUE,
        resolution_status TEXT NOT NULL,
        synced_at TEXT,
        sync_status TEXT NOT NULL DEFAULT 'pending',
        failure_reason TEXT,
        retry_count INTEGER NOT NULL DEFAULT 0
      )
    ''');

    await database.execute('''
      CREATE TABLE event_summaries (
        event_id TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        total_synced INTEGER NOT NULL DEFAULT 0,
        last_synced_at TEXT,
        wiped_at TEXT
      )
    ''');

    await database.execute(
      'CREATE INDEX idx_offline_scans_event_sync '
      'ON offline_scans(event_id, sync_status)',
    );
    await database.execute(
      'CREATE INDEX idx_offline_scans_scope_sync '
      'ON offline_scans(event_id, event_participation_scope_id, sync_status)',
    );
    await database.execute(
      'CREATE INDEX idx_roster_students_qr '
      'ON roster_students(qr_code_value)',
    );
    await database.execute(
      'CREATE INDEX idx_roster_students_id_number '
      'ON roster_students(student_id_number)',
    );
  }

  Future<void> _onUpgrade(
    Database database,
    int oldVersion,
    int newVersion,
  ) async {
    if (oldVersion < 2) {
      await database.execute(
        'ALTER TABLE roster_students ADD COLUMN student_id_number TEXT',
      );
      await database.execute(
        "ALTER TABLE offline_scans ADD COLUMN sync_status TEXT NOT NULL DEFAULT 'pending'",
      );
      await database.execute(
        'ALTER TABLE offline_scans ADD COLUMN failure_reason TEXT',
      );
      await database.execute(
        'ALTER TABLE offline_scans ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0',
      );
      await database.execute('''
        CREATE TABLE IF NOT EXISTS event_summaries (
          event_id TEXT PRIMARY KEY,
          title TEXT NOT NULL,
          total_synced INTEGER NOT NULL DEFAULT 0,
          last_synced_at TEXT,
          wiped_at TEXT
        )
      ''');
      await database.execute(
        "UPDATE offline_scans SET sync_status = 'synced' WHERE synced_at IS NOT NULL",
      );
      await database.execute(
        "UPDATE offline_scans SET sync_status = 'pending' "
        "WHERE synced_at IS NULL AND (sync_status IS NULL OR sync_status = '')",
      );
      await database.execute(
        'CREATE INDEX IF NOT EXISTS idx_roster_students_id_number '
        'ON roster_students(student_id_number)',
      );
    }

    if (oldVersion < 3) {
      await database.execute(
        'ALTER TABLE offline_scans ADD COLUMN event_participation_scope_id TEXT',
      );
      await database.execute(
        'CREATE INDEX IF NOT EXISTS idx_offline_scans_scope_sync '
        'ON offline_scans(event_id, event_participation_scope_id, sync_status)',
      );
    }
  }

  @override
  Future<void> upsertRoster(List<RosterStudent> students) async {
    final db = await database;
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final student in students) {
        batch.insert(
          'roster_students',
          student.toDb(),
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  @override
  Future<void> replaceRoster(List<RosterStudent> students) async {
    final db = await database;
    await db.transaction((txn) async {
      await txn.delete('roster_students');
      final batch = txn.batch();
      for (final student in students) {
        batch.insert(
          'roster_students',
          student.toDb(),
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  @override
  Future<RosterStudent?> findStudentByQr(String qrCodeValue) async {
    final db = await database;
    final rows = await db.query(
      'roster_students',
      where: 'qr_code_value = ?',
      whereArgs: [qrCodeValue],
      limit: 1,
    );

    if (rows.isEmpty) {
      return null;
    }

    return RosterStudent.fromDb(rows.first);
  }

  @override
  Future<RosterStudent?> findStudentByIdNumber(String studentIdNumber) async {
    final db = await database;
    final rows = await db.query(
      'roster_students',
      where: 'student_id_number = ?',
      whereArgs: [studentIdNumber],
      limit: 1,
    );

    if (rows.isEmpty) {
      return null;
    }

    return RosterStudent.fromDb(rows.first);
  }

  @override
  Future<List<RosterStudent>> rosterStudents({
    String? query,
    int limit = 100,
    int offset = 0,
  }) async {
    final db = await database;
    final normalizedLimit = limit < 1 ? 1 : limit;
    final normalizedOffset = offset < 0 ? 0 : offset;
    final trimmedQuery = query?.trim();

    final rows = await db.query(
      'roster_students',
      where: trimmedQuery == null || trimmedQuery.isEmpty
          ? null
          : "(name LIKE ? ESCAPE '\\' OR program_code LIKE ? ESCAPE '\\' "
                "OR student_id_number LIKE ? ESCAPE '\\')",
      whereArgs: trimmedQuery == null || trimmedQuery.isEmpty
          ? null
          : List.filled(3, '%${_escapeLike(trimmedQuery)}%'),
      orderBy: 'name COLLATE NOCASE ASC',
      limit: normalizedLimit,
      offset: normalizedOffset,
    );

    return rows.map(RosterStudent.fromDb).toList();
  }

  @override
  Future<int> insertScan(OfflineScan scan) async {
    final db = await database;
    return db.insert(
      'offline_scans',
      scan.toDb(),
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  @override
  Future<List<OfflineScan>> unsyncedScans(
    String eventId, {
    int limit = 200,
  }) async {
    final db = await database;
    final rows = await db.query(
      'offline_scans',
      where: "event_id = ? AND sync_status IN ('pending', 'failed')",
      whereArgs: [eventId],
      orderBy: 'scanned_at ASC',
      limit: limit,
    );

    return rows.map(OfflineScan.fromDb).toList();
  }

  @override
  Future<List<OfflineScan>> failedScans(
    String eventId, {
    int limit = 200,
  }) async {
    final db = await database;
    final rows = await db.query(
      'offline_scans',
      where: "event_id = ? AND sync_status = 'failed'",
      whereArgs: [eventId],
      orderBy: 'scanned_at ASC',
      limit: limit,
    );

    return rows.map(OfflineScan.fromDb).toList();
  }

  @override
  Future<int> pendingCount(String eventId) async {
    final db = await database;
    final result = await db.rawQuery(
      "SELECT COUNT(*) AS count FROM offline_scans "
      "WHERE event_id = ? AND sync_status = 'pending'",
      [eventId],
    );
    return Sqflite.firstIntValue(result) ?? 0;
  }

  @override
  Future<int> syncedCount(String eventId) async {
    final db = await database;
    final result = await db.rawQuery(
      "SELECT COUNT(*) AS count FROM offline_scans "
      "WHERE event_id = ? AND sync_status = 'synced'",
      [eventId],
    );
    return Sqflite.firstIntValue(result) ?? 0;
  }

  @override
  Future<int> failedCount(String eventId) async {
    final db = await database;
    final result = await db.rawQuery(
      "SELECT COUNT(*) AS count FROM offline_scans "
      "WHERE event_id = ? AND sync_status = 'failed'",
      [eventId],
    );
    return Sqflite.firstIntValue(result) ?? 0;
  }

  @override
  Future<void> markSyncing(List<String> dedupKeys) async {
    if (dedupKeys.isEmpty) {
      return;
    }

    final db = await database;
    final placeholders = List.filled(dedupKeys.length, '?').join(',');
    await db.rawUpdate(
      "UPDATE offline_scans SET sync_status = 'syncing', failure_reason = NULL "
      'WHERE dedup_key IN ($placeholders)',
      dedupKeys,
    );
  }

  @override
  Future<void> markSynced(List<String> dedupKeys) async {
    if (dedupKeys.isEmpty) {
      return;
    }

    final db = await database;
    final now = DateTime.now().toUtc().toIso8601String();
    final placeholders = List.filled(dedupKeys.length, '?').join(',');
    await db.rawUpdate(
      "UPDATE offline_scans SET synced_at = ?, sync_status = 'synced', "
      'failure_reason = NULL '
      'WHERE dedup_key IN ($placeholders)',
      [now, ...dedupKeys],
    );
  }

  @override
  Future<void> markFailed(
    List<String> dedupKeys, {
    required String failureReason,
  }) async {
    if (dedupKeys.isEmpty) {
      return;
    }

    final db = await database;
    final placeholders = List.filled(dedupKeys.length, '?').join(',');
    await db.rawUpdate(
      "UPDATE offline_scans SET sync_status = 'failed', failure_reason = ?, "
      'retry_count = retry_count + 1 '
      'WHERE dedup_key IN ($placeholders)',
      [failureReason, ...dedupKeys],
    );
  }

  @override
  Future<void> resetFailedToPending(List<String> dedupKeys) async {
    if (dedupKeys.isEmpty) {
      return;
    }

    final db = await database;
    final placeholders = List.filled(dedupKeys.length, '?').join(',');
    await db.rawUpdate(
      "UPDATE offline_scans SET sync_status = 'pending', failure_reason = NULL "
      'WHERE dedup_key IN ($placeholders)',
      dedupKeys,
    );
  }

  @override
  Future<List<OfflineScan>> recentScans(
    String eventId, {
    int limit = 100,
  }) async {
    final db = await database;
    final rows = await db.query(
      'offline_scans',
      where: 'event_id = ?',
      whereArgs: [eventId],
      orderBy: 'scanned_at DESC',
      limit: limit,
    );
    return rows.map(OfflineScan.fromDb).toList();
  }

  @override
  Future<int> rosterCount() async {
    final db = await database;
    final result = await db.rawQuery(
      'SELECT COUNT(*) AS count FROM roster_students',
    );
    return Sqflite.firstIntValue(result) ?? 0;
  }

  @override
  Future<void> wipeRoster() async {
    final db = await database;
    await db.delete('roster_students');
  }

  @override
  Future<void> upsertEventSummary(EventSummary summary) async {
    final db = await database;
    await db.insert(
      'event_summaries',
      summary.toDb(),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  @override
  Future<EventSummary?> eventSummary(String eventId) async {
    final db = await database;
    final rows = await db.query(
      'event_summaries',
      where: 'event_id = ?',
      whereArgs: [eventId],
      limit: 1,
    );
    if (rows.isEmpty) {
      return null;
    }
    return EventSummary.fromDb(rows.first);
  }

  String _escapeLike(String value) {
    return value
        .replaceAll('\\', '\\\\')
        .replaceAll('%', r'\%')
        .replaceAll('_', r'\_');
  }
}
