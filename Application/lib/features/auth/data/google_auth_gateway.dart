import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../../../config/app_config.dart';

class GoogleAccountToken {
  const GoogleAccountToken({required this.idToken});

  final String idToken;
}

class GoogleAuthException implements Exception {
  const GoogleAuthException(this.message);

  final String message;

  @override
  String toString() => message;
}

class GoogleAuthCancelledException implements Exception {
  const GoogleAuthCancelledException();
}

abstract class GoogleAuthGateway {
  Future<void> initialize();
  Future<GoogleAccountToken> authenticate();
  Future<void> signOut();
}

class GoogleSignInGateway implements GoogleAuthGateway {
  GoogleSignInGateway({
    GoogleSignInAdapter? adapter,
    TargetPlatform? platform,
    this.googleClientId = AppConfig.googleClientId,
    this.googleServerClientId = AppConfig.googleServerClientId,
    this.hostedDomain = AppConfig.googleHostedDomain,
  }) : _adapter = adapter ?? GoogleSignInPluginAdapter(),
       _platform = platform;

  final GoogleSignInAdapter _adapter;
  final TargetPlatform? _platform;
  final String googleClientId;
  final String googleServerClientId;
  final String hostedDomain;

  @override
  Future<void> initialize() {
    return _adapter.initialize(
      clientId: _isAndroid ? null : _emptyToNull(googleClientId),
      serverClientId: _emptyToNull(googleServerClientId),
      hostedDomain: _emptyToNull(hostedDomain),
    );
  }

  @override
  Future<GoogleAccountToken> authenticate() async {
    try {
      return await _adapter.authenticate();
    } on GoogleSignInException catch (error) {
      if (error.code == GoogleSignInExceptionCode.canceled) {
        throw const GoogleAuthCancelledException();
      }

      throw GoogleAuthException(_friendlyGoogleMessage(error));
    }
  }

  @override
  Future<void> signOut() => _adapter.signOut();

  bool get _isAndroid {
    return (_platform ?? defaultTargetPlatform) == TargetPlatform.android;
  }

  static String? _emptyToNull(String value) {
    final trimmed = value.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  static String _friendlyGoogleMessage(GoogleSignInException error) {
    switch (error.code) {
      case GoogleSignInExceptionCode.canceled:
        return 'Google account selection did not complete.';
      case GoogleSignInExceptionCode.clientConfigurationError:
      case GoogleSignInExceptionCode.providerConfigurationError:
        return 'Google sign in is not configured for this scanner build. Check the Android OAuth client, SHA-1, and web server client ID.';
      case GoogleSignInExceptionCode.uiUnavailable:
        return 'Google sign in is unavailable on this device. Check Google Play services and try again.';
      case GoogleSignInExceptionCode.interrupted:
        return 'Google sign in was interrupted. Open the account picker again.';
      case GoogleSignInExceptionCode.userMismatch:
        return 'The selected Google account does not match the active scanner sign-in. Choose your USM account again.';
      case GoogleSignInExceptionCode.unknownError:
        return 'Google sign in could not continue. Choose your account again or check the phone Google account setup.';
    }
  }
}

abstract class GoogleSignInAdapter {
  Future<void> initialize({
    String? clientId,
    String? serverClientId,
    String? hostedDomain,
  });

  Future<GoogleAccountToken> authenticate();
  Future<void> signOut();
}

class GoogleSignInPluginAdapter implements GoogleSignInAdapter {
  GoogleSignInPluginAdapter({GoogleSignIn? googleSignIn})
    : _googleSignIn = googleSignIn ?? GoogleSignIn.instance;

  final GoogleSignIn _googleSignIn;

  @override
  Future<void> initialize({
    String? clientId,
    String? serverClientId,
    String? hostedDomain,
  }) {
    return _googleSignIn.initialize(
      clientId: clientId,
      serverClientId: serverClientId,
      hostedDomain: hostedDomain,
    );
  }

  @override
  Future<GoogleAccountToken> authenticate() async {
    final account = await _googleSignIn.authenticate();
    final idToken = account.authentication.idToken;
    if (idToken == null || idToken.isEmpty) {
      throw const GoogleAuthException(
        'Google did not return an ID token. Choose your USM account again.',
      );
    }

    return GoogleAccountToken(idToken: idToken);
  }

  @override
  Future<void> signOut() => _googleSignIn.signOut();
}
