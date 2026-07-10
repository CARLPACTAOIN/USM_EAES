import 'package:flutter/services.dart';

class StudentIdInputFormatter extends TextInputFormatter {
  const StudentIdInputFormatter();

  static const int maxDigits = 7;

  static String digitsOnly(String value) {
    return value.replaceAll(RegExp(r'\D'), '');
  }

  static String formatDigits(String digits) {
    final sanitized = digitsOnly(digits);
    final limited = sanitized.length > maxDigits
        ? sanitized.substring(0, maxDigits)
        : sanitized;

    if (limited.length < 2) {
      return limited;
    }

    final prefix = limited.substring(0, 2);
    final suffix = limited.substring(2);
    return '$prefix-$suffix';
  }

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final selectionOffset = newValue.selection.extentOffset.clamp(
      0,
      newValue.text.length,
    );
    final digitsBeforeSelection = digitsOnly(
      newValue.text.substring(0, selectionOffset),
    ).length.clamp(0, maxDigits);
    final rawDigits = digitsOnly(newValue.text);
    final formatted = formatDigits(rawDigits);
    final cursorOffset = _offsetForDigitCount(
      digitsBeforeSelection.clamp(0, digitsOnly(formatted).length),
    );

    return TextEditingValue(
      text: formatted,
      selection: TextSelection.collapsed(offset: cursorOffset),
      composing: TextRange.empty,
    );
  }

  static int _offsetForDigitCount(int digitCount) {
    if (digitCount <= 0) {
      return 0;
    }
    if (digitCount < 2) {
      return digitCount;
    }
    return digitCount + 1;
  }
}
