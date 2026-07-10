import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../../../../config/app_colors.dart';

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
    final c = AppColors.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 80,
              height: 80,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: c.quietIconSurface,
                border: Border.all(
                  color: AppColors.usmGold.withValues(alpha: 0.12),
                ),
              ),
              child: Icon(
                Icons.cloud_off_rounded,
                size: 36,
                color: AppColors.usmGold.withValues(alpha: 0.40),
              ),
            ),
            const SizedBox(height: 20),
            Text(
              'Open a session first',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 16,
                fontWeight: FontWeight.w700,
                color: c.onSurface,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Offline tools become available once a scanner session is active.',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 13,
                color: c.onSurfaceMuted,
                height: 1.5,
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
    final c = AppColors.of(context);

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: c.isDark ? c.panel : null,
        gradient: c.isDark
            ? null
            : LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  c.goldWash.withValues(alpha: 0.64),
                  Colors.white,
                  c.brandWash.withValues(alpha: 0.72),
                ],
              ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: c.isDark
              ? c.panelBorder
              : AppColors.usmGold.withValues(alpha: 0.28),
        ),
        boxShadow: [
          BoxShadow(
            color: c.softShadow,
            blurRadius: 12,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(icon, size: 20, color: AppColors.usmGold),
              const SizedBox(width: 10),
              Text(
                title,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: c.onSurface,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Container(
            height: 1,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  Colors.transparent,
                  AppColors.usmGold.withValues(alpha: 0.15),
                  Colors.transparent,
                ],
              ),
            ),
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
    final color = positive ? AppColors.accentGreen : AppColors.usmGold;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 16, color: color),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            text,
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 12,
              color: color.withValues(alpha: 0.85),
              height: 1.4,
            ),
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
    final errorColor = theme.colorScheme.error;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: errorColor.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: errorColor.withValues(alpha: 0.30)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.error_outline_rounded, color: errorColor, size: 20),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Something went wrong',
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: errorColor,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      message,
                      style: TextStyle(
                        fontFamily: 'Inter',
                        fontSize: 12,
                        color: AppColors.of(context).onSurfaceMuted,
                        height: 1.4,
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
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.accentGreen.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: AppColors.accentGreen.withValues(alpha: 0.20),
        ),
      ),
      child: Row(
        children: [
          Icon(
            Icons.check_circle_outline_rounded,
            size: 16,
            color: AppColors.accentGreen,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 12,
                color: AppColors.accentGreen.withValues(alpha: 0.90),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
