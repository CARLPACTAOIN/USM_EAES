import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../config/app_colors.dart';
import '../../../../config/app_config.dart';
import '../../domain/scan_type.dart';
import '../formatters/student_id_input_formatter.dart';
import '../scanner_controller.dart';
import '../widgets/scan_result_card.dart';
import '../widgets/scanner_viewfinder.dart';
import '../widgets/sync_summary_strip.dart';

class LiveScannerScreen extends StatefulWidget {
  const LiveScannerScreen({super.key, required this.controller});

  final ScannerController controller;

  @override
  State<LiveScannerScreen> createState() => _LiveScannerScreenState();
}

class _LiveScannerScreenState extends State<LiveScannerScreen> {
  bool _showDuplicateOverlay = false;
  bool _showManualEntry = false;
  String? _manualError;
  final TextEditingController _manualController = TextEditingController();
  Timer? _duplicateOverlayTimer;

  // --- Brand tokens ---------------------------------------------------------

  /// Post-scan throttle: after a successful capture, ignore detections for a
  /// short window so we don't rapidly read the next QR (or re-read this one).
  bool _scanThrottled = false;
  Timer? _scanThrottleTimer;

  /// Frame-presence tracking for the "QR must leave the frame" guard.
  /// Holds the raw value currently sitting in the camera frame; cleared once
  /// the camera reports no detections for [AppConfig.frameClearMs].
  String? _valueInFrame;
  Timer? _frameClearTimer;

  ScannerController get _ctrl => widget.controller;

  @override
  void dispose() {
    _duplicateOverlayTimer?.cancel();
    _scanThrottleTimer?.cancel();
    _frameClearTimer?.cancel();
    _manualController.dispose();
    super.dispose();
  }

  ViewfinderProcessingState get _processingState {
    if (_ctrl.isSavingScan) {
      return ViewfinderProcessingState.saving;
    }
    if (_showDuplicateOverlay) {
      return ViewfinderProcessingState.duplicate;
    }
    if (_scanThrottled) {
      return ViewfinderProcessingState.captured;
    }
    return ViewfinderProcessingState.idle;
  }

  void _showDuplicateFeedback() {
    _duplicateOverlayTimer?.cancel();
    setState(() => _showDuplicateOverlay = true);
    HapticFeedback.lightImpact();
    _duplicateOverlayTimer = Timer(const Duration(milliseconds: 1800), () {
      if (mounted) {
        setState(() => _showDuplicateOverlay = false);
      }
    });
  }

  /// Begins the post-scan throttle window after a successful capture.
  void _startScanThrottle() {
    _scanThrottleTimer?.cancel();
    setState(() => _scanThrottled = true);
    HapticFeedback.mediumImpact();
    _scanThrottleTimer = Timer(
      Duration(milliseconds: AppConfig.postScanThrottleMs),
      () {
        if (mounted) setState(() => _scanThrottled = false);
      },
    );
  }

  /// Re-arms the timer that clears [_valueInFrame] once the QR leaves the view.
  /// While a code stays in frame, detections keep firing and re-arm this timer,
  /// so it only fires after the frame is empty for [AppConfig.frameClearMs].
  void _armFrameClearTimer() {
    _frameClearTimer?.cancel();
    _frameClearTimer = Timer(
      Duration(milliseconds: AppConfig.frameClearMs),
      () => _valueInFrame = null,
    );
  }

  Future<void> _handleScan(dynamic capture) async {
    if (!_ctrl.hasSession) return;

    final barcodes = (capture as dynamic).barcodes as List<dynamic>;
    final rawValue = barcodes
        .map((b) => b.rawValue as String?)
        .whereType<String>()
        .where((v) => v.trim().isNotEmpty)
        .firstOrNull;

    if (rawValue == null) return;

    // Keep frame-presence tracking fresh on every detection (even while saving
    // or throttled) so the "must leave frame" guard stays accurate.
    final sameValueStillInFrame = rawValue == _valueInFrame;
    _valueInFrame = rawValue;
    _armFrameClearTimer();

    // Guard 1: saving in progress or post-scan throttle window is active.
    if (_ctrl.isSavingScan || _scanThrottled) return;

    // Guard 2 (optional): same code has not left the frame yet.
    if (AppConfig.requireQrExitFrame && sameValueStillInFrame) return;

    final result = await _ctrl.recordQr(rawValue);
    if (result.success) {
      _startScanThrottle();
    } else if (result.isDuplicate) {
      _showDuplicateFeedback();
    }
  }

  Future<void> _handleManualScan() async {
    final value = _manualController.text.trim();
    if (value.isEmpty) {
      setState(
        () => _manualError =
            'Enter a valid student ID number, example: 23-72634.',
      );
      return;
    }

    final result = await _ctrl.recordQr(value, manual: true);
    if (!result.success && !result.isDuplicate) {
      setState(() => _manualError = result.detail);
      return;
    }

    if (result.success) {
      _manualController.clear();
      setState(() {
        _manualError = null;
        _showManualEntry = false;
      });
    } else if (result.isDuplicate) {
      _showDuplicateFeedback();
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (context, _) {
        final scanType = _ctrl.scanType;
        final isTimeIn = scanType == ScanType.timeIn;
        final pendingCount = _ctrl.pendingCount;

        return Container(
          decoration: BoxDecoration(
            gradient: RadialGradient(
              center: Alignment(0.0, -0.6),
              radius: 1.4,
              colors: [
                AppColors.of(context).gradientStart,
                AppColors.of(context).gradientMid,
                AppColors.of(context).gradientEnd,
              ],
              stops: [0.0, 0.45, 1.0],
            ),
          ),
          child: Scaffold(
            backgroundColor: Colors.transparent,
            appBar: _ScannerAppBar(
              ctrl: _ctrl,
              scanType: scanType,
              isTimeIn: isTimeIn,
              pendingCount: pendingCount + _ctrl.failedCount,
            ),
            body: SafeArea(
              child: Column(
                children: [
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                      child: ScannerViewfinder(
                        active: _ctrl.hasSession,
                        processingState: _processingState,
                        onDetect: _handleScan,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  AnimatedSwitcher(
                    duration: const Duration(milliseconds: 250),
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
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: SyncSummaryStrip(controller: _ctrl),
                  ),
                  const SizedBox(height: 12),
                  AnimatedSize(
                    duration: const Duration(milliseconds: 250),
                    curve: Curves.easeOutCubic,
                    child: _showManualEntry
                        ? _ManualEntryPanel(
                            controller: _manualController,
                            errorText: _manualError,
                            onSubmit: _handleManualScan,
                            onDismiss: () => setState(() {
                              _showManualEntry = false;
                              _manualError = null;
                            }),
                          )
                        : const SizedBox.shrink(),
                  ),
                  _BottomActionRow(
                    ctrl: _ctrl,
                    isTimeIn: isTimeIn,
                    pendingCount: pendingCount,
                    onManualEntry: () => setState(() {
                      _showManualEntry = !_showManualEntry;
                      _manualError = null;
                    }),
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
          ),
        );
      },
    );
  }
}

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
    final c = AppColors.of(context);

    return AppBar(
      backgroundColor: Colors.transparent,
      elevation: 0,
      foregroundColor: c.isDark ? Colors.white : AppColors.brandGreenDark,
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
              color: c.isDark ? Colors.white : AppColors.brandGreenDark,
              fontWeight: FontWeight.w700,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 1),
          Text(
            'USM EAES Scanner',
            style: theme.textTheme.labelSmall?.copyWith(
              color: c.isDark
                  ? AppColors.usmGold.withValues(alpha: 0.60)
                  : c.onSurfaceMuted,
            ),
          ),
        ],
      ),
      actions: [
        // Scan type badge — gold tinted
        Container(
          margin: const EdgeInsets.only(right: 4),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          decoration: BoxDecoration(
            color: AppColors.usmGold.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(
              color: AppColors.usmGold.withValues(alpha: 0.25),
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                isTimeIn ? Icons.login_rounded : Icons.logout_rounded,
                size: 14,
                color: AppColors.usmGold,
              ),
              const SizedBox(width: 5),
              Text(
                scanType.label,
                style: const TextStyle(
                  fontFamily: 'Inter',
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.usmGold,
                ),
              ),
            ],
          ),
        ),
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
        color: AppColors.usmGold.withValues(alpha: 0.20),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.usmGold.withValues(alpha: 0.40)),
      ),
      child: Text(
        '$count',
        style: const TextStyle(
          fontFamily: 'Inter',
          fontSize: 12,
          fontWeight: FontWeight.w700,
          color: AppColors.usmGold,
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
    final c = AppColors.of(context);
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: c.isDark ? c.panelElevated : null,
        gradient: c.isDark
            ? null
            : LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Colors.white,
                  c.brandWash.withValues(alpha: 0.70),
                  c.goldWash.withValues(alpha: 0.42),
                ],
              ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: c.panelBorder),
        boxShadow: [
          BoxShadow(
            color: c.isDark
                ? Colors.black.withValues(alpha: 0.30)
                : c.softShadow,
            blurRadius: 12,
            offset: const Offset(0, -2),
          ),
        ],
      ),
      child: Row(
        children: [
          Expanded(
            child: _ActionButton(
              icon: Icons.keyboard_rounded,
              label: 'Manual Entry',
              onTap: onManualEntry,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _ActionButton(
              icon: isTimeIn ? Icons.logout_rounded : Icons.login_rounded,
              label: isTimeIn ? 'Switch to Out' : 'Switch to In',
              onTap: onSwitchMode,
            ),
          ),
          if (pendingCount > 0 || ctrl.failedCount > 0) ...[
            const SizedBox(width: 8),
            Expanded(
              child: _ActionButton(
                icon: Icons.cloud_upload_rounded,
                label: 'Sync',
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
    final bgColor = primary
        ? AppColors.brandGreen
        : AppColors.of(context).panelElevated;

    final fgColor = primary
        ? Colors.white
        : (AppColors.of(context).isDark
              ? AppColors.usmGold.withValues(alpha: 0.75)
              : AppColors.brandGreen);

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
    this.errorText,
  });

  final TextEditingController controller;
  final VoidCallback onSubmit;
  final VoidCallback onDismiss;
  final String? errorText;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = AppColors.of(context);

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: c.isDark ? c.panelElevated : null,
        gradient: c.isDark
            ? null
            : LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [c.blueWash.withValues(alpha: 0.75), Colors.white],
              ),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: c.panelBorder),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(
                Icons.keyboard_rounded,
                size: 18,
                color: AppColors.usmGold.withValues(alpha: 0.70),
              ),
              const SizedBox(width: 8),
              Text(
                'Manual Student ID Entry',
                style: theme.textTheme.titleSmall?.copyWith(color: c.onSurface),
              ),
              const Spacer(),
              IconButton(
                icon: const Icon(Icons.close_rounded, size: 20),
                onPressed: onDismiss,
                tooltip: 'Close',
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                color: c.onSurfaceMuted,
              ),
            ],
          ),
          const SizedBox(height: 10),
          TextField(
            controller: controller,
            autofocus: true,
            style: TextStyle(color: c.onSurface),
            keyboardType: TextInputType.number,
            inputFormatters: const [StudentIdInputFormatter()],
            decoration: InputDecoration(
              labelText: 'Student ID number',
              hintText: '23-72634',
              prefixIcon: const Icon(Icons.badge_rounded),
              errorText: errorText,
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
