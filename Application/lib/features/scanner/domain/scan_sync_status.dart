enum ScanSyncStatus {
  pending('pending'),
  syncing('syncing'),
  synced('synced'),
  failed('failed');

  const ScanSyncStatus(this.dbValue);

  final String dbValue;

  static ScanSyncStatus fromDb(String? value) {
    return ScanSyncStatus.values.firstWhere(
      (item) => item.dbValue == value,
      orElse: () => ScanSyncStatus.pending,
    );
  }
}

enum FeedbackKind { none, loading, success, error, warning }

class GlobalFeedback {
  const GlobalFeedback({
    required this.kind,
    required this.message,
    this.subtitle,
  });

  const GlobalFeedback.none()
    : kind = FeedbackKind.none,
      message = '',
      subtitle = null;

  final FeedbackKind kind;
  final String message;
  final String? subtitle;

  bool get isVisible => kind != FeedbackKind.none;
}
