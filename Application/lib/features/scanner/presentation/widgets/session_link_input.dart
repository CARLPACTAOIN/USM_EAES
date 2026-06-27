import 'package:flutter/material.dart';

import '../scanner_controller.dart';

/// Session link paste field + Open Session primary CTA.
///
/// Shows an inline error message below the field if the link is invalid or the
/// session validation failed — never shows errors in a persistent bottom bar.
class SessionLinkInput extends StatelessWidget {
  const SessionLinkInput({
    super.key,
    required this.controller,
    required this.linkController,
    required this.onOpenSession,
  });

  final ScannerController controller;
  final TextEditingController linkController;
  final VoidCallback onOpenSession;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hasError = controller.errorMessage != null && !controller.hasSession;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // ----- Text field -----
        TextField(
          controller: linkController,
          minLines: 1,
          maxLines: 3,
          enabled: !controller.busy,
          style: theme.textTheme.bodyMedium,
          decoration: InputDecoration(
            labelText: 'Scanner session link',
            hintText: 'eaes://scanner?token=…&event_id=…',
            prefixIcon: const Icon(Icons.link_rounded),
            errorText: hasError ? _friendlyError(controller.errorMessage!) : null,
            errorMaxLines: 3,
          ),
          textInputAction: TextInputAction.go,
          onSubmitted: (_) => onOpenSession(),
        ),

        if (!hasError) ...[
          const SizedBox(height: 6),
          Text(
            'Ask the event organizer for the scanner link.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurface.withValues(alpha: 0.5),
            ),
          ),
        ],

        const SizedBox(height: 16),

        // ----- Primary CTA -----
        FilledButton.icon(
          onPressed: controller.busy ? null : onOpenSession,
          icon: controller.busy
              ? const SizedBox.square(
                  dimension: 18,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : const Icon(Icons.login_rounded),
          label: Text(controller.busy ? 'Validating…' : 'Open Scanner Session'),
        ),
      ],
    );
  }

  /// Converts raw error strings into user-friendly copy.
  String _friendlyError(String raw) {
    if (raw.contains('invalid') || raw.contains('eaes://')) {
      return 'Invalid scanner link.\n'
          'Please paste the complete scanner session link from the event dashboard.';
    }
    return raw;
  }
}
