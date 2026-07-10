import 'package:flutter/material.dart';

import '../../../../config/app_colors.dart';
import '../../domain/scan_sync_status.dart';

class GlobalFeedbackBanner extends StatelessWidget {
  const GlobalFeedbackBanner({
    super.key,
    required this.feedback,
    this.onDismiss,
  });

  final GlobalFeedback feedback;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    if (!feedback.isVisible) {
      return const SizedBox.shrink();
    }

    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    late Color background;
    late Color foreground;
    late IconData icon;

    switch (feedback.kind) {
      case FeedbackKind.loading:
        background = AppColors.accentBlue.withValues(alpha: 0.12);
        foreground = AppColors.accentBlue;
        icon = Icons.hourglass_top_rounded;
      case FeedbackKind.success:
        background = AppColors.accentGreen.withValues(alpha: 0.10);
        foreground = AppColors.accentGreen;
        icon = Icons.check_circle_rounded;
      case FeedbackKind.error:
        background = theme.colorScheme.error.withValues(alpha: 0.12);
        foreground = isDark ? AppColors.errorRedLight : theme.colorScheme.error;
        icon = Icons.error_outline_rounded;
      case FeedbackKind.warning:
        background = AppColors.usmGold.withValues(alpha: 0.10);
        foreground = AppColors.usmGold;
        icon = Icons.warning_amber_rounded;
      case FeedbackKind.none:
        return const SizedBox.shrink();
    }

    return Material(
      color: background,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (feedback.kind == FeedbackKind.loading)
                Padding(
                  padding: const EdgeInsets.only(top: 2, right: 12),
                  child: SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: foreground,
                    ),
                  ),
                )
              else
                Padding(
                  padding: const EdgeInsets.only(top: 2, right: 12),
                  child: Icon(icon, color: foreground, size: 20),
                ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      feedback.message,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: foreground,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    if (feedback.subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(
                        feedback.subtitle!,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: foreground.withValues(alpha: 0.80),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              if (onDismiss != null && feedback.kind != FeedbackKind.loading)
                IconButton(
                  icon: const Icon(Icons.close_rounded, size: 18),
                  color: foreground,
                  onPressed: onDismiss,
                  tooltip: 'Dismiss',
                ),
            ],
          ),
        ),
      ),
    );
  }
}
