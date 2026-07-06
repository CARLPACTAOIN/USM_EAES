import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:eaes_scanner/features/scanner/data/scanner_api_client.dart';
import 'package:eaes_scanner/features/scanner/data/scanner_contracts.dart';
import 'package:eaes_scanner/features/scanner/domain/event_summary.dart';
import 'package:eaes_scanner/features/scanner/domain/offline_scan.dart';
import 'package:eaes_scanner/features/scanner/domain/roster_student.dart';
import 'package:eaes_scanner/features/scanner/domain/scan_sync_status.dart';
import 'package:eaes_scanner/features/scanner/domain/scan_type.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_error_mapper.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:eaes_scanner/features/scanner/domain/student_id_validator.dart';
import 'package:eaes_scanner/features/scanner/presentation/scanner_controller.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const session = ScannerSession(token: 'token-1', eventId: 'event-1');

  ScannerController buildController({
    FakeLocalStore? localStore,
    FakeRemoteApi? remoteApi,
    FakeNetworkStatus? networkStatus,
    String Function()? dedupIdFactory,
    DateTime Function()? nowFactory,
    Duration duplicateCooldown = const Duration(seconds: 5),
  }) {
    return ScannerController(
      localDatabase: localStore ?? FakeLocalStore(),
      apiClient: remoteApi ?? FakeRemoteApi(),
      deviceId: 'test-device',
      networkStatusChecker:
          networkStatus ?? FakeNetworkStatus([ConnectivityResult.wifi]),
      dedupIdFactory: dedupIdFactory,
      nowFactory: nowFactory,
      duplicateCooldown: duplicateCooldown,
    );
  }

  group('StudentIdValidator', () {
    test('accepts valid ID number like 23-72634', () {
      final result = StudentIdValidator.validate('23-72634');
      expect(result.isValid, isTrue);
      expect(result.value, '23-72634');
    });

    test('rejects invalid formats', () {
      expect(StudentIdValidator.validate('').isValid, isFalse);
      expect(StudentIdValidator.validate('ABC').isValid, isFalse);
      expect(StudentIdValidator.validate('23-726').isValid, isFalse);
    });
  });

  group('ScannerErrorMapper', () {
    test('maps 401 to friendly expired message', () {
      final mapped = ScannerErrorMapper.map(
        const ScannerApiException('Unauthorized', statusCode: 401),
      );
      expect(mapped.message, contains('expired or unauthorized'));
      expect(mapped.message, isNot(contains('401')));
    });

    test('does not expose raw URLs in friendly message', () {
      final mapped = ScannerErrorMapper.map(
        const ScannerApiException(
          'https://example.ngrok-free.dev/api/v1/scanner/validate',
          statusCode: 500,
        ),
      );
      expect(mapped.message, isNot(contains('ngrok')));
      expect(mapped.message, isNot(contains('/api/')));
      expect(mapped.technicalDetails, contains('ngrok'));
    });
  });

  group('ScannerController', () {
    test('activates session and hydrates roster', () async {
      final local = FakeLocalStore();
      final remote = FakeRemoteApi(
        roster: const [
          RosterStudent(
            studentId: 'student-1',
            qrCodeValue: 'QR-1',
            name: 'Juan Dela Cruz',
            programCode: 'BSIS',
            studentIdNumber: '23-72634',
          ),
        ],
      );
      final controller = buildController(localStore: local, remoteApi: remote);

      await controller.activateSession(session);
      expect(controller.hasSession, isTrue);
      expect(controller.eventTitle, 'Test Event');
      expect(controller.statusMessage, contains('Event active'));
      expect(controller.globalFeedback.message, contains('Scanning enabled'));

      await controller.hydrateRoster();
      expect(controller.rosterCount, 1);
      expect(await local.findStudentByIdNumber('23-72634'), isNotNull);
      expect(controller.statusMessage, contains('Offline scanning is ready'));
    });

    test('does not keep session when backend validation fails', () async {
      final remote = FakeRemoteApi(
        validateError: ScannerApiException('Unauthorized', statusCode: 401),
      );
      final controller = buildController(remoteApi: remote);

      await controller.activateSession(session);

      expect(controller.hasSession, isFalse);
      expect(controller.errorMessage, contains('expired or unauthorized'));
      expect(controller.errorMessage, isNot(contains('401')));
    });

    test('records known QR and unresolved manual ID offline', () async {
      final local = FakeLocalStore();
      await local.upsertRoster(const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-1',
          name: 'Juan Dela Cruz',
          programCode: 'BSIS',
          studentIdNumber: '23-72634',
        ),
      ]);
      final controller = buildController(localStore: local);
      await controller.activateSession(session);

      final known = await controller.recordQr('QR-1');
      expect(known.success, isTrue);
      expect(known.unresolved, isFalse);
      expect(known.title, 'Juan Dela Cruz');
      expect(controller.pendingCount, 1);
      expect(local.scans.single.studentId, 'student-1');

      controller.setScanType(ScanType.timeOut);
      final unresolved = await controller.recordQr('99-99999', manual: true);
      expect(unresolved.success, isTrue);
      expect(unresolved.unresolved, isTrue);
      expect(controller.pendingCount, 2);
      expect(local.scans.last.manualEntry, isTrue);
      expect(local.scans.last.qrCodeValue, '99-99999');
      expect(local.scans.last.resolutionStatus, 'pending_server_lookup');
    });

    test('scheduled event disables scanner with start message', () async {
      final controller = buildController(
        remoteApi: FakeRemoteApi(eventPhase: 'scheduled'),
      );
      await controller.activateSession(session);

      final result = await controller.recordQr('QR-1');

      expect(result.success, isFalse);
      expect(result.detail, contains('Event has not started yet'));
      expect(controller.canCaptureScans, isFalse);
    });

    test('ended event disables new scans but leaves sync available', () async {
      final local = FakeLocalStore();
      final remote = FakeRemoteApi(
        eventPhase: 'evaluation_open',
        syncResponse: {'unresolved_count': 0, 'ingested_count': 1},
      );
      final controller = buildController(localStore: local, remoteApi: remote);
      await controller.activateSession(session);

      final result = await controller.recordQr('99-99999', manual: true);
      expect(result.success, isFalse);
      expect(result.detail, contains('Event has ended'));

      local.scans.add(
        OfflineScan(
          eventId: session.eventId,
          qrCodeValue: 'QR-PENDING',
          scanType: ScanType.timeIn,
          scannedAt: DateTime.utc(2026, 1, 1, 9),
          deviceId: 'test-device',
          manualEntry: false,
          dedupKey: 'pending-before-ended',
          resolutionStatus: 'pending_server_lookup',
        ),
      );
      local.setPending('pending-before-ended');

      await controller.syncPending();
      expect(remote.syncedBatches, hasLength(1));
    });

    test('rejects invalid manual student ID format', () async {
      final controller = buildController();
      await controller.activateSession(session);

      final result = await controller.recordQr('bad-id', manual: true);
      expect(result.success, isFalse);
      expect(result.detail, StudentIdValidator.invalidFormatMessage);
    });

    test('ignores scan while isSavingScan is true', () async {
      final local = FakeLocalStore(blockInsert: true);
      await local.upsertRoster(const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-1',
          name: 'Juan Dela Cruz',
          programCode: 'BSIS',
        ),
      ]);
      final controller = buildController(localStore: local);
      await controller.activateSession(session);

      final first = controller.recordQr('QR-1');
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(controller.isSavingScan, isTrue);

      final second = await controller.recordQr('QR-1');
      local.releaseInsert();
      await first;

      expect(second.success, isFalse);
      expect(local.scans, hasLength(1));
    });

    test('ignores duplicate QR within cooldown', () async {
      final local = FakeLocalStore();
      await local.upsertRoster(const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-1',
          name: 'Juan Dela Cruz',
          programCode: 'BSIS',
        ),
      ]);
      var now = DateTime.utc(2026, 1, 1, 12);
      final controller = buildController(
        localStore: local,
        nowFactory: () => now,
      );
      await controller.activateSession(session);

      await controller.recordQr('QR-1');
      expect(controller.pendingCount, 1);

      now = now.add(const Duration(seconds: 2));
      final duplicate = await controller.recordQr('QR-1');
      expect(duplicate.isDuplicate, isTrue);
      expect(duplicate.title, 'Already scanned just now.');
      expect(controller.pendingCount, 1);
      expect(local.scans, hasLength(1));
    });

    test('allows same QR after cooldown expires', () async {
      final local = FakeLocalStore();
      await local.upsertRoster(const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-1',
          name: 'Juan Dela Cruz',
          programCode: 'BSIS',
        ),
      ]);
      var now = DateTime.utc(2026, 1, 1, 12);
      final controller = buildController(
        localStore: local,
        nowFactory: () => now,
      );
      await controller.activateSession(session);

      await controller.recordQr('QR-1');
      now = now.add(const Duration(seconds: 6));
      await controller.recordQr('QR-1');

      expect(controller.pendingCount, 2);
      expect(local.scans, hasLength(2));
    });

    test('treats Time-In and Time-Out duplicates separately', () async {
      final local = FakeLocalStore();
      await local.upsertRoster(const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-1',
          name: 'Juan Dela Cruz',
          programCode: 'BSIS',
        ),
      ]);
      final controller = buildController(localStore: local);
      await controller.activateSession(session);

      await controller.recordQr('QR-1');
      controller.setScanType(ScanType.timeOut);
      await controller.recordQr('QR-1');

      expect(controller.pendingCount, 2);
      expect(local.scans, hasLength(2));
    });

    test('blocks sync while offline', () async {
      final remote = FakeRemoteApi();
      final controller = buildController(
        remoteApi: remote,
        networkStatus: FakeNetworkStatus([ConnectivityResult.none]),
      );
      await controller.activateSession(session);

      await controller.syncPending();

      expect(controller.errorMessage, contains('Device is offline'));
      expect(remote.syncedBatches, isEmpty);
    });

    test('syncs pending scans and records unresolved count', () async {
      final local = FakeLocalStore();
      final remote = FakeRemoteApi(
        syncResponse: {'unresolved_count': 1, 'ingested_count': 1},
      );
      final controller = buildController(localStore: local, remoteApi: remote);
      await controller.activateSession(session);
      await controller.recordQr('99-99999', manual: true);

      expect(controller.pendingCount, 1);
      await controller.syncPending();

      expect(remote.syncedBatches, hasLength(1));
      expect(controller.pendingCount, 0);
      expect(controller.syncedCount, 1);
      expect(controller.unresolvedLastSync, 1);
      expect(controller.statusMessage, contains('Uploaded 1 scans'));
    });

    test(
      'does not decrease pending count until backend confirms sync',
      () async {
        final local = FakeLocalStore();
        final remote = FakeRemoteApi(
          syncResponse: {'unresolved_count': 0, 'ingested_count': 0},
        );
        final controller = buildController(
          localStore: local,
          remoteApi: remote,
        );
        await controller.activateSession(session);
        await controller.recordQr('99-99999', manual: true);

        await controller.syncPending();

        expect(controller.pendingCount, 0);
        expect(controller.failedCount, 1);
        expect(controller.syncedCount, 0);
      },
    );

    test(
      'auto-wipes roster only after completed event and all scans synced',
      () async {
        final local = FakeLocalStore();
        final remote = FakeRemoteApi(
          eventStatus: 'completed',
          syncResponse: {'unresolved_count': 0, 'ingested_count': 1},
        );
        await local.upsertRoster(const [
          RosterStudent(
            studentId: 'student-1',
            qrCodeValue: 'QR-1',
            name: 'Juan Dela Cruz',
            programCode: 'BSIS',
            studentIdNumber: '23-72634',
          ),
        ]);
        final controller = buildController(
          localStore: local,
          remoteApi: remote,
        );
        await controller.activateSession(session);
        expect(controller.rosterCount, 1);

        local.scans.add(
          OfflineScan(
            eventId: session.eventId,
            qrCodeValue: 'QR-COMPLETED',
            scanType: ScanType.timeIn,
            scannedAt: DateTime.utc(2026, 1, 1, 9),
            deviceId: 'test-device',
            manualEntry: false,
            dedupKey: 'completed-pending',
            resolutionStatus: 'pending_server_lookup',
          ),
        );
        local.setPending('completed-pending');
        await controller.syncPending();
        await controller.checkEventRetention();

        expect(controller.hasSession, isFalse);
        expect(await local.rosterCount(), 0);
        expect(local.scans, hasLength(1));
        expect(
          controller.globalFeedback.message,
          contains('Local roster data has been removed'),
        );
      },
    );

    test('does not wipe when completed but scans remain pending', () async {
      final local = FakeLocalStore();
      final remote = FakeRemoteApi(eventStatus: 'completed');
      await local.upsertRoster(const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-1',
          name: 'Juan Dela Cruz',
          programCode: 'BSIS',
        ),
      ]);
      final controller = buildController(localStore: local, remoteApi: remote);
      await controller.activateSession(session);
      local.scans.add(
        OfflineScan(
          eventId: session.eventId,
          qrCodeValue: 'QR-COMPLETED-PENDING',
          scanType: ScanType.timeIn,
          scannedAt: DateTime.utc(2026, 1, 1, 9),
          deviceId: 'test-device',
          manualEntry: false,
          dedupKey: 'completed-still-pending',
          resolutionStatus: 'pending_server_lookup',
        ),
      );
      local.setPending('completed-still-pending');
      await controller.checkEventRetention();

      expect(controller.hasSession, isTrue);
      expect(await local.rosterCount(), 1);
      expect(controller.globalFeedback.message, contains('unsynced scans'));
    });

    test(
      'retryFailedScans resets failed records to pending and syncs',
      () async {
        final local = FakeLocalStore();
        final remote = FakeRemoteApi(
          syncResponse: {'unresolved_count': 0, 'ingested_count': 1},
        );
        final controller = buildController(
          localStore: local,
          remoteApi: remote,
        );
        await controller.activateSession(session);
        await controller.recordQr('99-99999', manual: true);

        await local.markFailed([
          local.scans.single.dedupKey,
        ], failureReason: 'network_error');
        await controller.retryFailedScans();

        expect(controller.pendingCount, 0);
        expect(controller.syncedCount, 1);
        expect(remote.syncedBatches, hasLength(1));
      },
    );
  });
}

class FakeRemoteApi implements ScannerRemoteApi {
  FakeRemoteApi({
    this.roster = const [],
    this.syncResponse = const {'unresolved_count': 0, 'ingested_count': 1},
    this.eventStatus = 'approved',
    this.eventPhase,
    this.validateError,
  });

  final List<RosterStudent> roster;
  final Map<String, dynamic> syncResponse;
  final String eventStatus;
  final String? eventPhase;
  final ScannerApiException? validateError;
  final List<List<OfflineScan>> syncedBatches = [];

  @override
  Future<Map<String, dynamic>> validateSession(ScannerSession session) async {
    if (validateError != null) {
      throw validateError!;
    }
    return {
      'success': true,
      'event': {
        'id': session.eventId,
        'title': 'Test Event',
        'status': eventStatus,
        'lifecycle_phase':
            eventPhase ?? (eventStatus == 'completed' ? 'completed' : 'active'),
      },
      'scanner_session': {
        'id': 'scanner-session-1',
        'status': 'active',
        'scan_window_start': '2026-01-01T08:00:00Z',
        'scan_window_end': '2026-01-01T12:00:00Z',
        'sync_grace_ends_at': '2026-01-04T12:00:00Z',
      },
    };
  }

  @override
  Future<List<RosterStudent>> hydrateRoster(ScannerSession session) async {
    return roster;
  }

  @override
  Future<Map<String, dynamic>> syncScans({
    required ScannerSession session,
    required List<OfflineScan> scans,
  }) async {
    syncedBatches.add(scans);
    return syncResponse;
  }
}

class FakeLocalStore implements ScannerLocalStore {
  FakeLocalStore({this.blockInsert = false});

  final bool blockInsert;
  Completer<void>? _insertGate;
  final Map<String, RosterStudent> _rosterByQr = {};
  final Map<String, RosterStudent> _rosterByIdNumber = {};
  final List<OfflineScan> scans = [];
  final Map<String, ScanSyncStatus> _syncStatuses = {};
  final Map<String, String?> _failureReasons = {};
  EventSummary? _summary;

  @override
  Future<void> upsertRoster(List<RosterStudent> students) async {
    for (final student in students) {
      _rosterByQr[student.qrCodeValue] = student;
      if (student.studentIdNumber != null) {
        _rosterByIdNumber[student.studentIdNumber!] = student;
      }
    }
  }

  @override
  Future<RosterStudent?> findStudentByQr(String qrCodeValue) async {
    return _rosterByQr[qrCodeValue];
  }

  @override
  Future<RosterStudent?> findStudentByIdNumber(String studentIdNumber) async {
    return _rosterByIdNumber[studentIdNumber];
  }

  void releaseInsert() {
    _insertGate?.complete();
  }

  void setPending(String dedupKey) {
    _syncStatuses[dedupKey] = ScanSyncStatus.pending;
  }

  @override
  Future<int> insertScan(OfflineScan scan) async {
    if (blockInsert) {
      _insertGate = Completer<void>();
      await _insertGate!.future;
    }
    if (scans.any((item) => item.dedupKey == scan.dedupKey)) {
      return 0;
    }

    scans.add(scan);
    _syncStatuses[scan.dedupKey] = ScanSyncStatus.pending;
    return 1;
  }

  @override
  Future<List<OfflineScan>> unsyncedScans(
    String eventId, {
    int limit = 200,
  }) async {
    return scans
        .where((scan) {
          final status = _syncStatuses[scan.dedupKey] ?? ScanSyncStatus.pending;
          return scan.eventId == eventId &&
              (status == ScanSyncStatus.pending ||
                  status == ScanSyncStatus.failed);
        })
        .take(limit)
        .toList();
  }

  @override
  Future<List<OfflineScan>> failedScans(
    String eventId, {
    int limit = 200,
  }) async {
    return scans
        .where(
          (scan) =>
              scan.eventId == eventId &&
              _syncStatuses[scan.dedupKey] == ScanSyncStatus.failed,
        )
        .take(limit)
        .toList();
  }

  @override
  Future<int> pendingCount(String eventId) async {
    return scans
        .where(
          (scan) =>
              scan.eventId == eventId &&
              (_syncStatuses[scan.dedupKey] ?? ScanSyncStatus.pending) ==
                  ScanSyncStatus.pending,
        )
        .length;
  }

  @override
  Future<int> syncedCount(String eventId) async {
    return scans
        .where(
          (scan) =>
              scan.eventId == eventId &&
              _syncStatuses[scan.dedupKey] == ScanSyncStatus.synced,
        )
        .length;
  }

  @override
  Future<int> failedCount(String eventId) async {
    return scans
        .where(
          (scan) =>
              scan.eventId == eventId &&
              _syncStatuses[scan.dedupKey] == ScanSyncStatus.failed,
        )
        .length;
  }

  @override
  Future<void> markSyncing(List<String> dedupKeys) async {
    for (final key in dedupKeys) {
      _syncStatuses[key] = ScanSyncStatus.syncing;
    }
  }

  @override
  Future<void> markSynced(List<String> dedupKeys) async {
    for (final key in dedupKeys) {
      _syncStatuses[key] = ScanSyncStatus.synced;
    }
  }

  @override
  Future<void> markFailed(
    List<String> dedupKeys, {
    required String failureReason,
  }) async {
    for (final key in dedupKeys) {
      _syncStatuses[key] = ScanSyncStatus.failed;
      _failureReasons[key] = failureReason;
    }
  }

  @override
  Future<void> resetFailedToPending(List<String> dedupKeys) async {
    for (final key in dedupKeys) {
      _syncStatuses[key] = ScanSyncStatus.pending;
      _failureReasons.remove(key);
    }
  }

  @override
  Future<List<OfflineScan>> recentScans(
    String eventId, {
    int limit = 100,
  }) async {
    return scans
        .where((scan) => scan.eventId == eventId)
        .toList()
        .reversed
        .take(limit)
        .toList();
  }

  @override
  Future<int> rosterCount() async => _rosterByQr.length;

  @override
  Future<void> wipeRoster() async {
    _rosterByQr.clear();
    _rosterByIdNumber.clear();
  }

  @override
  Future<void> upsertEventSummary(EventSummary summary) async {
    _summary = summary;
  }

  @override
  Future<EventSummary?> eventSummary(String eventId) async => _summary;
}

class FakeNetworkStatus implements NetworkStatusChecker {
  FakeNetworkStatus(this.results);

  final List<ConnectivityResult> results;

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => results;
}
