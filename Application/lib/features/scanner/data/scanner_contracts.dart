import 'package:connectivity_plus/connectivity_plus.dart';

import '../domain/event_summary.dart';
import '../domain/offline_scan.dart';
import '../domain/roster_student.dart';
import '../domain/scanner_session.dart';

abstract class ScannerRemoteApi {
  Future<Map<String, dynamic>> validateSession(ScannerSession session);

  Future<List<RosterStudent>> hydrateRoster(ScannerSession session);

  Future<Map<String, dynamic>> syncScans({
    required ScannerSession session,
    required List<OfflineScan> scans,
  });
}

abstract class ScannerLocalStore {
  Future<void> upsertRoster(List<RosterStudent> students);

  Future<void> replaceRoster(List<RosterStudent> students);

  Future<RosterStudent?> findStudentByQr(String qrCodeValue);

  Future<RosterStudent?> findStudentByIdNumber(String studentIdNumber);

  Future<List<RosterStudent>> rosterStudents({
    String? query,
    int limit = 100,
    int offset = 0,
  });

  Future<int> insertScan(OfflineScan scan);

  Future<List<OfflineScan>> unsyncedScans(String eventId, {int limit = 200});

  Future<List<OfflineScan>> failedScans(String eventId, {int limit = 200});

  Future<int> pendingCount(String eventId);

  Future<int> syncedCount(String eventId);

  Future<int> failedCount(String eventId);

  Future<void> markSyncing(List<String> dedupKeys);

  Future<void> markSynced(List<String> dedupKeys);

  Future<void> markFailed(
    List<String> dedupKeys, {
    required String failureReason,
  });

  Future<void> resetFailedToPending(List<String> dedupKeys);

  Future<List<OfflineScan>> recentScans(String eventId, {int limit = 100});

  Future<int> rosterCount();

  Future<void> wipeRoster();

  Future<void> upsertEventSummary(EventSummary summary);

  Future<EventSummary?> eventSummary(String eventId);
}

abstract class NetworkStatusChecker {
  Future<List<ConnectivityResult>> checkConnectivity();
}

class ConnectivityStatusChecker implements NetworkStatusChecker {
  ConnectivityStatusChecker([Connectivity? connectivity])
    : _connectivity = connectivity ?? Connectivity();

  final Connectivity _connectivity;

  @override
  Future<List<ConnectivityResult>> checkConnectivity() {
    return _connectivity.checkConnectivity();
  }
}
