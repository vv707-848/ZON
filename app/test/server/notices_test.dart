// The bell with a server (lib/data/server/server_notices.dart; notification_center.dart, notifications_screen.dart,
// home_screen.dart): the count the server gives, its notices on the page, a tap read there and opened where it
// leads, older ones page by page, nothing of another account — and every place a notice of the server leads
// (docs/contracts/fixtures/api/notice_targets.json, recorded by NoticeTargetsTest) opened on its screen.

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/features/account/account_screen.dart';
import 'package:zon/features/account/service_screen.dart';
import 'package:zon/features/add/add_screen.dart';
import 'package:zon/features/add/listing_edit_screen.dart';
import 'package:zon/features/add/listing_review_screen.dart';
import 'package:zon/features/add/project_review_screen.dart';
import 'package:zon/features/chats/server_chat_thread.dart';
import 'package:zon/features/details/listing_screen.dart';
import 'package:zon/features/details/project_screen.dart';
import 'package:zon/features/notifications/notifications_screen.dart';
import 'package:zon/features/profile/server_user_profile.dart';
import 'package:zon/features/requests/request_detail_screen.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Future<void> _settle(WidgetTester tester, [int times = 8]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Map<String, Object?> _notice(String id, String title, String target, {bool read = false, String kind = 'listing'}) => <String, Object?>{
  'id': id,
  'kind': kind,
  'title': title,
  'body': 'شقة في النرجس',
  'target': target,
  'key': null,
  'read': read,
  'at': DateTime.now().toUtc().subtract(const Duration(minutes: 5)).toIso8601String(),
};

Map<String, Object?> _page(List<Map<String, Object?>> rows, {required int unread, int current = 1, int last = 1}) => <String, Object?>{
  'success': true,
  'data': rows,
  'meta': <String, Object?>{
    'unread': unread,
    'page': <String, Object?>{'current': current, 'last': last},
  },
};

void main() {
  testWidgets('the bell counts what the server says is unread; the page shows its notices; a tap is read there and opens its place', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on(
      'GET',
      'notifications',
      200,
      _page(<Map<String, Object?>>[
        _notice('a1', 'نُشر عرضك', '/listings/16'),
        _notice('a2', 'تم تفعيل الباقة', '/account/plans', read: true, kind: 'plan'),
      ], unread: 3),
    );
    server.on('POST', 'notifications/read', 200, <String, Object?>{
      'success': true,
      'data': <String, Object?>{'unread': 2},
    });
    server.on('GET', 'listings/16', 200, recorded('listing'));
    await serverSignIn(
      server,
      account: me(id: 41, role: 'broker', name: 'مكتب الريادة'),
    );
    await pumpApp(tester, '/');
    await _settle(tester);
    expect(
      find.descendant(of: find.byKey(const ValueKey<String>('home-bell')), matching: find.text('3')),
      findsOneWidget,
      reason: "the server's count, not the rows on the phone",
    );

    await tester.tap(find.byKey(const ValueKey<String>('home-bell')));
    await _settle(tester);
    expect(_path(), '/notifications');
    expect(find.text('نُشر عرضك'), findsOneWidget);
    expect(find.text('تم تفعيل الباقة'), findsOneWidget);

    await tapText(tester, 'نُشر عرضك');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', 'notifications/read').single), <String, Object?>{
      'ids': <Object?>['a1'],
    });
    expect(_path(), '/listings/16');
    expect(notifications.unreadCount(), 2);
  });

  testWidgets('older notices page by page; a read one is not sent again', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.onQuery(
      'GET',
      'notifications',
      <String, String>{'page': '1'},
      200,
      _page(<Map<String, Object?>>[_notice('b1', 'جديد', '/account/plans', read: true, kind: 'plan')], unread: 0, last: 2),
    );
    server.onQuery(
      'GET',
      'notifications',
      <String, String>{'page': '2'},
      200,
      _page(<Map<String, Object?>>[_notice('b0', 'أقدم', '/account/plans', read: true, kind: 'plan')], unread: 0, current: 2, last: 2),
    );
    await serverSignIn(
      server,
      account: me(id: 41, role: 'broker', name: 'مكتب الريادة'),
    );
    await pumpApp(tester, '/notifications');
    await _settle(tester);
    expect(find.text('أقدم'), findsNothing);
    await tapText(tester, 'إشعارات أقدم');
    await _settle(tester);
    expect(find.text('أقدم'), findsOneWidget);
    expect(find.text('إشعارات أقدم'), findsNothing);
    await tapText(tester, 'جديد');
    await _settle(tester);
    expect(server.sent('POST', 'notifications/read'), isEmpty, reason: 'already read on the server');
  });

  testWidgets('another account signed in: nothing of the previous one is shown', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.onFor(
      '7|first',
      'GET',
      'notifications',
      200,
      _page(<Map<String, Object?>>[_notice('c1', 'لحساب الأول', '/account/plans', kind: 'plan')], unread: 1),
    );
    server.onFor('7|second', 'GET', 'notifications', 200, _page(<Map<String, Object?>>[], unread: 0));
    server.on('POST', 'auth/logout', 200, <String, Object?>{'success': true, 'data': null});
    await serverSignIn(
      server,
      account: me(id: 41, role: 'broker', name: 'مكتب الريادة'),
      token: '7|first',
    );
    await pumpApp(tester, '/notifications');
    await _settle(tester);
    expect(find.text('لحساب الأول'), findsOneWidget);
    expect(notifications.unreadCount(), 1);

    await serverSignIn(
      server,
      account: me(id: 42, role: 'broker', name: 'مكتب آخر'),
      token: '7|second',
    );
    expect(notifications.list(), isEmpty, reason: 'the rows were the first account’s');
    expect(notifications.unreadCount(), 0);
    await notifications.refresh(force: true);
    expect(notifications.list(), isEmpty);
  });

  testWidgets('the server down: said on the page, not an empty bell', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.offline('GET', 'notifications');
    await serverSignIn(
      server,
      account: me(id: 41, role: 'broker', name: 'مكتب الريادة'),
    );
    await pumpApp(tester, '/notifications');
    await _settle(tester);
    expect(find.text('لا إشعارات بعد'), findsNothing);
    expect(notifications.serverError, isNotNull);
  });

  group('every place a notice of the server leads opens its screen', () {
    final targets = (recorded('notice_targets')['data']! as Map).cast<String, String>();
    final screens = <String, Type>{
      'wording_ready': ListingReviewScreen,
      'wording_reminder': ListingReviewScreen,
      'listing_published': ListingScreen,
      'listing_inactive': ListingEditScreen,
      'listing_hidden': ListingEditScreen,
      'licence_expiring': ListingEditScreen,
      'licence_expired': ListingEditScreen,
      'unit_licence_expiring': ListingEditScreen,
      'unit_licence_expired': ListingEditScreen,
      'boost_ended': ListingScreen,
      'project_wording_ready': ProjectReviewScreen,
      'project_published': ProjectScreen,
      'followed_published': ListingScreen,
      'rated': ServerUserProfile,
      'new_message': ServerChatThread,
      'area_request': RequestDetailScreen,
      'matches': ServiceScreen,
      'plan_activated': ServiceScreen,
      'renewal_reminder': ServiceScreen,
      'task_reminder': ServiceScreen,
      'owner_case': AddScreen,
      'account_activated': AccountScreen,
      'announcement': NotificationsScreen,
    };
    // «الدعم» is not in the app yet (docs/handover.md ← «ترتيب الشرائح»): its notices have no screen to open
    const notYet = <String>{'support_message', 'support_closed'};

    test('every recorded notice is known here', () {
      expect(<String>{...screens.keys, ...notYet}, targets.keys.toSet());
    });

    for (final MapEntry(key: name, value: target) in targets.entries) {
      testWidgets('$name → $target', (tester) async {
        await freshDevice();
        serverDevice();
        final screen = screenFor(target);
        expect(screen.runtimeType, notYet.contains(name) ? NotFoundScreen : screens[name], reason: target);
      });
    }
  });
}
