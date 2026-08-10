import 'dart:convert';

import 'package:eaes_scanner/features/scanner/data/scanner_api_client.dart';
import 'package:eaes_scanner/features/scanner/domain/offline_scan.dart';
import 'package:eaes_scanner/features/scanner/domain/scan_sync_status.dart';
import 'package:eaes_scanner/features/scanner/domain/scan_type.dart';
import 'package:eaes_scanner/features/scanner/domain/scanner_session.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('preserves Retry-After from scanner sync failures', () async {
    final client = ScannerApiClient(
      'https://example.test/api',
      httpClient: MockClient((request) async {
        return http.Response(
          jsonEncode({
            'success': false,
            'message': 'Service coordination is temporarily unavailable.',
            'retry_after': 9,
          }),
          503,
          headers: {'retry-after': '9'},
        );
      }),
    );
    final scan = OfflineScan(
      dedupKey: 'retry-test',
      eventId: 'event-1',
      scanType: ScanType.timeIn,
      scannedAt: DateTime.utc(2026, 8, 8, 8),
      deviceId: 'device-1',
      manualEntry: false,
      qrCodeValue: 'QR-1',
      resolutionStatus: 'pending_server_lookup',
      syncStatus: ScanSyncStatus.pending,
    );

    await expectLater(
      client.syncScans(
        session: const ScannerSession(token: 'token-1', eventId: 'event-1'),
        scans: [scan],
      ),
      throwsA(
        isA<ScannerApiException>()
            .having((error) => error.statusCode, 'statusCode', 503)
            .having(
              (error) => error.retryAfter,
              'retryAfter',
              const Duration(seconds: 9),
            ),
      ),
    );
  });
}
