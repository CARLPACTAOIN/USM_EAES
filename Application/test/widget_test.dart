import 'package:eaes_scanner/features/auth/data/auth_api_client.dart';
import 'package:eaes_scanner/features/auth/data/google_auth_gateway.dart';
import 'package:eaes_scanner/features/auth/domain/mobile_user.dart';
import 'package:eaes_scanner/features/auth/domain/scanner_assignment.dart';
import 'package:eaes_scanner/features/auth/presentation/auth_controller.dart';
import 'package:eaes_scanner/features/auth/presentation/screens/assigned_events_screen.dart';
import 'package:eaes_scanner/features/auth/presentation/screens/login_screen.dart';
import 'package:eaes_scanner/features/scanner/data/scanner_contracts.dart';
import 'package:eaes_scanner/features/scanner/domain/event_summary.dart';
import 'package:eaes_scanner/features/scanner/domain/offline_scan.dart';
import 'package:eaes_scanner/features/scanner/domain/roster_student.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:eaes_scanner/features/scanner/presentation/scanner_controller.dart';
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
      ..assignments = const [
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
    expect(
      flutterErrors.where(
        (details) =>
            details.exceptionAsString().contains('A RenderFlex overflowed'),
      ),
      isEmpty,
    );
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
    return null;
  }

  @override
  Future<RosterStudent?> findStudentByQr(String qrCodeValue) async {
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
  Future<void> replaceRoster(List<RosterStudent> students) async {}

  @override
  Future<void> resetFailedToPending(List<String> dedupKeys) async {}

  @override
  Future<int> rosterCount() async {
    return 0;
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
  Future<void> upsertRoster(List<RosterStudent> students) async {}

  @override
  Future<void> wipeRoster() async {}
}

class _FakeNetworkStatusChecker implements NetworkStatusChecker {
  @override
  Future<List<ConnectivityResult>> checkConnectivity() async {
    return const [ConnectivityResult.wifi];
  }
}
