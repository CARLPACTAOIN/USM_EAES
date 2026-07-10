import 'package:flutter/material.dart';

import '../../../../config/app_colors.dart';
import '../scanner_controller.dart';
import 'technical_details_panel.dart';

class SyncStatusPanel extends StatelessWidget {
  const SyncStatusPanel({
    super.key,
    required this.controller,
    this.compact = false,
  });

  final ScannerController controller;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ctrl = controller;
    final c = AppColors.of(context);

    return Container(
      padding: EdgeInsets.all(compact ? 14 : 20),
      decoration: BoxDecoration(
        color: c.isDark ? c.panel : null,
        gradient: c.isDark
            ? null
            : LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Colors.white,
                  c.blueWash.withValues(alpha: 0.62),
                  c.brandWash.withValues(alpha: 0.74),
                ],
              ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: c.isDark
              ? c.panelBorder
              : AppColors.accentBlue.withValues(alpha: 0.20),
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
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: c.isDark
                      ? AppColors.usmGold.withValues(alpha: 0.12)
                      : AppColors.accentBlue.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(
                  Icons.cloud_sync_rounded,
                  size: 19,
                  color: c.isDark ? AppColors.usmGold : AppColors.accentBlue,
                ),
              ),
              const SizedBox(width: 10),
              Text(
                'Sync Status',
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: c.onSurface,
                ),
              ),
              const Spacer(),
              _NetworkChip(isOnline: ctrl.isOnline),
            ],
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _MetricChip(
                label: 'Pending',
                value: '${ctrl.pendingCount}',
                color: ctrl.pendingCount > 0 ? AppColors.usmGold : null,
              ),
              _MetricChip(
                label: 'Synced',
                value: '${ctrl.syncedCount}',
                color: AppColors.accentGreen,
              ),
              _MetricChip(
                label: 'Failed',
                value: '${ctrl.failedCount}',
                color: ctrl.failedCount > 0 ? theme.colorScheme.error : null,
              ),
            ],
          ),
          const SizedBox(height: 12),
          _StatusLine(message: ctrl.syncStatusMessage),
          if (ctrl.lastSyncAttempt != null)
            _MetaLine(
              label: 'Last sync attempt',
              value: _formatTime(ctrl.lastSyncAttempt!),
            ),
          if (ctrl.lastSuccessfulSync != null)
            _MetaLine(
              label: 'Last successful sync',
              value: _formatTime(ctrl.lastSuccessfulSync!),
            ),
          if (ctrl.pendingCount > 0 || ctrl.failedCount > 0) ...[
            const SizedBox(height: 8),
            Text(
              'Do not uninstall the app until all scans are synced.',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: AppColors.usmGold.withValues(alpha: 0.80),
              ),
            ),
          ],
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed:
                      (!ctrl.isOnline || ctrl.syncing || ctrl.failedCount == 0)
                      ? null
                      : ctrl.retryFailedScans,
                  icon: const Icon(Icons.replay_rounded, size: 18),
                  label: const Text('Retry Failed'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: FilledButton.icon(
                  onPressed:
                      (!ctrl.isOnline ||
                          ctrl.syncing ||
                          (ctrl.pendingCount == 0 && ctrl.failedCount == 0))
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
                      : const Icon(Icons.cloud_upload_rounded, size: 18),
                  label: Text(ctrl.syncing ? 'Syncing…' : 'Sync Pending'),
                ),
              ),
            ],
          ),
          if (ctrl.errorMessage != null) ...[
            const SizedBox(height: 12),
            Text(
              ctrl.errorMessage!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
            TechnicalDetailsPanel(details: ctrl.errorDetails ?? ''),
          ],
        ],
      ),
    );
  }

  String _formatTime(DateTime value) {
    final local = value.toLocal();
    final hour = local.hour % 12 == 0 ? 12 : local.hour % 12;
    final minute = local.minute.toString().padLeft(2, '0');
    final period = local.hour >= 12 ? 'PM' : 'AM';
    return '${local.month}/${local.day}/${local.year} • $hour:$minute $period';
  }
}

class _NetworkChip extends StatelessWidget {
  const _NetworkChip({required this.isOnline});

  final bool isOnline;

  static const Color _accentGreen = AppColors.accentGreen;
  static const Color _usmGold = AppColors.usmGold;

  @override
  Widget build(BuildContext context) {
    final color = isOnline ? _accentGreen : _usmGold;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color.withValues(alpha: 0.25)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            isOnline ? Icons.wifi_rounded : Icons.wifi_off_rounded,
            size: 13,
            color: color,
          ),
          const SizedBox(width: 5),
          Text(
            isOnline ? 'Online' : 'Offline',
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}

class _MetricChip extends StatelessWidget {
  const _MetricChip({required this.label, required this.value, this.color});

  final String label;
  final String value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    final chipColor = color ?? AppColors.of(context).onSurfaceMuted;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: chipColor.withValues(alpha: c.isDark ? 0.10 : 0.15),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: chipColor.withValues(alpha: c.isDark ? 0.20 : 0.28),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 11,
              fontWeight: FontWeight.w500,
              color: chipColor.withValues(alpha: 0.80),
            ),
          ),
          Text(
            value,
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: chipColor,
            ),
          ),
        ],
      ),
    );
  }
}

class _StatusLine extends StatelessWidget {
  const _StatusLine({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    return Text(
      message,
      style: TextStyle(
        fontFamily: 'Inter',
        fontSize: 12,
        fontWeight: FontWeight.w600,
        color: c.onSurfaceMuted,
      ),
    );
  }
}

class _MetaLine extends StatelessWidget {
  const _MetaLine({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Text(
        '$label: $value',
        style: TextStyle(
          fontFamily: 'Inter',
          fontSize: 11,
          fontWeight: FontWeight.w500,
          color: c.onSurfaceMuted.withValues(alpha: c.isDark ? 0.65 : 0.88),
        ),
      ),
    );
  }
}
