import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

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

    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextButton.icon(
          onPressed: () => setState(() => _expanded = !_expanded),
          icon: Icon(
            _expanded ? Icons.expand_less_rounded : Icons.expand_more_rounded,
            size: 18,
          ),
          label: const Text('Technical details'),
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
              color: theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(8),
            ),
            child: SelectableText(
              widget.details,
              style: theme.textTheme.bodySmall?.copyWith(
                fontFamily: 'monospace',
                fontSize: 11,
              ),
            ),
          ),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: () {
                Clipboard.setData(ClipboardData(text: widget.details));
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Technical details copied')),
                );
              },
              icon: const Icon(Icons.copy_rounded, size: 16),
              label: const Text('Copy'),
            ),
          ),
        ],
      ],
    );
  }
}
