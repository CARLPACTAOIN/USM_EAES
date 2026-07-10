import 'package:flutter/material.dart';

/// Centralized colour tokens for the EAES scanner app.
///
/// Every colour that changes between dark and light mode lives here.
/// Widgets should call `AppColors.of(context)` (or use `AppColors.dark` /
/// `AppColors.light` directly) instead of duplicating hex literals.
class AppColors {
  const AppColors._({
    required this.surface,
    required this.card,
    required this.cardElevated,
    required this.navBarBg,
    required this.navBarBorder,
    required this.onSurface,
    required this.onSurfaceMuted,
    required this.gradientStart,
    required this.gradientMid,
    required this.gradientEnd,
    required this.inputFill,
    required this.inputBorder,
    required this.inputBorderFocused,
    required this.divider,
    required this.chipBorder,
    required this.appBarBg,
    required this.brightness,
  });

  // --- Mode-invariant brand tokens ------------------------------------------
  static const Color brandGreen = Color(0xFF1B4D3E);
  static const Color brandGreenDark = Color(0xFF0F2E25);
  static const Color usmGold = Color(0xFFD4A843);
  static const Color usmGoldLight = Color(0xFFF0D078);
  static const Color accentBlue = Color(0xFF2563EB);
  static const Color accentGreen = Color(0xFF34D399);
  static const Color errorRed = Color(0xFFDC2626);
  static const Color errorRedLight = Color(0xFFFCA5A5);
  static const Color googleBlue = Color(0xFF4285F4);

  // --- Per-mode palettes ----------------------------------------------------
  static const AppColors dark = AppColors._(
    surface: Color(0xFF0A0F1E),
    card: Color(0xFF0D2A22), // green-tinted (was #111827)
    cardElevated: Color(0xFF1E293B),
    navBarBg: Color(0xFF0D2A22), // green-tinted
    navBarBorder: Color(0x1AD4A843), // gold @ 10%
    onSurface: Color(0xFFE2E8F0),
    onSurfaceMuted: Color(0xFF6B7280),
    gradientStart: Color(0xFF1B4D3E),
    gradientMid: Color(0xFF0F2E25),
    gradientEnd: Color(0xFF071A14),
    inputFill: Color(0xFF1E293B),
    inputBorder: Color(0xFF334155),
    inputBorderFocused: Color(0xFFD4A843),
    divider: Color(0x1AD4A843), // gold @ 10%
    chipBorder: Color(0x33D4A843), // gold @ 20%
    appBarBg: Color(0xFF0F2E25),
    brightness: Brightness.dark,
  );

  static const AppColors light = AppColors._(
    surface: Color(0xFFF1F7F3),
    card: Color(0xFFFFFFFF),
    cardElevated: Color(0xFFF9FBFA),
    navBarBg: Color(0xFFFFFFFF),
    navBarBorder: Color(0x401B4D3E),
    onSurface: Color(0xFF0F172A),
    onSurfaceMuted: Color(0xFF536B5E),
    gradientStart: Color(0xFFD9F0E5),
    gradientMid: Color(0xFFFFF2C7),
    gradientEnd: Color(0xFFE6F0FF),
    inputFill: Color(0xFFFFFFFF),
    inputBorder: Color(0xFFC9D8D0),
    inputBorderFocused: Color(0xFF1B4D3E),
    divider: Color(0xFFDCE7DF),
    chipBorder: Color(0xFFC9D8D0),
    appBarBg: Color(0xFF1B4D3E),
    brightness: Brightness.light,
  );

  // --- Per-mode properties --------------------------------------------------
  final Color surface;
  final Color card;
  final Color cardElevated;
  final Color navBarBg;
  final Color navBarBorder;
  final Color onSurface;
  final Color onSurfaceMuted;
  final Color gradientStart;
  final Color gradientMid;
  final Color gradientEnd;
  final Color inputFill;
  final Color inputBorder;
  final Color inputBorderFocused;
  final Color divider;
  final Color chipBorder;
  final Color appBarBg;
  final Brightness brightness;

  bool get isDark => brightness == Brightness.dark;

  Color get panel => isDark
      ? Colors.white.withValues(alpha: 0.05)
      : Colors.white.withValues(alpha: 0.92);

  Color get panelElevated => isDark ? cardElevated : Colors.white;

  Color get panelBorder =>
      isDark ? usmGold.withValues(alpha: 0.12) : const Color(0xFFD5E4DC);

  Color get softShadow => isDark
      ? Colors.black.withValues(alpha: 0.20)
      : const Color(0xFF0F172A).withValues(alpha: 0.08);

  Color get quietIconSurface => isDark
      ? Colors.white.withValues(alpha: 0.05)
      : usmGold.withValues(alpha: 0.18);

  Color get brandWash =>
      isDark ? Colors.white.withValues(alpha: 0.05) : const Color(0xFFE6F5EC);

  Color get goldWash =>
      isDark ? Colors.white.withValues(alpha: 0.05) : const Color(0xFFFFF2C7);

  Color get blueWash =>
      isDark ? Colors.white.withValues(alpha: 0.05) : const Color(0xFFE7F0FF);

  // --- Convenience accessor -------------------------------------------------

  /// Resolve the palette from the ambient [Theme].
  static AppColors of(BuildContext context) {
    return Theme.of(context).brightness == Brightness.dark
        ? AppColors.dark
        : AppColors.light;
  }
}
