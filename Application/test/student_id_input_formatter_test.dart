import 'package:eaes_scanner/features/scanner/presentation/formatters/student_id_input_formatter.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const formatter = StudentIdInputFormatter();

  TextEditingValue format(String oldText, String newText, int cursorOffset) {
    return formatter.formatEditUpdate(
      TextEditingValue(
        text: oldText,
        selection: TextSelection.collapsed(offset: oldText.length),
      ),
      TextEditingValue(
        text: newText,
        selection: TextSelection.collapsed(offset: cursorOffset),
      ),
    );
  }

  test('formats typed student ID digits as NN-NNNNN', () {
    final value = format('', '2372634', 7);

    expect(value.text, '23-72634');
    expect(value.selection.baseOffset, 8);
  });

  test('strips invalid characters and avoids duplicate hyphens', () {
    final value = format('', '23--72 A6#34', 12);

    expect(value.text, '23-72634');
    expect(value.selection.baseOffset, 8);
  });

  test('limits input to seven digits', () {
    final value = format('', '237263499', 9);

    expect(value.text, '23-72634');
    expect(value.selection.baseOffset, 8);
  });

  test('keeps cursor aligned when editing before the automatic hyphen', () {
    final value = format('23-72634', '293-72634', 2);

    expect(value.text, '29-37263');
    expect(value.selection.baseOffset, 3);
  });

  test('removes the automatic hyphen when backspacing to two digits', () {
    final value = format('23-7', '23-', 3);

    expect(value.text, '23-');
    expect(value.selection.baseOffset, 3);
  });

  test('removes all formatting when deleting below two digits', () {
    final value = format('23-', '2-', 1);

    expect(value.text, '2');
    expect(value.selection.baseOffset, 1);
  });
}
