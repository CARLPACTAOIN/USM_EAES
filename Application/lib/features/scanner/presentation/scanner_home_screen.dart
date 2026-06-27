import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';

import '../../../config/app_config.dart';
import 'scanner_controller.dart';
import 'screens/offline_tools_screen.dart';
import 'screens/scan_logs_screen.dart';
import 'screens/session_setup_screen.dart';

/// Root shell for the EAES scanner app.
///
/// Provides a 3-tab bottom navigation bar:
///   [0] Scanner   — Session setup + guided scan flow
///   [1] Offline   — Roster hydration, pending sync, connection status
///   [2] Logs      — Recent scan history
///
/// The Live Scanner screen is **pushed** on top of this shell via
/// [Navigator.push] inside [SessionSetupScreen], preserving back-button
/// behaviour and keeping the bottom nav hidden during active scanning.
class ScannerHomeScreen extends StatefulWidget {
  const ScannerHomeScreen({super.key});

  @override
  State<ScannerHomeScreen> createState() => _ScannerHomeScreenState();
}

class _ScannerHomeScreenState extends State<ScannerHomeScreen> {
  late final ScannerController _controller;
  final AppLinks _appLinks = AppLinks();
  final TextEditingController _sessionLinkController = TextEditingController();
  StreamSubscription<Uri>? _linkSubscription;
  int _selectedTab = 0;

  @override
  void initState() {
    super.initState();
    _controller = ScannerController.defaultInstance();
    _listenForSessionLinks();
  }

  Future<void> _listenForSessionLinks() async {
    try {
      final initialLink = await _appLinks.getInitialLink();
      if (!mounted) return;

      if (initialLink != null) {
        await _controller.applySessionUri(initialLink);
      }

      _linkSubscription = _appLinks.uriLinkStream.listen(
        (uri) => _controller.applySessionUri(uri),
        onError: (_) {},
      );
    } catch (_) {
      // Deep links are optional during local widget tests and desktop runs.
    }
  }

  @override
  void dispose() {
    _linkSubscription?.cancel();
    _sessionLinkController.dispose();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _applyManualSession() async {
    final raw = _sessionLinkController.text.trim();
    if (raw.isEmpty) return;
    final uri = Uri.tryParse(raw);
    if (uri == null) {
      // applySessionUri will set errorMessage via the controller
      await _controller.applySessionUri(Uri.parse('invalid:'));
      return;
    }
    await _controller.applySessionUri(uri);
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        return Scaffold(
          // ---- Body (IndexedStack keeps state alive across tab switches) ----
          body: IndexedStack(
            index: _selectedTab,
            children: [
              // Tab 0 — Session Setup
              _TabScaffold(
                title: AppConfig.appName,
                child: SessionSetupScreen(
                  controller: _controller,
                  linkController: _sessionLinkController,
                  onOpenSession: _applyManualSession,
                  onHydrate: _controller.hydrateRoster,
                ),
              ),

              // Tab 1 — Offline Tools
              _TabScaffold(
                title: 'Offline Tools',
                child: OfflineToolsScreen(controller: _controller),
              ),

              // Tab 2 — Scan Logs
              _TabScaffold(
                title: 'Scan Logs',
                child: ScanLogsScreen(controller: _controller),
              ),
            ],
          ),

          // ---- Bottom Navigation Bar ----
          bottomNavigationBar: _BottomNav(
            selectedIndex: _selectedTab,
            pendingCount: _controller.pendingCount,
            onTabSelected: (index) => setState(() => _selectedTab = index),
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Tab scaffold wrapper (AppBar per tab)
// ---------------------------------------------------------------------------

class _TabScaffold extends StatelessWidget {
  const _TabScaffold({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: child,
    );
  }
}

// ---------------------------------------------------------------------------
// Bottom navigation bar
// ---------------------------------------------------------------------------

class _BottomNav extends StatelessWidget {
  const _BottomNav({
    required this.selectedIndex,
    required this.pendingCount,
    required this.onTabSelected,
  });

  final int selectedIndex;
  final int pendingCount;
  final ValueChanged<int> onTabSelected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return NavigationBar(
      selectedIndex: selectedIndex,
      onDestinationSelected: onTabSelected,
      backgroundColor:
          theme.bottomNavigationBarTheme.backgroundColor,
      indicatorColor: const Color(0xFF1B4D3E).withValues(alpha: 0.12),
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      destinations: [
        const NavigationDestination(
          icon: Icon(Icons.qr_code_scanner_rounded),
          selectedIcon: Icon(
            Icons.qr_code_scanner_rounded,
            color: Color(0xFF1B4D3E),
          ),
          label: 'Scanner',
        ),
        NavigationDestination(
          icon: Badge(
            isLabelVisible: pendingCount > 0,
            label: Text('$pendingCount'),
            child: const Icon(Icons.wifi_off_rounded),
          ),
          selectedIcon: Badge(
            isLabelVisible: pendingCount > 0,
            label: Text('$pendingCount'),
            child: const Icon(
              Icons.wifi_off_rounded,
              color: Color(0xFF1B4D3E),
            ),
          ),
          label: 'Offline',
        ),
        const NavigationDestination(
          icon: Icon(Icons.receipt_long_rounded),
          selectedIcon: Icon(
            Icons.receipt_long_rounded,
            color: Color(0xFF1B4D3E),
          ),
          label: 'Logs',
        ),
      ],
    );
  }
}
