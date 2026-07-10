import 'package:flutter/material.dart';

import '../../scanner/presentation/scanner_controller.dart';
import '../data/device_identity_provider.dart';
import 'auth_controller.dart';
import 'screens/assigned_events_screen.dart';
import 'screens/login_screen.dart';
import 'screens/splash_screen.dart';

class AuthGate extends StatefulWidget {
  const AuthGate({super.key});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<AuthGate> {
  final DeviceIdentityProvider _deviceIdentityProvider =
      PlatformDeviceIdentityProvider();
  AuthController? _authController;
  ScannerController? _scannerController;

  /// Stays true until boot completes AND the minimum splash duration elapses.
  bool _showSplash = true;

  @override
  void initState() {
    super.initState();
    _boot();
  }

  Future<void> _boot() async {
    // Run initialisation and a minimum splash timer in parallel so the splash
    // is never shown for less than 2 seconds (avoids a jarring flash).
    await Future.wait([
      _initControllers(),
      Future<void>.delayed(const Duration(milliseconds: 2000)),
    ]);

    if (mounted) {
      setState(() => _showSplash = false);
    }
  }

  Future<void> _initControllers() async {
    final deviceId = await _deviceIdentityProvider.deviceId();
    final authController = AuthController.defaultInstance(
      deviceIdentityProvider: _deviceIdentityProvider,
    );
    final scannerController = ScannerController.defaultInstance(
      deviceId: deviceId,
    );

    _authController = authController;
    _scannerController = scannerController;

    await authController.initialize();
    await authController.restoreScannerSession(scannerController);
  }

  @override
  void dispose() {
    _authController?.dispose();
    _scannerController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final authController = _authController;
    final scannerController = _scannerController;

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 500),
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeIn,
      child: _showSplash || authController == null || scannerController == null
          ? const SplashScreen(key: ValueKey('splash'))
          : AnimatedBuilder(
              key: const ValueKey('main'),
              animation: Listenable.merge([authController, scannerController]),
              builder: (context, _) {
                if (!authController.initialized) {
                  // Edge-case: controllers reset after splash — show splash again
                  return const SplashScreen();
                }

                if (!authController.isSignedIn) {
                  return LoginScreen(controller: authController);
                }

                return AssignedEventsScreen(
                  authController: authController,
                  scannerController: scannerController,
                );
              },
            ),
    );
  }
}
