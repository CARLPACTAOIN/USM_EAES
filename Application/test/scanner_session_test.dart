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
  });
}
