/// Runtime configuration for the EAES scanner app.
///
/// Override at build/run time with `--dart-define`:
/// `flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api` or `http://localhost:8000/api` 
class AppConfig {
  AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://satisfactory-flo-inarguably.ngrok-free.dev/api',
  );

  static const String appName = 'USM EAES Scanner';
  static const String deviceId = String.fromEnvironment(
    'EAES_DEVICE_ID',
    defaultValue: 'scanner-dev-device',
  );
  static const int syncBatchSize = int.fromEnvironment(
    'EAES_SYNC_BATCH_SIZE',
    defaultValue: 200,
  );

  /// Deep-link scheme for scanner sessions (PRD Epic 3.1).
  /// Example: `eaes://scanner?token=...&event_id=...`
  static const String deepLinkScheme = 'eaes';
  static const String deepLinkScannerHost = 'scanner';
}
