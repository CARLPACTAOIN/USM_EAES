import 'event_schedule_day.dart';

class ScannerSession {
  const ScannerSession({
    required this.token,
    required this.eventId,
    this.assignmentId,
    this.eventParticipationScopeId,
    this.participationScopeName,
    this.eventDays = const [],
  });

  final String token;
  final String eventId;
  final String? assignmentId;
  final String? eventParticipationScopeId;
  final String? participationScopeName;
  final List<EventScheduleDay> eventDays;

  ScannerSession copyWith({
    String? assignmentId,
    String? eventParticipationScopeId,
    String? participationScopeName,
    List<EventScheduleDay>? eventDays,
  }) {
    return ScannerSession(
      token: token,
      eventId: eventId,
      assignmentId: assignmentId ?? this.assignmentId,
      eventParticipationScopeId:
          eventParticipationScopeId ?? this.eventParticipationScopeId,
      participationScopeName:
          participationScopeName ?? this.participationScopeName,
      eventDays: eventDays ?? this.eventDays,
    );
  }
}

class ScannerSessionSnapshot {
  const ScannerSessionSnapshot({
    required this.token,
    required this.eventId,
    this.assignmentId,
    this.eventParticipationScopeId,
    this.participationScopeName,
    this.eventTitle,
    this.eventStatus,
    this.eventLifecyclePhase,
    this.scannerSessionStatus,
    this.eventDays = const [],
    this.scanWindowStart,
    this.scanWindowEnd,
    this.timeInWindowStart,
    this.timeInWindowEnd,
    this.timeOutWindowStart,
    this.timeOutWindowEnd,
    this.syncGraceEndsAt,
    required this.savedAt,
  });

  final String token;
  final String eventId;
  final String? assignmentId;
  final String? eventParticipationScopeId;
  final String? participationScopeName;
  final String? eventTitle;
  final String? eventStatus;
  final String? eventLifecyclePhase;
  final String? scannerSessionStatus;
  final List<EventScheduleDay> eventDays;
  final DateTime? scanWindowStart;
  final DateTime? scanWindowEnd;
  final DateTime? timeInWindowStart;
  final DateTime? timeInWindowEnd;
  final DateTime? timeOutWindowStart;
  final DateTime? timeOutWindowEnd;
  final DateTime? syncGraceEndsAt;
  final DateTime savedAt;

  factory ScannerSessionSnapshot.fromJson(Map<String, dynamic> json) {
    final token = json['token']?.toString();
    final eventId = json['event_id']?.toString();
    if (token == null || token.isEmpty || eventId == null || eventId.isEmpty) {
      throw const FormatException('Saved scanner session is incomplete.');
    }

    return ScannerSessionSnapshot(
      token: token,
      eventId: eventId,
      assignmentId: _stringOrNull(json['assignment_id']),
      eventParticipationScopeId: _stringOrNull(
        json['event_participation_scope_id'],
      ),
      participationScopeName: _stringOrNull(json['participation_scope_name']),
      eventTitle: _stringOrNull(json['event_title']),
      eventStatus: _stringOrNull(json['event_status']),
      eventLifecyclePhase: _stringOrNull(json['event_lifecycle_phase']),
      scannerSessionStatus: _stringOrNull(json['scanner_session_status']),
      eventDays: eventScheduleDaysFromJson(json['event_days']),
      scanWindowStart: _dateTimeOrNull(json['scan_window_start']),
      scanWindowEnd: _dateTimeOrNull(json['scan_window_end']),
      timeInWindowStart: _dateTimeOrNull(json['time_in_window_start']),
      timeInWindowEnd: _dateTimeOrNull(json['time_in_window_end']),
      timeOutWindowStart: _dateTimeOrNull(json['time_out_window_start']),
      timeOutWindowEnd: _dateTimeOrNull(json['time_out_window_end']),
      syncGraceEndsAt: _dateTimeOrNull(json['sync_grace_ends_at']),
      savedAt: _dateTimeOrNull(json['saved_at']) ?? DateTime.now().toUtc(),
    );
  }

  ScannerSession get session {
    return ScannerSession(
      token: token,
      eventId: eventId,
      assignmentId: assignmentId,
      eventParticipationScopeId: eventParticipationScopeId,
      participationScopeName: participationScopeName,
      eventDays: eventDays,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'token': token,
      'event_id': eventId,
      'assignment_id': assignmentId,
      'event_participation_scope_id': eventParticipationScopeId,
      'participation_scope_name': participationScopeName,
      'event_title': eventTitle,
      'event_status': eventStatus,
      'event_lifecycle_phase': eventLifecyclePhase,
      'scanner_session_status': scannerSessionStatus,
      'event_days': eventDays.map((day) => day.toJson()).toList(),
      'scan_window_start': scanWindowStart?.toUtc().toIso8601String(),
      'scan_window_end': scanWindowEnd?.toUtc().toIso8601String(),
      'time_in_window_start': timeInWindowStart?.toUtc().toIso8601String(),
      'time_in_window_end': timeInWindowEnd?.toUtc().toIso8601String(),
      'time_out_window_start': timeOutWindowStart?.toUtc().toIso8601String(),
      'time_out_window_end': timeOutWindowEnd?.toUtc().toIso8601String(),
      'sync_grace_ends_at': syncGraceEndsAt?.toUtc().toIso8601String(),
      'saved_at': savedAt.toUtc().toIso8601String(),
    };
  }

  static String? _stringOrNull(Object? value) {
    if (value == null) return null;
    final text = value.toString();
    return text.isEmpty ? null : text;
  }

  static DateTime? _dateTimeOrNull(Object? value) {
    if (value == null) return null;
    return DateTime.tryParse(value.toString());
  }
}
