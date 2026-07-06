import 'package:flutter/material.dart';

import '../scanner_controller.dart';

class ScanResultCard extends StatelessWidget {
  const ScanResultCard({super.key, required this.result, this.compact = false});

  final ScanCaptureResult result;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final Color borderColor;
    final Color backgroundColor;
    final IconData statusIcon;

    if (result.isDuplicate || result.kind == ScanResultKind.duplicate) {
      borderColor = Colors.amber.shade700;
      backgroundColor = Colors.amber.shade50;
      statusIcon = Icons.warning_amber_rounded;
    } else if (result.success) {
      if (result.unresolved) {
        borderColor = Colors.orange.shade600;
        backgroundColor = Colors.orange.shade50;
        statusIcon = Icons.manage_search_rounded;
      } else {
        borderColor = const Color(0xFF1B4D3E);
        backgroundColor = const Color(0xFFECFDF5);
        statusIcon = Icons.check_circle_rounded;
      }
    } else {
      borderColor = theme.colorScheme.error;
      backgroundColor = theme.colorScheme.errorContainer.withValues(alpha: 0.4);
      statusIcon = Icons.error_outline_rounded;
    }

    final isDark = theme.brightness == Brightness.dark;
    final effectiveBg = isDark
        ? borderColor.withValues(alpha: 0.15)
        : backgroundColor;

    final verticalPadding = compact ? 10.0 : 14.0;
    final horizontalPadding = compact ? 12.0 : 16.0;

    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(
        horizontal: horizontalPadding,
        vertical: verticalPadding,
      ),
      decoration: BoxDecoration(
        color: effectiveBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: borderColor.withValues(alpha: 0.5),
          width: 1.5,
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(statusIcon, color: borderColor, size: compact ? 20 : 24),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  result.title,
                  style:
                      (compact
                              ? theme.textTheme.labelLarge
                              : theme.textTheme.titleSmall)
                          ?.copyWith(
                            color: borderColor,
                            fontWeight: FontWeight.w700,
                          ),
                ),
                const SizedBox(height: 2),
                Text(
                  result.detail,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: isDark
                        ? theme.colorScheme.onSurface.withValues(alpha: 0.8)
                        : theme.colorScheme.onSurface.withValues(alpha: 0.7),
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
