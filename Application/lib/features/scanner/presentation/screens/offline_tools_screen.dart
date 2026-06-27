import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../scanner_controller.dart';
import '../widgets/metric_chip.dart';

/// Screen 3 — Offline Tools.
///
/// Groups support actions that are important but not in the primary scanning
/// flow: roster hydration, pending sync, connection status, and error report.
class OfflineToolsScreen extends StatefulWidget {
  const OfflineToolsScreen({
    super.key,
    required this.controller,
  });

  final ScannerController controller;

  @override
  State<OfflineToolsScreen> createState() => _OfflineToolsScreenState();
}

class _OfflineToolsScreenState extends State<OfflineToolsScreen> {
  StreamSubscription<List<ConnectivityResult>>? _connSub;

  @override
  void initState() {
    super.initState();
    // Check connectivity once on mount, then listen for changes
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

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.controller,
      builder: (context, _) {
        final ctrl = widget.controller;

        if (!ctrl.hasSession) {
          return _NoSessionPlaceholder();
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // ---- Connection status ----
                  _ConnectionStatusCard(isOnline: ctrl.isOnline),

                  const SizedBox(height: 16),

                  // ---- Roster section ----
                  _SectionCard(
                    icon: Icons.badge_rounded,
                    title: 'Student Roster',
                    children: [
                      if (ctrl.rosterCount > 0)
                        _InfoRow(
                          icon: Icons.people_alt_rounded,
                          text:
                              'Roster hydrated: ${ctrl.rosterCount} students',
                          positive: true,
                        ),
                      if (ctrl.rosterCount == 0)
                        _InfoRow(
                          icon: Icons.warning_amber_rounded,
                          text:
                              'Roster not downloaded — scans will be saved as '
                              'unresolved until synced.',
                          positive: false,
                        ),
                      const SizedBox(height: 12),
                      OutlinedButton.icon(
                        onPressed: ctrl.busy ? null : ctrl.hydrateRoster,
                        icon: ctrl.busy
                            ? const SizedBox.square(
                                dimension: 16,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.download_rounded),
                        label: Text(
                          ctrl.busy
                              ? 'Downloading…'
                              : ctrl.rosterCount > 0
                                  ? 'Refresh Roster'
                                  : 'Hydrate Roster',
                        ),
                      ),
                    ],
                  ),

                  const SizedBox(height: 16),

                  // ---- Sync section ----
                  _SectionCard(
                    icon: Icons.cloud_sync_rounded,
                    title: 'Sync Pending Scans',
                    children: [
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          EaesMetricChip(
                            icon: Icons.pending_actions_rounded,
                            label: 'Pending',
                            value: ctrl.pendingCount.toString(),
                            color: ctrl.pendingCount > 0
                                ? Colors.orange.shade700
                                : null,
                          ),
                          if (ctrl.unresolvedLastSync > 0)
                            EaesMetricChip(
                              icon: Icons.manage_search_rounded,
                              label: 'Unresolved',
                              value: ctrl.unresolvedLastSync.toString(),
                              color: Colors.orange.shade700,
                            ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      if (!ctrl.isOnline)
                        _InfoRow(
                          icon: Icons.wifi_off_rounded,
                          text:
                              'Device is offline. Sync will be available when '
                              'network returns.',
                          positive: false,
                        ),
                      if (ctrl.isOnline && ctrl.pendingCount == 0)
                        _InfoRow(
                          icon: Icons.check_circle_rounded,
                          text: 'All scans are synced.',
                          positive: true,
                        ),
                      const SizedBox(height: 12),
                      FilledButton.icon(
                        onPressed: (!ctrl.isOnline ||
                                ctrl.syncing ||
                                ctrl.pendingCount == 0)
                            ? null
                            : ctrl.syncPending,
                        icon: ctrl.syncing
                            ? const SizedBox.square(
                                dimension: 16,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Icon(Icons.cloud_upload_rounded),
                        label: Text(
                          ctrl.syncing
                              ? 'Uploading…'
                              : 'Sync Pending Scans',
                        ),
                      ),
                    ],
                  ),

                  // ---- Error section (shown only when there is an error) ----
                  if (ctrl.errorMessage != null) ...[
                    const SizedBox(height: 16),
                    _ErrorCard(message: ctrl.errorMessage!),
                  ],

                  // ---- Status message (positive) ----
                  if (ctrl.statusMessage != null &&
                      ctrl.errorMessage == null) ...[
                    const SizedBox(height: 16),
                    _StatusMessage(message: ctrl.statusMessage!),
                  ],
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Sub-widgets
// ---------------------------------------------------------------------------

class _NoSessionPlaceholder extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.cloud_off_rounded,
              size: 64,
              color: theme.colorScheme.onSurface.withValues(alpha: 0.2),
            ),
            const SizedBox(height: 20),
            Text(
              'Open a session first',
              style: theme.textTheme.titleMedium?.copyWith(
                color: theme.colorScheme.onSurface.withValues(alpha: 0.5),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Offline tools become available once a scanner session is active.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurface.withValues(alpha: 0.4),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ConnectionStatusCard extends StatelessWidget {
  const _ConnectionStatusCard({required this.isOnline});
  final bool isOnline;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final color = isOnline ? const Color(0xFF1B4D3E) : Colors.orange.shade700;
    final bgColor = isOnline
        ? (isDark
            ? const Color(0xFF0F2E25)
            : const Color(0xFFECFDF5))
        : (isDark
            ? Colors.orange.withValues(alpha: 0.1)
            : Colors.orange.shade50);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Icon(
            isOnline ? Icons.wifi_rounded : Icons.wifi_off_rounded,
            color: color,
            size: 20,
          ),
          const SizedBox(width: 12),
          Text(
            isOnline ? 'Connected to network' : 'No network connection',
            style: theme.textTheme.labelLarge?.copyWith(
              color: color,
              fontWeight: FontWeight.w600,
            ),
          ),
          const Spacer(),
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              color: isOnline ? const Color(0xFF22C55E) : Colors.orange,
              shape: BoxShape.circle,
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({
    required this.icon,
    required this.title,
    required this.children,
  });

  final IconData icon;
  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF1E293B) : Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: theme.colorScheme.outlineVariant,
        ),
        boxShadow: isDark
            ? null
            : [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.04),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                ),
              ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(icon, size: 20, color: const Color(0xFF1B4D3E)),
              const SizedBox(width: 10),
              Text(
                title,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Divider(
            color: theme.colorScheme.outlineVariant,
            height: 1,
          ),
          const SizedBox(height: 14),
          ...children,
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({
    required this.icon,
    required this.text,
    required this.positive,
  });

  final IconData icon;
  final String text;
  final bool positive;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = positive
        ? const Color(0xFF1B4D3E)
        : Colors.orange.shade700;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 16, color: color),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            text,
            style: theme.textTheme.bodySmall?.copyWith(color: color),
          ),
        ),
      ],
    );
  }
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: isDark
            ? theme.colorScheme.error.withValues(alpha: 0.15)
            : theme.colorScheme.errorContainer.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: theme.colorScheme.error.withValues(alpha: 0.4),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.error_outline_rounded,
            color: theme.colorScheme.error,
            size: 20,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Something went wrong',
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: theme.colorScheme.error,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  message,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurface.withValues(alpha: 0.7),
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

class _StatusMessage extends StatelessWidget {
  const _StatusMessage({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: isDark
            ? const Color(0xFF0F2E25)
            : const Color(0xFFECFDF5),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: const Color(0xFF1B4D3E).withValues(alpha: 0.3),
        ),
      ),
      child: Row(
        children: [
          Icon(
            Icons.check_circle_outline_rounded,
            size: 16,
            color: const Color(0xFF1B4D3E),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: theme.textTheme.bodySmall?.copyWith(
                color: const Color(0xFF1B4D3E),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
