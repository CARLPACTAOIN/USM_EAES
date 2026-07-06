class StudentIdValidation {
  const StudentIdValidation.valid(this.value) : errorMessage = null;

  const StudentIdValidation.invalid(this.errorMessage) : value = null;

  final String? value;
  final String? errorMessage;

  bool get isValid => value != null;
}

class StudentIdValidator {
  StudentIdValidator._();

  static final RegExp _pattern = RegExp(r'^\d{2}-\d{5}$');

  static const String invalidFormatMessage =
      'Enter a valid student ID number, example: 23-72634.';

  static StudentIdValidation validate(String raw) {
    final trimmed = raw.trim();
    if (trimmed.isEmpty) {
      return const StudentIdValidation.invalid(invalidFormatMessage);
    }
    if (!_pattern.hasMatch(trimmed)) {
      return const StudentIdValidation.invalid(invalidFormatMessage);
    }
    return StudentIdValidation.valid(trimmed);
  }
}
