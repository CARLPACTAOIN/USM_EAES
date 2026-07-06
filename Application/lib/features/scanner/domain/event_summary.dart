class EventSummary {
  const EventSummary({
    required this.eventId,
    required this.title,
    required this.totalSynced,
    this.lastSyncedAt,
    this.wipedAt,
  });

  final String eventId;
  final String title;
  final int totalSynced;
  final DateTime? lastSyncedAt;
  final DateTime? wipedAt;

  factory EventSummary.fromDb(Map<String, Object?> row) {
    return EventSummary(
      eventId: row['event_id'] as String,
      title: row['title'] as String,
      totalSynced: row['total_synced'] as int? ?? 0,
      lastSyncedAt: row['last_synced_at'] == null
          ? null
          : DateTime.parse(row['last_synced_at'] as String),
      wipedAt: row['wiped_at'] == null
          ? null
          : DateTime.parse(row['wiped_at'] as String),
    );
  }

  Map<String, Object?> toDb() {
    return {
      'event_id': eventId,
      'title': title,
      'total_synced': totalSynced,
      'last_synced_at': lastSyncedAt?.toUtc().toIso8601String(),
      'wiped_at': wipedAt?.toUtc().toIso8601String(),
    };
  }
}
