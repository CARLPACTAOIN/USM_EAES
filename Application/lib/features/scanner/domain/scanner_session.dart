import '../../../config/app_config.dart';

class ScannerSession {
  const ScannerSession({required this.token, required this.eventId});

  final String token;
  final String eventId;

  static ScannerSession? fromUri(Uri uri) {
    if (uri.scheme != AppConfig.deepLinkScheme ||
        uri.host != AppConfig.deepLinkScannerHost) {
      return null;
    }

    final token = uri.queryParameters['token'];
    final eventId =
        uri.queryParameters['event_id'] ??
        uri.queryParameters['eventId'] ??
        uri.queryParameters['event'];

    if (token == null || token.isEmpty || eventId == null || eventId.isEmpty) {
      return null;
    }

    return ScannerSession(token: token, eventId: eventId);
  }
}
