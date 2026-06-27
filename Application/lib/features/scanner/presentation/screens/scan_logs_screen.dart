import 'package:flutter/material.dart';

import '../../domain/offline_scan.dart';
import '../../domain/scan_type.dart';
import '../scanner_controller.dart';

/// Screen 4 — Scan Logs.
///
/// Shows recent scans for the active event in reverse-chronological order.
/// Includes student name (or "Unresolved QR"), scan type chip, timestamp, and
/// sync/resolution status.
class ScanLogsScreen extends StatefulWidget {
  const ScanLogsScreen({
    super.key,
    required this.controller,
  });

  final ScannerController controller;

  @override
  State<ScanLogsScreen> createState() => _ScanLogsScreenState();
}

class _ScanLogsScreenState extends State<ScanLogsScreen> {
  List<OfflineScan>? _scans;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadScans();
  }

  Future<void> _loadScans() async {
    if (!widget.controller.hasSession) return;

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final scans = await widget.controller.recentScans(limit: 100);
      if (mounted) setState(() => _scans = scans);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (!widget.controller.hasSession) {
      return _EmptyState(
        icon: Icons.receipt_long_rounded,
        title: 'No active session',
        subtitle:
            'Open a scanner session to view captured attendance logs.',
      );
    }

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                Icons.error_outline_rounded,
                size: 48,
                color: theme.colorScheme.error,
              ),
              const SizedBox(height: 16),
              Text(
                'Could not load logs',
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
                ),
              ),
              const SizedBox(height: 20),
              FilledButton.icon(
                onPressed: _loadScans,
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('Retry'),
                style: FilledButton.styleFrom(
                  minimumSize: const Size(0, 44),
                ),
              ),
            ],
          ),
        ),
      );
    }

    final scans = _scans ?? [];

    if (scans.isEmpty) {
      return _EmptyState(
        icon: Icons.inbox_rounded,
        title: 'No scans yet',
        subtitle:
            'Scans captured during this event will appear here.',
        action: OutlinedButton.icon(
          onPressed: _loadScans,
          icon: const Icon(Icons.refresh_rounded, size: 18),
          label: const Text('Refresh'),
          style: OutlinedButton.styleFrom(minimumSize: const Size(0, 44)),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _loadScans,
      color: const Color(0xFF1B4D3E),
      child: CustomScrollView(
        slivers: [
          // Summary header
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 8),
              child: Row(
                children: [
                  Text(
                    '${scans.length} scan${scans.length == 1 ? '' : 's'}',
                    style: theme.textTheme.titleSmall?.copyWith(
                      color: theme.colorScheme.onSurface.withValues(
                        alpha: 0.7,
                      ),
                    ),
                  ),
                  const Spacer(),
                  Text(
                    'Pull to refresh',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: theme.colorScheme.onSurface.withValues(
                        alpha: 0.4,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // Scan list
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
            sliver: SliverList.separated(
              itemCount: scans.length,
              separatorBuilder: (_, index) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                return _ScanLogTile(scan: scans[index]);
              },
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Sub-widgets
// ---------------------------------------------------------------------------

class _EmptyState extends StatelessWidget {
  const _EmptyState({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.action,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(40),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icon,
              size: 64,
              color: theme.colorScheme.onSurface.withValues(alpha: 0.2),
            ),
            const SizedBox(height: 20),
            Text(
              title,
              style: theme.textTheme.titleMedium?.copyWith(
                color: theme.colorScheme.onSurface.withValues(alpha: 0.7),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              subtitle,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurface.withValues(alpha: 0.45),
              ),
            ),
            if (action != null) ...[
              const SizedBox(height: 24),
              action!,
            ],
          ],
        ),
      ),
    );
  }
}

class _ScanLogTile extends StatelessWidget {
  const _ScanLogTile({required this.scan});
  final OfflineScan scan;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    final isTimeIn = scan.scanType == ScanType.timeIn;
    final isSynced = scan.syncedAt != null;
    final isUnresolved = scan.resolutionStatus == 'pending_server_lookup';
    final isManual = scan.manualEntry;

    // Display name
    final displayName = scan.studentId != null
        ? scan.qrCodeValue // Will ideally come from a joined query later
        : 'Unresolved QR';

    // Colours
    final typeColor = isTimeIn
        ? const Color(0xFF1B4D3E)
        : const Color(0xFF2563EB);

    final syncColor = isSynced
        ? const Color(0xFF16A34A)
        : isUnresolved
            ? Colors.orange.shade700
            : theme.colorScheme.onSurface.withValues(alpha: 0.4);

    final syncLabel = isSynced
        ? 'Synced'
        : isUnresolved
            ? 'Unresolved'
            : 'Pending sync';

    final syncIcon = isSynced
        ? Icons.cloud_done_rounded
        : isUnresolved
            ? Icons.manage_search_rounded
            : Icons.cloud_off_rounded;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF1E293B) : Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: theme.colorScheme.outlineVariant,
        ),
      ),
      child: Row(
        children: [
          // Scan type icon
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: typeColor.withValues(alpha: isDark ? 0.15 : 0.08),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(
              isTimeIn ? Icons.login_rounded : Icons.logout_rounded,
              color: typeColor,
              size: 20,
            ),
          ),
          const SizedBox(width: 12),

          // Main content
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        displayName,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (isManual)
                      Container(
                        margin: const EdgeInsets.only(left: 6),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 6,
                          vertical: 2,
                        ),
                        decoration: BoxDecoration(
                          color: theme.colorScheme.surfaceContainerHighest,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(
                          'Manual',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    // Type chip
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 7,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        color: typeColor.withValues(
                          alpha: isDark ? 0.15 : 0.08,
                        ),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        scan.scanType.label,
                        style: TextStyle(
                          fontFamily: 'Inter',
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: typeColor,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    // Timestamp
                    Text(
                      _formatTime(scan.scannedAt),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurface.withValues(
                          alpha: 0.5,
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),

          const SizedBox(width: 10),

          // Sync status
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(syncIcon, size: 16, color: syncColor),
              const SizedBox(height: 2),
              Text(
                syncLabel,
                style: TextStyle(
                  fontFamily: 'Inter',
                  fontSize: 10,
                  fontWeight: FontWeight.w500,
                  color: syncColor,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _formatTime(DateTime dt) {
    final local = dt.toLocal();
    final h = local.hour.toString().padLeft(2, '0');
    final m = local.minute.toString().padLeft(2, '0');
    final mon = _monthAbbr(local.month);
    return '${local.day} $mon $h:$m';
  }

  String _monthAbbr(int month) {
    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    return months[month - 1];
  }
}
