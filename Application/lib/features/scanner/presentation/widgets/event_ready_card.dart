import 'package:flutter/material.dart';

import '../../../../config/app_colors.dart';

import '../scanner_controller.dart';
import '../../domain/scan_type.dart';

class EventReadyCard extends StatelessWidget {
  const EventReadyCard({super.key, required this.controller});

  final ScannerController controller;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = AppColors.of(context);
    final title = controller.eventTitle ?? 'Active Event';

    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: c.isDark
              ? const [AppColors.brandGreen, Color(0xFF0D2137)]
              : const [
                  AppColors.brandGreen,
                  Color(0xFF24765D),
                  Color(0xFFD4A843),
                ],
          stops: c.isDark ? null : const [0.0, 0.64, 1.0],
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: AppColors.usmGold.withValues(alpha: 0.18),
          width: 1,
        ),
        boxShadow: [
          BoxShadow(
            color: AppColors.brandGreen.withValues(
              alpha: c.isDark ? 0.30 : 0.22,
            ),
            blurRadius: 20,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: AppColors.usmGold.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(
                      color: AppColors.usmGold.withValues(alpha: 0.20),
                    ),
                  ),
                  child: Icon(
                    Icons.event_available_rounded,
                    color: AppColors.usmGold,
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
                          color: AppColors.usmGold.withValues(alpha: 0.80),
                          letterSpacing: 0.8,
                          fontWeight: FontWeight.w600,
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
                _StatusBadge(
                  status:
                      controller.eventLifecyclePhase ??
                      controller.eventStatus ??
                      'scheduled',
                ),
              ],
            ),
            const SizedBox(height: 16),
            // Gold-tinted divider
            Container(
              height: 1,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [
                    Colors.transparent,
                    AppColors.usmGold.withValues(alpha: 0.25),
                    Colors.transparent,
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            Wrap(
              spacing: 20,
              runSpacing: 8,
              children: [
                _MetaItem(
                  icon: controller.isOnline
                      ? Icons.wifi_rounded
                      : Icons.wifi_off_rounded,
                  label: controller.isOnline ? 'Online' : 'Offline',
                  warning: !controller.isOnline,
                ),
                _MetaItem(
                  icon: Icons.groups_rounded,
                  label: controller.rosterCount > 0
                      ? '${controller.rosterCount} students'
                      : 'No roster yet',
                  warning: controller.rosterCount == 0,
                ),
                _MetaItem(
                  icon: controller.scannerSessionStatus == 'active'
                      ? Icons.verified_user_rounded
                      : Icons.link_off_rounded,
                  label: controller.scannerSessionStatus == null
                      ? 'Session'
                      : 'Session ${controller.scannerSessionStatus}',
                  warning: controller.scannerSessionStatus != 'active',
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  const _StatusBadge({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: AppColors.usmGold.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.usmGold.withValues(alpha: 0.30)),
      ),
      child: Text(
        status[0].toUpperCase() + status.substring(1),
        style: TextStyle(
          fontFamily: 'Inter',
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: AppColors.usmGold,
          letterSpacing: 0.3,
        ),
      ),
    );
  }
}

class _MetaItem extends StatelessWidget {
  const _MetaItem({
    required this.icon,
    required this.label,
    this.warning = false,
  });

  final IconData icon;
  final String label;
  final bool warning;

  @override
  Widget build(BuildContext context) {
    final color = warning
        ? AppColors.usmGold.withValues(alpha: 0.80)
        : Colors.white.withValues(alpha: 0.85);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 16, color: color),
        const SizedBox(width: 6),
        Text(
          label,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 13,
            fontWeight: FontWeight.w500,
            color: color,
          ),
        ),
      ],
    );
  }
}

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
    final c = AppColors.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Choose scanning mode',
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 15,
            fontWeight: FontWeight.w600,
            color: c.onSurfaceMuted,
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

  static const Color _brandGreen = AppColors.brandGreen;
  static const Color _accentBlue = AppColors.accentBlue;
  static const Color _usmGold = AppColors.usmGold;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    final isTimeIn = scanType == ScanType.timeIn;
    final buttonColor = isTimeIn ? _brandGreen : _accentBlue;
    final borderColor = isTimeIn
        ? _usmGold.withValues(alpha: 0.25)
        : _accentBlue.withValues(alpha: 0.30);

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 18),
          decoration: BoxDecoration(
            color: c.isDark ? buttonColor.withValues(alpha: 0.12) : null,
            gradient: c.isDark
                ? null
                : LinearGradient(
                    begin: Alignment.centerLeft,
                    end: Alignment.centerRight,
                    colors: [
                      isTimeIn ? c.brandWash : c.blueWash,
                      Colors.white,
                      isTimeIn
                          ? c.goldWash.withValues(alpha: 0.52)
                          : c.blueWash.withValues(alpha: 0.62),
                    ],
                  ),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: c.isDark
                  ? borderColor
                  : buttonColor.withValues(alpha: 0.34),
              width: 1.5,
            ),
            boxShadow: c.isDark
                ? null
                : [
                    BoxShadow(
                      color: buttonColor.withValues(alpha: 0.10),
                      blurRadius: 16,
                      offset: const Offset(0, 6),
                    ),
                  ],
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
                      style: TextStyle(
                        fontFamily: 'Inter',
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: isTimeIn
                            ? (c.isDark ? _usmGold : _brandGreen)
                            : (c.isDark ? Colors.white : _accentBlue),
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: TextStyle(
                        fontFamily: 'Inter',
                        fontSize: 12,
                        color: c.onSurfaceMuted,
                      ),
                    ),
                  ],
                ),
              ),
              Icon(
                Icons.chevron_right_rounded,
                color: (isTimeIn ? _usmGold : _accentBlue).withValues(
                  alpha: 0.50,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
