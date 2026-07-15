import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:eaes_scanner/features/auth/data/auth_api_client.dart';
import 'package:eaes_scanner/features/auth/data/device_identity_provider.dart';
import 'package:eaes_scanner/features/auth/data/google_auth_gateway.dart';
import 'package:eaes_scanner/features/auth/domain/mobile_user.dart';
import 'package:eaes_scanner/features/auth/domain/scanner_assignment.dart';
import 'package:eaes_scanner/features/auth/presentation/auth_controller.dart';
import 'package:eaes_scanner/features/scanner/data/scanner_api_client.dart';
import 'package:eaes_scanner/features/scanner/data/scanner_contracts.dart';
import 'package:eaes_scanner/features/scanner/domain/event_schedule_day.dart';
import 'package:eaes_scanner/features/scanner/domain/event_summary.dart';
import 'package:eaes_scanner/features/scanner/domain/offline_scan.dart';
import 'package:eaes_scanner/features/scanner/domain/roster_student.dart';
import 'package:eaes_scanner/features/scanner/domain/scan_sync_status.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:eaes_scanner/features/scanner/presentation/scanner_controller.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  group('GoogleSignInGateway', () {
    test('omits client ID and hosted domain on Android by default', () async {
      final adapter = FakeGoogleSignInAdapter();
      final gateway = GoogleSignInGateway(
        adapter: adapter,
        platform: TargetPlatform.android,
        googleClientId: 'android-client-id',
        googleServerClientId: 'web-server-client-id',
      );

      await gateway.initialize();

      expect(adapter.clientId, isNull);
      expect(adapter.serverClientId, 'web-server-client-id');
      expect(adapter.hostedDomain, isNull);
    });

    test(
      'passes hosted domain on Android when explicitly configured',
      () async {
        final adapter = FakeGoogleSignInAdapter();
        final gateway = GoogleSignInGateway(
          adapter: adapter,
          platform: TargetPlatform.android,
          googleClientId: 'android-client-id',
          googleServerClientId: 'web-server-client-id',
          hostedDomain: 'usm.edu.ph',
        );

        await gateway.initialize();

        expect(adapter.clientId, isNull);
        expect(adapter.serverClientId, 'web-server-client-id');
        expect(adapter.hostedDomain, 'usm.edu.ph');
      },
    );

    test('treats Android canceled result as a quiet cancellation', () async {
      final adapter = FakeGoogleSignInAdapter(
        authenticateResults: [
          const GoogleSignInException(code: GoogleSignInExceptionCode.canceled),
        ],
      );
      final gateway = GoogleSignInGateway(
        adapter: adapter,
        platform: TargetPlatform.android,
        googleServerClientId: 'web-server-client-id',
        hostedDomain: 'usm.edu.ph',
      );

      await gateway.initialize();

      await expectLater(
        gateway.authenticate(),
        throwsA(isA<GoogleAuthCancelledException>()),
      );
      expect(adapter.initializeCalls, hasLength(1));
      expect(adapter.initializeCalls.first.hostedDomain, 'usm.edu.ph');
    });
  });

  group('AuthController Google login', () {
    test('sends Google ID token to API and persists scanner user', () async {
      SharedPreferences.setMockInitialValues({});
      FlutterSecureStorage.setMockInitialValues({});

      final preferences = await SharedPreferences.getInstance();
      final gateway = FakeGoogleAuthGateway(
        token: const GoogleAccountToken(idToken: 'google-id-token'),
      );
      final api = FakeAuthRemoteApi(
        loginResult: const AuthLoginResult(
          token: 'sanctum-token',
          user: MobileUser(
            id: 'user-1',
            name: 'Scanner Student',
            email: 's.scanner@usm.edu.ph',
            userType: 'student',
            roles: ['Student'],
          ),
        ),
        assignmentList: const [
          ScannerAssignment(
            id: 'assignment-1',
            status: 'active',
            activationAllowed: true,
            sessionAvailable: true,
            eventId: 'event-1',
            eventTitle: 'Foundation Day',
            eventStatus: 'approved',
            eventPhase: 'active',
          ),
        ],
      );
      final controller = AuthController(
        apiClient: api,
        secureStorage: const FlutterSecureStorage(),
        preferences: preferences,
        googleAuthGateway: gateway,
        deviceIdentityProvider: const FakeDeviceIdentityProvider('test-device'),
      );

      await controller.initialize();
      await controller.signInWithGoogle();

      expect(gateway.initialized, isTrue);
      expect(api.loginIdToken, 'google-id-token');
      expect(api.loginDeviceName, 'test-device');
      expect(api.assignmentsDeviceId, 'test-device');
      expect(controller.authToken, 'sanctum-token');
      expect(controller.user?.email, 's.scanner@usm.edu.ph');
      expect(controller.assignments, hasLength(1));
      expect(
        preferences.getString('eaes.mobile_user'),
        contains('Scanner Student'),
      );
    });

    test('maps Google sign-in failures to a friendly message', () async {
      SharedPreferences.setMockInitialValues({});
      FlutterSecureStorage.setMockInitialValues({});

      final preferences = await SharedPreferences.getInstance();
      final api = FakeAuthRemoteApi(
        loginResult: const AuthLoginResult(
          token: 'unused-token',
          user: MobileUser(
            id: 'user-1',
            name: 'Unused',
            email: 'unused@usm.edu.ph',
          ),
        ),
      );
      final controller = AuthController(
        apiClient: api,
        secureStorage: const FlutterSecureStorage(),
        preferences: preferences,
        googleAuthGateway: FakeGoogleAuthGateway(
          error: const GoogleAuthException(
            'Google sign in is not configured for this scanner build.',
          ),
        ),
        deviceIdentityProvider: const FakeDeviceIdentityProvider('test-device'),
      );

      await controller.initialize();
      await controller.signInWithGoogle();

      expect(api.loginIdToken, isNull);
      expect(controller.errorMessage, contains('not configured'));
      expect(controller.statusMessage, isNull);
    });

    test('quietly clears state when account selection is canceled', () async {
      SharedPreferences.setMockInitialValues({});
      FlutterSecureStorage.setMockInitialValues({});

      final preferences = await SharedPreferences.getInstance();
      final api = FakeAuthRemoteApi(
        loginResult: const AuthLoginResult(
          token: 'unused-token',
          user: MobileUser(
            id: 'user-1',
            name: 'Unused',
            email: 'unused@usm.edu.ph',
          ),
        ),
      );
      final controller = AuthController(
        apiClient: api,
        secureStorage: const FlutterSecureStorage(),
        preferences: preferences,
        googleAuthGateway: FakeGoogleAuthGateway(
          error: const GoogleAuthCancelledException(),
        ),
        deviceIdentityProvider: const FakeDeviceIdentityProvider('test-device'),
      );

      await controller.initialize();
      await controller.signInWithGoogle();

      expect(api.loginIdToken, isNull);
      expect(controller.errorMessage, isNull);
      expect(controller.statusMessage, isNull);
      expect(controller.busy, isFalse);
    });

    test('activation persists enriched scanner session snapshot', () async {
      SharedPreferences.setMockInitialValues({});
      FlutterSecureStorage.setMockInitialValues({});

      final preferences = await SharedPreferences.getInstance();
      final secureStorage = const FlutterSecureStorage();
      final assignment = ScannerAssignment(
        id: 'assignment-1',
        status: 'active',
        activationAllowed: true,
        sessionAvailable: true,
        eventId: 'event-1',
        eventTitle: 'Foundation Day',
        eventStatus: 'approved',
        eventPhase: 'active',
        eventDays: [
          EventScheduleDay(
            id: 'day-1',
            dayNumber: 1,
            date: DateTime.utc(2026, 7, 10),
            startTime: '08:00',
            endTime: '12:00',
          ),
        ],
      );
      final authApi = FakeAuthRemoteApi(
        loginResult: const AuthLoginResult(
          token: 'mobile-token',
          user: MobileUser(
            id: 'user-1',
            name: 'Scanner',
            email: 's@usm.edu.ph',
          ),
        ),
        assignmentList: [assignment],
        activatedSession: const ScannerSession(
          token: 'scanner-token',
          eventId: 'event-1',
          assignmentId: 'assignment-1',
        ),
      );
      final scannerController = ScannerController(
        localDatabase: AuthFakeScannerLocalStore(),
        apiClient: AuthFakeScannerRemoteApi(),
        deviceId: 'test-device',
        networkStatusChecker: const AuthFakeNetworkStatus(),
        nowFactory: () => DateTime.utc(2026, 7, 10, 9),
      );
      final controller = AuthController(
        apiClient: authApi,
        secureStorage: secureStorage,
        preferences: preferences,
        googleAuthGateway: FakeGoogleAuthGateway(),
        deviceIdentityProvider: const FakeDeviceIdentityProvider('test-device'),
      );
      controller.authToken = 'mobile-token';
      controller.user = const MobileUser(
        id: 'user-1',
        name: 'Scanner',
        email: 's@usm.edu.ph',
      );

      final activated = await controller.activateAssignment(
        assignment,
        scannerController,
      );
      final raw = await secureStorage.read(key: 'eaes.active_scanner_session');
      final snapshot = jsonDecode(raw!) as Map<String, dynamic>;

      expect(activated, isTrue);
      expect(authApi.activateDeviceId, 'test-device');
      expect(snapshot['token'], 'scanner-token');
      expect(snapshot['event_title'], 'Test Event');
      expect(snapshot['event_lifecycle_phase'], 'active');
      expect(snapshot['scan_window_end'], '2026-07-10T12:00:00.000Z');
      expect(snapshot['event_days'], isNotEmpty);
      expect((snapshot['event_days'] as List).first['start_time'], '08:00');
    });

    test(
      'offline restore keeps saved session after server/network failure',
      () async {
        SharedPreferences.setMockInitialValues({
          'eaes.mobile_user': jsonEncode(
            const MobileUser(
              id: 'user-1',
              name: 'Scanner',
              email: 's@usm.edu.ph',
            ).toJson(),
          ),
        });
        FlutterSecureStorage.setMockInitialValues({
          'eaes.mobile_auth_token': 'mobile-token',
          'eaes.active_scanner_session': jsonEncode({
            'token': 'scanner-token',
            'event_id': 'event-1',
            'assignment_id': 'assignment-1',
            'event_title': 'Cached Event',
            'event_lifecycle_phase': 'active',
            'scan_window_start': '2026-07-10T08:00:00Z',
            'scan_window_end': '2026-07-10T12:00:00Z',
            'saved_at': '2026-07-10T08:30:00Z',
          }),
        });

        final preferences = await SharedPreferences.getInstance();
        final secureStorage = const FlutterSecureStorage();
        final scannerController = ScannerController(
          localDatabase: AuthFakeScannerLocalStore(),
          apiClient: AuthFakeScannerRemoteApi(
            validateError: const ScannerApiException(
              'Server error',
              statusCode: 500,
            ),
          ),
          deviceId: 'test-device',
          networkStatusChecker: const AuthFakeNetworkStatus([
            ConnectivityResult.none,
          ]),
          nowFactory: () => DateTime.utc(2026, 7, 10, 9),
        );
        final controller = AuthController(
          apiClient: FakeAuthRemoteApi(
            loginResult: const AuthLoginResult(
              token: 'mobile-token',
              user: MobileUser(
                id: 'user-1',
                name: 'Scanner',
                email: 's@usm.edu.ph',
              ),
            ),
          ),
          secureStorage: secureStorage,
          preferences: preferences,
          googleAuthGateway: FakeGoogleAuthGateway(),
          deviceIdentityProvider: const FakeDeviceIdentityProvider(
            'test-device',
          ),
        );

        await controller.initialize();
        await controller.restoreScannerSession(scannerController);

        expect(scannerController.hasSession, isTrue);
        expect(scannerController.canCaptureScans, isTrue);
        expect(scannerController.statusMessage, contains('Offline mode'));
        expect(
          await secureStorage.read(key: 'eaes.active_scanner_session'),
          isNotNull,
        );
      },
    );

    test(
      'restore clears saved session after definitive invalid response',
      () async {
        SharedPreferences.setMockInitialValues({
          'eaes.mobile_user': jsonEncode(
            const MobileUser(
              id: 'user-1',
              name: 'Scanner',
              email: 's@usm.edu.ph',
            ).toJson(),
          ),
        });
        FlutterSecureStorage.setMockInitialValues({
          'eaes.mobile_auth_token': 'mobile-token',
          'eaes.active_scanner_session': jsonEncode({
            'token': 'scanner-token',
            'event_id': 'event-1',
            'event_lifecycle_phase': 'active',
            'saved_at': '2026-07-10T08:30:00Z',
          }),
        });

        final preferences = await SharedPreferences.getInstance();
        final secureStorage = const FlutterSecureStorage();
        final scannerController = ScannerController(
          localDatabase: AuthFakeScannerLocalStore(),
          apiClient: AuthFakeScannerRemoteApi(
            validateError: const ScannerApiException(
              'Unauthorized',
              statusCode: 401,
            ),
          ),
          deviceId: 'test-device',
          networkStatusChecker: const AuthFakeNetworkStatus(),
        );
        final controller = AuthController(
          apiClient: FakeAuthRemoteApi(
            loginResult: const AuthLoginResult(
              token: 'mobile-token',
              user: MobileUser(
                id: 'user-1',
                name: 'Scanner',
                email: 's@usm.edu.ph',
              ),
            ),
          ),
          secureStorage: secureStorage,
          preferences: preferences,
          googleAuthGateway: FakeGoogleAuthGateway(),
          deviceIdentityProvider: const FakeDeviceIdentityProvider(
            'test-device',
          ),
        );

        await controller.initialize();
        await controller.restoreScannerSession(scannerController);

        expect(scannerController.hasSession, isFalse);
        expect(
          await secureStorage.read(key: 'eaes.active_scanner_session'),
          isNull,
        );
      },
    );
  });
}

class FakeGoogleSignInAdapter implements GoogleSignInAdapter {
  FakeGoogleSignInAdapter({this.authenticateResults = const []});

  final List<Object> authenticateResults;
  final List<({String? clientId, String? serverClientId, String? hostedDomain})>
  initializeCalls = [];
  int authenticateCount = 0;

  String? clientId;
  String? serverClientId;
  String? hostedDomain;

  @override
  Future<void> initialize({
    String? clientId,
    String? serverClientId,
    String? hostedDomain,
  }) async {
    this.clientId = clientId;
    this.serverClientId = serverClientId;
    this.hostedDomain = hostedDomain;
    initializeCalls.add((
      clientId: clientId,
      serverClientId: serverClientId,
      hostedDomain: hostedDomain,
    ));
  }

  @override
  Future<GoogleAccountToken> authenticate() async {
    if (authenticateCount < authenticateResults.length) {
      final result = authenticateResults[authenticateCount++];
      if (result is GoogleAccountToken) {
        return result;
      }
      if (result is Exception) {
        throw result;
      }
    }

    return const GoogleAccountToken(idToken: 'adapter-token');
  }

  @override
  Future<void> signOut() async {}
}

class FakeGoogleAuthGateway implements GoogleAuthGateway {
  FakeGoogleAuthGateway({this.token, this.error});

  final GoogleAccountToken? token;
  final Object? error;
  bool initialized = false;

  @override
  Future<void> initialize() async {
    initialized = true;
  }

  @override
  Future<GoogleAccountToken> authenticate() async {
    final authError = error;
    if (authError != null) {
      throw authError;
    }

    return token ?? const GoogleAccountToken(idToken: 'fake-token');
  }

  @override
  Future<void> signOut() async {}
}

class FakeAuthRemoteApi implements AuthRemoteApi {
  FakeAuthRemoteApi({
    required this.loginResult,
    this.assignmentList = const [],
    this.activatedSession,
  });

  final AuthLoginResult loginResult;
  final List<ScannerAssignment> assignmentList;
  final ScannerSession? activatedSession;
  String? loginIdToken;
  String? loginDeviceName;
  String? assignmentsDeviceId;
  String? activateDeviceId;

  @override
  Future<AuthLoginResult> loginWithGoogle({
    required String idToken,
    required String deviceName,
  }) async {
    loginIdToken = idToken;
    loginDeviceName = deviceName;
    return loginResult;
  }

  @override
  Future<List<ScannerAssignment>> assignments({
    required String token,
    required String deviceId,
  }) async {
    assignmentsDeviceId = deviceId;
    return assignmentList;
  }

  @override
  Future<void> logout(String token) async {}

  @override
  Future<ScannerSession> activateAssignment({
    required String token,
    required ScannerAssignment assignment,
    required String deviceId,
  }) {
    activateDeviceId = deviceId;
    final session = activatedSession;
    if (session == null) {
      throw UnimplementedError();
    }
    return Future.value(session);
  }
}

class FakeDeviceIdentityProvider implements DeviceIdentityProvider {
  const FakeDeviceIdentityProvider(this.value);

  final String value;

  @override
  Future<String> deviceId() async {
    return value;
  }
}

class AuthFakeScannerRemoteApi implements ScannerRemoteApi {
  AuthFakeScannerRemoteApi({this.validateError});

  final Object? validateError;

  @override
  Future<Map<String, dynamic>> validateSession(ScannerSession session) async {
    final error = validateError;
    if (error != null) {
      throw error;
    }

    return {
      'event': {
        'id': session.eventId,
        'title': 'Test Event',
        'status': 'approved',
        'lifecycle_phase': 'active',
        'event_days': [
          {
            'id': 'day-1',
            'day_number': 1,
            'date': '2026-07-10',
            'start_time': '08:00',
            'end_time': '12:00',
          },
        ],
      },
      'scanner_session': {
        'id': 'scanner-session-1',
        'assignment_id': session.assignmentId,
        'event_participation_scope_id': 'scope-1',
        'status': 'active',
        'participation_scope': {'name': 'PSITS'},
        'scan_window_start': '2026-07-10T08:00:00Z',
        'scan_window_end': '2026-07-10T12:00:00Z',
        'sync_grace_ends_at': '2026-07-13T12:00:00Z',
      },
    };
  }

  @override
  Future<List<RosterStudent>> hydrateRoster(ScannerSession session) async {
    return const [
      RosterStudent(
        studentId: 'student-1',
        qrCodeValue: 'QR-1',
        name: 'Scanner Student',
        programCode: 'BSIS',
        studentIdNumber: '23-72634',
      ),
    ];
  }

  @override
  Future<Map<String, dynamic>> syncScans({
    required ScannerSession session,
    required List<OfflineScan> scans,
  }) async {
    return {'ingested_count': scans.length, 'unresolved_count': 0};
  }
}

class AuthFakeScannerLocalStore implements ScannerLocalStore {
  final List<RosterStudent> _roster = [];
  final List<OfflineScan> _scans = [];
  final Map<String, ScanSyncStatus> _statuses = {};

  @override
  Future<void> upsertRoster(List<RosterStudent> students) async {
    _roster.addAll(students);
  }

  @override
  Future<void> replaceRoster(List<RosterStudent> students) async {
    _roster
      ..clear()
      ..addAll(students);
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
  Future<RosterStudent?> findStudentByIdNumber(String studentIdNumber) async {
    for (final student in _roster) {
      if (student.studentIdNumber == studentIdNumber) {
        return student;
      }
    }
    return null;
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
  Future<int> insertScan(OfflineScan scan) async {
    _scans.add(scan);
    _statuses[scan.dedupKey] = ScanSyncStatus.pending;
    return 1;
  }

  @override
  Future<List<OfflineScan>> unsyncedScans(
    String eventId, {
    int limit = 200,
  }) async {
    return _scans.take(limit).toList();
  }

  @override
  Future<List<OfflineScan>> failedScans(
    String eventId, {
    int limit = 200,
  }) async {
    return const [];
  }

  @override
  Future<int> pendingCount(String eventId) async {
    return _scans
        .where((scan) => _statuses[scan.dedupKey] == ScanSyncStatus.pending)
        .length;
  }

  @override
  Future<int> syncedCount(String eventId) async => 0;

  @override
  Future<int> failedCount(String eventId) async => 0;

  @override
  Future<void> markSyncing(List<String> dedupKeys) async {}

  @override
  Future<void> markSynced(List<String> dedupKeys) async {}

  @override
  Future<void> markFailed(
    List<String> dedupKeys, {
    required String failureReason,
  }) async {}

  @override
  Future<void> resetFailedToPending(List<String> dedupKeys) async {}

  @override
  Future<List<OfflineScan>> recentScans(
    String eventId, {
    int limit = 100,
  }) async {
    return _scans.take(limit).toList();
  }

  @override
  Future<int> rosterCount() async => _roster.length;

  @override
  Future<void> wipeRoster() async {
    _roster.clear();
  }

  @override
  Future<void> upsertEventSummary(EventSummary summary) async {}

  @override
  Future<EventSummary?> eventSummary(String eventId) async => null;
}

class AuthFakeNetworkStatus implements NetworkStatusChecker {
  const AuthFakeNetworkStatus([this.results = const [ConnectivityResult.wifi]]);

  final List<ConnectivityResult> results;

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => results;
}
