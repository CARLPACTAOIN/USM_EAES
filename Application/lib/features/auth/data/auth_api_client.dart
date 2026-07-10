import 'dart:convert';

import 'package:http/http.dart' as http;

import '../../scanner/domain/scanner_session.dart';
import '../domain/mobile_user.dart';
import '../domain/scanner_assignment.dart';

abstract class AuthRemoteApi {
  Future<AuthLoginResult> loginWithGoogle({
    required String idToken,
    required String deviceName,
  });

  Future<void> logout(String token);
  Future<List<ScannerAssignment>> assignments({
    required String token,
    required String deviceId,
  });
  Future<ScannerSession> activateAssignment({
    required String token,
    required ScannerAssignment assignment,
    required String deviceId,
  });
}

class AuthApiClient implements AuthRemoteApi {
  AuthApiClient(this.apiBaseUrl, {http.Client? httpClient})
    : _client = httpClient ?? http.Client();

  final String apiBaseUrl;
  final http.Client _client;

  @override
  Future<AuthLoginResult> loginWithGoogle({
    required String idToken,
    required String deviceName,
  }) async {
    final response = await _client.post(
      _uri('/v1/auth/google/mobile'),
      headers: _jsonHeaders(),
      body: jsonEncode({'id_token': idToken, 'device_name': deviceName}),
    );
    final payload = _decodeJson(response);

    return AuthLoginResult(
      token: payload['token'].toString(),
      user: MobileUser.fromJson(payload['user'] as Map<String, dynamic>),
    );
  }

  @override
  Future<void> logout(String token) async {
    await _client.post(_uri('/v1/mobile/logout'), headers: _authHeaders(token));
  }

  @override
  Future<List<ScannerAssignment>> assignments({
    required String token,
    required String deviceId,
  }) async {
    final response = await _client.get(
      _uri('/v1/scanner/assignments'),
      headers: {..._authHeaders(token), 'X-Device-Id': deviceId},
    );
    final payload = _decodeJson(response);
    final items = payload['assignments'] as List<dynamic>? ?? [];

    return items
        .map((item) => ScannerAssignment.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<ScannerSession> activateAssignment({
    required String token,
    required ScannerAssignment assignment,
    required String deviceId,
  }) async {
    final response = await _client.post(
      _uri('/v1/scanner/assignments/${assignment.id}/activate'),
      headers: _authHeaders(token),
      body: jsonEncode({'device_id': deviceId}),
    );
    final payload = _decodeJson(response);
    final event = payload['event'] as Map<String, dynamic>? ?? {};
    final scannerSession =
        payload['scanner_session'] as Map<String, dynamic>? ?? {};
    var participationScope =
        scannerSession['participation_scope'] as Map<String, dynamic>?;
    if (participationScope == null &&
        payload['assignment'] is Map<String, dynamic>) {
      participationScope =
          (payload['assignment'] as Map<String, dynamic>)['participation_scope']
              as Map<String, dynamic>?;
    }
    participationScope ??= {};

    return ScannerSession(
      token: payload['token'].toString(),
      eventId: event['id']?.toString() ?? assignment.eventId,
      assignmentId:
          scannerSession['assignment_id']?.toString() ?? assignment.id,
      eventParticipationScopeId:
          scannerSession['event_participation_scope_id']?.toString() ??
          assignment.eventParticipationScopeId,
      participationScopeName:
          participationScope['name']?.toString() ??
          assignment.participationScopeName,
    );
  }

  Uri _uri(String path) {
    return Uri.parse('${apiBaseUrl.replaceFirst(RegExp(r'/$'), '')}$path');
  }

  Map<String, String> _jsonHeaders() {
    return const {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };
  }

  Map<String, String> _authHeaders(String token) {
    return {..._jsonHeaders(), 'Authorization': 'Bearer $token'};
  }

  Map<String, dynamic> _decodeJson(http.Response response) {
    final decoded = jsonDecode(response.body.isEmpty ? '{}' : response.body);
    if (response.statusCode >= 400) {
      final message = decoded is Map<String, dynamic>
          ? decoded['message']?.toString()
          : null;
      throw AuthApiException(
        message ?? 'Request failed with HTTP ${response.statusCode}.',
        statusCode: response.statusCode,
      );
    }

    if (decoded is! Map<String, dynamic>) {
      throw const AuthApiException('Unexpected API response format.');
    }

    return decoded;
  }
}

class AuthLoginResult {
  const AuthLoginResult({required this.token, required this.user});

  final String token;
  final MobileUser user;
}

class AuthApiException implements Exception {
  const AuthApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}
