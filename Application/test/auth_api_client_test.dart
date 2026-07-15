import 'dart:convert';

import 'package:eaes_scanner/features/auth/data/auth_api_client.dart';
import 'package:eaes_scanner/features/auth/domain/scanner_assignment.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('sends device ID header when fetching scanner assignments', () async {
    final api = AuthApiClient(
      'https://example.test/api',
      httpClient: MockClient((request) async {
        expect(request.method, 'GET');
        expect(request.url.path, '/api/v1/scanner/assignments');
        expect(request.headers['Authorization'], 'Bearer mobile-token');
        expect(request.headers['X-Device-Id'], 'android:abc123');

        return http.Response(jsonEncode({'assignments': []}), 200);
      }),
    );

    final assignments = await api.assignments(
      token: 'mobile-token',
      deviceId: 'android:abc123',
    );

    expect(assignments, isEmpty);
  });

  test('parses scanner assignment activation state fields', () {
    final assignment = ScannerAssignment.fromJson({
      'id': 'assignment-1',
      'status': 'active',
      'activation_allowed': false,
      'session_available': true,
      'activation_state': 'blocked_active_session',
      'active_session_device_matches': false,
      'activation_message': 'Ask an admin to revoke the old scanner session.',
      'event': {
        'id': 'event-1',
        'title': 'Foundation Day',
        'status': 'approved',
        'lifecycle_phase': 'active',
        'event_days': [
          {
            'id': 'day-1',
            'day_number': 1,
            'date': '2026-07-10',
            'start_time': '08:00',
            'end_time': '12:00',
          },
        ],
      },
    });

    expect(assignment.activationState, 'blocked_active_session');
    expect(assignment.activeSessionDeviceMatches, isFalse);
    expect(
      assignment.activationMessage,
      'Ask an admin to revoke the old scanner session.',
    );
    expect(assignment.eventDays, hasLength(1));
    expect(assignment.eventTimeRangeLabel, '8:00 AM - 12:00 PM');
    expect(assignment.eventDaysDetailLabel, contains('D1: Jul 10, 2026'));
  });

  test('sends device ID body when activating scanner assignment', () async {
    const assignment = ScannerAssignment(
      id: 'assignment-1',
      status: 'active',
      activationAllowed: true,
      sessionAvailable: false,
      eventId: 'event-1',
      eventTitle: 'Foundation Day',
      eventStatus: 'approved',
      eventPhase: 'active',
    );
    final api = AuthApiClient(
      'https://example.test/api',
      httpClient: MockClient((request) async {
        expect(request.method, 'POST');
        expect(
          request.url.path,
          '/api/v1/scanner/assignments/assignment-1/activate',
        );
        expect(request.headers['Authorization'], 'Bearer mobile-token');
        expect(jsonDecode(request.body), {'device_id': 'android:abc123'});

        return http.Response(
          jsonEncode({
            'success': true,
            'resumed': false,
            'token': 'scanner-token',
            'event': {
              'id': 'event-1',
              'event_days': [
                {
                  'id': 'day-1',
                  'day_number': 1,
                  'date': '2026-07-10',
                  'start_time': '08:00',
                  'end_time': '12:00',
                },
              ],
            },
            'scanner_session': {
              'id': 'session-1',
              'assignment_id': 'assignment-1',
            },
          }),
          200,
        );
      }),
    );

    final session = await api.activateAssignment(
      token: 'mobile-token',
      assignment: assignment,
      deviceId: 'android:abc123',
    );

    expect(session.token, 'scanner-token');
    expect(session.assignmentId, 'assignment-1');
    expect(session.eventDays.single.timeRangeLabel, '8:00 AM - 12:00 PM');
  });

  test('surfaces active session transfer block message', () async {
    const assignment = ScannerAssignment(
      id: 'assignment-1',
      status: 'active',
      activationAllowed: true,
      sessionAvailable: true,
      eventId: 'event-1',
      eventTitle: 'Foundation Day',
      eventStatus: 'approved',
      eventPhase: 'active',
    );
    final api = AuthApiClient(
      'https://example.test/api',
      httpClient: MockClient((request) async {
        return http.Response(
          jsonEncode({
            'success': false,
            'reason': 'active_session_exists',
            'message':
                'This scanner assignment is already active on another device. Ask an admin to revoke the old scanner session before activating this device.',
          }),
          409,
        );
      }),
    );

    await expectLater(
      api.activateAssignment(
        token: 'mobile-token',
        assignment: assignment,
        deviceId: 'android:abc123',
      ),
      throwsA(
        isA<AuthApiException>()
            .having((error) => error.statusCode, 'statusCode', 409)
            .having(
              (error) => error.message,
              'message',
              contains('Ask an admin to revoke'),
            ),
      ),
    );
  });
}
