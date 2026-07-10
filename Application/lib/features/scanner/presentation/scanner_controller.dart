import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../../../config/app_config.dart';
import '../data/scanner_api_client.dart';
import '../data/scanner_contracts.dart';
import '../data/scanner_local_database.dart';
import '../domain/event_summary.dart';
import '../domain/offline_scan.dart';
import '../domain/roster_student.dart';
import '../domain/scan_sync_status.dart';
import '../domain/scan_type.dart';
import '../domain/scanner_error_mapper.dart';
import '../domain/scanner_session.dart';
import '../domain/student_id_validator.dart';

class ScannerController extends ChangeNotifier {
  ScannerController({
    required ScannerLocalStore localDatabase,
    required ScannerRemoteApi apiClient,
    required String deviceId,
    NetworkStatusChecker? networkStatusChecker,
    String Function()? dedupIdFactory,
    DateTime Function()? nowFactory,
    Duration? duplicateCooldown,
  }) : _localDatabase = localDatabase,
       _apiClient = apiClient,
       _deviceId = deviceId,
       _networkStatusChecker =
           networkStatusChecker ?? ConnectivityStatusChecker(),
       _dedupIdFactory = dedupIdFactory ?? (() => const Uuid().v4()),
       _nowFactory = nowFactory ?? DateTime.now,
       duplicateCooldown =
           duplicateCooldown ??
           Duration(seconds: AppConfig.duplicateCooldownSeconds);

  factory ScannerController.defaultInstance({String? deviceId}) {
    final resolvedDeviceId = deviceId ?? AppConfig.deviceId;
    return ScannerController(
      localDatabase: ScannerLocalDatabase.instance,
      apiClient: ScannerApiClient(
        AppConfig.apiBaseUrl,
        deviceIdResolver: () async => resolvedDeviceId,
      ),
      deviceId: resolvedDeviceId,
    );
  }

  final ScannerLocalStore _localDatabase;
  final ScannerRemoteApi _apiClient;
  final String _deviceId;
  final NetworkStatusChecker _networkStatusChecker;
  final String Function() _dedupIdFactory;
  final DateTime Function() _nowFactory;

  ScannerSession? session;
  ScanType scanType = ScanType.timeIn;
  bool busy = false;
  bool syncing = false;
  bool isSavingScan = false;
  String? eventTitle;
  String? eventStatus;
  String? eventLifecyclePhase;
  String? scannerSessionStatus;
  String? participationScopeName;
  DateTime? scanWindowStart;
  DateTime? scanWindowEnd;
  DateTime? syncGraceEndsAt;
  String? statusMessage;
  String? errorMessage;
  String? errorDetails;
  int rosterCount = 0;
  int pendingCount = 0;
  int syncedCount = 0;
  int failedCount = 0;
  int unresolvedLastSync = 0;
  DateTime? lastSyncAttempt;
  DateTime? lastSuccessfulSync;
  ScanCaptureResult? lastScanResult;
  GlobalFeedback globalFeedback = const GlobalFeedback.none();
  int sessionActivatedSignal = 0;
  ScannerErrorCategory? lastSessionActivationErrorCategory;

  final Map<String, DateTime> recentScanCache = {};
  final Duration duplicateCooldown;

  bool get hasSession => session != null;

  bool get canCaptureScans =>
      hasSession && eventLifecyclePhase == 'active' && _isInsideScanWindow();

  bool get lastSessionActivationFailureIsRecoverable =>
      lastSessionActivationErrorCategory == ScannerErrorCategory.networkError ||
      lastSessionActivationErrorCategory == ScannerErrorCategory.serverError;

  bool get syncGraceActive {
    final graceEnd = syncGraceEndsAt;
    return graceEnd != null && _nowFactory().toUtc().isBefore(graceEnd.toUtc());
  }

  bool isOnline = false;

  String get syncStatusMessage {
    if (syncing) {
      return 'Syncing scans…';
    }
    if (!isOnline) {
      return 'Device offline. Sync will resume when network returns.';
    }
    if (failedCount > 0) {
      return 'Some scans failed to sync.';
    }
    if (pendingCount == 0 && failedCount == 0) {
      return 'All scans are synced.';
    }
    return 'Scans are waiting to sync.';
  }

  void setScanType(ScanType value) {
    scanType = value;
    notifyListeners();
  }

  void clearGlobalFeedback() {
    globalFeedback = const GlobalFeedback.none();
    notifyListeners();
  }

  MappedScannerError _setError(Object error, {bool sessionActivation = false}) {
    final mapped = sessionActivation
        ? ScannerErrorMapper.mapSessionActivation(error)
        : ScannerErrorMapper.map(error);
    errorMessage = mapped.message;
    errorDetails = mapped.technicalDetails;
    globalFeedback = GlobalFeedback(
      kind: FeedbackKind.error,
      message: mapped.message,
    );
    return mapped;
  }

  Future<void> _refreshCounts(String eventId) async {
    pendingCount = await _localDatabase.pendingCount(eventId);
    syncedCount = await _localDatabase.syncedCount(eventId);
    failedCount = await _localDatabase.failedCount(eventId);
    rosterCount = await _localDatabase.rosterCount();
  }

  ScannerSessionSnapshot? currentSessionSnapshot() {
    final activeSession = session;
    if (activeSession == null) return null;

    return ScannerSessionSnapshot(
      token: activeSession.token,
      eventId: activeSession.eventId,
      assignmentId: activeSession.assignmentId,
      eventParticipationScopeId: activeSession.eventParticipationScopeId,
      participationScopeName:
          participationScopeName ?? activeSession.participationScopeName,
      eventTitle: eventTitle,
      eventStatus: eventStatus,
      eventLifecyclePhase: eventLifecyclePhase,
      scannerSessionStatus: scannerSessionStatus,
      scanWindowStart: scanWindowStart,
      scanWindowEnd: scanWindowEnd,
      syncGraceEndsAt: syncGraceEndsAt,
      savedAt: _nowFactory().toUtc(),
    );
  }

  Future<void> restoreCachedSession(ScannerSessionSnapshot snapshot) async {
    session = snapshot.session;
    eventTitle = snapshot.eventTitle;
    eventStatus = snapshot.eventStatus;
    eventLifecyclePhase = snapshot.eventLifecyclePhase;
    scannerSessionStatus = snapshot.scannerSessionStatus;
    participationScopeName =
        snapshot.participationScopeName ??
        snapshot.session.participationScopeName;
    scanWindowStart = snapshot.scanWindowStart;
    scanWindowEnd = snapshot.scanWindowEnd;
    syncGraceEndsAt = snapshot.syncGraceEndsAt;
    lastSessionActivationErrorCategory = null;
    errorMessage = null;
    errorDetails = null;
    isOnline = false;
    await _refreshCounts(snapshot.eventId);
    statusMessage =
        'Offline mode. Using saved scanner session and hydrated roster.';
    globalFeedback = GlobalFeedback(
      kind: canCaptureScans ? FeedbackKind.success : FeedbackKind.warning,
      message: statusMessage!,
    );
    sessionActivatedSignal++;
    notifyListeners();
  }

  Future<bool> activateSession(ScannerSession nextSession) async {
    busy = true;
    lastSessionActivationErrorCategory = null;
    errorMessage = null;
    errorDetails = null;
    statusMessage = null;
    globalFeedback = const GlobalFeedback(
      kind: FeedbackKind.loading,
      message: 'Activating scanner session…',
    );
    notifyListeners();

    try {
      final response = await _apiClient.validateSession(nextSession);
      final event = response['event'] as Map<String, dynamic>?;
      final scannerSession =
          response['scanner_session'] as Map<String, dynamic>?;
      final participationScope =
          scannerSession?['participation_scope'] as Map<String, dynamic>?;
      session = nextSession.copyWith(
        eventParticipationScopeId:
            scannerSession?['event_participation_scope_id']?.toString(),
        participationScopeName: participationScope?['name']?.toString(),
      );
      eventTitle = event?['title']?.toString();
      eventStatus = event?['status']?.toString();
      eventLifecyclePhase =
          event?['lifecycle_phase']?.toString() ?? eventStatus;
      scannerSessionStatus = scannerSession?['status']?.toString();
      participationScopeName =
          participationScope?['name']?.toString() ??
          nextSession.participationScopeName;
      scanWindowStart = _parseDateTime(scannerSession?['scan_window_start']);
      scanWindowEnd = _parseDateTime(scannerSession?['scan_window_end']);
      syncGraceEndsAt = _parseDateTime(scannerSession?['sync_grace_ends_at']);
      await _refreshCounts(nextSession.eventId);
      statusMessage = _sessionReadyMessage();
      globalFeedback = GlobalFeedback(
        kind: canCaptureScans ? FeedbackKind.success : FeedbackKind.warning,
        message: statusMessage!,
      );
      sessionActivatedSignal++;
      return true;
    } catch (error) {
      session = null;
      eventTitle = null;
      eventStatus = null;
      eventLifecyclePhase = null;
      scannerSessionStatus = null;
      participationScopeName = null;
      scanWindowStart = null;
      scanWindowEnd = null;
      syncGraceEndsAt = null;
      final mapped = _setError(error, sessionActivation: true);
      lastSessionActivationErrorCategory = mapped.category;
      statusMessage = null;
      return false;
    } finally {
      busy = false;
      notifyListeners();
      if (session != null) {
        await checkEventRetention();
      }
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
    errorDetails = null;
    statusMessage = 'Downloading participant roster.';
    notifyListeners();

    try {
      final roster = await _apiClient.hydrateRoster(activeSession);
      await _localDatabase.replaceRoster(roster);
      await _refreshCounts(activeSession.eventId);
      statusMessage = 'Participant roster hydrated. Offline scanning is ready.';
    } catch (error) {
      _setError(error);
      statusMessage = null;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  String _duplicateKey({
    required String eventId,
    required String? eventParticipationScopeId,
    required String? eventDayId,
    required String value,
    required ScanType type,
  }) {
    return '$eventId|${eventParticipationScopeId ?? ''}|${eventDayId ?? ''}|$value|${type.apiValue}';
  }

  Future<ScanCaptureResult> recordQr(
    String rawValue, {
    bool manual = false,
  }) async {
    if (isSavingScan) {
      return lastScanResult ??
          const ScanCaptureResult(
            success: false,
            unresolved: false,
            isDuplicate: false,
            kind: ScanResultKind.error,
            title: 'Scan not saved',
            detail: 'A scan is already being saved.',
          );
    }

    final activeSession = session;
    final trimmed = rawValue.trim();

    if (activeSession == null) {
      return _captureError('Open a scanner session before recording scans.');
    }

    if (!canCaptureScans) {
      return _captureError(_scanBlockedMessage());
    }

    if (manual) {
      return _recordManualEntry(trimmed, activeSession);
    }

    if (trimmed.isEmpty) {
      return _captureError('QR value is empty.');
    }

    return _recordScanValue(
      activeSession: activeSession,
      lookupValue: trimmed,
      storedQrValue: trimmed,
      manual: false,
    );
  }

  Future<ScanCaptureResult> _recordManualEntry(
    String trimmed,
    ScannerSession activeSession,
  ) async {
    final validation = StudentIdValidator.validate(trimmed);
    if (!validation.isValid) {
      return _captureError(validation.errorMessage!);
    }

    final studentIdNumber = validation.value!;
    RosterStudent? student;
    try {
      student = await _localDatabase.findStudentByIdNumber(studentIdNumber);
    } catch (error) {
      return _captureError(ScannerErrorMapper.map(error).message);
    }

    return _recordScanValue(
      activeSession: activeSession,
      lookupValue: studentIdNumber,
      storedQrValue: student?.qrCodeValue ?? studentIdNumber,
      manual: true,
      student: student,
    );
  }

  Future<ScanCaptureResult> _recordScanValue({
    required ScannerSession activeSession,
    required String lookupValue,
    required String storedQrValue,
    required bool manual,
    RosterStudent? student,
  }) async {
    student ??= manual
        ? null
        : await _localDatabase.findStudentByQr(lookupValue);

    final duplicateKey = _duplicateKey(
      eventId: activeSession.eventId,
      eventParticipationScopeId: activeSession.eventParticipationScopeId,
      eventDayId: null,
      value: lookupValue,
      type: scanType,
    );

    final lastScanAt = recentScanCache[duplicateKey];
    final now = _nowFactory().toUtc();
    if (lastScanAt != null && now.difference(lastScanAt) < duplicateCooldown) {
      final duplicateResult = ScanCaptureResult(
        success: false,
        unresolved: false,
        isDuplicate: true,
        kind: ScanResultKind.duplicate,
        title: 'Already scanned just now.',
        detail: 'Please scan the next student.',
      );
      lastScanResult = duplicateResult;
      notifyListeners();
      return duplicateResult;
    }

    isSavingScan = true;
    notifyListeners();

    try {
      final dedupKey =
          '${activeSession.eventId}-${scanType.apiValue}-${_dedupIdFactory()}';

      final scan = OfflineScan(
        eventId: activeSession.eventId,
        eventParticipationScopeId: activeSession.eventParticipationScopeId,
        studentId: student?.studentId,
        qrCodeValue: storedQrValue,
        scanType: scanType,
        scannedAt: now,
        deviceId: _deviceId,
        manualEntry: manual,
        dedupKey: dedupKey,
        resolutionStatus: student == null
            ? 'pending_server_lookup'
            : 'local_roster_match',
      );

      final inserted = await _localDatabase.insertScan(scan);
      if (inserted == 0) {
        return _captureError('This scan could not be saved. Please try again.');
      }

      recentScanCache[duplicateKey] = now;
      await _refreshCounts(activeSession.eventId);

      final result = student == null
          ? ScanCaptureResult(
              success: true,
              unresolved: true,
              isDuplicate: false,
              kind: ScanResultKind.unresolved,
              title: 'Saved as unresolved scan.',
              detail: 'It will be checked during sync.',
            )
          : ScanCaptureResult(
              success: true,
              unresolved: false,
              isDuplicate: false,
              kind: ScanResultKind.success,
              title: student.name,
              detail: '${student.name} captured as ${scanType.label}.',
            );

      statusMessage = result.detail;
      errorMessage = null;
      errorDetails = null;
      lastScanResult = result;
      notifyListeners();
      return result;
    } catch (error) {
      return _captureError(ScannerErrorMapper.map(error).message);
    } finally {
      isSavingScan = false;
      notifyListeners();
    }
  }

  Future<void> syncPending() async {
    await _syncScans(includeFailed: true);
  }

  Future<void> retryFailedScans() async {
    final activeSession = session;
    if (activeSession == null) {
      errorMessage = 'Open a scanner session before syncing.';
      notifyListeners();
      return;
    }

    final failed = await _localDatabase.failedScans(activeSession.eventId);
    if (failed.isEmpty) {
      return;
    }

    await _localDatabase.resetFailedToPending(
      failed.map((scan) => scan.dedupKey).toList(),
    );
    await _refreshCounts(activeSession.eventId);
    notifyListeners();
    await _syncScans(includeFailed: true);
  }

  Future<void> _syncScans({required bool includeFailed}) async {
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
          'Device is offline. Sync will resume when network returns.';
      notifyListeners();
      return;
    }

    syncing = true;
    errorMessage = null;
    errorDetails = null;
    statusMessage = 'Uploading pending scans.';
    lastSyncAttempt = _nowFactory();
    notifyListeners();

    List<OfflineScan> batch = [];
    try {
      batch = await _localDatabase.unsyncedScans(
        activeSession.eventId,
        limit: AppConfig.syncBatchSize,
      );

      if (batch.isEmpty) {
        statusMessage = 'No pending scans to sync.';
        await _refreshCounts(activeSession.eventId);
        return;
      }

      await _localDatabase.markSyncing(
        batch.map((scan) => scan.dedupKey).toList(),
      );

      final response = await _apiClient.syncScans(
        session: activeSession,
        scans: batch,
      );

      final ingested = (response['ingested_count'] as num?)?.toInt();
      final confirmedCount = ingested ?? batch.length;
      final confirmedKeys = batch
          .take(confirmedCount.clamp(0, batch.length))
          .map((scan) => scan.dedupKey)
          .toList();

      if (confirmedKeys.isNotEmpty) {
        await _localDatabase.markSynced(confirmedKeys);
        lastSuccessfulSync = _nowFactory();
      }

      final unconfirmed = batch
          .where((scan) => !confirmedKeys.contains(scan.dedupKey))
          .map((scan) => scan.dedupKey)
          .toList();
      if (unconfirmed.isNotEmpty) {
        await _localDatabase.markFailed(
          unconfirmed,
          failureReason: 'validation_error',
        );
      }

      await _refreshCounts(activeSession.eventId);
      unresolvedLastSync = (response['unresolved_count'] as num?)?.toInt() ?? 0;
      statusMessage =
          'Uploaded ${confirmedKeys.length} scans. '
          '$unresolvedLastSync require server-side lookup.';

      await _localDatabase.upsertEventSummary(
        EventSummary(
          eventId: activeSession.eventId,
          title: eventTitle ?? 'Event',
          totalSynced: syncedCount,
          lastSyncedAt: lastSuccessfulSync,
        ),
      );
    } catch (error) {
      final mapped = ScannerErrorMapper.map(error);
      if (batch.isNotEmpty) {
        await _localDatabase.markFailed(
          batch.map((scan) => scan.dedupKey).toList(),
          failureReason: mapped.categoryKey,
        );
        await _refreshCounts(activeSession.eventId);
      }
      _setError(error);
      statusMessage = null;
    } finally {
      syncing = false;
      notifyListeners();
      await checkEventRetention();
    }
  }

  Future<void> checkEventRetention() async {
    final activeSession = session;
    if (activeSession == null) {
      return;
    }

    try {
      final response = await _apiClient.validateSession(activeSession);
      final event = response['event'] as Map<String, dynamic>?;
      final scannerSession =
          response['scanner_session'] as Map<String, dynamic>?;
      final participationScope =
          scannerSession?['participation_scope'] as Map<String, dynamic>?;
      final status = event?['status']?.toString();
      eventStatus = status;
      eventLifecyclePhase =
          event?['lifecycle_phase']?.toString() ?? eventStatus;
      scannerSessionStatus = scannerSession?['status']?.toString();
      participationScopeName =
          participationScope?['name']?.toString() ??
          activeSession.participationScopeName;
      scanWindowStart = _parseDateTime(scannerSession?['scan_window_start']);
      scanWindowEnd = _parseDateTime(scannerSession?['scan_window_end']);
      syncGraceEndsAt = _parseDateTime(scannerSession?['sync_grace_ends_at']);

      if (status != 'completed' && eventLifecyclePhase != 'completed') {
        notifyListeners();
        return;
      }

      await _refreshCounts(activeSession.eventId);

      if (pendingCount > 0 || failedCount > 0) {
        globalFeedback = const GlobalFeedback(
          kind: FeedbackKind.warning,
          message:
              'Event completed, but this device still has unsynced scans. '
              'Do not uninstall the app until all scans are synced.',
        );
        notifyListeners();
        return;
      }

      if (lastSuccessfulSync == null) {
        notifyListeners();
        return;
      }

      await _localDatabase.wipeRoster();
      rosterCount = 0;
      final wipedAt = _nowFactory();
      await _localDatabase.upsertEventSummary(
        EventSummary(
          eventId: activeSession.eventId,
          title: eventTitle ?? 'Event',
          totalSynced: syncedCount,
          lastSyncedAt: lastSuccessfulSync,
          wipedAt: wipedAt,
        ),
      );

      session = null;
      eventTitle = null;
      eventStatus = null;
      eventLifecyclePhase = null;
      scannerSessionStatus = null;
      participationScopeName = null;
      scanWindowStart = null;
      scanWindowEnd = null;
      syncGraceEndsAt = null;
      globalFeedback = const GlobalFeedback(
        kind: FeedbackKind.success,
        message:
            'Event completed. Local roster data has been removed from this device.',
      );
      statusMessage = null;
      notifyListeners();
    } catch (_) {
      // Retention checks are best-effort and should not block scanning.
    }
  }

  ScanCaptureResult _captureError(String message) {
    errorMessage = message;
    lastScanResult = ScanCaptureResult(
      success: false,
      unresolved: false,
      isDuplicate: false,
      kind: ScanResultKind.error,
      title: 'Scan not saved',
      detail: message,
    );
    notifyListeners();
    return lastScanResult!;
  }

  DateTime? _parseDateTime(Object? value) {
    if (value == null) return null;
    return DateTime.tryParse(value.toString());
  }

  bool _isInsideScanWindow() {
    final now = _nowFactory().toUtc();
    final start = scanWindowStart?.toUtc();
    final end = scanWindowEnd?.toUtc();
    if (start != null && now.isBefore(start)) {
      return false;
    }
    if (end != null && now.isAfter(end)) {
      return false;
    }
    return true;
  }

  String _sessionReadyMessage() {
    switch (eventLifecyclePhase) {
      case 'scheduled':
        final start = scanWindowStart;
        return start == null
            ? 'Event has not started yet.'
            : 'Event has not started yet. Scanning will be available at ${_formatTime(start)}.';
      case 'active':
        if (!_isInsideScanWindow()) {
          return _scanWindowBlockedMessage();
        }
        return 'Event active. Scanning enabled.';
      case 'ended':
      case 'evaluation_open':
      case 'evaluation_closed':
      case 'processing':
        return 'Event has ended. New scans are disabled. Please sync all pending scans.';
      case 'completed':
        return 'Event completed. Local data will be removed after scans are synced.';
      default:
        return 'Session ready. Hydrate roster before scanning offline.';
    }
  }

  String _scanBlockedMessage() {
    switch (eventLifecyclePhase) {
      case 'scheduled':
        final start = scanWindowStart;
        return start == null
            ? 'Event has not started yet.'
            : 'Event has not started yet. Scanning will be available at ${_formatTime(start)}.';
      case 'active':
        return _scanWindowBlockedMessage();
      case 'ended':
      case 'evaluation_open':
      case 'evaluation_closed':
      case 'processing':
        return 'Event has ended. New scans are disabled. Please sync all pending scans.';
      case 'completed':
        return 'Event completed. New scanner activity is closed.';
      default:
        return 'Scanning is not available for this event phase.';
    }
  }

  String _scanWindowBlockedMessage() {
    final now = _nowFactory().toUtc();
    final start = scanWindowStart;
    if (start != null && now.isBefore(start.toUtc())) {
      return 'Event has not started yet. Scanning will be available at ${_formatTime(start)}.';
    }
    final end = scanWindowEnd;
    if (end != null && now.isAfter(end.toUtc())) {
      return 'Event scan window has ended. Please sync all pending scans.';
    }
    return 'Scanning is not available for this event phase.';
  }

  String _formatTime(DateTime value) {
    final local = value.toLocal();
    final hour = local.hour > 12
        ? local.hour - 12
        : (local.hour == 0 ? 12 : local.hour);
    final minute = local.minute.toString().padLeft(2, '0');
    final suffix = local.hour >= 12 ? 'PM' : 'AM';
    return '$hour:$minute $suffix';
  }

  Future<List<OfflineScan>> recentScans({int limit = 100}) async {
    final activeSession = session;
    if (activeSession == null) return [];
    return _localDatabase.recentScans(activeSession.eventId, limit: limit);
  }

  Future<void> checkConnectivity() async {
    final result = await _networkStatusChecker.checkConnectivity();
    isOnline =
        !(result.length == 1 && result.contains(ConnectivityResult.none));
    notifyListeners();
  }
}

enum ScanResultKind { success, unresolved, duplicate, error }

class ScanCaptureResult {
  const ScanCaptureResult({
    required this.success,
    required this.unresolved,
    required this.isDuplicate,
    required this.kind,
    required this.title,
    required this.detail,
  });

  final bool success;
  final bool unresolved;
  final bool isDuplicate;
  final ScanResultKind kind;
  final String title;
  final String detail;
}
