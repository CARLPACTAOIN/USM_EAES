import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../scanner_controller.dart';
import '../widgets/sync_status_panel.dart';
import '../widgets/technical_details_panel.dart';

class OfflineToolsScreen extends StatefulWidget {
  const OfflineToolsScreen({super.key, required this.controller});

  final ScannerController controller;

  @override
  State<OfflineToolsScreen> createState() => _OfflineToolsScreenState();
}

class _OfflineToolsScreenState extends State<OfflineToolsScreen> {
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
                  _SectionCard(
                    icon: Icons.badge_rounded,
                    title: 'Student Roster',
                    children: [
                      if (ctrl.rosterCount > 0)
                        _InfoRow(
                          icon: Icons.people_alt_rounded,
                          text: 'Roster hydrated: ${ctrl.rosterCount} students',
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
                  SyncStatusPanel(controller: ctrl),
                  if (ctrl.errorMessage != null) ...[
                    const SizedBox(height: 16),
                    _ErrorCard(
                      message: ctrl.errorMessage!,
                      details: ctrl.errorDetails,
                    ),
                  ],
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
        border: Border.all(color: theme.colorScheme.outlineVariant),
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
          Divider(color: theme.colorScheme.outlineVariant, height: 1),
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
    final color = positive ? const Color(0xFF1B4D3E) : Colors.orange.shade700;

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
  const _ErrorCard({required this.message, this.details});

  final String message;
  final String? details;

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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
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
                        color: theme.colorScheme.onSurface.withValues(
                          alpha: 0.7,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          TechnicalDetailsPanel(details: details ?? ''),
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
        color: isDark ? const Color(0xFF0F2E25) : const Color(0xFFECFDF5),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: const Color(0xFF1B4D3E).withValues(alpha: 0.3),
        ),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.check_circle_outline_rounded,
            size: 16,
            color: Color(0xFF1B4D3E),
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
