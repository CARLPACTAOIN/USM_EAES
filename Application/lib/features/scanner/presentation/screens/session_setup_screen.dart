import 'package:flutter/material.dart';

import '../../domain/scan_type.dart';
import '../scanner_controller.dart';
import '../widgets/event_ready_card.dart';
import '../widgets/session_link_input.dart';
import '../widgets/scan_result_card.dart';
import 'live_scanner_screen.dart';

/// Screen 1 — Session Setup.
///
/// Shows two distinct states based on whether a scanner session is active:
///
/// **State A (no session):**
/// - Large illustration + "No Active Session" heading
/// - Session link paste field + Open Session CTA
///
/// **State B (session active):**
/// - Event Ready card (gradient, event title, status chips)
/// - Two large scan-mode buttons → push to [LiveScannerScreen]
/// - Hydrate Roster secondary button
class SessionSetupScreen extends StatelessWidget {
  const SessionSetupScreen({
    super.key,
    required this.controller,
    required this.linkController,
    required this.onOpenSession,
    required this.onHydrate,
  });

  final ScannerController controller;
  final TextEditingController linkController;
  final VoidCallback onOpenSession;
  final VoidCallback onHydrate;

  void _pushScanner(BuildContext context, ScanType scanType) {
    controller.setScanType(scanType);
    Navigator.of(context).push(
      PageRouteBuilder<void>(
        pageBuilder: (context, animation, secondaryAnimation) => LiveScannerScreen(
          controller: controller,
        ),
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
    final hasSession = controller.hasSession;

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
                    controller: controller,
                    onHydrate: onHydrate,
                    onTimeIn: () =>
                        _pushScanner(context, ScanType.timeIn),
                    onTimeOut: () =>
                        _pushScanner(context, ScanType.timeOut),
                  )
                : _NoSessionContent(
                    key: const ValueKey('no-session'),
                    controller: controller,
                    linkController: linkController,
                    onOpenSession: onOpenSession,
                  ),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// State A — No session
// ---------------------------------------------------------------------------

class _NoSessionContent extends StatelessWidget {
  const _NoSessionContent({
    super.key,
    required this.controller,
    required this.linkController,
    required this.onOpenSession,
  });

  final ScannerController controller;
  final TextEditingController linkController;
  final VoidCallback onOpenSession;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Illustration area
        Center(
          child: Container(
            width: 120,
            height: 120,
            decoration: BoxDecoration(
              color: isDark
                  ? const Color(0xFF0F2E25)
                  : const Color(0xFFECFDF5),
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

        // Heading
        Text(
          'No Active Session',
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineMedium?.copyWith(
            fontWeight: FontWeight.w800,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Open a scanner session to start capturing attendance offline.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
          ),
        ),

        const SizedBox(height: 32),

        // Session link input + CTA
        SessionLinkInput(
          controller: controller,
          linkController: linkController,
          onOpenSession: onOpenSession,
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// State B — Session active
// ---------------------------------------------------------------------------

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
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Active event info card
        EventReadyCard(controller: controller),

        const SizedBox(height: 24),

        // Hydrate roster prompt (shown when roster not yet downloaded)
        if (controller.rosterCount == 0) ...[
          _HydrateRosterPrompt(
            controller: controller,
            onHydrate: onHydrate,
          ),
          const SizedBox(height: 24),
        ],

        // Scan mode selection
        ScanModeButtons(
          onTimeIn: onTimeIn,
          onTimeOut: onTimeOut,
        ),

        // Last scan result (if any from a previous session in the same run)
        if (controller.lastScanResult != null) ...[
          const SizedBox(height: 20),
          ScanResultCard(result: controller.lastScanResult!),
        ],

        // Hydrate roster button (always shown when session active, as refresh)
        if (controller.rosterCount > 0) ...[
          const SizedBox(height: 20),
          OutlinedButton.icon(
            onPressed: controller.busy ? null : onHydrate,
            icon: controller.busy
                ? const SizedBox.square(
                    dimension: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.refresh_rounded, size: 20),
            label: Text(
              controller.busy ? 'Refreshing roster…' : 'Refresh Roster',
            ),
            style: OutlinedButton.styleFrom(
              minimumSize: const Size(double.infinity, 48),
            ),
          ),
        ],

        const SizedBox(height: 20),

        // Change session link
        TextButton.icon(
          onPressed: controller.busy ? null : () => _showChangeLinkSheet(context),
          icon: const Icon(Icons.link_off_rounded, size: 18),
          label: const Text('Change Session Link'),
          style: TextButton.styleFrom(
            foregroundColor: theme.colorScheme.onSurface.withValues(alpha: 0.5),
          ),
        ),
      ],
    );
  }

  void _showChangeLinkSheet(BuildContext context) {
    final linkCtrl = TextEditingController();
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            20,
            20,
            MediaQuery.viewInsetsOf(ctx).bottom + 24,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: Theme.of(ctx)
                        .colorScheme
                        .onSurface
                        .withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 20),
              Text(
                'Enter New Session Link',
                style: Theme.of(ctx).textTheme.titleMedium,
              ),
              const SizedBox(height: 16),
              SessionLinkInput(
                controller: controller,
                linkController: linkCtrl,
                onOpenSession: () {
                  final raw = linkCtrl.text.trim();
                  final uri = Uri.tryParse(raw);
                  if (uri != null) {
                    controller.applySessionUri(uri);
                  }
                  Navigator.of(ctx).pop();
                },
              ),
            ],
          ),
        );
      },
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
        border: Border.all(
          color: Colors.orange.withValues(alpha: 0.4),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, color: Colors.orange.shade700, size: 22),
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
                    controller.busy
                        ? 'Downloading roster…'
                        : 'Hydrate Roster',
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
