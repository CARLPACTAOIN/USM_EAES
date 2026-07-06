import 'package:flutter/material.dart';

import '../../../scanner/presentation/scanner_controller.dart';
import '../../../scanner/presentation/scanner_home_screen.dart';
import '../../domain/scanner_assignment.dart';
import '../auth_controller.dart';

class AssignedEventsScreen extends StatelessWidget {
  const AssignedEventsScreen({
    super.key,
    required this.authController,
    required this.scannerController,
  });

  final AuthController authController;
  final ScannerController scannerController;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Assigned Events'),
        actions: [
          IconButton(
            tooltip: 'Refresh assignments',
            onPressed: authController.busy
                ? null
                : () => authController.refreshAssignments(),
            icon: const Icon(Icons.refresh_rounded),
          ),
          IconButton(
            tooltip: 'Sign out',
            onPressed: authController.busy ? null : authController.signOut,
            icon: const Icon(Icons.logout_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: authController.refreshAssignments,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (authController.statusMessage != null)
              _StateCard(
                icon: Icons.info_outline_rounded,
                message: authController.statusMessage!,
              ),
            if (authController.errorMessage != null)
              _StateCard(
                icon: Icons.wifi_off_rounded,
                message: authController.errorMessage!,
                isError: true,
              ),
            if (scannerController.hasSession) ...[
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        scannerController.eventTitle ?? 'Active scanner event',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Scanner session is active on this device.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 12),
                      FilledButton.icon(
                        onPressed: () => _openScanner(context),
                        icon: const Icon(Icons.qr_code_scanner_rounded),
                        label: const Text('Open scanner'),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
            ],
            if (authController.assignments.isEmpty)
              const _EmptyAssignmentsState()
            else
              ...authController.assignments.map(
                (assignment) => Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _AssignmentCard(
                    assignment: assignment,
                    busy: authController.busy,
                    onActivate: () async {
                      final activated = await authController.activateAssignment(
                        assignment,
                        scannerController,
                      );
                      if (activated && context.mounted) {
                        _openScanner(context);
                      }
                    },
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  void _openScanner(BuildContext context) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ScannerHomeScreen(controller: scannerController),
      ),
    );
  }
}

class _AssignmentCard extends StatelessWidget {
  const _AssignmentCard({
    required this.assignment,
    required this.busy,
    required this.onActivate,
  });

  final ScannerAssignment assignment;
  final bool busy;
  final VoidCallback onActivate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final canActivate = assignment.activationAllowed && assignment.isActive;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        assignment.eventTitle,
                        style: theme.textTheme.titleMedium,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        assignment.organizationAcronym ??
                            assignment.organizationName ??
                            'USM event',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
                Chip(
                  label: Text(_labelForStatus(assignment)),
                  avatar: Icon(
                    canActivate
                        ? Icons.check_circle_outline_rounded
                        : Icons.lock_outline_rounded,
                    size: 16,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              assignment.label == null || assignment.label!.isEmpty
                  ? 'Event scanner'
                  : assignment.label!,
              style: theme.textTheme.bodyMedium,
            ),
            if (assignment.participationScopeName != null) ...[
              const SizedBox(height: 4),
              Text(
                'Scope: ${assignment.participationScopeName}',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.primary,
                ),
              ),
            ],
            const SizedBox(height: 4),
            Text(
              _helperText(assignment),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 14),
            FilledButton.icon(
              onPressed: canActivate && !busy ? onActivate : null,
              icon: const Icon(Icons.play_arrow_rounded),
              label: Text(
                canActivate ? 'Activate event' : 'Activation blocked',
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _labelForStatus(ScannerAssignment assignment) {
    if (!assignment.isActive) return assignment.status;
    if (!assignment.activationAllowed) return 'locked';
    return assignment.eventPhase;
  }

  String _helperText(ScannerAssignment assignment) {
    if (assignment.status == 'revoked') {
      return 'Access was revoked by an admin. Pending scans remain on this device until synced or reviewed.';
    }
    if (assignment.status == 'expired') {
      return 'This assignment expired. Ask an admin to assign a new scanner session.';
    }
    if (!assignment.activationAllowed) {
      return 'The event is not currently available for scanner activation.';
    }
    return 'Login verified. Activation creates a device-bound scanner session for this event.';
  }
}

class _EmptyAssignmentsState extends StatelessWidget {
  const _EmptyAssignmentsState();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            Icon(
              Icons.assignment_ind_outlined,
              size: 48,
              color: theme.colorScheme.primary,
            ),
            const SizedBox(height: 16),
            Text('No scanner assignments', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(
              'You are signed in, but no admin has assigned this account as a scanner for an active event.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StateCard extends StatelessWidget {
  const _StateCard({
    required this.icon,
    required this.message,
    this.isError = false,
  });

  final IconData icon;
  final String message;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final color = isError
        ? Theme.of(context).colorScheme.error
        : Theme.of(context).colorScheme.primary;

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        color: color.withValues(alpha: 0.08),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Icon(icon, color: color),
              const SizedBox(width: 10),
              Expanded(child: Text(message)),
            ],
          ),
        ),
      ),
    );
  }
}
