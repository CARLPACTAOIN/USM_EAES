import 'package:eaes_scanner/features/auth/data/auth_api_client.dart';
import 'package:eaes_scanner/features/auth/data/google_auth_gateway.dart';
import 'package:eaes_scanner/features/auth/domain/mobile_user.dart';
import 'package:eaes_scanner/features/auth/domain/scanner_assignment.dart';
import 'package:eaes_scanner/features/auth/presentation/auth_controller.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
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
      );

      await controller.initialize();
      await controller.signInWithGoogle();

      expect(gateway.initialized, isTrue);
      expect(api.loginIdToken, 'google-id-token');
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
      );

      await controller.initialize();
      await controller.signInWithGoogle();

      expect(api.loginIdToken, isNull);
      expect(controller.errorMessage, isNull);
      expect(controller.statusMessage, isNull);
      expect(controller.busy, isFalse);
    });
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
  });

  final AuthLoginResult loginResult;
  final List<ScannerAssignment> assignmentList;
  String? loginIdToken;

  @override
  Future<AuthLoginResult> loginWithGoogle({
    required String idToken,
    required String deviceName,
  }) async {
    loginIdToken = idToken;
    return loginResult;
  }

  @override
  Future<List<ScannerAssignment>> assignments(String token) async {
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
    throw UnimplementedError();
  }
}
