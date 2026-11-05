// Push notifications, the way Firebase recommends now: the tokens are kept by our server. The app sends this
// phone's token after signing in, on every start and whenever FCM replaces it (POST /me/devices); the server
// ties it to this phone's session and drops it when the session ends. No FCM device groups — they stop after
// 29 September 2027 (docs/integrations.md «الإشعارات — Firebase»).
//
// The phone's side is a [PushProvider]: Firebase in the app (firebase_push.dart), a fake in the tests.

import 'dart:async';

import 'package:flutter/foundation.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/app_identity.dart';

/// A notification: tapped from the system tray, or arrived while the app is open.
@immutable
class PushMessage {
  const PushMessage({required this.title, required this.body, required this.target});

  final String title;
  final String body;

  /// The app page it opens (`/listings/12`, `/account/matching`…); empty → the bell.
  final String target;
}

/// What the app needs from the phone's push service.
abstract interface class PushProvider {
  /// Asks the person (the system asks once and remembers); true when notifications may be shown.
  Future<bool> requestPermission();

  /// This phone's FCM token; null when there is none yet.
  Future<String?> token();

  /// A new token replaced the previous one.
  Stream<String> get tokenRefresh;

  /// Notifications tapped while the app was in the background.
  Stream<PushMessage> get opened;

  /// Notifications that arrive while the app is open (the system shows none then).
  Stream<PushMessage> get received;

  /// The notification whose tap started the app.
  Future<PushMessage?> initial();
}

/// The app's push notifications; every call is a no-op without a [provider] or a server.
abstract final class Push {
  /// Null in a build without Firebase (ZON_PUSH) and in the prototype.
  static PushProvider? provider;

  /// Opens a tapped notification's page (set by the app: lib/app/server.dart).
  static void Function(PushMessage message)? onOpen;

  /// Shows a notification that arrived while the app is open (set by the app).
  static void Function(PushMessage message)? onReceived;

  static String? _token;
  static bool _listening = false;

  /// The token registered for this phone's session.
  static String? get token => _token;

  /// At the start, signed in or not: taps and notifications in the foreground, and a replaced token.
  static Future<void> listen() async {
    final p = provider;
    if (p == null || _listening) return;
    _listening = true;
    p.tokenRefresh.listen((t) => unawaited(_register(t)));
    p.opened.listen((m) => onOpen?.call(m));
    p.received.listen((m) => onReceived?.call(m));
    final first = await p.initial();
    if (first != null) onOpen?.call(first);
  }

  /// After signing in or a restored session: the permission (asked once), then the token to the server.
  static Future<void> start() async {
    final p = provider;
    if (p == null || !Api.on) return;
    if (!await p.requestPermission()) return;
    final t = await p.token();
    if (t != null) await _register(t);
  }

  /// After signing out: the server dropped this phone's token with its session.
  static void stop() {
    _token = null;
  }

  static Future<void> _register(String token) async {
    final api = Api.client;
    final platform = AppIdentity.current.platform;
    if (api == null || api.token() == null || platform == 'web') return;
    try {
      await api.post('me/devices', <String, Object?>{'push_token': token, 'platform': platform, 'app_version': AppIdentity.current.version});
      _token = token;
    } on ApiError {
      // Offline: sent again at the next start.
    }
  }

  /// For the tests: forget the listeners and the token.
  @visibleForTesting
  static void reset() {
    provider = null;
    onOpen = null;
    onReceived = null;
    _token = null;
    _listening = false;
  }
}
