class RosterStudent {
  const RosterStudent({
    required this.studentId,
    required this.qrCodeValue,
    required this.name,
    required this.programCode,
  });

  final String studentId;
  final String qrCodeValue;
  final String name;
  final String? programCode;

  factory RosterStudent.fromJson(Map<String, dynamic> json) {
    return RosterStudent(
      studentId: json['student_id'].toString(),
      qrCodeValue: json['qr_code_value'].toString(),
      name: json['name'].toString(),
      programCode: json['program_code']?.toString(),
    );
  }

  factory RosterStudent.fromDb(Map<String, Object?> row) {
    return RosterStudent(
      studentId: row['student_id'] as String,
      qrCodeValue: row['qr_code_value'] as String,
      name: row['name'] as String,
      programCode: row['program_code'] as String?,
    );
  }

  Map<String, Object?> toDb() {
    return {
      'student_id': studentId,
      'qr_code_value': qrCodeValue,
      'name': name,
      'program_code': programCode,
      'updated_at': DateTime.now().toUtc().toIso8601String(),
    };
  }
}
