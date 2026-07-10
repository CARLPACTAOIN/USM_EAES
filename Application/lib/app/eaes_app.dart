import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config/app_colors.dart';
import '../config/app_config.dart';
import '../config/theme_provider.dart';
import '../features/auth/presentation/auth_gate.dart';

class EaesApp extends StatefulWidget {
  const EaesApp({super.key});

  @override
  State<EaesApp> createState() => _EaesAppState();
}

class _EaesAppState extends State<EaesApp> {
  final _themeProvider = ThemeProvider.instance;

  @override
  void initState() {
    super.initState();
    _themeProvider.addListener(_onThemeChanged);
  }

  void _onThemeChanged() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _themeProvider.removeListener(_onThemeChanged);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: _buildTheme(Brightness.light),
      darkTheme: _buildTheme(Brightness.dark),
      themeMode: _themeProvider.themeMode,
      home: const AuthGate(),
    );
  }

  // ---------------------------------------------------------------------------
  // Theme builder
  // ---------------------------------------------------------------------------

  static ThemeData _buildTheme(Brightness brightness) {
    final isDark = brightness == Brightness.dark;
    final c = isDark ? AppColors.dark : AppColors.light;

    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.brandGreen,
      brightness: brightness,
      primary: AppColors.brandGreen,
      onPrimary: Colors.white,
      secondary: AppColors.accentBlue,
      onSecondary: Colors.white,
      surface: c.surface,
      onSurface: c.onSurface,
      error: AppColors.errorRed,
      onError: Colors.white,
    );

    const borderRadius12 = BorderRadius.all(Radius.circular(12));
    const borderRadius8 = BorderRadius.all(Radius.circular(8));

    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,

      // Background
      scaffoldBackgroundColor: c.surface,

      // AppBar — deep green with white text in both modes
      appBarTheme: AppBarTheme(
        backgroundColor: c.appBarBg,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
        systemOverlayStyle: SystemUiOverlayStyle.light,
        titleTextStyle: const TextStyle(
          fontFamily: 'Inter',
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: Colors.white,
          letterSpacing: 0,
        ),
      ),

      // Bottom navigation bar (legacy — for BottomNavigationBar)
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: c.navBarBg,
        selectedItemColor: isDark ? AppColors.usmGold : AppColors.brandGreen,
        unselectedItemColor: c.onSurfaceMuted,
        type: BottomNavigationBarType.fixed,
        elevation: 12,
        selectedLabelStyle: const TextStyle(
          fontFamily: 'Inter',
          fontSize: 11,
          fontWeight: FontWeight.w600,
        ),
        unselectedLabelStyle: const TextStyle(
          fontFamily: 'Inter',
          fontSize: 11,
          fontWeight: FontWeight.w400,
        ),
      ),

      // NavigationBar (Material 3)
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: c.navBarBg,
        indicatorColor: AppColors.usmGold.withValues(alpha: 0.15),
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        iconTheme: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) {
            return IconThemeData(
              color: isDark ? AppColors.usmGold : AppColors.brandGreen,
              size: 24,
            );
          }
          return IconThemeData(color: c.onSurfaceMuted, size: 24);
        }),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.selected)) {
            return TextStyle(
              fontFamily: 'Inter',
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: isDark ? AppColors.usmGold : AppColors.brandGreen,
            );
          }
          return TextStyle(
            fontFamily: 'Inter',
            fontSize: 11,
            fontWeight: FontWeight.w400,
            color: c.onSurfaceMuted,
          );
        }),
      ),

      // Filled button — primary CTA
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.brandGreen,
          foregroundColor: Colors.white,
          minimumSize: const Size(double.infinity, 52),
          shape: const RoundedRectangleBorder(borderRadius: borderRadius12),
          textStyle: const TextStyle(
            fontFamily: 'Inter',
            fontSize: 15,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.2,
          ),
        ),
      ),

      // Outlined button — secondary action
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: isDark ? AppColors.usmGold : AppColors.brandGreen,
          side: BorderSide(
            color: isDark
                ? AppColors.usmGold.withValues(alpha: 0.5)
                : AppColors.brandGreen,
            width: 1.5,
          ),
          minimumSize: const Size(double.infinity, 48),
          shape: const RoundedRectangleBorder(borderRadius: borderRadius12),
          textStyle: const TextStyle(
            fontFamily: 'Inter',
            fontSize: 15,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),

      // Text button
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: isDark ? AppColors.usmGold : AppColors.brandGreen,
          minimumSize: const Size(44, 44),
          textStyle: const TextStyle(
            fontFamily: 'Inter',
            fontSize: 14,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),

      // Card
      cardTheme: CardThemeData(
        margin: EdgeInsets.zero,
        elevation: 0,
        shape: const RoundedRectangleBorder(borderRadius: borderRadius12),
        color: isDark ? c.cardElevated : c.card,
      ),

      // Input decoration
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: c.inputFill,
        border: OutlineInputBorder(
          borderRadius: borderRadius8,
          borderSide: BorderSide(color: c.inputBorder),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: borderRadius8,
          borderSide: BorderSide(
            color: isDark
                ? AppColors.usmGold.withValues(alpha: 0.20)
                : c.inputBorder,
          ),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: borderRadius8,
          borderSide: BorderSide(color: c.inputBorderFocused, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 14,
        ),
        labelStyle: TextStyle(
          fontFamily: 'Inter',
          fontSize: 14,
          color: c.onSurfaceMuted,
        ),
        hintStyle: TextStyle(
          fontFamily: 'Inter',
          fontSize: 14,
          color: isDark ? const Color(0xFF475569) : const Color(0xFF94A3B8),
        ),
      ),

      // Divider
      dividerTheme: DividerThemeData(color: c.divider, thickness: 1, space: 1),

      // Chip
      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: borderRadius8),
        backgroundColor: isDark ? c.cardElevated : null,
        side: isDark ? BorderSide(color: c.chipBorder) : null,
        labelStyle: TextStyle(
          fontFamily: 'Inter',
          fontSize: 12,
          fontWeight: FontWeight.w500,
          color: isDark ? c.onSurface : null,
        ),
      ),

      // Text theme — Inter throughout
      textTheme: const TextTheme(
        displayLarge: TextStyle(
          fontFamily: 'Inter',
          fontSize: 57,
          fontWeight: FontWeight.w700,
          letterSpacing: -1.5,
        ),
        headlineLarge: TextStyle(
          fontFamily: 'Inter',
          fontSize: 32,
          fontWeight: FontWeight.w800,
          letterSpacing: 0,
        ),
        headlineMedium: TextStyle(
          fontFamily: 'Inter',
          fontSize: 24,
          fontWeight: FontWeight.w700,
          letterSpacing: 0,
        ),
        headlineSmall: TextStyle(
          fontFamily: 'Inter',
          fontSize: 20,
          fontWeight: FontWeight.w700,
        ),
        titleLarge: TextStyle(
          fontFamily: 'Inter',
          fontSize: 18,
          fontWeight: FontWeight.w700,
          letterSpacing: 0,
        ),
        titleMedium: TextStyle(
          fontFamily: 'Inter',
          fontSize: 16,
          fontWeight: FontWeight.w600,
        ),
        titleSmall: TextStyle(
          fontFamily: 'Inter',
          fontSize: 14,
          fontWeight: FontWeight.w600,
        ),
        bodyLarge: TextStyle(
          fontFamily: 'Inter',
          fontSize: 16,
          fontWeight: FontWeight.w400,
          height: 1.5,
        ),
        bodyMedium: TextStyle(
          fontFamily: 'Inter',
          fontSize: 14,
          fontWeight: FontWeight.w400,
          height: 1.5,
        ),
        bodySmall: TextStyle(
          fontFamily: 'Inter',
          fontSize: 12,
          fontWeight: FontWeight.w400,
          height: 1.4,
        ),
        labelLarge: TextStyle(
          fontFamily: 'Inter',
          fontSize: 14,
          fontWeight: FontWeight.w600,
          letterSpacing: 0.1,
        ),
        labelMedium: TextStyle(
          fontFamily: 'Inter',
          fontSize: 12,
          fontWeight: FontWeight.w500,
        ),
        labelSmall: TextStyle(
          fontFamily: 'Inter',
          fontSize: 11,
          fontWeight: FontWeight.w500,
          letterSpacing: 0.2,
        ),
      ),
    );
  }
}
