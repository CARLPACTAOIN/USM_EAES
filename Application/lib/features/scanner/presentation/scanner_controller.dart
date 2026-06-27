import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../../../config/app_config.dart';
import '../data/scanner_api_client.dart';
import '../data/scanner_contracts.dart';
import '../data/scanner_local_database.dart';
import '../domain/offline_scan.dart';
import '../domain/roster_student.dart';
import '../domain/scan_type.dart';
import '../domain/scanner_session.dart';

class ScannerController extends ChangeNotifier {
  ScannerController({
    required ScannerLocalStore localDatabase,
    required ScannerRemoteApi apiClient,
    required String deviceId,
    NetworkStatusChecker? networkStatusChecker,
    String Function()? dedupIdFactory,
  }) : _localDatabase = localDatabase,
       _apiClient = apiClient,
       _deviceId = deviceId,
       _networkStatusChecker =
           networkStatusChecker ?? ConnectivityStatusChecker(),
       _dedupIdFactory = dedupIdFactory ?? (() => const Uuid().v4());

  factory ScannerController.defaultInstance() {
    return ScannerController(
      localDatabase: ScannerLocalDatabase.instance,
      apiClient: ScannerApiClient(AppConfig.apiBaseUrl),
      deviceId: AppConfig.deviceId,
    );
  }

  final ScannerLocalStore _localDatabase;
  final ScannerRemoteApi _apiClient;
  final String _deviceId;
  final NetworkStatusChecker _networkStatusChecker;
  final String Function() _dedupIdFactory;

  ScannerSession? session;
  ScanType scanType = ScanType.timeIn;
  bool busy = false;
  bool syncing = false;
  String? eventTitle;
  String? statusMessage;
  String? errorMessage;
  int rosterCount = 0;
  int pendingCount = 0;
  int unresolvedLastSync = 0;
  ScanCaptureResult? lastScanResult;

  bool get hasSession => session != null;

  /// Whether the device currently has any network connectivity.
  bool isOnline = false;

  void setScanType(ScanType value) {
    scanType = value;
    notifyListeners();
  }

  Future<void> applySessionUri(Uri uri) async {
    final parsed = ScannerSession.fromUri(uri);
    if (parsed == null) {
      errorMessage =
          'Scanner link is invalid. Use eaes://scanner?token=...&event_id=...';
      notifyListeners();
      return;
    }

    await activateSession(parsed);
  }

  Future<void> activateSession(ScannerSession nextSession) async {
    session = nextSession;
    busy = true;
    errorMessage = null;
    statusMessage = 'Validating scanner session.';
    notifyListeners();

    try {
      final response = await _apiClient.validateSession(nextSession);
      final event = response['event'] as Map<String, dynamic>?;
      eventTitle = event?['title']?.toString();
      statusMessage = 'Session ready. Hydrate roster before scanning offline.';
      pendingCount = await _localDatabase.pendingCount(nextSession.eventId);
    } catch (error) {
      errorMessage = error.toString();
      statusMessage = null;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> hydrateRoster() async {
    final activeSession = session;
    if (activeSession == null) {
      errorMessage = 'Open a scanner session before hydrating the roster.';
      notifyListeners();
      return;
    }

    busy = true;
    errorMessage = null;
    statusMessage = 'Downloading student roster.';
    notifyListeners();

    try {
      final roster = await _apiClient.hydrateRoster(activeSession);
      await _localDatabase.upsertRoster(roster);
      rosterCount = roster.length;
      pendingCount = await _localDatabase.pendingCount(activeSession.eventId);
      statusMessage = 'Roster hydrated. Offline scanning is ready.';
    } catch (error) {
      errorMessage = error.toString();
      statusMessage = null;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<ScanCaptureResult> recordQr(
    String rawValue, {
    bool manual = false,
  }) async {
    final activeSession = session;
    final qrValue = rawValue.trim();

    if (activeSession == null) {
      return _captureError('Open a scanner session before recording scans.');
    }
    if (qrValue.isEmpty) {
      return _captureError('QR value is empty.');
    }

    RosterStudent? student;
    try {
      student = await _localDatabase.findStudentByQr(qrValue);
    } catch (error) {
      return _captureError(error.toString());
    }

    final now = DateTime.now().toUtc();
    final dedupKey =
        '${activeSession.eventId}-${scanType.apiValue}-${_dedupIdFactory()}';

    final scan = OfflineScan(
      eventId: activeSession.eventId,
      studentId: student?.studentId,
      qrCodeValue: qrValue,
      scanType: scanType,
      scannedAt: now,
      deviceId: _deviceId,
      manualEntry: manual,
      dedupKey: dedupKey,
      resolutionStatus: student == null
          ? 'pending_server_lookup'
          : 'local_roster_match',
    );

    await _localDatabase.insertScan(scan);
    pendingCount = await _localDatabase.pendingCount(activeSession.eventId);

    final result = ScanCaptureResult(
      success: true,
      unresolved: student == null,
      title: student?.name ?? 'Unresolved QR saved',
      detail: student == null
          ? 'Pending registration lookup on sync.'
          : '${student.programCode ?? 'Student'} captured as ${scanType.label}.',
    );

    statusMessage = result.detail;
    errorMessage = null;
    lastScanResult = result;
    notifyListeners();
    return result;
  }

  Future<void> syncPending() async {
    final activeSession = session;
    if (activeSession == null) {
      errorMessage = 'Open a scanner session before syncing.';
      notifyListeners();
      return;
    }

    final connectivity = await _networkStatusChecker.checkConnectivity();
    if (connectivity.length == 1 &&
        connectivity.contains(ConnectivityResult.none)) {
      errorMessage =
          'Device is offline. Sync will be available when network returns.';
      notifyListeners();
      return;
    }

    syncing = true;
    errorMessage = null;
    statusMessage = 'Uploading pending scans.';
    notifyListeners();

    try {
      final scans = await _localDatabase.unsyncedScans(
        activeSession.eventId,
        limit: AppConfig.syncBatchSize,
      );

      if (scans.isEmpty) {
        statusMessage = 'No pending scans to sync.';
        pendingCount = 0;
        return;
      }

      final response = await _apiClient.syncScans(
        session: activeSession,
        scans: scans,
      );
      await _localDatabase.markSynced(
        scans.map((scan) => scan.dedupKey).toList(),
      );
      pendingCount = await _localDatabase.pendingCount(activeSession.eventId);
      unresolvedLastSync = (response['unresolved_count'] as num?)?.toInt() ?? 0;
      statusMessage =
          'Uploaded ${scans.length} scans. '
          '$unresolvedLastSync require server-side lookup.';
    } catch (error) {
      errorMessage = error.toString();
      statusMessage = null;
    } finally {
      syncing = false;
      notifyListeners();
    }
  }

  ScanCaptureResult _captureError(String message) {
    errorMessage = message;
    lastScanResult = ScanCaptureResult(
      success: false,
      unresolved: false,
      title: 'Scan not saved',
      detail: message,
    );
    notifyListeners();
    return lastScanResult!;
  }

  /// Returns the [limit] most-recent scans for the current event, newest first.
  /// Returns an empty list if no session is active.
  Future<List<OfflineScan>> recentScans({int limit = 100}) async {
    final activeSession = session;
    if (activeSession == null) return [];
    return _localDatabase.recentScans(activeSession.eventId, limit: limit);
  }

  /// Refreshes [isOnline] from the connectivity plugin.
  Future<void> checkConnectivity() async {
    final result = await _networkStatusChecker.checkConnectivity();
    isOnline =
        !(result.length == 1 && result.contains(ConnectivityResult.none));
    notifyListeners();
  }
}

class ScanCaptureResult {
  const ScanCaptureResult({
    required this.success,
    required this.unresolved,
    required this.title,
    required this.detail,
  });

  final bool success;
  final bool unresolved;
  final String title;
  final String detail;
}
