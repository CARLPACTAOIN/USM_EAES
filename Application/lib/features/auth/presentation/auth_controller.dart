import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../config/app_config.dart';
import '../../scanner/presentation/scanner_controller.dart';
import '../../scanner/domain/scanner_session.dart';
import '../data/auth_api_client.dart';
import '../data/device_identity_provider.dart';
import '../data/google_auth_gateway.dart';
import '../domain/mobile_user.dart';
import '../domain/scanner_assignment.dart';

class AuthController extends ChangeNotifier {
  AuthController({
    required AuthRemoteApi apiClient,
    required FlutterSecureStorage secureStorage,
    SharedPreferences? preferences,
    GoogleAuthGateway? googleAuthGateway,
    DeviceIdentityProvider? deviceIdentityProvider,
  }) : _apiClient = apiClient,
       _secureStorage = secureStorage,
       _preferences = preferences,
       _googleAuthGateway = googleAuthGateway ?? GoogleSignInGateway(),
       _deviceIdentityProvider =
           deviceIdentityProvider ?? PlatformDeviceIdentityProvider();

  factory AuthController.defaultInstance({
    DeviceIdentityProvider? deviceIdentityProvider,
  }) {
    return AuthController(
      apiClient: AuthApiClient(AppConfig.apiBaseUrl),
      secureStorage: const FlutterSecureStorage(),
      deviceIdentityProvider: deviceIdentityProvider,
    );
  }

  static const _tokenKey = 'eaes.mobile_auth_token';
  static const _activeSessionKey = 'eaes.active_scanner_session';
  static const _userKey = 'eaes.mobile_user';
  static const _assignmentsKey = 'eaes.scanner_assignments';

  final AuthRemoteApi _apiClient;
  final FlutterSecureStorage _secureStorage;
  SharedPreferences? _preferences;
  final GoogleAuthGateway _googleAuthGateway;
  final DeviceIdentityProvider _deviceIdentityProvider;

  MobileUser? user;
  List<ScannerAssignment> assignments = [];
  bool initialized = false;
  bool busy = false;
  String? authToken;
  String? statusMessage;
  String? errorMessage;

  bool get isSignedIn => authToken != null && user != null;
  bool get hasAssignments => assignments.isNotEmpty;

  Future<void> initialize() async {
    busy = true;
    notifyListeners();

    try {
      await _googleAuthGateway.initialize();

      _preferences ??= await SharedPreferences.getInstance();
      authToken = await _secureStorage.read(key: _tokenKey);
      final userJson = _preferences!.getString(_userKey);
      if (userJson != null) {
        user = MobileUser.fromJson(
          jsonDecode(userJson) as Map<String, dynamic>,
        );
      }

      await _loadCachedAssignments();
      if (authToken != null) {
        await refreshAssignments(silent: true);
      }
    } catch (error) {
      errorMessage = 'Could not restore scanner login. Please sign in again.';
    } finally {
      initialized = true;
      busy = false;
      notifyListeners();
    }
  }

  Future<void> signInWithGoogle() async {
    busy = true;
    errorMessage = null;
    statusMessage = 'Opening Google sign in.';
    notifyListeners();

    try {
      final accountToken = await _googleAuthGateway.authenticate();

      final result = await _apiClient.loginWithGoogle(
        idToken: accountToken.idToken,
        deviceName: await _deviceIdentityProvider.deviceId(),
      );

      authToken = result.token;
      user = result.user;
      await _secureStorage.write(key: _tokenKey, value: result.token);
      await _preferences!.setString(_userKey, jsonEncode(result.user.toJson()));
      await refreshAssignments(silent: true);
      statusMessage = 'Signed in. Select an assigned event.';
    } catch (error) {
      statusMessage = null;
      errorMessage = error is GoogleAuthCancelledException
          ? null
          : _friendlyAuthError(error);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> refreshAssignments({bool silent = false}) async {
    final token = authToken;
    if (token == null) return;

    if (!silent) {
      busy = true;
      errorMessage = null;
      statusMessage = 'Refreshing assigned events.';
      notifyListeners();
    }

    try {
      assignments = await _apiClient.assignments(
        token: token,
        deviceId: await _deviceIdentityProvider.deviceId(),
      );
      await _preferences!.setString(
        _assignmentsKey,
        jsonEncode(assignments.map((item) => item.toJson()).toList()),
      );
      if (!silent) {
        statusMessage = assignments.isEmpty
            ? 'No scanner assignments found.'
            : 'Assigned events refreshed.';
      }
    } catch (error) {
      errorMessage =
          'Could not refresh assignments. Cached assignments may be outdated.';
    } finally {
      if (!silent) {
        busy = false;
      }
      notifyListeners();
    }
  }

  Future<bool> activateAssignment(
    ScannerAssignment assignment,
    ScannerController scannerController,
  ) async {
    final token = authToken;
    if (token == null) {
      errorMessage = 'Sign in before activating a scanner assignment.';
      notifyListeners();
      return false;
    }

    busy = true;
    errorMessage = null;
    statusMessage = 'Activating ${assignment.eventTitle}.';
    notifyListeners();

    try {
      final session = await _apiClient.activateAssignment(
        token: token,
        assignment: assignment,
        deviceId: await _deviceIdentityProvider.deviceId(),
      );
      await scannerController.activateSession(session);
      if (scannerController.session != null) {
        await _persistActiveSessionSnapshot(scannerController);
        await scannerController.hydrateRoster();
      }
      await refreshAssignments(silent: true);
      statusMessage = 'Scanner ready for ${assignment.eventTitle}.';
      return scannerController.session != null;
    } catch (error) {
      errorMessage = error.toString();
      statusMessage = null;
      return false;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> signOut() async {
    final token = authToken;
    busy = true;
    notifyListeners();

    try {
      if (token != null) {
        await _apiClient.logout(token);
      }
    } catch (_) {
      // Local logout must still work when the API is unreachable.
    }

    await _googleAuthGateway.signOut();
    await _secureStorage.delete(key: _tokenKey);
    await _secureStorage.delete(key: _activeSessionKey);
    await _preferences?.remove(_userKey);
    await _preferences?.remove(_assignmentsKey);
    authToken = null;
    user = null;
    assignments = [];
    statusMessage = null;
    errorMessage = null;
    busy = false;
    notifyListeners();
  }

  Future<void> restoreScannerSession(
    ScannerController scannerController,
  ) async {
    if (!isSignedIn || scannerController.hasSession) return;

    final raw = await _secureStorage.read(key: _activeSessionKey);
    if (raw == null) return;

    try {
      final snapshot = ScannerSessionSnapshot.fromJson(
        jsonDecode(raw) as Map<String, dynamic>,
      );
      await scannerController.activateSession(snapshot.session);
      if (scannerController.session != null) {
        await _persistActiveSessionSnapshot(scannerController);
        return;
      }

      if (scannerController.lastSessionActivationFailureIsRecoverable) {
        await scannerController.restoreCachedSession(snapshot);
        return;
      }

      await _secureStorage.delete(key: _activeSessionKey);
    } catch (_) {
      await _secureStorage.delete(key: _activeSessionKey);
    }
  }

  Future<void> _persistActiveSessionSnapshot(
    ScannerController scannerController,
  ) async {
    final snapshot = scannerController.currentSessionSnapshot();
    if (snapshot == null) {
      await _secureStorage.delete(key: _activeSessionKey);
      return;
    }

    await _secureStorage.write(
      key: _activeSessionKey,
      value: jsonEncode(snapshot.toJson()),
    );
  }

  Future<void> _loadCachedAssignments() async {
    final raw = _preferences?.getString(_assignmentsKey);
    if (raw == null) return;

    final decoded = jsonDecode(raw) as List<dynamic>;
    assignments = decoded
        .map((item) => ScannerAssignment.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  String _friendlyAuthError(Object error) {
    if (error is AuthApiException) {
      return error.message;
    }
    if (error is GoogleAuthException) {
      return error.message;
    }

    return 'Google sign in could not continue. Choose your USM account again.';
  }
}
