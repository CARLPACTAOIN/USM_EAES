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
  static const String googleClientId = String.fromEnvironment(
    'GOOGLE_CLIENT_ID',
    defaultValue:
        '436328333223-lmb41qei92hv5i84fk0596can1og40jl.apps.googleusercontent.com',
  );
  static const String googleServerClientId = String.fromEnvironment(
    'GOOGLE_SERVER_CLIENT_ID',
    defaultValue:
        '436328333223-lmb41qei92hv5i84fk0596can1og40jl.apps.googleusercontent.com',
  );
  static const String googleHostedDomain = String.fromEnvironment(
    'GOOGLE_HOSTED_DOMAIN',
    defaultValue: '',
  );
  static const String deviceId = String.fromEnvironment(
    'EAES_DEVICE_ID',
    defaultValue: 'scanner-dev-device',
  );
  static const int syncBatchSize = int.fromEnvironment(
    'EAES_SYNC_BATCH_SIZE',
    defaultValue: 200,
  );

  /// When true, collapsed technical error details are shown in the UI.
  static const bool debugDetails = bool.fromEnvironment(
    'EAES_DEBUG_DETAILS',
    defaultValue: false,
  );

  static const int duplicateCooldownSeconds = int.fromEnvironment(
    'EAES_DUPLICATE_COOLDOWN_SECONDS',
    defaultValue: 5,
  );

  /// After a successful camera capture, scanning is throttled for this many
  /// milliseconds to prevent rapid repeated reads of the next QR code.
  /// Spec range is 500–1000ms; defaults to 800ms.
  static const int postScanThrottleMs = int.fromEnvironment(
    'EAES_POST_SCAN_THROTTLE_MS',
    defaultValue: 800,
  );

  /// When true, the same QR code must leave the camera frame before it can be
  /// scanned again, preventing duplicate reads while a code lingers in view.
  static const bool requireQrExitFrame = bool.fromEnvironment(
    'EAES_REQUIRE_QR_EXIT_FRAME',
    defaultValue: true,
  );

  /// How long the camera must report no detections before the frame is
  /// considered "cleared" (the QR has left the view). Used together with
  /// [requireQrExitFrame].
  static const int frameClearMs = int.fromEnvironment(
    'EAES_FRAME_CLEAR_MS',
    defaultValue: 700,
  );
}
