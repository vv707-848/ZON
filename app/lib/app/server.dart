// Before runApp: this phone's identity, and — when the build names a server (--dart-define=ZON_API_URL=…) — the
// client and its gate, the saved session and push notifications. Without ZON_API_URL the app is the approved local
// prototype and only the identity is read (its «أجهزتي» shows this phone).

import 'dart:async';

import 'package:flutter/widgets.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_client.dart';
import 'package:zon/core/net/app_gate.dart';
import 'package:zon/core/net/app_identity.dart';
import 'package:zon/core/net/secret_store.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/push/firebase_push.dart';
import 'package:zon/data/push/push.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/push_banner.dart';

/// The version a newer-version offer was shown for (once per version).
const String _offeredKey = 'zon.update.offered';

Future<void> startApp() async {
  final secrets = await DeviceSecretStore.open();
  await AppIdentity.load(secrets);
  if (kApiUrl.isEmpty) return;

  final api = ApiClient(
    base: Uri.parse(kApiUrl),
    identity: () => AppIdentity.current,
    token: () => ServerSession.token,
    onUnauthenticated: () => unawaited(ServerSession.expire()),
  );
  Api.client = api;
  ServerSession.secrets = secrets;
  ServerSession.onEnded = _sessionEnded;
  await ServerSession.restore();

  Push.provider = await FirebasePush.start();
  Push.onOpen = openPushTarget;
  Push.onReceived = (message) => showPushBanner(message, open: openPushTarget);
  unawaited(Push.listen());

  AppGate.newer.addListener(_offerNewer);
  unawaited(AppGate.check(api));
  if (ServerSession.token != null) {
    unawaited(ServerSession.refresh());
    unawaited(Push.start());
  }
  WidgetsBinding.instance.addObserver(_Resume(api));
}

/// A tapped notification opens its page; an empty or foreign target opens the bell.
void openPushTarget(PushMessage message) {
  final nav = Nav.key.currentState;
  if (nav == null) return;
  final t = message.target;
  unawaited(nav.pushNamed(t.startsWith('/') && !t.startsWith('//') ? t : '/notifications'));
}

/// The server ended this phone's session: back to the sign-in page.
void _sessionEnded() {
  final nav = Nav.key.currentState;
  if (nav == null) return;
  unawaited(nav.pushNamedAndRemoveUntil('/login', (_) => false, arguments: const NavArgs(animate: false)));
}

/// The store has a newer version: offered once per version, never while the gate is closed.
void _offerNewer() {
  final newer = AppGate.newer.value;
  if (newer == null || AppGate.state.value is! GateOpen || store.getItem(_offeredKey) == newer.version) return;
  final context = Nav.key.currentState?.overlay?.context;
  if (context == null) {
    WidgetsBinding.instance.addPostFrameCallback((_) => _offerNewer());
    return;
  }
  store.setItem(_offeredKey, newer.version);
  final url = newer.storeUrl;
  unawaited(
    showAppDialog(
      context,
      icon: Lu.smartphone,
      title: 'نسخة أحدث من زون',
      message: 'النسخة ${newer.version} في المتجر الآن.',
      confirmLabel: url == null ? 'حسناً' : 'تحديث',
      cancelLabel: 'لاحقاً',
    ).then((update) {
      if (update && url != null) unawaited(openExternal(url));
    }),
  );
}

/// The gate is read again whenever the app comes back to the screen (a version may have been stopped meanwhile).
class _Resume with WidgetsBindingObserver {
  _Resume(this.api);

  final ApiClient api;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(AppGate.check(api));
  }
}
