import 'package:eaes_scanner/features/auth/data/auth_api_client.dart';
import 'package:eaes_scanner/features/auth/data/google_auth_gateway.dart';
import 'package:eaes_scanner/features/auth/domain/mobile_user.dart';
import 'package:eaes_scanner/features/auth/domain/scanner_assignment.dart';
import 'package:eaes_scanner/features/auth/presentation/auth_controller.dart';
import 'package:eaes_scanner/features/auth/presentation/screens/login_screen.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
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
  Future<List<ScannerAssignment>> assignments(String token) async {
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
