import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../../../../config/app_colors.dart';

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
    final c = AppColors.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Center(
          child: Container(
            width: 120,
            height: 120,
            decoration: BoxDecoration(
              color: c.quietIconSurface,
              shape: BoxShape.circle,
              border: Border.all(
                color: AppColors.usmGold.withValues(alpha: 0.15),
                width: 1.5,
              ),
              boxShadow: [
                BoxShadow(
                  color: AppColors.usmGold.withValues(alpha: 0.08),
                  blurRadius: 40,
                  spreadRadius: 4,
                ),
              ],
            ),
            child: Icon(
              Icons.qr_code_scanner_rounded,
              size: 56,
              color: AppColors.usmGold.withValues(alpha: 0.60),
            ),
          ),
        ),
        const SizedBox(height: 24),
        Text(
          'No Active Session',
          textAlign: TextAlign.center,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 22,
            fontWeight: FontWeight.w800,
            color: c.onSurface,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Activate one of your assigned events before capturing attendance offline.',
          textAlign: TextAlign.center,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 13,
            color: c.onSurfaceMuted,
            height: 1.5,
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
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: AppColors.usmGold,
                    ),
                  )
                : Icon(
                    Icons.refresh_rounded,
                    size: 18,
                    color: AppColors.usmGold.withValues(alpha: 0.70),
                  ),
            label: Text(
              controller.busy ? 'Refreshing...' : 'Refresh roster',
              style: TextStyle(
                color: AppColors.usmGold.withValues(alpha: 0.70),
              ),
            ),
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
    final c = AppColors.of(context);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.orange.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.orange.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, color: AppColors.usmGold, size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Roster not downloaded',
                  style: TextStyle(
                    fontFamily: 'Inter',
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: AppColors.usmGold,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'Download the student roster before scanning offline so the '
                  'app can identify students without a network connection.',
                  style: TextStyle(
                    fontFamily: 'Inter',
                    fontSize: 12,
                    color: c.onSurfaceMuted,
                    height: 1.5,
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
