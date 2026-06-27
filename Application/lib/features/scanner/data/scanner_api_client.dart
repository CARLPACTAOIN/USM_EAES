import 'dart:convert';

import 'package:http/http.dart' as http;

import '../domain/offline_scan.dart';
import '../domain/roster_student.dart';
import '../domain/scanner_session.dart';
import 'scanner_contracts.dart';

class ScannerApiClient implements ScannerRemoteApi {
  ScannerApiClient(this.apiBaseUrl, {http.Client? httpClient})
    : _client = httpClient ?? http.Client();

  final String apiBaseUrl;
  final http.Client _client;

  @override
  Future<Map<String, dynamic>> validateSession(ScannerSession session) async {
    final response = await _client.post(
      _uri('/v1/scanner/validate'),
      headers: _headers(session.token),
      body: jsonEncode({'event_id': session.eventId}),
    );
    return _decodeJson(response);
  }

  @override
  Future<List<RosterStudent>> hydrateRoster(ScannerSession session) async {
    final response = await _client.get(
      _uri('/v1/events/${session.eventId}/hydrate'),
      headers: _headers(session.token),
    );
    final payload = _decodeJson(response);
    final roster = payload['roster'] as List<dynamic>? ?? [];
    return roster
        .map((item) => RosterStudent.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<Map<String, dynamic>> syncScans({
    required ScannerSession session,
    required List<OfflineScan> scans,
  }) async {
    final response = await _client.post(
      _uri('/v1/attendance/sync'),
      headers: _headers(session.token),
      body: jsonEncode({
        'event_id': session.eventId,
        'scans': scans.map((scan) => scan.toSyncJson()).toList(),
      }),
    );
    return _decodeJson(response);
  }

  Uri _uri(String path) {
    return Uri.parse('${apiBaseUrl.replaceFirst(RegExp(r'/$'), '')}$path');
  }

  Map<String, String> _headers(String token) {
    return {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'Authorization': 'Bearer $token',
    };
  }

  Map<String, dynamic> _decodeJson(http.Response response) {
    final decoded = jsonDecode(response.body.isEmpty ? '{}' : response.body);
    if (response.statusCode >= 400) {
      final message = decoded is Map<String, dynamic>
          ? decoded['message']?.toString()
          : null;
      throw ScannerApiException(
        message ?? 'Request failed with HTTP ${response.statusCode}.',
        statusCode: response.statusCode,
      );
    }

    if (decoded is! Map<String, dynamic>) {
      throw const ScannerApiException('Unexpected API response format.');
    }

    return decoded;
  }
}

class ScannerApiException implements Exception {
  const ScannerApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}
