class ScannerAssignment {
  const ScannerAssignment({
    required this.id,
    required this.status,
    required this.activationAllowed,
    required this.sessionAvailable,
    required this.eventId,
    required this.eventTitle,
    required this.eventStatus,
    required this.eventPhase,
    this.eventParticipationScopeId,
    this.participationScopeName,
    this.participationScopeType,
    this.participationScopeStatus,
    this.label,
    this.organizationName,
    this.organizationAcronym,
    this.expiresAt,
    this.lastActivatedAt,
  });

  final String id;
  final String? label;
  final String status;
  final bool activationAllowed;
  final bool sessionAvailable;
  final String eventId;
  final String eventTitle;
  final String eventStatus;
  final String eventPhase;
  final String? eventParticipationScopeId;
  final String? participationScopeName;
  final String? participationScopeType;
  final String? participationScopeStatus;
  final String? organizationName;
  final String? organizationAcronym;
  final DateTime? expiresAt;
  final DateTime? lastActivatedAt;

  bool get isActive => status == 'active';

  factory ScannerAssignment.fromJson(Map<String, dynamic> json) {
    final event = json['event'] as Map<String, dynamic>? ?? {};
    final organization = event['organization'] as Map<String, dynamic>? ?? {};
    final participationScope =
        json['participation_scope'] as Map<String, dynamic>? ?? {};

    return ScannerAssignment(
      id: json['id'].toString(),
      label: json['label']?.toString(),
      status: json['status']?.toString() ?? 'active',
      activationAllowed: json['activation_allowed'] == true,
      sessionAvailable: json['session_available'] == true,
      eventId: event['id']?.toString() ?? '',
      eventTitle: event['title']?.toString() ?? 'Assigned event',
      eventStatus: event['status']?.toString() ?? 'unknown',
      eventPhase: event['lifecycle_phase']?.toString() ?? 'scheduled',
      eventParticipationScopeId: participationScope['id']?.toString(),
      participationScopeName: participationScope['name']?.toString(),
      participationScopeType: participationScope['participant_type']
          ?.toString(),
      participationScopeStatus: participationScope['ppa_status']?.toString(),
      organizationName: organization['name']?.toString(),
      organizationAcronym: organization['acronym']?.toString(),
      expiresAt: _parseDate(json['expires_at']),
      lastActivatedAt: _parseDate(json['last_activated_at']),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'label': label,
      'status': status,
      'activation_allowed': activationAllowed,
      'session_available': sessionAvailable,
      'expires_at': expiresAt?.toIso8601String(),
      'last_activated_at': lastActivatedAt?.toIso8601String(),
      'event': {
        'id': eventId,
        'title': eventTitle,
        'status': eventStatus,
        'lifecycle_phase': eventPhase,
        'organization': {
          'name': organizationName,
          'acronym': organizationAcronym,
        },
      },
      'participation_scope': {
        'id': eventParticipationScopeId,
        'name': participationScopeName,
        'participant_type': participationScopeType,
        'ppa_status': participationScopeStatus,
      },
    };
  }

  static DateTime? _parseDate(Object? value) {
    if (value == null) return null;
    return DateTime.tryParse(value.toString());
  }
}
