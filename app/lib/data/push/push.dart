// Push notifications, the way Firebase recommends now (firebase.google.com/docs/cloud-messaging/manage-tokens):
// this installation registers with FCM and gets its Firebase Installation ID (FID) — registration tokens are
// deprecated — and our server keeps it. The app sends it after signing in, at every start and whenever it changes
// (POST /me/devices, the field `push_token`); the server ties it to this phone's session, drops it when the
// session ends, and sends with `message.fid`. No FCM device groups — they stop after 29 September 2027
// (docs/integrations.md «الإشعارات — Firebase»).
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

  /// Registers this installation with FCM; its id arrives on [registrations].
  Future<void> register();

  /// This installation's id (FID): at FCM's routine sync on every start, after [register], and when it changes.
  Stream<String> get registrations;

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
  static String? _latest;
  static bool _listening = false;

  /// The id registered with the server for this phone's session.
  static String? get token => _token;

  /// At the start, signed in or not — and always before registering, so no id is missed: the installation's id,
  /// taps, and notifications in the foreground.
  static Future<void> listen() async {
    final p = provider;
    if (p == null || _listening) return;
    _listening = true;
    p.registrations.listen(
      (id) {
        _latest = id;
        unawaited(_register(id));
      },
      onError: (Object _) {
        // FCM could not register now; the next start tries again.
      },
    );
    p.opened.listen((m) => onOpen?.call(m));
    p.received.listen((m) => onReceived?.call(m));
    final first = await p.initial();
    if (first != null) onOpen?.call(first);
  }

  /// After signing in or a restored session: the permission (asked once), then the registration — the id
  /// reaches the server through [listen]. An id FCM gave before the sign-in is sent now.
  static Future<void> start() async {
    final p = provider;
    if (p == null || !Api.on) return;
    await listen();
    if (!await p.requestPermission()) return;
    final known = _latest;
    if (known != null) await _register(known);
    await p.register();
  }

  /// After signing out: the server dropped this phone's id with its session.
  static void stop() {
    _token = null;
  }

  static Future<void> _register(String id) async {
    final api = Api.client;
    final platform = AppIdentity.current.platform;
    if (api == null || api.token() == null || platform == 'web' || id == _token) return;
    try {
      await api.post('me/devices', <String, Object?>{'push_token': id, 'platform': platform, 'app_version': AppIdentity.current.version});
      _token = id;
    } on ApiError {
      // Offline: sent again at the next start.
    }
  }

  /// For the tests: forget the listeners and the ids.
  @visibleForTesting
  static void reset() {
    provider = null;
    onOpen = null;
    onReceived = null;
    _token = null;
    _latest = null;
    _listening = false;
  }
}
