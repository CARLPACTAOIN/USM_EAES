class ScannerSession {
  const ScannerSession({
    required this.token,
    required this.eventId,
    this.assignmentId,
    this.eventParticipationScopeId,
    this.participationScopeName,
  });

  final String token;
  final String eventId;
  final String? assignmentId;
  final String? eventParticipationScopeId;
  final String? participationScopeName;

  ScannerSession copyWith({
    String? assignmentId,
    String? eventParticipationScopeId,
    String? participationScopeName,
  }) {
    return ScannerSession(
      token: token,
      eventId: eventId,
      assignmentId: assignmentId ?? this.assignmentId,
      eventParticipationScopeId:
          eventParticipationScopeId ?? this.eventParticipationScopeId,
      participationScopeName:
          participationScopeName ?? this.participationScopeName,
    );
  }
}
