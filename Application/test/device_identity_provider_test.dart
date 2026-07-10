import 'package:eaes_scanner/features/auth/data/device_identity_provider.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test(
    'returns platform device identity when native channel provides one',
    () async {
      SharedPreferences.setMockInitialValues({});
      const channel = MethodChannel('test/device_identity/platform');
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, (call) async {
            expect(call.method, 'deviceId');
            return 'android:abc123';
          });
      addTearDown(
        () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(channel, null),
      );

      final provider = PlatformDeviceIdentityProvider(channel: channel);

      expect(await provider.deviceId(), 'android:abc123');
    },
  );

  test(
    'falls back to stored generated identity when native channel is missing',
    () async {
      SharedPreferences.setMockInitialValues({});
      const channel = MethodChannel('test/device_identity/fallback');
      final preferences = await SharedPreferences.getInstance();
      var generatedCount = 0;
      final provider = PlatformDeviceIdentityProvider(
        channel: channel,
        preferences: preferences,
        uuidFactory: () {
          generatedCount++;
          return 'generated-device-id';
        },
      );

      expect(await provider.deviceId(), 'fallback:generated-device-id');
      expect(generatedCount, 1);

      final restoredProvider = PlatformDeviceIdentityProvider(
        channel: channel,
        preferences: preferences,
        uuidFactory: () => 'different-device-id',
      );

      expect(await restoredProvider.deviceId(), 'fallback:generated-device-id');
    },
  );
}
