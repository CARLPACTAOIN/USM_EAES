import 'package:flutter/material.dart';

import '../../../../config/app_colors.dart';

import '../scanner_controller.dart';

class ScanResultCard extends StatelessWidget {
  const ScanResultCard({super.key, required this.result, this.compact = false});

  final ScanCaptureResult result;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = AppColors.of(context);

    final Color borderColor;
    final IconData statusIcon;

    if (result.isDuplicate || result.kind == ScanResultKind.duplicate) {
      borderColor = AppColors.usmGold;
      statusIcon = Icons.warning_amber_rounded;
    } else if (result.success) {
      if (result.unresolved) {
        borderColor = AppColors.usmGold.withValues(alpha: 0.80);
        statusIcon = Icons.manage_search_rounded;
      } else {
        borderColor = AppColors.accentGreen;
        statusIcon = Icons.check_circle_rounded;
      }
    } else {
      borderColor = theme.colorScheme.error;
      statusIcon = Icons.error_outline_rounded;
    }

    final effectiveBg = borderColor.withValues(alpha: 0.10);

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
          color: borderColor.withValues(alpha: 0.30),
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
                    color: c.onSurfaceMuted,
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
