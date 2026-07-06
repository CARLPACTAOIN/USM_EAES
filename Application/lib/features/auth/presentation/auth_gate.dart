import 'package:flutter/material.dart';

import '../../scanner/presentation/scanner_controller.dart';
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
  late final AuthController _authController;
  late final ScannerController _scannerController;

  /// Stays true until boot completes AND the minimum splash duration elapses.
  bool _showSplash = true;

  @override
  void initState() {
    super.initState();
    _authController = AuthController.defaultInstance();
    _scannerController = ScannerController.defaultInstance();
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
    await _authController.initialize();
    await _authController.restoreScannerSession(_scannerController);
  }

  @override
  void dispose() {
    _authController.dispose();
    _scannerController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 500),
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeIn,
      child: _showSplash
          ? const SplashScreen(key: ValueKey('splash'))
          : AnimatedBuilder(
              key: const ValueKey('main'),
              animation:
                  Listenable.merge([_authController, _scannerController]),
              builder: (context, _) {
                if (!_authController.initialized) {
                  // Edge-case: controllers reset after splash — show splash again
                  return const SplashScreen();
                }

                if (!_authController.isSignedIn) {
                  return LoginScreen(controller: _authController);
                }

                return AssignedEventsScreen(
                  authController: _authController,
                  scannerController: _scannerController,
                );
              },
            ),
    );
  }
}
