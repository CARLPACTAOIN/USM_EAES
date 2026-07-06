import 'dart:async';

import 'package:flutter/material.dart';

import '../../../config/app_config.dart';
import '../domain/scan_sync_status.dart';
import 'scanner_controller.dart';
import 'screens/offline_tools_screen.dart';
import 'screens/scan_logs_screen.dart';
import 'screens/session_setup_screen.dart';
import 'widgets/global_feedback_banner.dart';

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
  const ScannerHomeScreen({super.key, this.controller});

  final ScannerController? controller;

  @override
  State<ScannerHomeScreen> createState() => _ScannerHomeScreenState();
}

class _ScannerHomeScreenState extends State<ScannerHomeScreen> {
  late final ScannerController _controller;
  Timer? _feedbackDismissTimer;
  int _selectedTab = 0;
  int _lastSessionActivatedSignal = 0;
  bool _ownsController = false;

  @override
  void initState() {
    super.initState();
    _controller = widget.controller ?? ScannerController.defaultInstance();
    _ownsController = widget.controller == null;
    _controller.addListener(_handleControllerUpdates);
    _controller.checkConnectivity();
  }

  void _handleControllerUpdates() {
    if (_controller.sessionActivatedSignal != _lastSessionActivatedSignal) {
      _lastSessionActivatedSignal = _controller.sessionActivatedSignal;
      if (mounted) {
        setState(() => _selectedTab = 0);
      }
    }
    _scheduleFeedbackAutoDismiss();
  }

  /// Toast-style auto-dismiss: success/warning banners clear themselves after a
  /// few seconds so they don't linger and re-clutter the top of the screen.
  /// Loading and error states stay until explicitly resolved or dismissed.
  void _scheduleFeedbackAutoDismiss() {
    final kind = _controller.globalFeedback.kind;
    final shouldAutoDismiss =
        kind == FeedbackKind.success || kind == FeedbackKind.warning;
    if (!shouldAutoDismiss) {
      _feedbackDismissTimer?.cancel();
      return;
    }
    if (_feedbackDismissTimer?.isActive ?? false) return;
    _feedbackDismissTimer = Timer(const Duration(seconds: 4), () {
      final current = _controller.globalFeedback.kind;
      if (current == FeedbackKind.success || current == FeedbackKind.warning) {
        _controller.clearGlobalFeedback();
      }
    });
  }

  @override
  void dispose() {
    _controller.removeListener(_handleControllerUpdates);
    _feedbackDismissTimer?.cancel();
    if (_ownsController) {
      _controller.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        return Scaffold(
          body: Column(
            children: [
              GlobalFeedbackBanner(
                feedback: _controller.globalFeedback,
                onDismiss: _controller.clearGlobalFeedback,
              ),
              Expanded(
                child: IndexedStack(
                  index: _selectedTab,
                  children: [
                    _TabScaffold(
                      title: AppConfig.appName,
                      child: SessionSetupScreen(
                        controller: _controller,
                        onReturnToAssignments: () =>
                            Navigator.of(context).maybePop(),
                        onHydrate: _controller.hydrateRoster,
                      ),
                    ),
                    _TabScaffold(
                      title: 'Offline Tools',
                      child: OfflineToolsScreen(controller: _controller),
                    ),
                    _TabScaffold(
                      title: 'Scan Logs',
                      child: ScanLogsScreen(controller: _controller),
                    ),
                  ],
                ),
              ),
            ],
          ),
          bottomNavigationBar: _BottomNav(
            selectedIndex: _selectedTab,
            pendingCount: _controller.pendingCount + _controller.failedCount,
            onTabSelected: (index) => setState(() => _selectedTab = index),
          ),
        );
      },
    );
  }
}

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
      backgroundColor: theme.bottomNavigationBarTheme.backgroundColor,
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
            child: const Icon(Icons.wifi_off_rounded, color: Color(0xFF1B4D3E)),
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
