// Who is calling the server: the app's platform and store version (the server's version gate), this
// installation's id and the phone's model (the name of its session in «أجهزتي»).

import 'dart:math';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:zon/core/net/secret_store.dart';

/// The app and the phone, as every request names them (docs/api.md «ترويسات يرسلها التطبيق»).
@immutable
class AppIdentity {
  const AppIdentity({required this.platform, required this.version, required this.build, required this.deviceId, required this.deviceName});

  /// `ios` · `android` · `web` — X-App-Platform.
  final String platform;

  /// The store version, `1.2.1` (pubspec.yaml `version` before the `+`) — X-App-Version, compared with the
  /// minimum set in the admin panel («إصدار التطبيق ووضع الصيانة»).
  final String version;

  /// The build number (after the `+`).
  final String build;

  /// A random id of this installation — X-Device-Id, the device layer of the AI quota.
  final String deviceId;

  /// The phone's model: «iPhone 16 Pro», «Samsung SM-S928B».
  final String deviceName;

  /// Before [load] (and in the tests that set their own).
  static AppIdentity current = const AppIdentity(platform: 'android', version: '0.0.0', build: '0', deviceId: 'device-unknown', deviceName: 'جهاز');

  static const String _deviceIdKey = 'zon.device_id';

  /// Reads the app's version and the phone's model, and this installation's id (made on the first start).
  static Future<AppIdentity> load(SecretStore secrets) async {
    var version = current.version;
    var build = current.build;
    try {
      final info = await PackageInfo.fromPlatform();
      version = info.version;
      build = info.buildNumber;
    } catch (_) {
      // Unknown version: the server lets the request through (no X-App-Version comparison).
    }
    var deviceId = await secrets.read(_deviceIdKey);
    if (deviceId == null || deviceId.isEmpty) {
      deviceId = 'dev${newId(16)}';
      await secrets.write(_deviceIdKey, deviceId);
    }
    return current = AppIdentity(platform: platformName(), version: version, build: build, deviceId: deviceId, deviceName: await _deviceName());
  }

  static Future<String> _deviceName() async {
    if (kIsWeb) return 'متصفح';
    try {
      final plugin = DeviceInfoPlugin();
      if (defaultTargetPlatform == TargetPlatform.iOS) {
        final ios = await plugin.iosInfo;
        return ios.modelName.isNotEmpty ? ios.modelName : ios.model;
      }
      final android = await plugin.androidInfo;
      final brand = android.brand.isEmpty ? '' : '${android.brand[0].toUpperCase()}${android.brand.substring(1)}';
      return '$brand ${android.model}'.trim();
    } catch (_) {
      return defaultTargetPlatform == TargetPlatform.iOS ? 'iPhone' : 'Android';
    }
  }
}

/// `ios` · `android` · `web`.
String platformName() {
  if (kIsWeb) return 'web';
  return defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';
}

final Random _random = Random.secure();

/// [bytes] random bytes in hex: request ids and the installation id (the server keeps ids of 8–64 safe characters).
String newId(int bytes) => List<String>.generate(bytes, (_) => _random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
