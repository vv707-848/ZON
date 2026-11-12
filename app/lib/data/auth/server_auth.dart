// Accounts on the server (docs/api.md «التسجيل والدخول» and «حسابي»): sign-in, sign-up with the SMS code,
// sign-out, the phones of the account («أجهزتي») and its notification switches. Used instead of LocalAuth when
// the app is built with a server (ZON_API_URL; lib/data/auth/auth.dart chooses).
//
// The session token lives in the phone's keychain / keystore (SecretStore), never in shared_preferences. The
// account the screens read is the same `Session` as before, with the id `srv-<id>`.

import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import 'package:zon/core/monitoring/monitoring.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_client.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/app_identity.dart';
import 'package:zon/core/net/secret_store.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/push/push.dart';
import 'package:zon/data/store.dart';

/// This phone's session on the server.
abstract final class ServerSession {
  /// The prefix of a server account's id (a session of the local prototype starts with `acc-`).
  static const String idPrefix = 'srv-';

  static const String _tokenKey = 'zon.api.token';
  static const String _prefsKey = 'zon.notice_prefs';

  /// The keychain (set by the app; the tests keep the memory one).
  static SecretStore secrets = MemorySecretStore();

  /// What the app does when the server ended this session (lib/app/server.dart: back to the sign-in page).
  static void Function()? onEnded;

  /// The account's notification switches as the server keeps them (key → on).
  static Map<String, bool> prefs = <String, bool>{};

  static String? _token;

  /// The session token; null when signed out.
  static String? get token => _token;

  /// At the start: the saved token and its account. A session of the local prototype, or a token without its
  /// account, is closed: the person signs in to the server.
  static Future<void> restore() async {
    _token = await secrets.read(_tokenKey);
    final session = store.readSession();
    if (_token == null || session == null || !session.id.startsWith(idPrefix)) {
      _token = null;
      await secrets.write(_tokenKey, null);
      if (session != null) store.clearSession();
      Monitoring.account(null);
      return;
    }
    prefs = _savedPrefs();
    Monitoring.account(_tag(session.id));
  }

  /// A new session (signed in, or a verified sign-up): the token in the keychain, the account for the screens,
  /// then this phone's push registration.
  static Future<Session> open(Map<String, Object?> data) async {
    final token = data['token'];
    final me = data['me'];
    if (token is! String || token.isEmpty || me is! Map) {
      throw const ApiError(status: 200, code: 'UNEXPECTED', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');
    }
    _token = token;
    await secrets.write(_tokenKey, token);
    final session = account(me.cast<String, Object?>());
    unawaited(Push.start());
    return session;
  }

  /// The account as the server describes it (`me`): the screens' Session and the notification switches.
  static Session account(Map<String, Object?> me) {
    final session = Session(
      id: '$idPrefix${me['id']}',
      name: _text(me['name']),
      username: _text(me['username']),
      phone: _text(me['phone']),
      role: me['role'] is String ? me['role']! as String : 'seeker',
      city: _text(me['city']),
    );
    store.writeSession(session);
    final switches = me['notification_prefs'];
    if (switches is Map) savePrefs(switches.cast<String, Object?>());
    Monitoring.account(_tag(session.id));
    return session;
  }

  /// The switches the server answered with (`PUT /me/notification-preferences`, or `me`).
  static void savePrefs(Map<String, Object?> switches) {
    prefs = <String, bool>{for (final e in switches.entries) e.key: e.value == true};
    store.setItem(_prefsKey, jsonEncode(prefs));
  }

  /// Whether a reply to a request sent with [sent] still belongs to this phone's session: one that arrives after
  /// a sign-out, or after another sign-in, writes no account and no switch.
  static bool owns(String? sent) => sent != null && sent == _token;

  /// Reads the account again (GET /me): its name or city may have been changed from the admin panel. An ended
  /// session answers 401, and the app goes back to the sign-in page (ApiClient → [expire]).
  static Future<void> refresh() async {
    final api = Api.client;
    final sent = _token;
    if (api == null || sent == null) return;
    try {
      final me = (await api.get('me')).map;
      if (owns(sent)) account(me);
    } on ApiError {
      // Offline, or ended (handled by ApiClient).
    }
  }

  /// This phone signs out: the token, the account and the switches leave the phone.
  static Future<void> close() async {
    _token = null;
    prefs = <String, bool>{};
    await secrets.write(_tokenKey, null);
    store.removeItem(_prefsKey);
    store.clearSession();
    Push.stop();
    Monitoring.account(null);
  }

  /// The server no longer knows this session (ended from another phone or the panel, expired, password changed).
  static Future<void> expire() async {
    if (_token == null) return;
    await close();
    onEnded?.call();
  }

  static Map<String, bool> _savedPrefs() {
    try {
      final json = jsonDecode(store.getItem(_prefsKey) ?? '{}');
      return json is Map ? <String, bool>{for (final e in json.entries) '${e.key}': e.value == true} : <String, bool>{};
    } on FormatException {
      return <String, bool>{};
    }
  }

  static String _tag(String sessionId) => 'customer:${sessionId.substring(idPrefix.length)}';

  static String _text(Object? value) => value is String ? value : '';

  /// For the tests.
  @visibleForTesting
  static void reset() {
    _token = null;
    prefs = <String, bool>{};
    onEnded = null;
    secrets = MemorySecretStore();
  }
}

/// One phone signed in to the account, as «أجهزتي» lists it.
@immutable
class DeviceSession {
  const DeviceSession({
    required this.id,
    required this.device,
    required this.current,
    this.platform,
    this.appVersion,
    this.signedInAt,
    this.lastUsedAt,
  });

  factory DeviceSession.fromJson(Map<String, Object?> j) {
    final id = j['id'];
    final device = j['device'];
    return DeviceSession(
      id: id is int ? id : int.tryParse('$id') ?? 0,
      device: device is String && device.isNotEmpty ? device : 'جهاز',
      current: j['current'] == true,
      platform: j['platform'] is String ? j['platform']! as String : null,
      appVersion: j['app_version'] is String ? j['app_version']! as String : null,
      signedInAt: DateTime.tryParse('${j['signed_in_at'] ?? ''}')?.toLocal(),
      lastUsedAt: DateTime.tryParse('${j['last_used_at'] ?? ''}')?.toLocal(),
    );
  }

  /// This phone alone — what the prototype without a server knows.
  factory DeviceSession.thisPhone() {
    final id = AppIdentity.current;
    return DeviceSession(id: 0, device: id.deviceName, current: true, platform: id.platform, appVersion: id.version);
  }

  final int id;
  final String device;
  final bool current;

  /// `ios` · `android`, when the phone registered for notifications.
  final String? platform;
  final String? appVersion;
  final DateTime? signedInAt;
  final DateTime? lastUsedAt;
}

/// The server's account calls.
abstract final class ServerAuth {
  static ApiClient get _api => Api.client!;

  /// What a sign-in tells the server about this phone: the session's name in «أجهزتي» and its push registration.
  static Map<String, Object?> device() {
    final id = AppIdentity.current;
    final push = Push.token;
    return <String, Object?>{
      'device_name': id.deviceName,
      if (id.platform != 'web') 'platform': id.platform,
      'app_version': id.version,
      'push_token': ?push,
    };
  }

  /// Signs in with the username (or the phone) and the password.
  static Future<AuthResult> signIn(String login, String password) async {
    try {
      final r = await _api.post('auth/login', <String, Object?>{'login': login.trim(), 'password': password, ...device()});
      return AuthResult.ok(await ServerSession.open(r.map));
    } on ApiError catch (e) {
      return AuthResult.fail(e.forUser);
    }
  }

  /// Starts a sign-up: the server keeps the form 15 minutes and sends the SMS code. Null when sent, else the error.
  static Future<String?> register({
    required String role,
    required String name,
    required String phone,
    required String city,
    required String username,
    required String password,
    String? fal,
  }) async {
    try {
      await _api.post('auth/register', <String, Object?>{
        'role': role,
        'name': name.trim(),
        'phone': phone.trim(),
        'city': city,
        'username': username.trim(),
        'password': password,
        if (fal != null && fal.trim().isNotEmpty) 'fal_number': fal.trim(),
        'device_id': AppIdentity.current.deviceId,
      });
      return null;
    } on ApiError catch (e) {
      return e.forUser;
    }
  }

  /// The SMS code of a sign-up: the account is created and signed in on this phone.
  static Future<AuthResult> verify(String phone, String code) async {
    try {
      final r = await _api.post('auth/otp/verify', <String, Object?>{'phone': phone.trim(), 'code': code, ...device()});
      return AuthResult.ok(await ServerSession.open(r.map));
    } on ApiError catch (e) {
      return AuthResult.fail(e.forUser);
    }
  }

  /// Sends the code again (the server allows three every ten minutes). Null when sent, else the error.
  static Future<String?> resend(String phone) async {
    try {
      await _api.post('auth/otp/resend', <String, Object?>{'phone': phone.trim()});
      return null;
    } on ApiError catch (e) {
      return e.forUser;
    }
  }

  /// Signs this phone out; the server drops its token and its push registration. Signed out here even offline.
  static Future<void> signOut() async {
    if (ServerSession.token != null) {
      final push = Push.token;
      try {
        await _api.post('auth/logout', <String, Object?>{'push_token': ?push});
      } on ApiError {
        // Offline or already ended: the session ends on this phone anyway; the server's expires in 90 days.
      }
    }
    await ServerSession.close();
  }

  /// The name and the city (PATCH /me). Null when saved, else the error.
  static Future<String?> updateProfile({required String name, required String city}) async {
    final sent = ServerSession.token;
    try {
      final me = (await _api.patch('me', <String, Object?>{'name': name.trim(), 'city': city})).map;
      if (ServerSession.owns(sent)) ServerSession.account(me);
      return null;
    } on ApiError catch (e) {
      return e.forUser;
    }
  }

  /// One notification switch. Null when saved, else the error.
  static Future<String?> setPref(String key, bool on) async {
    final sent = ServerSession.token;
    try {
      final switches = (await _api.put('me/notification-preferences', <String, Object?>{key: on})).map;
      if (ServerSession.owns(sent)) ServerSession.savePrefs(switches);
      return null;
    } on ApiError catch (e) {
      return e.forUser;
    }
  }

  /// «أجهزتي»: one session per phone, the most recently used first, this phone marked.
  static Future<List<DeviceSession>> sessions() async => <DeviceSession>[
    for (final s in (await _api.get('me/sessions')).list) DeviceSession.fromJson(s),
  ];

  /// Ends a phone's session; this phone's own is a sign-out.
  static Future<void> endSession(DeviceSession session) async {
    if (session.current) {
      await signOut();
      return;
    }
    await _api.delete('me/sessions/${session.id}');
  }
}
