import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

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
      version: 1,
      onCreate: (database, version) async {
        await database.execute('''
          CREATE TABLE roster_students (
            student_id TEXT PRIMARY KEY,
            qr_code_value TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            program_code TEXT,
            updated_at TEXT NOT NULL
          )
        ''');

        await database.execute('''
          CREATE TABLE offline_scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id TEXT NOT NULL,
            event_day_id TEXT,
            student_id TEXT,
            qr_code_value TEXT NOT NULL,
            scan_type TEXT NOT NULL,
            scanned_at TEXT NOT NULL,
            device_id TEXT NOT NULL,
            manual_entry INTEGER NOT NULL DEFAULT 0,
            dedup_key TEXT NOT NULL UNIQUE,
            resolution_status TEXT NOT NULL,
            synced_at TEXT
          )
        ''');

        await database.execute(
          'CREATE INDEX idx_offline_scans_event_sync '
          'ON offline_scans(event_id, synced_at)',
        );
        await database.execute(
          'CREATE INDEX idx_roster_students_qr '
          'ON roster_students(qr_code_value)',
        );
      },
    );

    _database = db;
    return db;
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
      where: 'event_id = ? AND synced_at IS NULL',
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
      'SELECT COUNT(*) AS count FROM offline_scans '
      'WHERE event_id = ? AND synced_at IS NULL',
      [eventId],
    );
    return Sqflite.firstIntValue(result) ?? 0;
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
      'UPDATE offline_scans SET synced_at = ? '
      'WHERE dedup_key IN ($placeholders)',
      [now, ...dedupKeys],
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
}
