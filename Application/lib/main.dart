import 'package:flutter/material.dart';

import 'app/eaes_app.dart';
import 'config/theme_provider.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await ThemeProvider.instance.init();
  runApp(const EaesApp());
}
