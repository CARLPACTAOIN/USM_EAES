import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import '../../../config/app_config.dart';

abstract class DeviceIdentityProvider {
  Future<String> deviceId();
}

class PlatformDeviceIdentityProvider implements DeviceIdentityProvider {
  PlatformDeviceIdentityProvider({
    MethodChannel channel = const MethodChannel('edu.usm.eaes/device_identity'),
    SharedPreferences? preferences,
    String Function()? uuidFactory,
  }) : _channel = channel,
       _preferences = preferences,
       _uuidFactory = uuidFactory ?? (() => const Uuid().v4());

  static const _fallbackDeviceIdKey = 'eaes.fallback_device_id';

  final MethodChannel _channel;
  SharedPreferences? _preferences;
  final String Function() _uuidFactory;
  Future<String>? _deviceIdFuture;

  @override
  Future<String> deviceId() {
    return _deviceIdFuture ??= _resolveDeviceId();
  }

  Future<String> _resolveDeviceId() async {
    try {
      final platformDeviceId = await _channel.invokeMethod<String>('deviceId');
      if (platformDeviceId != null && platformDeviceId.trim().isNotEmpty) {
        return platformDeviceId.trim();
      }
    } on MissingPluginException {
      // Non-mobile targets and tests use the fallback path.
    } on PlatformException {
      // Keep scanner login usable even when native identity lookup fails.
    }

    if (AppConfig.hasDeviceIdOverride) {
      return AppConfig.deviceId;
    }

    _preferences ??= await SharedPreferences.getInstance();
    final cached = _preferences!.getString(_fallbackDeviceIdKey);
    if (cached != null && cached.isNotEmpty) {
      return cached;
    }

    final generated = 'fallback:${_uuidFactory()}';
    await _preferences!.setString(_fallbackDeviceIdKey, generated);
    return generated;
  }
}
