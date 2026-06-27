import 'package:flutter/material.dart';

import '../scanner_controller.dart';
import '../../domain/scan_type.dart';

/// Shows active event details after a session has been validated successfully.
///
/// Displays event title, scanner info, and offline-ready status in a prominent
/// card. Used as the second state of Session Setup screen.
class EventReadyCard extends StatelessWidget {
  const EventReadyCard({
    super.key,
    required this.controller,
  });

  final ScannerController controller;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    const brandGreen = Color(0xFF1B4D3E);

    final title =
        controller.eventTitle ?? 'Event ${controller.session!.eventId}';

    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: isDark
              ? [const Color(0xFF0F2E25), const Color(0xFF0D2137)]
              : [brandGreen, const Color(0xFF1E6B54)],
        ),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: brandGreen.withValues(alpha: isDark ? 0.3 : 0.25),
            blurRadius: 16,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header row
            Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(
                    Icons.event_available_rounded,
                    color: Colors.white,
                    size: 22,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Session Active',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: Colors.white.withValues(alpha: 0.7),
                          letterSpacing: 0.8,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleLarge?.copyWith(
                          color: Colors.white,
                          fontWeight: FontWeight.w800,
                          height: 1.2,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),

            const SizedBox(height: 16),
            Divider(color: Colors.white.withValues(alpha: 0.15), height: 1),
            const SizedBox(height: 16),

            // Status chips row
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _WhiteChip(
                  icon: Icons.wifi_off_rounded,
                  label: 'Offline-ready',
                ),
                _WhiteChip(
                  icon: Icons.badge_rounded,
                  label: controller.rosterCount > 0
                      ? 'Roster: ${controller.rosterCount} students'
                      : 'Roster not hydrated',
                  warning: controller.rosterCount == 0,
                ),
                _WhiteChip(
                  icon: Icons.pending_actions_rounded,
                  label: '${controller.pendingCount} pending',
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _WhiteChip extends StatelessWidget {
  const _WhiteChip({
    required this.icon,
    required this.label,
    this.warning = false,
  });

  final IconData icon;
  final String label;
  final bool warning;

  @override
  Widget build(BuildContext context) {
    final chipColor = warning
        ? Colors.orange.shade300
        : Colors.white.withValues(alpha: 0.9);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: warning ? 0.1 : 0.15),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(
          color: warning
              ? Colors.orange.withValues(alpha: 0.5)
              : Colors.white.withValues(alpha: 0.2),
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13, color: chipColor),
          const SizedBox(width: 5),
          Text(
            label,
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 12,
              fontWeight: FontWeight.w500,
              color: chipColor,
            ),
          ),
        ],
      ),
    );
  }
}

/// The two big scan-mode selection buttons shown on the Event Ready screen.
class ScanModeButtons extends StatelessWidget {
  const ScanModeButtons({
    super.key,
    required this.onTimeIn,
    required this.onTimeOut,
  });

  final VoidCallback onTimeIn;
  final VoidCallback onTimeOut;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Choose scanning mode',
          style: theme.textTheme.titleMedium?.copyWith(
            color: theme.colorScheme.onSurface.withValues(alpha: 0.7),
          ),
        ),
        const SizedBox(height: 12),
        _ScanModeButton(
          icon: Icons.login_rounded,
          title: 'Start Time-In Scanning',
          subtitle: 'Record student arrivals',
          scanType: ScanType.timeIn,
          onTap: onTimeIn,
        ),
        const SizedBox(height: 12),
        _ScanModeButton(
          icon: Icons.logout_rounded,
          title: 'Start Time-Out Scanning',
          subtitle: 'Record student departures',
          scanType: ScanType.timeOut,
          onTap: onTimeOut,
        ),
      ],
    );
  }
}

class _ScanModeButton extends StatelessWidget {
  const _ScanModeButton({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.scanType,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final ScanType scanType;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    const brandGreen = Color(0xFF1B4D3E);
    const accentBlue = Color(0xFF2563EB);

    final isTimeIn = scanType == ScanType.timeIn;
    final buttonColor = isTimeIn ? brandGreen : accentBlue;
    final bgColor = isDark
        ? buttonColor.withValues(alpha: 0.15)
        : buttonColor.withValues(alpha: 0.06);

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 18),
          decoration: BoxDecoration(
            color: bgColor,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: buttonColor.withValues(alpha: 0.3),
              width: 1.5,
            ),
          ),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: buttonColor,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, color: Colors.white, size: 24),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: buttonColor,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurface.withValues(
                          alpha: 0.6,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              Icon(
                Icons.chevron_right_rounded,
                color: buttonColor.withValues(alpha: 0.6),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
