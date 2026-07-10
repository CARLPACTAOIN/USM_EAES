package edu.usm.eaes.eaes_scanner

import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(
            flutterEngine.dartExecutor.binaryMessenger,
            "edu.usm.eaes/device_identity"
        ).setMethodCallHandler { call, result ->
            if (call.method != "deviceId") {
                result.notImplemented()
                return@setMethodCallHandler
            }

            val androidId = Settings.Secure.getString(
                contentResolver,
                Settings.Secure.ANDROID_ID
            )
            result.success(androidId?.takeIf { it.isNotBlank() }?.let { "android:$it" })
        }
    }
}
