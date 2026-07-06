import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../auth_controller.dart';

/// Premium login screen that visually extends the splash-screen aesthetic:
/// same radial gradient background, glowing icon, glassmorphic card, and
/// staggered entrance animations for a seamless first-impression flow.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.controller});

  final AuthController controller;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen>
    with TickerProviderStateMixin {
  // --- Brand tokens ---------------------------------------------------------
  static const Color _accentGreen = Color(0xFF34D399); // emerald-400
  static const Color _usmGold = Color(0xFFD4A843); // USM institutional gold
  static const Color _usmGoldLight = Color(0xFFF0D078); // lighter gold for glows

  // --- Animation controllers ------------------------------------------------
  late final AnimationController _entranceController;
  late final AnimationController _pulseController;

  // --- Derived animations ---------------------------------------------------
  late final Animation<double> _logoScale;
  late final Animation<double> _logoOpacity;
  late final Animation<double> _cardSlide;
  late final Animation<double> _cardOpacity;
  late final Animation<double> _footerOpacity;

  @override
  void initState() {
    super.initState();

    _entranceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    );

    _logoScale = Tween<double>(begin: 0.7, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.0, 0.45, curve: Curves.easeOutBack),
      ),
    );
    _logoOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.0, 0.35, curve: Curves.easeOut),
      ),
    );

    _cardSlide = Tween<double>(begin: 40.0, end: 0.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.20, 0.65, curve: Curves.easeOutCubic),
      ),
    );
    _cardOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.20, 0.55, curve: Curves.easeOut),
      ),
    );

    _footerOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.60, 1.0, curve: Curves.easeOut),
      ),
    );

    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2400),
    )..repeat(reverse: true);

    _entranceController.forward();
  }

  @override
  void dispose() {
    _entranceController.dispose();
    _pulseController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
        statusBarBrightness: Brightness.dark,
      ),
    );

    return Scaffold(
      resizeToAvoidBottomInset: false,
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: const BoxDecoration(
          gradient: RadialGradient(
            center: Alignment(0.0, -0.45),
            radius: 1.3,
            colors: [
              Color(0xFF1B4D3E), // brand green
              Color(0xFF0F2E25), // brand green dark
              Color(0xFF071A14), // near black
            ],
            stops: [0.0, 0.50, 1.0],
          ),
        ),
        child: AnimatedBuilder(
          animation: Listenable.merge([
            _entranceController,
            _pulseController,
            widget.controller,
          ]),
          builder: (context, _) => SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 28),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 400),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const SizedBox(height: 32),
                      _buildLogo(),
                      const SizedBox(height: 24),
                      _buildCard(),
                      const SizedBox(height: 40),
                      _buildFooter(),
                      const SizedBox(height: 24),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  // ──────────────────────────────────────────────────────────────────────────
  //  Logo with pulsing glow (shared aesthetic with splash)
  // ──────────────────────────────────────────────────────────────────────────

  Widget _buildLogo() {
    final pulseValue = _pulseController.value;
    final glowRadius = 50.0 + (pulseValue * 16.0);
    final glowOpacity = 0.12 + (pulseValue * 0.08);
    final goldGlowOpacity = 0.06 + (pulseValue * 0.05);

    return Opacity(
      opacity: _logoOpacity.value,
      child: Transform.scale(
        scale: _logoScale.value,
        child: Column(
          children: [
            Stack(
              alignment: Alignment.center,
              children: [
                // Glow — green + gold blend
                Container(
                  width: glowRadius * 2,
                  height: glowRadius * 2,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    boxShadow: [
                      BoxShadow(
                        color: _accentGreen.withValues(alpha: glowOpacity),
                        blurRadius: glowRadius,
                        spreadRadius: 2,
                      ),
                      BoxShadow(
                        color: _usmGoldLight.withValues(alpha: goldGlowOpacity),
                        blurRadius: glowRadius * 0.7,
                        spreadRadius: 1,
                      ),
                    ],
                  ),
                ),
                // Icon ring — gold-tinted border
                Container(
                  width: 88,
                  height: 88,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.white.withValues(alpha: 0.07),
                    border: Border.all(
                      color: _usmGold.withValues(alpha: 0.28),
                      width: 1.5,
                    ),
                  ),
                  child: const Icon(
                    Icons.qr_code_scanner_rounded,
                    size: 40,
                    color: Colors.white,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
            const Text(
              'USM EAES',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 26,
                fontWeight: FontWeight.w800,
                color: Colors.white,
                letterSpacing: 1.2,
                height: 1.1,
              ),
            ),
            const SizedBox(height: 3),
            Text(
              'SCANNER',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 13,
                fontWeight: FontWeight.w500,
                color: _usmGold,
                letterSpacing: 5.0,
                height: 1.2,
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ──────────────────────────────────────────────────────────────────────────
  //  Glassmorphic login card
  // ──────────────────────────────────────────────────────────────────────────

  Widget _buildCard() {
    return Opacity(
      opacity: _cardOpacity.value,
      child: Transform.translate(
        offset: Offset(0, _cardSlide.value),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            color: Colors.white.withValues(alpha: 0.06),
            border: Border.all(
              color: _usmGold.withValues(alpha: 0.15),
              width: 1,
            ),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.25),
                blurRadius: 32,
                offset: const Offset(0, 8),
              ),
            ],
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(20),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Heading
                  Text(
                    'Welcome',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontFamily: 'Inter',
                      fontSize: 22,
                      fontWeight: FontWeight.w700,
                      color: Colors.white.withValues(alpha: 0.92),
                      letterSpacing: -0.3,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Sign in with your institutional account\nto view scanner assignments.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontFamily: 'Inter',
                      fontSize: 13,
                      fontWeight: FontWeight.w400,
                      color: Colors.white.withValues(alpha: 0.50),
                      height: 1.5,
                    ),
                  ),
                  const SizedBox(height: 28),

                  // Error card
                  if (widget.controller.errorMessage != null) ...[
                    _buildErrorBanner(widget.controller.errorMessage!),
                    const SizedBox(height: 18),
                  ],

                  // Google sign-in button
                  _buildGoogleButton(),

                  const SizedBox(height: 20),

                  // Hint
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(
                        Icons.info_outline_rounded,
                        size: 14,
                        color: Colors.white.withValues(alpha: 0.30),
                      ),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          'Scanner access is admin-granted per event.',
                          style: TextStyle(
                            fontFamily: 'Inter',
                            fontSize: 11,
                            fontWeight: FontWeight.w400,
                            color: Colors.white.withValues(alpha: 0.30),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  // ──────────────────────────────────────────────────────────────────────────
  //  Google sign-in button
  // ──────────────────────────────────────────────────────────────────────────

  Widget _buildGoogleButton() {
    final busy = widget.controller.busy;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: busy ? null : widget.controller.signInWithGoogle,
        borderRadius: BorderRadius.circular(14),
        splashColor: _usmGold.withValues(alpha: 0.10),
        highlightColor: Colors.white.withValues(alpha: 0.04),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          curve: Curves.easeOut,
          height: 54,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: busy
                  ? [
                      Colors.white.withValues(alpha: 0.06),
                      Colors.white.withValues(alpha: 0.04),
                    ]
                  : [
                      Colors.white.withValues(alpha: 0.12),
                      Colors.white.withValues(alpha: 0.06),
                    ],
            ),
            border: Border.all(
              color: busy
                  ? Colors.white.withValues(alpha: 0.06)
                  : Colors.white.withValues(alpha: 0.16),
              width: 1,
            ),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (busy)
                SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.0,
                    valueColor: AlwaysStoppedAnimation(
                      Colors.white.withValues(alpha: 0.60),
                    ),
                  ),
                )
              else
                // Google "G" icon replica via container
                Container(
                  width: 24,
                  height: 24,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: const Center(
                    child: Text(
                      'G',
                      style: TextStyle(
                        fontFamily: 'Inter',
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        color: Color(0xFF4285F4),
                        height: 1.0,
                      ),
                    ),
                  ),
                ),
              const SizedBox(width: 12),
              Text(
                busy ? 'Signing in…' : 'Sign in with Google',
                style: TextStyle(
                  fontFamily: 'Inter',
                  fontSize: 15,
                  fontWeight: FontWeight.w600,
                  color: busy
                      ? Colors.white.withValues(alpha: 0.45)
                      : Colors.white.withValues(alpha: 0.90),
                  letterSpacing: 0.1,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ──────────────────────────────────────────────────────────────────────────
  //  Error banner
  // ──────────────────────────────────────────────────────────────────────────

  Widget _buildErrorBanner(String message) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: const Color(0xFFDC2626).withValues(alpha: 0.12),
        border: Border.all(
          color: const Color(0xFFDC2626).withValues(alpha: 0.25),
          width: 1,
        ),
      ),
      child: Row(
        children: [
          Icon(
            Icons.error_outline_rounded,
            size: 20,
            color: const Color(0xFFFCA5A5).withValues(alpha: 0.90),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 13,
                fontWeight: FontWeight.w400,
                color: Colors.white.withValues(alpha: 0.75),
                height: 1.4,
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ──────────────────────────────────────────────────────────────────────────
  //  Footer
  // ──────────────────────────────────────────────────────────────────────────

  Widget _buildFooter() {
    return Opacity(
      opacity: _footerOpacity.value,
      child: Column(
        children: [
          // Decorative divider — gold tinted
          Container(
            width: 40,
            height: 1,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  Colors.transparent,
                  _usmGold.withValues(alpha: 0.30),
                  Colors.transparent,
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'University of Southern Mindanao',
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 11,
              fontWeight: FontWeight.w500,
              color: _usmGold.withValues(alpha: 0.40),
              letterSpacing: 0.5,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Event Attendance & Evaluation System',
            style: TextStyle(
              fontFamily: 'Inter',
              fontSize: 10,
              fontWeight: FontWeight.w400,
              color: Colors.white.withValues(alpha: 0.18),
              letterSpacing: 0.3,
            ),
          ),
        ],
      ),
    );
  }
}
