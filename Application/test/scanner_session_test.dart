import 'package:eaes_scanner/features/scanner/domain/event_schedule_day.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('ScannerSession', () {
    test('stores activated assignment session details', () {
      const session = ScannerSession(
        token: 'abc123',
        eventId: 'event-1',
        assignmentId: 'assignment-1',
      );

      expect(session.token, 'abc123');
      expect(session.eventId, 'event-1');
      expect(session.assignmentId, 'assignment-1');
    });

    test('serializes rich session snapshot for offline restore', () {
      final savedAt = DateTime.utc(2026, 7, 10, 8);
      final snapshot = ScannerSessionSnapshot(
        token: 'abc123',
        eventId: 'event-1',
        assignmentId: 'assignment-1',
        eventParticipationScopeId: 'scope-1',
        participationScopeName: 'PSITS',
        eventTitle: 'Development Seminar',
        eventStatus: 'approved',
        eventLifecyclePhase: 'active',
        scannerSessionStatus: 'active',
        eventDays: [
          EventScheduleDay(
            id: 'day-1',
            dayNumber: 1,
            date: DateTime.utc(2026, 7, 10),
            startTime: '08:00',
            endTime: '12:00',
          ),
        ],
        scanWindowStart: DateTime.utc(2026, 7, 10, 8),
        scanWindowEnd: DateTime.utc(2026, 7, 10, 12),
        timeInWindowStart: DateTime.utc(2026, 7, 10, 7),
        timeInWindowEnd: DateTime.utc(2026, 7, 10, 12),
        timeOutWindowStart: DateTime.utc(2026, 7, 10, 8),
        timeOutWindowEnd: DateTime.utc(2026, 7, 10, 13),
        syncGraceEndsAt: DateTime.utc(2026, 7, 13, 12),
        savedAt: savedAt,
      );

      final restored = ScannerSessionSnapshot.fromJson(snapshot.toJson());

      expect(restored.session.token, 'abc123');
      expect(restored.session.eventParticipationScopeId, 'scope-1');
      expect(restored.eventLifecyclePhase, 'active');
      expect(restored.eventDays.single.timeRangeLabel, '8:00 AM - 12:00 PM');
      expect(restored.session.eventDays.single.dateLabel, 'Jul 10, 2026');
      expect(restored.scanWindowEnd, DateTime.utc(2026, 7, 10, 12));
      expect(restored.timeInWindowStart, DateTime.utc(2026, 7, 10, 7));
      expect(restored.timeOutWindowEnd, DateTime.utc(2026, 7, 10, 13));
      expect(restored.savedAt, savedAt);
    });

    test('accepts legacy minimal saved session payload', () {
      final snapshot = ScannerSessionSnapshot.fromJson(const {
        'token': 'legacy-token',
        'event_id': 'event-1',
      });

      expect(snapshot.session.token, 'legacy-token');
      expect(snapshot.eventLifecyclePhase, isNull);
      expect(snapshot.scanWindowStart, isNull);
    });
  });
}
