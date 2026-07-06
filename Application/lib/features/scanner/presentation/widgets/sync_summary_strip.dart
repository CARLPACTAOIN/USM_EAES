import 'package:flutter/material.dart';

import '../scanner_controller.dart';

/// Compact, glanceable sync status used on the Scanner and Live Scanner
/// screens. Shows a single status line plus pending / synced / failed counts.
/// It is intentionally read-only — the full panel with retry actions lives on
/// the Offline Tools tab to avoid duplicating controls.
class SyncSummaryStrip extends StatelessWidget {
  const SyncSummaryStrip({super.key, required this.controller, this.onTap});

  final ScannerController controller;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final ctrl = controller;

    final (Color accent, IconData icon) = _statusVisual(ctrl, theme);

    return Material(
      color: isDark ? const Color(0xFF1E293B) : Colors.white,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: theme.colorScheme.outlineVariant),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              Icon(icon, size: 18, color: accent),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  ctrl.syncStatusMessage,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelLarge?.copyWith(
                    color: accent,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              _Count(
                value: ctrl.pendingCount,
                color: Colors.orange.shade700,
                tooltip: 'Pending',
              ),
              if (ctrl.failedCount > 0)
                _Count(
                  value: ctrl.failedCount,
                  color: theme.colorScheme.error,
                  tooltip: 'Failed',
                ),
              if (onTap != null) ...[
                const SizedBox(width: 4),
                Icon(
                  Icons.chevron_right_rounded,
                  size: 18,
                  color: theme.colorScheme.onSurface.withValues(alpha: 0.35),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  (Color, IconData) _statusVisual(ScannerController ctrl, ThemeData theme) {
    if (ctrl.syncing) {
      return (const Color(0xFF2563EB), Icons.cloud_sync_rounded);
    }
    if (!ctrl.isOnline) {
      return (Colors.orange.shade700, Icons.cloud_off_rounded);
    }
    if (ctrl.failedCount > 0) {
      return (theme.colorScheme.error, Icons.error_outline_rounded);
    }
    if (ctrl.pendingCount == 0) {
      return (const Color(0xFF16A34A), Icons.cloud_done_rounded);
    }
    return (Colors.orange.shade700, Icons.cloud_upload_rounded);
  }
}

class _Count extends StatelessWidget {
  const _Count({
    required this.value,
    required this.color,
    required this.tooltip,
  });

  final int value;
  final Color color;
  final String tooltip;

  @override
  Widget build(BuildContext context) {
    if (value == 0) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    return Tooltip(
      message: tooltip,
      child: Container(
        margin: const EdgeInsets.only(left: 8),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          '$value',
          style: theme.textTheme.labelMedium?.copyWith(
            color: color,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}
