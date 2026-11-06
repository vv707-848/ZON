// The device's secrets — the session token and this installation's id — in the phone's keychain (iOS) or
// keystore (Android), never in shared_preferences.

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Where the app keeps its secrets: [DeviceSecretStore] on a phone, [MemorySecretStore] in the tests.
abstract interface class SecretStore {
  Future<String?> read(String key);

  /// Saves [value]; null deletes the key.
  Future<void> write(String key, String? value);
}

/// The phone's keychain / keystore (flutter_secure_storage).
class DeviceSecretStore implements SecretStore {
  DeviceSecretStore._();

  static const FlutterSecureStorage _storage = FlutterSecureStorage();

  /// shared_preferences mark of this installation.
  static const String _installedKey = 'zon.secrets.installed';

  /// The iOS keychain outlives an uninstall, so a reinstalled app would find the previous installation's
  /// session: the first start of an installation (no mark in shared_preferences yet) forgets every secret.
  static Future<DeviceSecretStore> open() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (prefs.getBool(_installedKey) != true) {
        await _storage.deleteAll();
        await prefs.setBool(_installedKey, true);
      }
    } catch (_) {
      // The keychain or the preferences cannot be read: the secrets of this run still work.
    }
    return DeviceSecretStore._();
  }

  @override
  Future<String?> read(String key) async {
    try {
      return await _storage.read(key: key);
    } catch (_) {
      return null; // an unreadable keystore counts as empty: the person signs in again
    }
  }

  @override
  Future<void> write(String key, String? value) async {
    try {
      await _storage.write(key: key, value: value);
    } catch (_) {
      // Not saved: the next start asks to sign in again.
    }
  }
}

/// Secrets kept in memory (the tests).
class MemorySecretStore implements SecretStore {
  final Map<String, String> values = <String, String>{};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String? value) async {
    if (value == null) {
      values.remove(key);
    } else {
      values[key] = value;
    }
  }
}
