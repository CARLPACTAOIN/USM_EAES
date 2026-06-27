import 'scan_type.dart';

class OfflineScan {
  const OfflineScan({
    this.localId,
    required this.eventId,
    this.eventDayId,
    this.studentId,
    required this.qrCodeValue,
    required this.scanType,
    required this.scannedAt,
    required this.deviceId,
    required this.manualEntry,
    required this.dedupKey,
    required this.resolutionStatus,
    this.syncedAt,
  });

  final int? localId;
  final String eventId;
  final String? eventDayId;
  final String? studentId;
  final String qrCodeValue;
  final ScanType scanType;
  final DateTime scannedAt;
  final String deviceId;
  final bool manualEntry;
  final String dedupKey;
  final String resolutionStatus;
  final DateTime? syncedAt;

  factory OfflineScan.fromDb(Map<String, Object?> row) {
    return OfflineScan(
      localId: row['id'] as int?,
      eventId: row['event_id'] as String,
      eventDayId: row['event_day_id'] as String?,
      studentId: row['student_id'] as String?,
      qrCodeValue: row['qr_code_value'] as String,
      scanType: (row['scan_type'] as String) == ScanType.timeOut.apiValue
          ? ScanType.timeOut
          : ScanType.timeIn,
      scannedAt: DateTime.parse(row['scanned_at'] as String),
      deviceId: row['device_id'] as String,
      manualEntry: (row['manual_entry'] as int) == 1,
      dedupKey: row['dedup_key'] as String,
      resolutionStatus: row['resolution_status'] as String,
      syncedAt: row['synced_at'] == null
          ? null
          : DateTime.parse(row['synced_at'] as String),
    );
  }

  Map<String, Object?> toDb() {
    return {
      'event_id': eventId,
      'event_day_id': eventDayId,
      'student_id': studentId,
      'qr_code_value': qrCodeValue,
      'scan_type': scanType.apiValue,
      'scanned_at': scannedAt.toUtc().toIso8601String(),
      'device_id': deviceId,
      'manual_entry': manualEntry ? 1 : 0,
      'dedup_key': dedupKey,
      'resolution_status': resolutionStatus,
      'synced_at': syncedAt?.toUtc().toIso8601String(),
    };
  }

  Map<String, Object?> toSyncJson() {
    return {
      'event_day_id': eventDayId,
      'qr_code_value': qrCodeValue,
      'scan_type': scanType.apiValue,
      'scanned_at': scannedAt.toUtc().toIso8601String(),
      'device_id': deviceId,
      'manual_entry': manualEntry,
      'dedup_key': dedupKey,
    };
  }
}
