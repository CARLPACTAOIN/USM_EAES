import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:eaes_scanner/features/scanner/data/scanner_contracts.dart';
import 'package:eaes_scanner/features/scanner/domain/offline_scan.dart';
import 'package:eaes_scanner/features/scanner/domain/roster_student.dart';
import 'package:eaes_scanner/features/scanner/domain/scan_type.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:eaes_scanner/features/scanner/presentation/scanner_controller.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const session = ScannerSession(token: 'token-1', eventId: 'event-1');

  ScannerController buildController({
    FakeLocalStore? localStore,
    FakeRemoteApi? remoteApi,
    FakeNetworkStatus? networkStatus,
    String Function()? dedupIdFactory,
  }) {
    return ScannerController(
      localDatabase: localStore ?? FakeLocalStore(),
      apiClient: remoteApi ?? FakeRemoteApi(),
      deviceId: 'test-device',
      networkStatusChecker:
          networkStatus ?? FakeNetworkStatus([ConnectivityResult.wifi]),
      dedupIdFactory: dedupIdFactory,
    );
  }

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
          ),
        ],
      );
      final controller = buildController(localStore: local, remoteApi: remote);

      await controller.activateSession(session);
      expect(controller.hasSession, isTrue);
      expect(controller.eventTitle, 'Test Event');
      expect(controller.statusMessage, contains('Session ready'));

      await controller.hydrateRoster();
      expect(controller.rosterCount, 1);
      expect(await local.findStudentByQr('QR-1'), isNotNull);
      expect(controller.statusMessage, contains('Offline scanning is ready'));
    });

    test('records known QR and unresolved manual QR offline', () async {
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

      final known = await controller.recordQr('QR-1');
      expect(known.success, isTrue);
      expect(known.unresolved, isFalse);
      expect(known.title, 'Juan Dela Cruz');
      expect(controller.pendingCount, 1);
      expect(local.scans.single.studentId, 'student-1');

      controller.setScanType(ScanType.timeOut);
      final unresolved = await controller.recordQr('QR-MISSING', manual: true);
      expect(unresolved.success, isTrue);
      expect(unresolved.unresolved, isTrue);
      expect(controller.pendingCount, 2);
      expect(local.scans.last.manualEntry, isTrue);
      expect(local.scans.last.resolutionStatus, 'pending_server_lookup');
    });

    test('keeps duplicate local dedup keys pending only once', () async {
      final local = FakeLocalStore();
      await local.upsertRoster(const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-1',
          name: 'Juan Dela Cruz',
          programCode: 'BSIS',
        ),
      ]);
      final controller = buildController(
        localStore: local,
        dedupIdFactory: () => 'fixed-id',
      );
      await controller.activateSession(session);

      await controller.recordQr('QR-1');
      await controller.recordQr('QR-1');

      expect(controller.pendingCount, 1);
      expect(local.scans, hasLength(1));
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
      final remote = FakeRemoteApi(syncResponse: {'unresolved_count': 1});
      final controller = buildController(localStore: local, remoteApi: remote);
      await controller.activateSession(session);
      await controller.recordQr('QR-UNKNOWN', manual: true);

      await controller.syncPending();

      expect(remote.syncedBatches, hasLength(1));
      expect(controller.pendingCount, 0);
      expect(controller.unresolvedLastSync, 1);
      expect(controller.statusMessage, contains('Uploaded 1 scans'));
    });
  });
}

class FakeRemoteApi implements ScannerRemoteApi {
  FakeRemoteApi({
    this.roster = const [],
    this.syncResponse = const {'unresolved_count': 0},
  });

  final List<RosterStudent> roster;
  final Map<String, dynamic> syncResponse;
  final List<List<OfflineScan>> syncedBatches = [];

  @override
  Future<Map<String, dynamic>> validateSession(ScannerSession session) async {
    return {
      'success': true,
      'event': {
        'id': session.eventId,
        'title': 'Test Event',
        'status': 'approved',
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
  final Map<String, RosterStudent> _rosterByQr = {};
  final List<OfflineScan> scans = [];
  final Set<String> _syncedDedupKeys = {};

  @override
  Future<void> upsertRoster(List<RosterStudent> students) async {
    for (final student in students) {
      _rosterByQr[student.qrCodeValue] = student;
    }
  }

  @override
  Future<RosterStudent?> findStudentByQr(String qrCodeValue) async {
    return _rosterByQr[qrCodeValue];
  }

  @override
  Future<int> insertScan(OfflineScan scan) async {
    if (scans.any((item) => item.dedupKey == scan.dedupKey)) {
      return 0;
    }

    scans.add(scan);
    return 1;
  }

  @override
  Future<List<OfflineScan>> unsyncedScans(
    String eventId, {
    int limit = 200,
  }) async {
    return scans
        .where(
          (scan) =>
              scan.eventId == eventId &&
              !_syncedDedupKeys.contains(scan.dedupKey),
        )
        .take(limit)
        .toList();
  }

  @override
  Future<int> pendingCount(String eventId) async {
    return (await unsyncedScans(eventId)).length;
  }

  @override
  Future<void> markSynced(List<String> dedupKeys) async {
    _syncedDedupKeys.addAll(dedupKeys);
  }

  @override
  Future<List<OfflineScan>> recentScans(String eventId, {int limit = 100}) async {
    return scans
        .where((scan) => scan.eventId == eventId)
        .toList()
        .reversed
        .take(limit)
        .toList();
  }
}

class FakeNetworkStatus implements NetworkStatusChecker {
  FakeNetworkStatus(this.results);

  final List<ConnectivityResult> results;

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => results;
}
