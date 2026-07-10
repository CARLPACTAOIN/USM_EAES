import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Provides and persists the user's chosen [ThemeMode].
///
/// Uses [SharedPreferences] so the choice survives app restarts.
/// Access via the singleton [ThemeProvider.instance] after calling [init].
class ThemeProvider extends ChangeNotifier {
  ThemeProvider._();

  static final ThemeProvider instance = ThemeProvider._();

  static const String _prefKey = 'eaes_theme_mode';

  ThemeMode _themeMode = ThemeMode.dark;
  ThemeMode get themeMode => _themeMode;

  bool get isDark => _themeMode == ThemeMode.dark;

  /// Load the saved preference. Call once at startup before [runApp].
  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    final stored = prefs.getString(_prefKey);
    if (stored == 'light') {
      _themeMode = ThemeMode.light;
    } else {
      _themeMode = ThemeMode.dark;
    }
    notifyListeners();
  }

  /// Toggle between dark and light mode and persist.
  Future<void> toggleTheme() async {
    _themeMode = isDark ? ThemeMode.light : ThemeMode.dark;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefKey, isDark ? 'dark' : 'light');
  }
}
