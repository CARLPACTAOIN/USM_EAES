import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../../../config/app_colors.dart';

enum ViewfinderProcessingState { idle, saving, captured, duplicate }

class ScannerViewfinder extends StatelessWidget {
  const ScannerViewfinder({
    super.key,
    required this.active,
    required this.processingState,
    required this.onDetect,
  });

  final bool active;
  final ViewfinderProcessingState processingState;
  final ValueChanged<BarcodeCapture> onDetect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = AppColors.of(context);
    final isSaving = processingState == ViewfinderProcessingState.saving;
    final isCaptured = processingState == ViewfinderProcessingState.captured;
    final isDuplicate = processingState == ViewfinderProcessingState.duplicate;

    final borderColor = isSaving
        ? Colors.orange.shade500
        : isCaptured
        ? AppColors.accentGreen
        : isDuplicate
        ? AppColors.usmGold
        : AppColors.usmGold.withValues(alpha: 0.70); // Idle → gold frame

    return AspectRatio(
      aspectRatio: 1,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: c.isDark ? AppColors.dark.card : c.cardElevated,
          ),
          child: active
              ? Stack(
                  fit: StackFit.expand,
                  children: [
                    MobileScanner(onDetect: onDetect),
                    IgnorePointer(
                      child: CustomPaint(
                        painter: _ScanFramePainter(
                          borderColor: borderColor,
                          highlighted: isSaving || isCaptured || isDuplicate,
                        ),
                      ),
                    ),
                    if (isCaptured)
                      Positioned(
                        left: 16,
                        right: 16,
                        bottom: 72,
                        child: _ProcessingCard(
                          color: AppColors.accentGreen,
                          icon: Icons.check_circle_rounded,
                          title: 'Captured — ready for next',
                          subtitle: 'Move the next QR into the frame.',
                        ),
                      ),
                    if (isSaving)
                      Positioned(
                        left: 16,
                        right: 16,
                        bottom: 72,
                        child: _ProcessingCard(
                          color: Colors.orange.shade700,
                          icon: Icons.hourglass_top_rounded,
                          title: 'Saving scan…',
                          showSpinner: true,
                        ),
                      ),
                    if (isDuplicate)
                      Positioned(
                        left: 16,
                        right: 16,
                        bottom: 72,
                        child: _ProcessingCard(
                          color: AppColors.usmGold,
                          icon: Icons.warning_amber_rounded,
                          title: 'Already scanned just now.',
                          subtitle: 'Please scan the next student.',
                        ),
                      ),
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 12,
                        ),
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            begin: Alignment.bottomCenter,
                            end: Alignment.topCenter,
                            colors: [
                              Colors.black.withValues(alpha: 0.8),
                              Colors.transparent,
                            ],
                          ),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              isSaving
                                  ? Icons.hourglass_top_rounded
                                  : isCaptured
                                  ? Icons.check_circle_rounded
                                  : Icons.qr_code_scanner_rounded,
                              color: Colors.white,
                              size: 16,
                            ),
                            const SizedBox(width: 8),
                            Text(
                              isSaving
                                  ? 'Saving scan…'
                                  : isCaptured
                                  ? 'Captured — pausing briefly…'
                                  : 'Align QR code in the frame',
                              style: theme.textTheme.labelLarge?.copyWith(
                                color: Colors.white,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                )
              : Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(
                      Icons.qr_code_2_rounded,
                      size: 72,
                      color: AppColors.usmGold.withValues(alpha: 0.20),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Camera preview will appear\nonce a session is active.',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontFamily: 'Inter',
                        fontSize: 13,
                        color: c.onSurfaceMuted,
                      ),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

class _ProcessingCard extends StatelessWidget {
  const _ProcessingCard({
    required this.color,
    required this.icon,
    required this.title,
    this.subtitle,
    this.showSpinner = false,
  });

  final Color color;
  final IconData icon;
  final String title;
  final String? subtitle;
  final bool showSpinner;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.25),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        children: [
          if (showSpinner)
            const SizedBox.square(
              dimension: 22,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                color: Colors.white,
              ),
            )
          else
            Icon(icon, color: Colors.white, size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    fontSize: 15,
                  ),
                ),
                if (subtitle != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    subtitle!,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.9),
                      fontSize: 12,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ScanFramePainter extends CustomPainter {
  _ScanFramePainter({required this.borderColor, required this.highlighted});

  final Color borderColor;
  final bool highlighted;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = borderColor
      ..style = PaintingStyle.stroke
      ..strokeWidth = highlighted ? 4.5 : 3.5
      ..strokeCap = StrokeCap.round;

    const padding = 32.0;
    const cornerLen = 28.0;
    const r = 6.0;

    final left = padding;
    final top = padding;
    final right = size.width - padding;
    final bottom = size.height - padding;

    canvas
      ..drawLine(Offset(left + r, top), Offset(left + cornerLen, top), paint)
      ..drawLine(Offset(left, top + r), Offset(left, top + cornerLen), paint)
      ..drawLine(Offset(right - cornerLen, top), Offset(right - r, top), paint)
      ..drawLine(Offset(right, top + r), Offset(right, top + cornerLen), paint)
      ..drawLine(
        Offset(left + r, bottom),
        Offset(left + cornerLen, bottom),
        paint,
      )
      ..drawLine(
        Offset(left, bottom - cornerLen),
        Offset(left, bottom - r),
        paint,
      )
      ..drawLine(
        Offset(right - cornerLen, bottom),
        Offset(right - r, bottom),
        paint,
      )
      ..drawLine(
        Offset(right, bottom - cornerLen),
        Offset(right, bottom - r),
        paint,
      );
  }

  @override
  bool shouldRepaint(_ScanFramePainter oldDelegate) =>
      oldDelegate.borderColor != borderColor ||
      oldDelegate.highlighted != highlighted;
}
