import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('ScannerSession', () {
    test('parses dashboard scanner deep link', () {
      final session = ScannerSession.fromUri(
        Uri.parse('eaes://scanner?token=abc123&event_id=event-1'),
      );

      expect(session, isNotNull);
      expect(session!.token, 'abc123');
      expect(session.eventId, 'event-1');
    });

    test('accepts compatible event id query aliases', () {
      final session = ScannerSession.fromUri(
        Uri.parse('eaes://scanner?token=abc123&eventId=event-2'),
      );

      expect(session, isNotNull);
      expect(session!.eventId, 'event-2');
    });

    test('rejects invalid scanner links', () {
      expect(
        ScannerSession.fromUri(Uri.parse('https://example.test/scanner')),
        isNull,
      );
      expect(
        ScannerSession.fromUri(Uri.parse('eaes://scanner?token=abc')),
        isNull,
      );
      expect(
        ScannerSession.fromUri(Uri.parse('eaes://portal?token=abc&event_id=1')),
        isNull,
      );
    });
  });
}
