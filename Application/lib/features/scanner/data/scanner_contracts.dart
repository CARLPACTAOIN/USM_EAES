import 'package:connectivity_plus/connectivity_plus.dart';

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

  Future<RosterStudent?> findStudentByQr(String qrCodeValue);

  Future<int> insertScan(OfflineScan scan);

  Future<List<OfflineScan>> unsyncedScans(String eventId, {int limit = 200});

  Future<int> pendingCount(String eventId);

  Future<void> markSynced(List<String> dedupKeys);

  /// Returns the most-recent [limit] scans for the given event, newest first.
  Future<List<OfflineScan>> recentScans(String eventId, {int limit = 100});
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
