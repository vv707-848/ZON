// Push notifications the way Firebase recommends now (lib/data/push/push.dart): this installation's id (FID —
// registration tokens are deprecated) goes to our server, tied to this phone's session — after signing in, at
// every start, whenever it changes — and nowhere else (no device groups). A tap opens the notification's page; in
// the foreground the app shows its own banner.

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/server.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/push/push.dart';
import 'package:zon/features/notifications/notifications_screen.dart';
import 'package:zon/shared/widgets/push_banner.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

void main() {
  test('after signing in: one permission prompt, then this installation\'s id to the server', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('POST', 'me/devices', 200, ok());
    final push = FakePush();
    Push.provider = push;

    await serverSignIn(server);
    await Future<void>.delayed(const Duration(milliseconds: 1));

    expect(push.asked, 1);
    final registered = server.sent('POST', 'me/devices').single;
    expect(FakeServer.body(registered), <String, Object?>{'push_token': 'fid-0001', 'platform': 'ios', 'app_version': '1.2.1'});
    expect(registered.headers['Authorization'], 'Bearer 7|server-token', reason: 'tied to this phone\'s session');
    expect(Push.token, 'fid-0001');
  });

  test('an id FCM changed is sent again, the same one only once; signed out, nothing is sent', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('POST', 'me/devices', 200, ok());
    server.on('POST', 'auth/logout', 200, ok());
    final push = FakePush();
    Push.provider = push;
    await Push.listen();
    await serverSignIn(server);
    await Future<void>.delayed(const Duration(milliseconds: 1));

    push.registered
      ..add('fid-0001') // FCM's routine sync: the same id
      ..add('fid-0002'); // a new one (the app's data was cleared)
    await Future<void>.delayed(const Duration(milliseconds: 1));
    expect(server.sent('POST', 'me/devices').map((r) => FakeServer.body(r)['push_token']), <Object?>['fid-0001', 'fid-0002']);
    expect(Push.token, 'fid-0002');

    await ServerAuth.signOut();
    push.registered.add('fid-0003');
    await Future<void>.delayed(const Duration(milliseconds: 1));
    expect(server.sent('POST', 'me/devices'), hasLength(2), reason: 'no session, no registration');
  });

  test('an id FCM gave before the sign-in reaches the server once signed in', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('POST', 'me/devices', 200, ok());
    final push = FakePush()..current = null;
    Push.provider = push;
    await Push.listen();
    push.registered.add('fid-early'); // FCM's sync at the start, signed out
    await Future<void>.delayed(const Duration(milliseconds: 1));
    expect(server.sent('POST', 'me/devices'), isEmpty);

    await serverSignIn(server);
    await Future<void>.delayed(const Duration(milliseconds: 1));

    expect(server.sent('POST', 'me/devices').map((r) => FakeServer.body(r)['push_token']), <Object?>['fid-early']);
  });

  test('notifications refused: nothing is registered, and the app does not ask again by itself', () async {
    await freshDevice();
    final server = serverDevice();
    final push = FakePush()..allowed = false;
    Push.provider = push;

    await serverSignIn(server);
    await Future<void>.delayed(const Duration(milliseconds: 1));

    expect(push.asked, 1);
    expect(server.sent('POST', 'me/devices'), isEmpty);
    expect(Push.token, isNull);
  });

  testWidgets('a tapped notification opens its page; one without a page opens the bell', (tester) async {
    await freshDevice();
    serverDevice();
    signUp('push.tap');
    final push = FakePush();
    Push.provider = push;
    Push.onOpen = openPushTarget;
    await pumpApp(tester, '/');
    await Push.listen();

    push.tapped.add(const PushMessage(title: 'عروض تطابق رغبتك', body: '3 عروض جديدة', target: 'https://example.com/'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    expect(find.byType(NotificationsScreen), findsOneWidget);
  });

  testWidgets('in the foreground: a banner for a few seconds, and a tap on it opens the page', (tester) async {
    await freshDevice();
    serverDevice();
    signUp('push.banner');
    final push = FakePush();
    Push.provider = push;
    Push.onOpen = openPushTarget;
    Push.onReceived = (m) => showPushBanner(m, open: openPushTarget);
    await pumpApp(tester, '/');
    await Push.listen();

    push.arrived.add(const PushMessage(title: 'رسالة جديدة', body: 'فهد: هل العرض متاح؟', target: '/notifications'));
    await tester.pump();
    expect(find.byType(PushBanner), findsOneWidget);
    expect(find.text('فهد: هل العرض متاح؟'), findsOneWidget);

    await tester.tap(find.text('رسالة جديدة'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.byType(PushBanner), findsNothing);
    expect(find.byType(NotificationsScreen), findsOneWidget);

    push.arrived.add(const PushMessage(title: 'تذكير', body: 'مهمة اليوم', target: '/notifications'));
    await tester.pump();
    expect(find.byType(PushBanner), findsOneWidget);
    await tester.pump(kPushBannerTime + const Duration(seconds: 1));
    expect(find.byType(PushBanner), findsNothing, reason: 'gone by itself');
  });
}
