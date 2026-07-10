import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../config/app_colors.dart';
import '../../../../config/app_config.dart';

class TechnicalDetailsPanel extends StatefulWidget {
  const TechnicalDetailsPanel({super.key, required this.details});

  final String details;

  @override
  State<TechnicalDetailsPanel> createState() => _TechnicalDetailsPanelState();
}

class _TechnicalDetailsPanelState extends State<TechnicalDetailsPanel> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    if (!AppConfig.debugDetails || widget.details.isEmpty) {
      return const SizedBox.shrink();
    }
    final c = AppColors.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextButton.icon(
          onPressed: () => setState(() => _expanded = !_expanded),
          icon: Icon(
            _expanded ? Icons.expand_less_rounded : Icons.expand_more_rounded,
            size: 18,
            color: AppColors.usmGold.withValues(alpha: 0.70),
          ),
          label: Text(
            'Technical details',
            style: TextStyle(color: AppColors.usmGold.withValues(alpha: 0.70)),
          ),
          style: TextButton.styleFrom(
            alignment: Alignment.centerLeft,
            padding: EdgeInsets.zero,
          ),
        ),
        if (_expanded) ...[
          const SizedBox(height: 4),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: c.isDark
                  ? Colors.white.withValues(alpha: 0.04)
                  : const Color(0xFFF3F6F4),
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: c.panelBorder),
            ),
            child: SelectableText(
              widget.details,
              style: TextStyle(
                fontFamily: 'monospace',
                fontSize: 11,
                color: c.isDark
                    ? Colors.white.withValues(alpha: 0.55)
                    : c.onSurfaceMuted,
              ),
            ),
          ),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: () {
                Clipboard.setData(ClipboardData(text: widget.details));
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: const Text('Technical details copied'),
                    backgroundColor: c.cardElevated,
                  ),
                );
              },
              icon: Icon(
                Icons.copy_rounded,
                size: 16,
                color: AppColors.usmGold.withValues(alpha: 0.60),
              ),
              label: Text(
                'Copy',
                style: TextStyle(
                  color: AppColors.usmGold.withValues(alpha: 0.60),
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}
