import 'package:eaes_scanner/features/auth/data/auth_api_client.dart';
import 'package:eaes_scanner/features/auth/data/google_auth_gateway.dart';
import 'package:eaes_scanner/features/auth/domain/mobile_user.dart';
import 'package:eaes_scanner/features/auth/domain/scanner_assignment.dart';
import 'package:eaes_scanner/features/auth/presentation/auth_controller.dart';
import 'package:eaes_scanner/features/auth/presentation/screens/assigned_events_screen.dart';
import 'package:eaes_scanner/features/auth/presentation/screens/login_screen.dart';
import 'package:eaes_scanner/features/scanner/data/scanner_contracts.dart';
import 'package:eaes_scanner/features/scanner/domain/event_schedule_day.dart';
import 'package:eaes_scanner/features/scanner/domain/event_summary.dart';
import 'package:eaes_scanner/features/scanner/domain/offline_scan.dart';
import 'package:eaes_scanner/features/scanner/domain/roster_student.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:eaes_scanner/features/scanner/presentation/scanner_controller.dart';
import 'package:eaes_scanner/features/scanner/presentation/widgets/event_ready_card.dart';
import 'package:eaes_scanner/features/scanner/presentation/screens/hydrated_roster_screen.dart';
import 'package:eaes_scanner/features/scanner/presentation/screens/session_setup_screen.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  testWidgets('shows scanner login shell', (WidgetTester tester) async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final controller = AuthController(
      apiClient: _FakeAuthRemoteApi(),
      secureStorage: const FlutterSecureStorage(),
      preferences: preferences,
      googleAuthGateway: _FakeGoogleAuthGateway(),
    );

    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(controller: controller)),
    );

    expect(find.text('USM EAES'), findsOneWidget);
    expect(find.text('SCANNER'), findsOneWidget);
    expect(find.text('Sign in with Google'), findsOneWidget);
  });

  testWidgets('assigned event card handles long title and scope on narrow screens', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final authController = AuthController(
      apiClient: _FakeAuthRemoteApi(),
      secureStorage: const FlutterSecureStorage(),
      preferences: preferences,
      googleAuthGateway: _FakeGoogleAuthGateway(),
    );
    authController
      ..authToken = 'mobile-token'
      ..user = const MobileUser(
        id: 'user-1',
        name: 'Scanner User',
        email: 'scanner@usm.edu.ph',
      )
      ..initialized = true
      ..assignments = [
        ScannerAssignment(
          id: 'assignment-1',
          status: 'active',
          activationAllowed: true,
          sessionAvailable: true,
          eventId: 'event-1',
          eventTitle:
              'PSITS Development Seminar and Information Technology Leadership Workshop 2026',
          eventStatus: 'approved',
          eventPhase: 'scheduled',
          eventParticipationScopeId: 'scope-1',
          participationScopeName:
              'PSITS – Philippine Society of Information Technology Students',
          eventDays: [
            EventScheduleDay(
              id: 'day-1',
              dayNumber: 1,
              date: DateTime.utc(2026, 7, 10),
              startTime: '08:00',
              endTime: '12:00',
            ),
          ],
          label: 'Event scanner',
          organizationAcronym: 'PSITS',
        ),
      ];

    final scannerController = ScannerController(
      localDatabase: _FakeScannerLocalStore(),
      apiClient: _FakeScannerRemoteApi(),
      deviceId: 'test-device',
      networkStatusChecker: _FakeNetworkStatusChecker(),
    );

    final flutterErrors = <FlutterErrorDetails>[];
    final previousOnError = FlutterError.onError;
    FlutterError.onError = flutterErrors.add;
    addTearDown(() {
      FlutterError.onError = previousOnError;
      scannerController.dispose();
    });

    await tester.pumpWidget(
      MaterialApp(
        home: AssignedEventsScreen(
          authController: authController,
          scannerController: scannerController,
        ),
      ),
    );
    await tester.pump(const Duration(seconds: 1));

    expect(
      find.text(
        'PSITS Development Seminar and Information Technology Leadership Workshop 2026',
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        'Scope: PSITS – Philippine Society of Information Technology Students',
      ),
      findsOneWidget,
    );
    expect(find.text('8:00 AM - 12:00 PM'), findsOneWidget);
    expect(
      flutterErrors.where(
        (details) =>
            details.exceptionAsString().contains('A RenderFlex overflowed'),
      ),
      isEmpty,
    );
  });

  testWidgets('hydrated roster screen shows local rows without QR values', (
    WidgetTester tester,
  ) async {
    final localStore = _FakeScannerLocalStore(
      roster: const [
        RosterStudent(
          studentId: 'student-1',
          qrCodeValue: 'QR-ALPHA',
          name: 'Alpha Student',
          programCode: 'BSIS',
          studentIdNumber: '23-72634',
        ),
      ],
    );
    final scannerController = ScannerController(
      localDatabase: localStore,
      apiClient: _FakeScannerRemoteApi(),
      deviceId: 'test-device',
      networkStatusChecker: _FakeNetworkStatusChecker(),
    );
    addTearDown(scannerController.dispose);

    await scannerController.restoreCachedSession(
      ScannerSessionSnapshot(
        token: 'scanner-token',
        eventId: 'event-1',
        eventParticipationScopeId: 'scope-1',
        participationScopeName: 'PSITS',
        eventTitle: 'Development Seminar',
        eventLifecyclePhase: 'active',
        scannerSessionStatus: 'active',
        savedAt: DateTime.utc(2026, 7, 10, 8),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: SessionSetupScreen(
          controller: scannerController,
          onReturnToAssignments: () {},
          onHydrate: scannerController.hydrateRoster,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('View hydrated roster'), findsOneWidget);
    await tester.tap(find.text('View hydrated roster'));
    await tester.pumpAndSettle();

    expect(find.text('Hydrated roster'), findsOneWidget);
    expect(find.text('1 hydrated student'), findsOneWidget);
    expect(find.text('PSITS'), findsOneWidget);
    expect(find.text('Alpha Student'), findsOneWidget);
    expect(find.text('BSIS - 23-72634'), findsOneWidget);
    expect(find.textContaining('QR-ALPHA'), findsNothing);

    await tester.enterText(find.byType(TextField), 'no match');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();

    expect(find.text('No matching students'), findsOneWidget);
  });

  testWidgets('empty hydrated roster view prompts hydration', (
    WidgetTester tester,
  ) async {
    final scannerController = ScannerController(
      localDatabase: _FakeScannerLocalStore(),
      apiClient: _FakeScannerRemoteApi(),
      deviceId: 'test-device',
      networkStatusChecker: _FakeNetworkStatusChecker(),
    );
    addTearDown(scannerController.dispose);

    await scannerController.restoreCachedSession(
      ScannerSessionSnapshot(
        token: 'scanner-token',
        eventId: 'event-1',
        eventLifecyclePhase: 'active',
        scannerSessionStatus: 'active',
        savedAt: DateTime.utc(2026, 7, 10, 8),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(home: HydratedRosterScreen(controller: scannerController)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Roster not downloaded'), findsOneWidget);
    expect(find.text('Hydrate Roster'), findsOneWidget);
  });

  testWidgets('active scanner card renders event schedule', (
    WidgetTester tester,
  ) async {
    final scannerController = ScannerController(
      localDatabase: _FakeScannerLocalStore(),
      apiClient: _FakeScannerRemoteApi(),
      deviceId: 'test-device',
      networkStatusChecker: _FakeNetworkStatusChecker(),
    );
    addTearDown(scannerController.dispose);

    await scannerController.restoreCachedSession(
      ScannerSessionSnapshot(
        token: 'scanner-token',
        eventId: 'event-1',
        eventTitle: 'Development Seminar',
        eventLifecyclePhase: 'active',
        scannerSessionStatus: 'active',
        eventDays: [
          EventScheduleDay(
            id: 'day-1',
            dayNumber: 1,
            date: DateTime.utc(2026, 7, 10),
            startTime: '08:00',
            endTime: '12:00',
          ),
        ],
        savedAt: DateTime.utc(2026, 7, 10, 8),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: EventReadyCard(controller: scannerController)),
      ),
    );

    expect(find.text('8:00 AM - 12:00 PM'), findsOneWidget);
    expect(find.textContaining('D1: Jul 10, 2026'), findsOneWidget);
  });
}

class _FakeGoogleAuthGateway implements GoogleAuthGateway {
  @override
  Future<void> initialize() async {}

  @override
  Future<GoogleAccountToken> authenticate() async {
    return const GoogleAccountToken(idToken: 'google-id-token');
  }

  @override
  Future<void> signOut() async {}
}

class _FakeAuthRemoteApi implements AuthRemoteApi {
  @override
  Future<AuthLoginResult> loginWithGoogle({
    required String idToken,
    required String deviceName,
  }) async {
    return const AuthLoginResult(
      token: 'mobile-token',
      user: MobileUser(
        id: 'user-1',
        name: 'Scanner User',
        email: 'scanner@usm.edu.ph',
      ),
    );
  }

  @override
  Future<List<ScannerAssignment>> assignments({
    required String token,
    required String deviceId,
  }) async {
    return const [];
  }

  @override
  Future<ScannerSession> activateAssignment({
    required String token,
    required ScannerAssignment assignment,
    required String deviceId,
  }) async {
    return ScannerSession(token: token, eventId: assignment.eventId);
  }

  @override
  Future<void> logout(String token) async {}
}

class _FakeScannerRemoteApi implements ScannerRemoteApi {
  @override
  Future<List<RosterStudent>> hydrateRoster(ScannerSession session) async {
    return const [];
  }

  @override
  Future<Map<String, dynamic>> syncScans({
    required ScannerSession session,
    required List<OfflineScan> scans,
  }) async {
    return const {};
  }

  @override
  Future<Map<String, dynamic>> validateSession(ScannerSession session) async {
    return const {};
  }
}

class _FakeScannerLocalStore implements ScannerLocalStore {
  _FakeScannerLocalStore({List<RosterStudent> roster = const []})
    : _roster = List.of(roster);

  final List<RosterStudent> _roster;

  @override
  Future<EventSummary?> eventSummary(String eventId) async {
    return null;
  }

  @override
  Future<int> failedCount(String eventId) async {
    return 0;
  }

  @override
  Future<List<OfflineScan>> failedScans(
    String eventId, {
    int limit = 200,
  }) async {
    return const [];
  }

  @override
  Future<RosterStudent?> findStudentByIdNumber(String studentIdNumber) async {
    for (final student in _roster) {
      if (student.studentIdNumber == studentIdNumber) {
        return student;
      }
    }
    return null;
  }

  @override
  Future<RosterStudent?> findStudentByQr(String qrCodeValue) async {
    for (final student in _roster) {
      if (student.qrCodeValue == qrCodeValue) {
        return student;
      }
    }
    return null;
  }

  @override
  Future<int> insertScan(OfflineScan scan) async {
    return 1;
  }

  @override
  Future<void> markFailed(
    List<String> dedupKeys, {
    required String failureReason,
  }) async {}

  @override
  Future<void> markSynced(List<String> dedupKeys) async {}

  @override
  Future<void> markSyncing(List<String> dedupKeys) async {}

  @override
  Future<int> pendingCount(String eventId) async {
    return 0;
  }

  @override
  Future<List<OfflineScan>> recentScans(
    String eventId, {
    int limit = 100,
  }) async {
    return const [];
  }

  @override
  Future<void> replaceRoster(List<RosterStudent> students) async {
    _roster
      ..clear()
      ..addAll(students);
  }

  @override
  Future<void> resetFailedToPending(List<String> dedupKeys) async {}

  @override
  Future<int> rosterCount() async {
    return _roster.length;
  }

  @override
  Future<List<RosterStudent>> rosterStudents({
    String? query,
    int limit = 100,
    int offset = 0,
  }) async {
    final trimmed = query?.trim().toLowerCase();
    final rows =
        _roster.where((student) {
          if (trimmed == null || trimmed.isEmpty) {
            return true;
          }

          return student.name.toLowerCase().contains(trimmed) ||
              (student.programCode ?? '').toLowerCase().contains(trimmed) ||
              (student.studentIdNumber ?? '').toLowerCase().contains(trimmed);
        }).toList()..sort(
          (a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()),
        );

    final normalizedOffset = offset < 0 ? 0 : offset;
    final normalizedLimit = limit < 1 ? 1 : limit;
    return rows.skip(normalizedOffset).take(normalizedLimit).toList();
  }

  @override
  Future<int> syncedCount(String eventId) async {
    return 0;
  }

  @override
  Future<List<OfflineScan>> unsyncedScans(
    String eventId, {
    int limit = 200,
  }) async {
    return const [];
  }

  @override
  Future<void> upsertEventSummary(EventSummary summary) async {}

  @override
  Future<void> upsertRoster(List<RosterStudent> students) async {
    _roster.addAll(students);
  }

  @override
  Future<void> wipeRoster() async {
    _roster.clear();
  }
}

class _FakeNetworkStatusChecker implements NetworkStatusChecker {
  @override
  Future<List<ConnectivityResult>> checkConnectivity() async {
    return const [ConnectivityResult.wifi];
  }
}
