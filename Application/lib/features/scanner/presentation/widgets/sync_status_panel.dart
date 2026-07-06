import 'package:flutter/material.dart';

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
    final isDark = theme.brightness == Brightness.dark;
    final ctrl = controller;

    return Container(
      padding: EdgeInsets.all(compact ? 14 : 20),
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
              const Icon(
                Icons.cloud_sync_rounded,
                size: 20,
                color: Color(0xFF1B4D3E),
              ),
              const SizedBox(width: 10),
              Text(
                'Sync Status',
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
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
                color: ctrl.pendingCount > 0 ? Colors.orange.shade700 : null,
              ),
              _MetricChip(
                label: 'Synced',
                value: '${ctrl.syncedCount}',
                color: const Color(0xFF1B4D3E),
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
              style: theme.textTheme.bodySmall?.copyWith(
                color: Colors.orange.shade800,
                fontWeight: FontWeight.w600,
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = isOnline ? const Color(0xFF16A34A) : Colors.orange.shade700;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
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
            style: theme.textTheme.labelSmall?.copyWith(
              color: color,
              fontWeight: FontWeight.w700,
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
    final theme = Theme.of(context);
    final chipColor = color ?? theme.colorScheme.onSurface;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: chipColor.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: chipColor.withValues(alpha: 0.25)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: theme.textTheme.labelSmall?.copyWith(color: chipColor),
          ),
          Text(
            value,
            style: theme.textTheme.titleMedium?.copyWith(
              color: chipColor,
              fontWeight: FontWeight.w800,
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
    final theme = Theme.of(context);
    return Text(
      message,
      style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600),
    );
  }
}

class _MetaLine extends StatelessWidget {
  const _MetaLine({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Text(
        '$label: $value',
        style: theme.textTheme.labelSmall?.copyWith(
          color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
        ),
      ),
    );
  }
}
