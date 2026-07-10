import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../config/app_colors.dart';

/// Full-screen branded splash shown while the app initialises (token restore,
/// network probing, etc.).  Animates in the logo, app title, and a pulsing
/// progress indicator using the EAES brand palette.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with TickerProviderStateMixin {
  // --- Brand tokens ---------------------------------------------------------
  static const Color _accentGreen = AppColors.accentGreen;
  static const Color _usmGold = AppColors.usmGold;
  static const Color _usmGoldLight = AppColors.usmGoldLight;

  // --- Controllers ----------------------------------------------------------
  late final AnimationController _entranceController;
  late final AnimationController _pulseController;
  late final AnimationController _shimmerController;

  // --- Derived animations ---------------------------------------------------
  late final Animation<double> _logoScale;
  late final Animation<double> _logoOpacity;
  late final Animation<double> _titleSlide;
  late final Animation<double> _titleOpacity;
  late final Animation<double> _subtitleSlide;
  late final Animation<double> _subtitleOpacity;
  late final Animation<double> _indicatorOpacity;

  @override
  void initState() {
    super.initState();

    // 1.  Entrance — staggered fade-in + slide for each element
    _entranceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    );

    _logoScale = Tween<double>(begin: 0.6, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.0, 0.5, curve: Curves.easeOutBack),
      ),
    );
    _logoOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.0, 0.4, curve: Curves.easeOut),
      ),
    );

    _titleSlide = Tween<double>(begin: 24.0, end: 0.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.25, 0.65, curve: Curves.easeOutCubic),
      ),
    );
    _titleOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.25, 0.55, curve: Curves.easeOut),
      ),
    );

    _subtitleSlide = Tween<double>(begin: 20.0, end: 0.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.40, 0.75, curve: Curves.easeOutCubic),
      ),
    );
    _subtitleOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.40, 0.70, curve: Curves.easeOut),
      ),
    );

    _indicatorOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.65, 1.0, curve: Curves.easeOut),
      ),
    );

    // 2.  Pulsing glow behind the icon
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2000),
    )..repeat(reverse: true);

    // 3.  Shimmer on the loading bar
    _shimmerController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1500),
    )..repeat();

    _entranceController.forward();
  }

  @override
  void dispose() {
    _entranceController.dispose();
    _pulseController.dispose();
    _shimmerController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Force light status bar icons on dark splash background
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
        statusBarBrightness: Brightness.dark,
      ),
    );

    return Scaffold(
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: Alignment(0.0, -0.3),
            radius: 1.2,
            colors: [
              AppColors.brandGreen,
              AppColors.brandGreenDark,
              AppColors.dark.gradientEnd,
            ],
            stops: [0.0, 0.55, 1.0],
          ),
        ),
        child: AnimatedBuilder(
          animation: Listenable.merge([
            _entranceController,
            _pulseController,
            _shimmerController,
          ]),
          builder: (context, _) => SafeArea(
            child: Column(
              children: [
                const Spacer(flex: 3),
                // --- Logo with pulsing glow ---
                _buildLogo(),
                const SizedBox(height: 32),
                // --- Title ---
                _buildTitle(),
                const SizedBox(height: 10),
                // --- Subtitle ---
                _buildSubtitle(),
                const Spacer(flex: 2),
                // --- Loading indicator ---
                _buildLoadingIndicator(),
                const SizedBox(height: 48),
                // --- Footer text ---
                _buildFooter(),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ──────────────────────────────────────────────────────────────────────────
  //  Composites
  // ──────────────────────────────────────────────────────────────────────────

  Widget _buildLogo() {
    final pulseValue = _pulseController.value;
    final glowRadius = 60.0 + (pulseValue * 20.0);
    final glowOpacity = 0.15 + (pulseValue * 0.10);
    final goldGlowOpacity = 0.08 + (pulseValue * 0.06);

    return Opacity(
      opacity: _logoOpacity.value,
      child: Transform.scale(
        scale: _logoScale.value,
        child: Stack(
          alignment: Alignment.center,
          children: [
            // Outer glow — green + gold blend
            Container(
              width: glowRadius * 2,
              height: glowRadius * 2,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: _accentGreen.withValues(alpha: glowOpacity),
                    blurRadius: glowRadius,
                    spreadRadius: 4,
                  ),
                  BoxShadow(
                    color: _usmGoldLight.withValues(alpha: goldGlowOpacity),
                    blurRadius: glowRadius * 0.7,
                    spreadRadius: 2,
                  ),
                ],
              ),
            ),
            // Icon container — gold-tinted border
            Container(
              width: 110,
              height: 110,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withValues(alpha: 0.08),
                border: Border.all(
                  color: _usmGold.withValues(alpha: 0.30),
                  width: 1.5,
                ),
              ),
              child: const Icon(
                Icons.qr_code_scanner_rounded,
                size: 52,
                color: Colors.white,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTitle() {
    return Opacity(
      opacity: _titleOpacity.value,
      child: Transform.translate(
        offset: Offset(0, _titleSlide.value),
        child: const Column(
          children: [
            Text(
              'USM EAES',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 32,
                fontWeight: FontWeight.w800,
                color: Colors.white,
                letterSpacing: 1.5,
                height: 1.1,
              ),
            ),
            SizedBox(height: 4),
            Text(
              'SCANNER',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 16,
                fontWeight: FontWeight.w500,
                color: _usmGold,
                letterSpacing: 6.0,
                height: 1.2,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSubtitle() {
    return Opacity(
      opacity: _subtitleOpacity.value,
      child: Transform.translate(
        offset: Offset(0, _subtitleSlide.value),
        child: Text(
          'Event Attendance & Evaluation System',
          textAlign: TextAlign.center,
          style: TextStyle(
            fontFamily: 'Inter',
            fontSize: 13,
            fontWeight: FontWeight.w400,
            color: Colors.white.withValues(alpha: 0.55),
            letterSpacing: 0.3,
          ),
        ),
      ),
    );
  }

  Widget _buildLoadingIndicator() {
    return Opacity(
      opacity: _indicatorOpacity.value,
      child: SizedBox(
        width: 200,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(2),
          child: CustomPaint(
            painter: _ShimmerBarPainter(
              progress: _shimmerController.value,
              baseColor: Colors.white.withValues(alpha: 0.08),
              shimmerColor: _usmGold.withValues(alpha: 0.60),
            ),
            size: const Size(200, 3),
          ),
        ),
      ),
    );
  }

  Widget _buildFooter() {
    return Opacity(
      opacity: _indicatorOpacity.value,
      child: Text(
        'University of Southern Mindanao',
        style: TextStyle(
          fontFamily: 'Inter',
          fontSize: 11,
          fontWeight: FontWeight.w500,
          color: _usmGold.withValues(alpha: 0.45),
          letterSpacing: 0.5,
        ),
      ),
    );
  }
}

// ════════════════════════════════════════════════════════════════════════════════
//  Shimmer bar custom painter
// ════════════════════════════════════════════════════════════════════════════════

class _ShimmerBarPainter extends CustomPainter {
  _ShimmerBarPainter({
    required this.progress,
    required this.baseColor,
    required this.shimmerColor,
  });

  final double progress;
  final Color baseColor;
  final Color shimmerColor;

  @override
  void paint(Canvas canvas, Size size) {
    // Base bar
    final basePaint = Paint()..color = baseColor;
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Offset.zero & size,
        const Radius.circular(2),
      ),
      basePaint,
    );

    // Shimmer highlight — slides left to right
    final shimmerWidth = size.width * 0.4;
    final shimmerCenter =
        -shimmerWidth + (size.width + shimmerWidth * 2) * progress;

    final shimmerPaint = Paint()
      ..shader = LinearGradient(
        colors: [
          baseColor,
          shimmerColor,
          baseColor,
        ],
        stops: const [0.0, 0.5, 1.0],
      ).createShader(
        Rect.fromLTWH(
          shimmerCenter - shimmerWidth / 2,
          0,
          shimmerWidth,
          size.height,
        ),
      );

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Offset.zero & size,
        const Radius.circular(2),
      ),
      shimmerPaint,
    );
  }

  @override
  bool shouldRepaint(_ShimmerBarPainter oldDelegate) =>
      oldDelegate.progress != progress;
}
