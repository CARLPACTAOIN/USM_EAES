import 'dart:async';

import 'package:flutter/material.dart';

import '../../domain/scan_type.dart';
import '../scanner_controller.dart';
import '../widgets/scan_result_card.dart';
import '../widgets/scanner_viewfinder.dart';

/// Screen 2 — Live Scanner (pushed, not a tab).
///
/// Focused entirely on scanning:
/// - Top AppBar: event name + mode pill + pending badge
/// - Large camera viewfinder (~70 % of viewport)
/// - Animated last-scan result card
/// - Bottom row: Manual Entry | Switch Mode | Sync (when pending > 0)
class LiveScannerScreen extends StatefulWidget {
  const LiveScannerScreen({
    super.key,
    required this.controller,
  });

  final ScannerController controller;

  @override
  State<LiveScannerScreen> createState() => _LiveScannerScreenState();
}

class _LiveScannerScreenState extends State<LiveScannerScreen> {
  bool _scanLocked = false;
  bool _showManualEntry = false;
  final TextEditingController _manualController = TextEditingController();

  ScannerController get _ctrl => widget.controller;

  @override
  void dispose() {
    _manualController.dispose();
    super.dispose();
  }

  Future<void> _handleScan(dynamic capture) async {
    if (_scanLocked || !_ctrl.hasSession) return;

    // capture is BarcodeCapture from mobile_scanner
    final barcodes = (capture as dynamic).barcodes as List<dynamic>;
    final rawValue = barcodes
        .map((b) => b.rawValue as String?)
        .whereType<String>()
        .where((v) => v.trim().isNotEmpty)
        .firstOrNull;

    if (rawValue == null) return;

    setState(() => _scanLocked = true);
    await _ctrl.recordQr(rawValue);

    await Future<void>.delayed(const Duration(milliseconds: 900));
    if (mounted) setState(() => _scanLocked = false);
  }

  Future<void> _handleManualScan() async {
    final value = _manualController.text.trim();
    if (value.isEmpty) return;

    final result = await _ctrl.recordQr(value, manual: true);
    if (result.success) _manualController.clear();

    if (mounted) setState(() => _showManualEntry = false);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return AnimatedBuilder(
      animation: _ctrl,
      builder: (context, _) {
        final scanType = _ctrl.scanType;
        final isTimeIn = scanType == ScanType.timeIn;
        final pendingCount = _ctrl.pendingCount;

        return Scaffold(
          backgroundColor: isDark ? const Color(0xFF0A0F1E) : const Color(0xFFF0F4F8),
          appBar: _ScannerAppBar(
            ctrl: _ctrl,
            scanType: scanType,
            isTimeIn: isTimeIn,
            pendingCount: pendingCount,
          ),
          body: SafeArea(
            child: Column(
              children: [
                // ---------- Camera area ----------
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                    child: ScannerViewfinder(
                      active: _ctrl.hasSession,
                      scanLocked: _scanLocked,
                      onDetect: _handleScan,
                    ),
                  ),
                ),

                const SizedBox(height: 12),

                // ---------- Last scan result ----------
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 250),
                  switchInCurve: Curves.easeOutCubic,
                  transitionBuilder: (child, animation) => FadeTransition(
                    opacity: animation,
                    child: SlideTransition(
                      position: Tween<Offset>(
                        begin: const Offset(0, 0.3),
                        end: Offset.zero,
                      ).animate(animation),
                      child: child,
                    ),
                  ),
                  child: _ctrl.lastScanResult != null
                      ? Padding(
                          key: ValueKey(_ctrl.lastScanResult?.title),
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          child: ScanResultCard(
                            result: _ctrl.lastScanResult!,
                            compact: true,
                          ),
                        )
                      : const SizedBox.shrink(key: ValueKey('no-result')),
                ),

                const SizedBox(height: 12),

                // ---------- Manual entry panel ----------
                AnimatedSize(
                  duration: const Duration(milliseconds: 250),
                  curve: Curves.easeOutCubic,
                  child: _showManualEntry
                      ? _ManualEntryPanel(
                          controller: _manualController,
                          onSubmit: _handleManualScan,
                          onDismiss: () =>
                              setState(() => _showManualEntry = false),
                        )
                      : const SizedBox.shrink(),
                ),

                // ---------- Bottom action row ----------
                _BottomActionRow(
                  ctrl: _ctrl,
                  isTimeIn: isTimeIn,
                  pendingCount: pendingCount,
                  onManualEntry: () =>
                      setState(() => _showManualEntry = !_showManualEntry),
                  onSwitchMode: () {
                    _ctrl.setScanType(
                      isTimeIn ? ScanType.timeOut : ScanType.timeIn,
                    );
                  },
                  onSync: _ctrl.syncPending,
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Sub-widgets
// ---------------------------------------------------------------------------

class _ScannerAppBar extends StatelessWidget implements PreferredSizeWidget {
  const _ScannerAppBar({
    required this.ctrl,
    required this.scanType,
    required this.isTimeIn,
    required this.pendingCount,
  });

  final ScannerController ctrl;
  final ScanType scanType;
  final bool isTimeIn;
  final int pendingCount;

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return AppBar(
      leading: IconButton(
        icon: const Icon(Icons.keyboard_arrow_down_rounded, size: 28),
        tooltip: 'Back to session',
        onPressed: () => Navigator.of(context).pop(),
      ),
      title: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            ctrl.eventTitle ?? 'Scanning',
            style: theme.textTheme.titleMedium?.copyWith(
              color: Colors.white,
              fontWeight: FontWeight.w700,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 1),
          Text(
            'USM EAES Scanner',
            style: theme.textTheme.labelSmall?.copyWith(
              color: Colors.white.withValues(alpha: 0.65),
            ),
          ),
        ],
      ),
      actions: [
        // Mode pill
        Container(
          margin: const EdgeInsets.only(right: 4),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.15),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: Colors.white.withValues(alpha: 0.25)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                isTimeIn ? Icons.login_rounded : Icons.logout_rounded,
                size: 14,
                color: Colors.white,
              ),
              const SizedBox(width: 5),
              Text(
                scanType.label,
                style: const TextStyle(
                  fontFamily: 'Inter',
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                ),
              ),
            ],
          ),
        ),
        // Pending badge
        if (pendingCount > 0)
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: _PendingBadge(count: pendingCount),
          ),
      ],
    );
  }
}

class _PendingBadge extends StatelessWidget {
  const _PendingBadge({required this.count});
  final int count;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.orange.shade600,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        '$count',
        style: const TextStyle(
          fontFamily: 'Inter',
          fontSize: 12,
          fontWeight: FontWeight.w700,
          color: Colors.white,
        ),
      ),
    );
  }
}

class _BottomActionRow extends StatelessWidget {
  const _BottomActionRow({
    required this.ctrl,
    required this.isTimeIn,
    required this.pendingCount,
    required this.onManualEntry,
    required this.onSwitchMode,
    required this.onSync,
  });

  final ScannerController ctrl;
  final bool isTimeIn;
  final int pendingCount;
  final VoidCallback onManualEntry;
  final VoidCallback onSwitchMode;
  final VoidCallback onSync;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF1E293B) : Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: isDark ? 0.3 : 0.08),
            blurRadius: 12,
            offset: const Offset(0, -2),
          ),
        ],
      ),
      child: Row(
        children: [
          // Manual entry
          Expanded(
            child: _ActionButton(
              icon: Icons.keyboard_rounded,
              label: 'Manual Entry',
              onTap: onManualEntry,
            ),
          ),
          const SizedBox(width: 8),
          // Switch mode
          Expanded(
            child: _ActionButton(
              icon: isTimeIn
                  ? Icons.logout_rounded
                  : Icons.login_rounded,
              label: isTimeIn ? 'Switch to Out' : 'Switch to In',
              onTap: onSwitchMode,
            ),
          ),
          // Sync — only visible when there are pending scans
          if (pendingCount > 0) ...[
            const SizedBox(width: 8),
            Expanded(
              child: _ActionButton(
                icon: Icons.cloud_upload_rounded,
                label: 'Sync $pendingCount',
                onTap: ctrl.syncing ? null : onSync,
                loading: ctrl.syncing,
                primary: true,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ActionButton extends StatelessWidget {
  const _ActionButton({
    required this.icon,
    required this.label,
    required this.onTap,
    this.loading = false,
    this.primary = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool loading;
  final bool primary;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    const brandGreen = Color(0xFF1B4D3E);

    final bgColor = primary
        ? brandGreen
        : isDark
            ? const Color(0xFF334155)
            : const Color(0xFFF1F5F9);

    final fgColor = primary
        ? Colors.white
        : isDark
            ? const Color(0xFFCBD5E1)
            : const Color(0xFF334155);

    return Material(
      color: bgColor,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Container(
          height: 52,
          alignment: Alignment.center,
          child: loading
              ? SizedBox.square(
                  dimension: 18,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: fgColor,
                  ),
                )
              : Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(icon, color: fgColor, size: 20),
                    const SizedBox(height: 3),
                    Text(
                      label,
                      style: TextStyle(
                        fontFamily: 'Inter',
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: fgColor,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

class _ManualEntryPanel extends StatelessWidget {
  const _ManualEntryPanel({
    required this.controller,
    required this.onSubmit,
    required this.onDismiss,
  });

  final TextEditingController controller;
  final VoidCallback onSubmit;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF1E293B) : Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: theme.colorScheme.outlineVariant,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.keyboard_rounded, size: 18),
              const SizedBox(width: 8),
              Text(
                'Manual QR Entry',
                style: theme.textTheme.titleSmall,
              ),
              const Spacer(),
              IconButton(
                icon: const Icon(Icons.close_rounded, size: 20),
                onPressed: onDismiss,
                tooltip: 'Close',
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
              ),
            ],
          ),
          const SizedBox(height: 10),
          TextField(
            controller: controller,
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'QR code value',
              hintText: 'Paste or type the QR value',
              prefixIcon: Icon(Icons.qr_code_rounded),
            ),
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => onSubmit(),
          ),
          const SizedBox(height: 10),
          FilledButton.icon(
            onPressed: onSubmit,
            icon: const Icon(Icons.save_alt_rounded, size: 18),
            label: const Text('Save Manual Scan'),
            style: FilledButton.styleFrom(minimumSize: const Size(0, 46)),
          ),
        ],
      ),
    );
  }
}
