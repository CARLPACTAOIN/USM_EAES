class RosterStudent {
  const RosterStudent({
    required this.studentId,
    required this.qrCodeValue,
    required this.name,
    required this.programCode,
    this.studentIdNumber,
  });

  final String studentId;
  final String qrCodeValue;
  final String name;
  final String? programCode;
  final String? studentIdNumber;

  factory RosterStudent.fromJson(Map<String, dynamic> json) {
    return RosterStudent(
      studentId: json['student_id'].toString(),
      qrCodeValue: json['qr_code_value'].toString(),
      name: json['name'].toString(),
      programCode: json['program_code']?.toString(),
      studentIdNumber: json['student_id_number']?.toString(),
    );
  }

  factory RosterStudent.fromDb(Map<String, Object?> row) {
    return RosterStudent(
      studentId: row['student_id'] as String,
      qrCodeValue: row['qr_code_value'] as String,
      name: row['name'] as String,
      programCode: row['program_code'] as String?,
      studentIdNumber: row['student_id_number'] as String?,
    );
  }

  Map<String, Object?> toDb() {
    return {
      'student_id': studentId,
      'qr_code_value': qrCodeValue,
      'name': name,
      'program_code': programCode,
      'student_id_number': studentIdNumber,
      'updated_at': DateTime.now().toUtc().toIso8601String(),
    };
  }
}
