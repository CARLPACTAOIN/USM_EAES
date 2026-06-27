import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// Full-width camera viewfinder with a scan-frame overlay.
///
/// Shows the live camera when [active] is true, or a placeholder when false.
/// [scanLocked] switches the border to orange to indicate a scan is processing.
class ScannerViewfinder extends StatelessWidget {
  const ScannerViewfinder({
    super.key,
    required this.active,
    required this.scanLocked,
    required this.onDetect,
  });

  final bool active;
  final bool scanLocked;
  final ValueChanged<BarcodeCapture> onDetect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final borderColor =
        scanLocked ? Colors.orange.shade500 : const Color(0xFF1B4D3E);

    return AspectRatio(
      aspectRatio: 1,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
          ),
          child: active
              ? Stack(
                  fit: StackFit.expand,
                  children: [
                    // Live camera feed
                    MobileScanner(onDetect: onDetect),

                    // Corner bracket overlay
                    IgnorePointer(
                      child: CustomPaint(
                        painter: _ScanFramePainter(
                          borderColor: borderColor,
                          scanLocked: scanLocked,
                        ),
                      ),
                    ),

                    // Bottom hint banner
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
                              Colors.black.withValues(alpha: 0.75),
                              Colors.transparent,
                            ],
                          ),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              scanLocked
                                  ? Icons.hourglass_top_rounded
                                  : Icons.qr_code_scanner_rounded,
                              color: Colors.white,
                              size: 16,
                            ),
                            const SizedBox(width: 8),
                            Text(
                              scanLocked
                                  ? 'Saving scan…'
                                  : 'Align QR code in the frame',
                              style: theme.textTheme.labelLarge?.copyWith(
                                color: Colors.white,
                                fontWeight: FontWeight.w500,
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
                      color: theme.colorScheme.onSurface.withValues(alpha: 0.2),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Camera preview will appear\nonce a session is active.',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurface.withValues(
                          alpha: 0.4,
                        ),
                      ),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

/// Draws four L-shaped corner brackets around the scan frame.
class _ScanFramePainter extends CustomPainter {
  _ScanFramePainter({required this.borderColor, required this.scanLocked});

  final Color borderColor;
  final bool scanLocked;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = borderColor
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3.5
      ..strokeCap = StrokeCap.round;

    const padding = 32.0;
    const cornerLen = 28.0;
    const r = 6.0;

    final left = padding;
    final top = padding;
    final right = size.width - padding;
    final bottom = size.height - padding;

    // Top-left
    canvas
      ..drawLine(Offset(left + r, top), Offset(left + cornerLen, top), paint)
      ..drawLine(Offset(left, top + r), Offset(left, top + cornerLen), paint)
      // Top-right
      ..drawLine(
          Offset(right - cornerLen, top), Offset(right - r, top), paint)
      ..drawLine(
          Offset(right, top + r), Offset(right, top + cornerLen), paint)
      // Bottom-left
      ..drawLine(
          Offset(left + r, bottom), Offset(left + cornerLen, bottom), paint)
      ..drawLine(
          Offset(left, bottom - cornerLen), Offset(left, bottom - r), paint)
      // Bottom-right
      ..drawLine(
          Offset(right - cornerLen, bottom), Offset(right - r, bottom), paint)
      ..drawLine(
          Offset(right, bottom - cornerLen), Offset(right, bottom - r), paint);
  }

  @override
  bool shouldRepaint(_ScanFramePainter oldDelegate) =>
      oldDelegate.borderColor != borderColor ||
      oldDelegate.scanLocked != scanLocked;
}
