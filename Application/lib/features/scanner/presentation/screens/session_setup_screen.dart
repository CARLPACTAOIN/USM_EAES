import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../../domain/scan_type.dart';
import '../scanner_controller.dart';
import '../widgets/event_ready_card.dart';
import '../widgets/sync_summary_strip.dart';
import 'live_scanner_screen.dart';

class SessionSetupScreen extends StatefulWidget {
  const SessionSetupScreen({
    super.key,
    required this.controller,
    required this.onReturnToAssignments,
    required this.onHydrate,
  });

  final ScannerController controller;
  final VoidCallback onReturnToAssignments;
  final VoidCallback onHydrate;

  @override
  State<SessionSetupScreen> createState() => _SessionSetupScreenState();
}

class _SessionSetupScreenState extends State<SessionSetupScreen> {
  StreamSubscription<List<ConnectivityResult>>? _connSub;

  @override
  void initState() {
    super.initState();
    widget.controller.checkConnectivity();
    _connSub = Connectivity().onConnectivityChanged.listen((_) {
      widget.controller.checkConnectivity();
    });
  }

  @override
  void dispose() {
    _connSub?.cancel();
    super.dispose();
  }

  void _pushScanner(BuildContext context, ScanType scanType) {
    widget.controller.setScanType(scanType);
    Navigator.of(context).push(
      PageRouteBuilder<void>(
        pageBuilder: (context, animation, secondaryAnimation) =>
            LiveScannerScreen(controller: widget.controller),
        transitionsBuilder: (context, animation, secondaryAnimation, child) {
          final curve = CurvedAnimation(
            parent: animation,
            curve: Curves.easeOutCubic,
          );
          return SlideTransition(
            position: Tween<Offset>(
              begin: const Offset(0, 1),
              end: Offset.zero,
            ).animate(curve),
            child: child,
          );
        },
        transitionDuration: const Duration(milliseconds: 300),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.controller,
      builder: (context, _) {
        final hasSession = widget.controller.hasSession;

        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 300),
                switchInCurve: Curves.easeOutCubic,
                switchOutCurve: Curves.easeIn,
                transitionBuilder: (child, animation) => FadeTransition(
                  opacity: animation,
                  child: SlideTransition(
                    position: Tween<Offset>(
                      begin: const Offset(0, 0.04),
                      end: Offset.zero,
                    ).animate(animation),
                    child: child,
                  ),
                ),
                child: hasSession
                    ? _SessionActiveContent(
                        key: const ValueKey('active'),
                        controller: widget.controller,
                        onHydrate: widget.onHydrate,
                        onTimeIn: () => _pushScanner(context, ScanType.timeIn),
                        onTimeOut: () =>
                            _pushScanner(context, ScanType.timeOut),
                      )
                    : _NoSessionContent(
                        key: const ValueKey('no-session'),
                        onReturnToAssignments: widget.onReturnToAssignments,
                      ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _NoSessionContent extends StatelessWidget {
  const _NoSessionContent({super.key, required this.onReturnToAssignments});

  final VoidCallback onReturnToAssignments;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Center(
          child: Container(
            width: 120,
            height: 120,
            decoration: BoxDecoration(
              color: isDark ? const Color(0xFF0F2E25) : const Color(0xFFECFDF5),
              shape: BoxShape.circle,
            ),
            child: const Icon(
              Icons.qr_code_scanner_rounded,
              size: 56,
              color: Color(0xFF1B4D3E),
            ),
          ),
        ),
        const SizedBox(height: 24),
        Text(
          'No Active Session',
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineMedium?.copyWith(
            fontWeight: FontWeight.w800,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Activate one of your assigned events before capturing attendance offline.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
          ),
        ),
        const SizedBox(height: 24),
        FilledButton.icon(
          onPressed: onReturnToAssignments,
          icon: const Icon(Icons.assignment_ind_rounded),
          label: const Text('Back to assigned events'),
        ),
      ],
    );
  }
}

class _SessionActiveContent extends StatelessWidget {
  const _SessionActiveContent({
    super.key,
    required this.controller,
    required this.onHydrate,
    required this.onTimeIn,
    required this.onTimeOut,
  });

  final ScannerController controller;
  final VoidCallback onHydrate;
  final VoidCallback onTimeIn;
  final VoidCallback onTimeOut;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        EventReadyCard(controller: controller),
        const SizedBox(height: 12),
        SyncSummaryStrip(controller: controller),
        const SizedBox(height: 28),
        if (controller.rosterCount == 0) ...[
          _HydrateRosterPrompt(controller: controller, onHydrate: onHydrate),
          const SizedBox(height: 28),
        ],
        ScanModeButtons(onTimeIn: onTimeIn, onTimeOut: onTimeOut),
        const SizedBox(height: 12),
        if (controller.rosterCount > 0)
          TextButton.icon(
            onPressed: controller.busy ? null : onHydrate,
            icon: controller.busy
                ? const SizedBox.square(
                    dimension: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.refresh_rounded, size: 18),
            label: Text(controller.busy ? 'Refreshing...' : 'Refresh roster'),
          ),
      ],
    );
  }
}

class _HydrateRosterPrompt extends StatelessWidget {
  const _HydrateRosterPrompt({
    required this.controller,
    required this.onHydrate,
  });

  final ScannerController controller;
  final VoidCallback onHydrate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: isDark
            ? Colors.orange.withValues(alpha: 0.1)
            : Colors.orange.shade50,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.orange.withValues(alpha: 0.4)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.warning_amber_rounded,
            color: Colors.orange.shade700,
            size: 22,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Roster not downloaded',
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: Colors.orange.shade800,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'Download the student roster before scanning offline so the '
                  'app can identify students without a network connection.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: Colors.orange.shade900,
                  ),
                ),
                const SizedBox(height: 12),
                FilledButton.icon(
                  onPressed: controller.busy ? null : onHydrate,
                  icon: controller.busy
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Icon(Icons.download_rounded, size: 18),
                  label: Text(
                    controller.busy ? 'Downloading roster…' : 'Hydrate Roster',
                  ),
                  style: FilledButton.styleFrom(
                    backgroundColor: Colors.orange.shade700,
                    minimumSize: const Size(0, 42),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
