import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../../../config/app_config.dart';
import '../data/scanner_api_client.dart';
import '../data/scanner_contracts.dart';
import '../data/scanner_local_database.dart';
import '../domain/event_summary.dart';
import '../domain/event_schedule_day.dart';
import '../domain/offline_scan.dart';
import '../domain/roster_student.dart';
import '../domain/scan_sync_status.dart';
import '../domain/scan_type.dart';
import '../domain/scanner_error_mapper.dart';
import '../domain/scanner_session.dart';
import '../domain/student_id_validator.dart';

class ScannerController extends ChangeNotifier {
  static const Duration _sessionEarlyCheckInBuffer = Duration(hours: 1);
  static const Duration _sessionCheckoutGracePeriod = Duration(hours: 1);

  ScannerController({
    required ScannerLocalStore localDatabase,
    required ScannerRemoteApi apiClient,
    required String deviceId,
    NetworkStatusChecker? networkStatusChecker,
    String Function()? dedupIdFactory,
    DateTime Function()? nowFactory,
    Duration? duplicateCooldown,
    Future<void> Function(Duration)? syncRetryDelay,
    int maxSyncRetries = 2,
  }) : _localDatabase = localDatabase,
       _apiClient = apiClient,
       _deviceId = deviceId,
       _networkStatusChecker =
           networkStatusChecker ?? ConnectivityStatusChecker(),
       _dedupIdFactory = dedupIdFactory ?? (() => const Uuid().v4()),
       _nowFactory = nowFactory ?? DateTime.now,
       _syncRetryDelay =
           syncRetryDelay ?? ((duration) => Future<void>.delayed(duration)),
       _maxSyncRetries = maxSyncRetries,
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
  final Future<void> Function(Duration) _syncRetryDelay;
  final int _maxSyncRetries;

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
  List<EventScheduleDay> eventDays = const [];
  DateTime? scanWindowStart;
  DateTime? scanWindowEnd;
  DateTime? timeInWindowStart;
  DateTime? timeInWindowEnd;
  DateTime? timeOutWindowStart;
  DateTime? timeOutWindowEnd;
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

  bool get canCaptureScans => hasSession && _canCaptureForCurrentPhase();

  bool get lastSessionActivationFailureIsRecoverable =>
      lastSessionActivationErrorCategory == ScannerErrorCategory.networkError ||
      lastSessionActivationErrorCategory == ScannerErrorCategory.serverError;

  bool get syncGraceActive {
    final graceEnd = syncGraceEndsAt;
    return graceEnd != null && _nowFactory().toUtc().isBefore(graceEnd.toUtc());
  }

  String? get eventTimeRangeLabel {
    if (eventDays.isEmpty) return null;
    final first = eventDays.first;
    final last = eventDays.last;

    if (eventDays.length == 1 || first.dateLabel == last.dateLabel) {
      return first.timeRangeLabel;
    }

    return '${first.dateLabel} ${first.startTimeLabel ?? 'Start TBA'} - '
        '${last.dateLabel} ${last.endTimeLabel ?? 'End TBA'}';
  }

  String? get eventDaysDetailLabel {
    if (eventDays.isEmpty) return null;
    return eventDays.map((day) => day.compactLabel).join(' | ');
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
      eventDays: eventDays,
      scanWindowStart: scanWindowStart,
      scanWindowEnd: scanWindowEnd,
      timeInWindowStart: timeInWindowStart,
      timeInWindowEnd: timeInWindowEnd,
      timeOutWindowStart: timeOutWindowStart,
      timeOutWindowEnd: timeOutWindowEnd,
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
    eventDays = snapshot.eventDays;
    participationScopeName =
        snapshot.participationScopeName ??
        snapshot.session.participationScopeName;
    scanWindowStart = snapshot.scanWindowStart;
    scanWindowEnd = snapshot.scanWindowEnd;
    timeInWindowStart = snapshot.timeInWindowStart ?? snapshot.scanWindowStart;
    timeInWindowEnd = snapshot.timeInWindowEnd ?? snapshot.scanWindowEnd;
    timeOutWindowStart =
        snapshot.timeOutWindowStart ?? snapshot.scanWindowStart;
    timeOutWindowEnd = snapshot.timeOutWindowEnd ?? snapshot.scanWindowEnd;
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
        eventDays: eventScheduleDaysFromJson(event?['event_days']),
      );
      eventTitle = event?['title']?.toString();
      eventStatus = event?['status']?.toString();
      eventLifecyclePhase =
          event?['lifecycle_phase']?.toString() ?? eventStatus;
      scannerSessionStatus = scannerSession?['status']?.toString();
      participationScopeName =
          participationScope?['name']?.toString() ??
          nextSession.participationScopeName;
      eventDays = session?.eventDays.isNotEmpty == true
          ? session!.eventDays
          : nextSession.eventDays;
      _applyScanWindows(scannerSession);
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
      eventDays = const [];
      scanWindowStart = null;
      scanWindowEnd = null;
      timeInWindowStart = null;
      timeInWindowEnd = null;
      timeOutWindowStart = null;
      timeOutWindowEnd = null;
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

  Future<List<RosterStudent>> rosterStudents({
    String? query,
    int limit = 100,
    int offset = 0,
  }) {
    return _localDatabase.rosterStudents(
      query: query,
      limit: limit,
      offset: offset,
    );
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
    final now = _nowFactory().toUtc();
    final matchedSchedule = _matchingScheduleForScan(now, scanType);

    final duplicateKey = _duplicateKey(
      eventId: activeSession.eventId,
      eventParticipationScopeId: activeSession.eventParticipationScopeId,
      eventDayId: matchedSchedule?.dayId,
      value: lookupValue,
      type: scanType,
    );

    final lastScanAt = recentScanCache[duplicateKey];
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
        eventDayId: matchedSchedule?.dayId,
        eventDaySessionId: matchedSchedule?.sessionId,
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

  _MatchedSchedule? _matchingScheduleForScan(
    DateTime scannedAt,
    ScanType type,
  ) {
    final candidates = <_MatchedSchedule>[];

    for (final day in eventDays) {
      final dayDate = day.date;
      if (dayDate == null) {
        continue;
      }

      final sessions = day.sessions.isNotEmpty
          ? day.sessions
          : [
              EventScheduleSession(
                id: null,
                name: 'Session ${day.dayNumber}',
                startTime: day.startTime,
                endTime: day.endTime,
              ),
            ];

      for (final session in sessions) {
        final start = _combineDateAndTime(dayDate, session.startTime);
        final end = _combineDateAndTime(dayDate, session.endTime);
        if (start == null || end == null) {
          continue;
        }

        final windowStart = type == ScanType.timeIn
            ? start.subtract(_sessionEarlyCheckInBuffer)
            : start;
        final windowEnd = type == ScanType.timeOut
            ? end.add(_sessionCheckoutGracePeriod)
            : end;

        if (scannedAt.isBefore(windowStart.toUtc()) ||
            scannedAt.isAfter(windowEnd.toUtc())) {
          continue;
        }

        candidates.add(
          _MatchedSchedule(
            dayId: day.id,
            sessionId: session.id,
            startsAt: start.toUtc(),
            endsAt: end.toUtc(),
          ),
        );
      }
    }

    if (candidates.isEmpty) {
      return null;
    }

    candidates.sort((a, b) {
      if (type == ScanType.timeIn) {
        final aUpcoming = !a.startsAt.isBefore(scannedAt);
        final bUpcoming = !b.startsAt.isBefore(scannedAt);
        if (aUpcoming != bUpcoming) {
          return aUpcoming ? -1 : 1;
        }

        return _absoluteDifference(
          a.startsAt,
          scannedAt,
        ).compareTo(_absoluteDifference(b.startsAt, scannedAt));
      }

      return _absoluteDifference(
        a.endsAt,
        scannedAt,
      ).compareTo(_absoluteDifference(b.endsAt, scannedAt));
    });

    return candidates.first;
  }

  DateTime? _combineDateAndTime(DateTime date, String? time) {
    if (time == null || time.trim().isEmpty) {
      return null;
    }

    final parts = time.split(':');
    if (parts.length < 2) {
      return null;
    }

    final hour = int.tryParse(parts[0]);
    final minute = int.tryParse(parts[1]);
    if (hour == null || minute == null) {
      return null;
    }

    return DateTime(date.year, date.month, date.day, hour, minute);
  }

  int _absoluteDifference(DateTime first, DateTime second) {
    return first.difference(second).abs().inSeconds;
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

      final response = await _syncBatchWithRetries(activeSession, batch);

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

  Future<Map<String, dynamic>> _syncBatchWithRetries(
    ScannerSession activeSession,
    List<OfflineScan> batch,
  ) async {
    for (
      var retryAttempt = 0;
      retryAttempt <= _maxSyncRetries;
      retryAttempt++
    ) {
      try {
        return await _apiClient.syncScans(session: activeSession, scans: batch);
      } catch (error) {
        if (!_isRetryableSyncError(error) || retryAttempt == _maxSyncRetries) {
          rethrow;
        }

        final delay = _retryDelayFor(error, retryAttempt);
        statusMessage =
            'Server is temporarily unavailable. Retrying sync shortly.';
        notifyListeners();
        await _syncRetryDelay(delay);
      }
    }

    throw StateError('Sync retry loop completed without a response.');
  }

  bool _isRetryableSyncError(Object error) {
    if (error is ScannerApiException) {
      return error.statusCode == 429 || error.statusCode == 503;
    }

    return ScannerErrorMapper.map(error).category ==
        ScannerErrorCategory.networkError;
  }

  Duration _retryDelayFor(Object error, int retryAttempt) {
    if (error is ScannerApiException && error.retryAfter != null) {
      return error.retryAfter!.inSeconds > 30
          ? const Duration(seconds: 30)
          : error.retryAfter!;
    }

    return retryAttempt == 0
        ? const Duration(seconds: 2)
        : const Duration(seconds: 4);
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
      eventTitle = event?['title']?.toString() ?? eventTitle;
      eventStatus = status;
      eventLifecyclePhase =
          event?['lifecycle_phase']?.toString() ?? eventStatus;
      scannerSessionStatus = scannerSession?['status']?.toString();
      participationScopeName =
          participationScope?['name']?.toString() ??
          activeSession.participationScopeName;
      final updatedEventDays = eventScheduleDaysFromJson(event?['event_days']);
      if (updatedEventDays.isNotEmpty) {
        eventDays = updatedEventDays;
      }
      _applyScanWindows(scannerSession);
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
      eventDays = const [];
      scanWindowStart = null;
      scanWindowEnd = null;
      timeInWindowStart = null;
      timeInWindowEnd = null;
      timeOutWindowStart = null;
      timeOutWindowEnd = null;
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

  void _applyScanWindows(Map<String, dynamic>? scannerSession) {
    scanWindowStart = _parseDateTime(scannerSession?['scan_window_start']);
    scanWindowEnd = _parseDateTime(scannerSession?['scan_window_end']);
    timeInWindowStart =
        _parseDateTime(scannerSession?['time_in_window_start']) ??
        scanWindowStart;
    timeInWindowEnd =
        _parseDateTime(scannerSession?['time_in_window_end']) ?? scanWindowEnd;
    timeOutWindowStart =
        _parseDateTime(scannerSession?['time_out_window_start']) ??
        scanWindowStart;
    timeOutWindowEnd =
        _parseDateTime(scannerSession?['time_out_window_end']) ?? scanWindowEnd;
  }

  DateTime? _windowStartFor(ScanType type) {
    return type == ScanType.timeIn ? timeInWindowStart : timeOutWindowStart;
  }

  DateTime? _windowEndFor(ScanType type) {
    return type == ScanType.timeIn ? timeInWindowEnd : timeOutWindowEnd;
  }

  bool _isInsideScanWindow(ScanType type) {
    final now = _nowFactory().toUtc();
    final start = _windowStartFor(type)?.toUtc();
    final end = _windowEndFor(type)?.toUtc();
    if (start != null && now.isBefore(start)) {
      return false;
    }
    if (end != null && now.isAfter(end)) {
      return false;
    }
    return true;
  }

  bool _canCaptureForCurrentPhase() {
    return switch (eventLifecyclePhase) {
      'scheduled' =>
        !_missingScheduledWindowMetadata() && _isInsideCurrentModeEventWindow(),
      'active' => _isInsideCurrentModeEventWindow(),
      'ended' || 'evaluation_open' || 'evaluation_closed' || 'processing' =>
        scanType == ScanType.timeOut &&
            !_missingScheduledWindowMetadata() &&
            _isInsideCurrentModeEventWindow(),
      _ => false,
    };
  }

  bool _missingScheduledWindowMetadata() {
    return eventDays.isEmpty &&
        _windowStartFor(scanType) == null &&
        _windowEndFor(scanType) == null;
  }

  bool _isInsideCurrentModeEventWindow() {
    if (eventDays.isEmpty) {
      return _isInsideScanWindow(scanType);
    }

    final now = _nowFactory().toLocal();
    final earlyCheckinBuffer = _earlyCheckinBuffer();
    final checkoutBuffer = _checkoutBuffer();

    for (final day in eventDays) {
      final date = day.date;
      final start = _eventDayDateTime(date, day.startTime);
      final end = _eventDayDateTime(date, day.endTime);
      if (start == null || end == null) {
        continue;
      }

      final windowStart = scanType == ScanType.timeIn
          ? start.subtract(earlyCheckinBuffer)
          : start;
      final windowEnd = scanType == ScanType.timeIn
          ? end
          : end.add(checkoutBuffer);

      if (!now.isBefore(windowStart) && !now.isAfter(windowEnd)) {
        return true;
      }
    }

    return false;
  }

  Duration _earlyCheckinBuffer() {
    final firstStart = _eventDayBounds().$1;
    final firstWindowStart = timeInWindowStart?.toLocal();
    if (firstStart == null || firstWindowStart == null) {
      return Duration.zero;
    }

    final buffer = firstStart.difference(firstWindowStart);
    return buffer.isNegative ? Duration.zero : buffer;
  }

  Duration _checkoutBuffer() {
    final finalEnd = _eventDayBounds().$2;
    final finalWindowEnd = timeOutWindowEnd?.toLocal();
    if (finalEnd == null || finalWindowEnd == null) {
      return Duration.zero;
    }

    final buffer = finalWindowEnd.difference(finalEnd);
    return buffer.isNegative ? Duration.zero : buffer;
  }

  (DateTime?, DateTime?) _eventDayBounds() {
    final starts = <DateTime>[];
    final ends = <DateTime>[];

    for (final day in eventDays) {
      final date = day.date;
      final start = _eventDayDateTime(date, day.startTime);
      final end = _eventDayDateTime(date, day.endTime);
      if (start != null) starts.add(start);
      if (end != null) ends.add(end);
    }

    starts.sort();
    ends.sort();

    return (
      starts.isEmpty ? null : starts.first,
      ends.isEmpty ? null : ends.last,
    );
  }

  DateTime? _eventDayDateTime(DateTime? date, String? time) {
    if (date == null || time == null || time.trim().isEmpty) {
      return null;
    }

    final parts = time.split(':');
    if (parts.length < 2) {
      return null;
    }

    final hour = int.tryParse(parts[0]);
    final minute = int.tryParse(parts[1]);
    if (hour == null || minute == null) {
      return null;
    }

    final localDate = date.toLocal();
    return DateTime(
      localDate.year,
      localDate.month,
      localDate.day,
      hour,
      minute,
    );
  }

  String _sessionReadyMessage() {
    switch (eventLifecyclePhase) {
      case 'scheduled':
        if (_canCaptureForCurrentPhase()) {
          return '${scanType.label} scanning enabled.';
        }
        final start = _windowStartFor(scanType);
        return start == null
            ? 'Event has not started yet.'
            : '${scanType.label} will be available at ${_formatAvailabilityDateTime(start)}.';
      case 'active':
        if (!_isInsideCurrentModeEventWindow()) {
          return _scanWindowBlockedMessage();
        }
        return '${scanType.label} scanning enabled.';
      case 'ended':
      case 'evaluation_open':
      case 'evaluation_closed':
      case 'processing':
        if (_canCaptureForCurrentPhase()) {
          return 'Time-Out scanning enabled for post-event checkout.';
        }
        return scanType == ScanType.timeOut
            ? _scanWindowBlockedMessage()
            : 'Event has ended. Time-In is closed. Switch to Time-Out for checkout if needed.';
      case 'completed':
        return 'Event completed. Local data will be removed after scans are synced.';
      default:
        return 'Session ready. Hydrate roster before scanning offline.';
    }
  }

  String _scanBlockedMessage() {
    switch (eventLifecyclePhase) {
      case 'scheduled':
        if (_canCaptureForCurrentPhase()) {
          return '${scanType.label} scanning enabled.';
        }
        final start = _windowStartFor(scanType);
        return start == null
            ? 'Event has not started yet.'
            : '${scanType.label} will be available at ${_formatAvailabilityDateTime(start)}.';
      case 'active':
        return _scanWindowBlockedMessage();
      case 'ended':
      case 'evaluation_open':
      case 'evaluation_closed':
      case 'processing':
        return scanType == ScanType.timeOut
            ? _scanWindowBlockedMessage()
            : 'Event has ended. Time-In is closed. Switch to Time-Out for checkout if needed.';
      case 'completed':
        return 'Event completed. New scanner activity is closed.';
      default:
        return 'Scanning is not available for this event phase.';
    }
  }

  String _scanWindowBlockedMessage() {
    final now = _nowFactory().toUtc();
    final start = _windowStartFor(scanType);
    if (start != null && now.isBefore(start.toUtc())) {
      return scanType == ScanType.timeOut
          ? 'Time-Out will be available when the event starts.'
          : 'Time-In will be available at ${_formatAvailabilityDateTime(start)}.';
    }
    final end = _windowEndFor(scanType);
    if (end != null && now.isAfter(end.toUtc())) {
      return scanType == ScanType.timeOut
          ? 'Time-Out checkout window has ended. Please sync all pending scans.'
          : 'Time-In is closed for this event.';
    }
    return 'Scanning is not available for this event phase.';
  }

  String _formatAvailabilityDateTime(DateTime value) {
    final local = value.toLocal();
    final today = _nowFactory().toLocal();
    if (local.year == today.year &&
        local.month == today.month &&
        local.day == today.day) {
      return _formatTime(local);
    }

    return '${_monthName(local.month)} ${local.day}, ${_formatTime(local)}';
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

  String _monthName(int month) {
    const months = [
      'January',
      'February',
      'March',
      'April',
      'May',
      'June',
      'July',
      'August',
      'September',
      'October',
      'November',
      'December',
    ];

    if (month < 1 || month > 12) return 'Date';
    return months[month - 1];
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

class _MatchedSchedule {
  const _MatchedSchedule({
    required this.dayId,
    required this.sessionId,
    required this.startsAt,
    required this.endsAt,
  });

  final String? dayId;
  final String? sessionId;
  final DateTime startsAt;
  final DateTime endsAt;
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
