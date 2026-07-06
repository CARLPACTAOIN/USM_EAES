class MobileUser {
  const MobileUser({
    required this.id,
    required this.name,
    required this.email,
    this.organizationId,
    this.collegeId,
    this.programId,
    this.userType,
    this.roles = const [],
  });

  final String id;
  final String name;
  final String email;
  final String? organizationId;
  final String? collegeId;
  final String? programId;
  final String? userType;
  final List<String> roles;

  factory MobileUser.fromJson(Map<String, dynamic> json) {
    return MobileUser(
      id: json['id'].toString(),
      name: json['name']?.toString() ?? 'Scanner',
      email: json['email']?.toString() ?? '',
      organizationId: json['organization_id']?.toString(),
      collegeId: json['college_id']?.toString(),
      programId: json['program_id']?.toString(),
      userType: json['user_type']?.toString(),
      roles: (json['roles'] as List<dynamic>? ?? [])
          .map((item) => item.toString())
          .toList(),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'email': email,
      'organization_id': organizationId,
      'college_id': collegeId,
      'program_id': programId,
      'user_type': userType,
      'roles': roles,
    };
  }
}
