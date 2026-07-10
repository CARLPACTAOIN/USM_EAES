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
        scanWindowStart: DateTime.utc(2026, 7, 10, 8),
        scanWindowEnd: DateTime.utc(2026, 7, 10, 12),
        syncGraceEndsAt: DateTime.utc(2026, 7, 13, 12),
        savedAt: savedAt,
      );

      final restored = ScannerSessionSnapshot.fromJson(snapshot.toJson());

      expect(restored.session.token, 'abc123');
      expect(restored.session.eventParticipationScopeId, 'scope-1');
      expect(restored.eventLifecyclePhase, 'active');
      expect(restored.scanWindowEnd, DateTime.utc(2026, 7, 10, 12));
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
