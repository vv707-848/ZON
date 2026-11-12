// The account on the server (lib/data/auth/server_auth.dart): sign-in from the app's own screen, the token in
// the keychain (never in shared_preferences), sign-out that takes this phone's push token with it, «أجهزتي» with
// its sessions, and the notification switches — the marketing consent off until the person turns it on.

import 'dart:async';
import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_client.dart';
import 'package:zon/core/net/secret_store.dart';
import 'package:zon/data/auth/auth.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/push/push.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/devices_view.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

http.Response _reply(int status, Object? body) =>
    http.Response.bytes(utf8.encode(jsonEncode(body)), status, headers: <String, String>{'content-type': 'application/json'});

/// A server whose answers to [slow] (`METHOD path`) wait until the test gives them: a slow network.
Map<String, Completer<http.Response>> _slowServer(List<String> slow) {
  final held = <String, Completer<http.Response>>{for (final key in slow) key: Completer<http.Response>()};
  Api.client = ApiClient(
    base: Uri.parse('https://api.test/api/v1'),
    identity: () => testIdentity,
    token: () => ServerSession.token,
    onUnauthenticated: () => unawaited(ServerSession.expire()),
    client: MockClient((request) {
      final key = '${request.method} ${request.url.path.replaceFirst('/api/v1/', '')}';
      return held[key]?.future ?? Future<http.Response>.value(_reply(200, ok()));
    }),
  );
  return held;
}

Map<String, Object?> _session(
  int id,
  String device, {
  bool current = false,
  String? platform = 'ios',
  String? lastUsed = '2026-11-05T09:30:00+03:00',
}) => <String, Object?>{
  'id': id,
  'device': device,
  'platform': platform,
  'app_version': '1.2.1',
  'signed_in_at': '2026-10-01T12:00:00+03:00',
  'last_used_at': lastUsed,
  'current': current,
};

void main() {
  testWidgets('signing in from the app\'s screen names this phone to the server and opens the account', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('POST', 'auth/login', 401, apiFail('INVALID_CREDENTIALS', 'اسم المستخدم أو كلمة السر غير صحيحة.'));

    await pumpApp(tester, '/login');
    await tapText(tester, 'تسجيل الدخول');
    await tester.enterText(find.byType(EditableText).at(0), 'faisal');
    await tester.enterText(find.byType(EditableText).at(1), 'wrong-password');
    await tapText(tester, 'دخول');
    expect(find.text('اسم المستخدم أو كلمة السر غير صحيحة.'), findsOneWidget);
    expect(store.readSession(), isNull);

    server.on('POST', 'auth/login', 200, ok(<String, Object?>{'token': '7|server-token', 'me': me()}));
    await tester.enterText(find.byType(EditableText).at(1), 'a-good-secret');
    await tapText(tester, 'دخول');

    final sent = FakeServer.body(server.sent('POST', 'auth/login').last);
    expect(sent['login'], 'faisal');
    expect(sent['device_name'], 'iPhone 16 Pro');
    expect(sent['platform'], 'ios');
    expect(sent['app_version'], '1.2.1');
    expect(store.readSession()!.id, 'srv-41');
    expect(store.readSession()!.name, 'فيصل العتيبي');
  });

  test('the token lives in the keychain, never in the preferences; signing out takes the push token with it', () async {
    await freshDevice();
    final server = serverDevice();
    final keychain = ServerSession.secrets as MemorySecretStore;
    await serverSignIn(server, token: '9|secret-token');

    expect(keychain.values['zon.api.token'], '9|secret-token');
    final prefs = await SharedPreferences.getInstance();
    for (final key in prefs.getKeys()) {
      expect('${prefs.get(key)}', isNot(contains('secret-token')), reason: key);
    }

    server.on('POST', 'me/devices', 200, ok());
    server.on('POST', 'auth/logout', 200, ok());
    final push = FakePush();
    Push.provider = push;
    await Push.start();
    await Future<void>.delayed(const Duration(milliseconds: 1)); // FCM gives the id on its stream
    expect(Push.token, 'fid-0001');
    await Auth.signOut();

    expect(FakeServer.body(server.sent('POST', 'auth/logout').single), <String, Object?>{'push_token': 'fid-0001'});
    expect(server.sent('POST', 'auth/logout').single.headers['Authorization'], 'Bearer 9|secret-token');
    expect(keychain.values, isNot(contains('zon.api.token')));
    expect(store.readSession(), isNull);
    expect(Push.token, isNull);
  });

  test('signing out offline still signs this phone out', () async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    server.offline('POST', 'auth/logout');

    await Auth.signOut();

    expect(ServerSession.token, isNull);
    expect(store.readSession(), isNull);
  });

  test('at the start, a saved token and its account come back; a session of the prototype does not', () async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    final keychain = ServerSession.secrets;

    await restartApp();
    ServerSession.reset();
    ServerSession.secrets = keychain;
    await ServerSession.restore();
    expect(ServerSession.token, '7|server-token');
    expect(store.readSession()!.id, 'srv-41');

    await freshDevice();
    serverDevice();
    signUp('local.only');
    await ServerSession.restore();
    expect(store.readSession(), isNull, reason: 'a local account is not a server session: sign in again');
  });

  test('a sign-up: the server sends the code, and the code creates the account on this phone', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('POST', 'auth/register', 202, ok(<String, Object?>{'phone': '0551234567', 'expires_in': 900}));
    server.on('POST', 'auth/otp/verify', 422, apiFail('OTP_INVALID', 'رمز التحقق غير صحيح أو انتهت صلاحيته.'));

    final sent = await ServerAuth.register(
      role: 'broker',
      name: 'فيصل العتيبي',
      phone: '0551234567',
      city: 'الرياض',
      username: 'faisal',
      password: 'a-good-secret',
      fal: '1100012345',
    );
    expect(sent, isNull);
    final register = FakeServer.body(server.sent('POST', 'auth/register').single);
    expect(register['fal_number'], '1100012345');
    expect(register['device_id'], 'dev-test-0001');

    final wrong = await ServerAuth.verify('0551234567', '111111');
    expect(wrong.error, 'رمز التحقق غير صحيح أو انتهت صلاحيته.');
    expect(store.readSession(), isNull);

    server.on('POST', 'auth/otp/verify', 201, ok(<String, Object?>{'token': '8|new-token', 'me': me(id: 52)}));
    final right = await ServerAuth.verify('0551234567', '204815');
    expect(right.ok, isTrue);
    expect(store.readSession()!.id, 'srv-52');
    expect(ServerSession.token, '8|new-token');
  });

  testWidgets('«أجهزتي» lists the account\'s phones and ends a lost one\'s session', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    server.on(
      'GET',
      'me/sessions',
      200,
      ok(<Object?>[_session(7, 'iPhone 16 Pro', current: true), _session(12, 'Samsung SM-S928B', platform: 'android', lastUsed: null)]),
    );
    server.on('DELETE', 'me/sessions/12', 200, ok());

    await pumpApp(tester, '/account/devices');
    await tester.pump();

    expect(find.byType(DeviceCard), findsNWidgets(2));
    expect(find.text('هذا الجهاز'), findsOneWidget);
    expect(find.text('Samsung SM-S928B'), findsOneWidget);
    expect(find.text('Android · زون 1.2.1'), findsOneWidget);

    server.on('GET', 'me/sessions', 200, ok(<Object?>[_session(7, 'iPhone 16 Pro', current: true)]));
    await tapText(tester, 'إنهاء الجلسة');
    expect(find.text('إنهاء جلسة Samsung SM-S928B؟'), findsOneWidget);
    await tapText(tester, 'إنهاء الجلسة', last: true);
    for (var i = 0; i < 6; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }

    expect(server.sent('DELETE', 'me/sessions/12'), hasLength(1));
    expect(find.text('انتهت جلسة Samsung SM-S928B.'), findsOneWidget);
    expect(find.byType(DeviceCard), findsOneWidget);
    expect(store.readSession(), isNotNull, reason: 'this phone stays signed in');
  });

  testWidgets('without a server, «أجهزتي» knows only this phone and ends nothing', (tester) async {
    await freshDevice();
    signUp('local.devices');

    await pumpApp(tester, '/account/devices');
    await tester.pump();

    expect(find.byType(DeviceCard), findsOneWidget);
    expect(find.text('هذا الجهاز'), findsOneWidget);
    expect(find.text('إنهاء الجلسة'), findsNothing);
    expect(find.textContaining('حين يعمل التطبيق مع خادم زون'), findsOneWidget);
  });

  testWidgets('the marketing consent is off until turned on, and a switch the server refused comes back', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server);
    server.on(
      'PUT',
      'me/notification-preferences',
      200,
      ok(<String, Object?>{
        'messages': true,
        'followed_posts': true,
        'area_requests': true,
        'listing_status': true,
        'ratings': true,
        'matching': true,
        'marketing': true,
      }),
    );

    await pumpApp(tester, '/account/settings');
    expect(ServerSession.prefs['marketing'], isFalse);
    await tapText(tester, 'العروض والتسويق');
    expect(FakeServer.body(server.sent('PUT', 'me/notification-preferences').single), <String, Object?>{'marketing': true});
    expect(ServerSession.prefs['marketing'], isTrue);

    server.offline('PUT', 'me/notification-preferences');
    await tapText(tester, 'تقييمات');
    expect(find.textContaining('الإنترنت'), findsOneWidget);
    expect(ServerSession.prefs['ratings'], isTrue, reason: 'not saved, so still on');
  });

  test('a reply that arrives after signing out brings no account back', () async {
    await freshDevice();
    serverDevice();
    final held = _slowServer(<String>['GET me']);
    await ServerSession.open(<String, Object?>{'token': '7|old', 'me': me()});

    final refresh = ServerSession.refresh(); // the app's start reads the account again
    await ServerSession.close(); // and the person signs out meanwhile
    held['GET me']!.complete(_reply(200, ok(me())));
    await refresh;

    expect(store.readSession(), isNull);
    expect(ServerSession.token, isNull);
  });

  test('late replies of an ended session change nothing of the next one: no account, no switch, no sign-out', () async {
    await freshDevice();
    serverDevice();
    final held = _slowServer(<String>['GET me', 'PUT me/notification-preferences']);
    await ServerSession.open(<String, Object?>{'token': '7|old', 'me': me()});
    final refresh = ServerSession.refresh();
    final pref = ServerAuth.setPref('marketing', true);
    await ServerSession.close();
    await ServerSession.open(<String, Object?>{'token': '8|new', 'me': me(id: 52, name: 'سارة القحطاني')});

    held['GET me']!.complete(_reply(200, ok(me())));
    held['PUT me/notification-preferences']!.complete(_reply(401, apiFail('UNAUTHENTICATED', 'انتهت الجلسة.')));
    await refresh;
    await pref;

    expect(ServerSession.token, '8|new', reason: "the old session's 401 does not sign the new one out");
    expect(store.readSession()!.id, 'srv-52');
    expect(store.readSession()!.name, 'سارة القحطاني');
    expect(ServerSession.prefs['marketing'], isFalse);
  });
}
