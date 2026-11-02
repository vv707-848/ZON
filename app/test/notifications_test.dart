// N01 — the bell opens the notifications page; each row opens its target;
// read state and the bell count follow.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/matching_view.dart';
import 'package:zon/features/account/services/plans_view.dart';
import 'package:zon/features/chats/chat_thread_screen.dart';
import 'package:zon/features/details/listing_screen.dart';
import 'package:zon/features/notifications/notifications_screen.dart';
import 'package:zon/features/requests/request_detail_screen.dart';

import 'support/test_env.dart';

Finder get _bell => find.byKey(const ValueKey<String>('home-bell'));

/// The number shown on the bell (0 when there is no badge).
int bellCount(WidgetTester tester) {
  final texts = find.descendant(of: _bell, matching: find.byType(RichText));
  for (final e in texts.evaluate()) {
    final t = (e.widget as RichText).text.toPlainText().trim();
    final n = int.tryParse(t);
    if (n != null) return n;
  }
  return 0;
}

void main() {
  testWidgets('bell → list → the matching row opens the matching assistant; read state and count update', (tester) async {
    await freshDevice();
    signUp('n01.seeker', role: 'seeker');
    store.saveWish(const Wish(city: 'الرياض', hoods: <String>['الملقا'], types: <String>[], budget: '', text: 'فيلا قرب المدارس'));
    expect(store.freshMatches(), isNotEmpty, reason: 'the sample data has offers in الملقا');

    await pumpApp(tester, '/');
    final before = bellCount(tester);
    expect(before, greaterThanOrEqualTo(1));

    await tester.tap(_bell);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.byType(NotificationsScreen), findsOneWidget);
    expect(find.text('الإشعارات'), findsOneWidget);
    expect(find.text('عروض مطابقة لرغبتك'), findsOneWidget);
    expect(find.byKey(const ValueKey<String>('unread-dot')), findsWidgets);

    await tapText(tester, 'عروض مطابقة لرغبتك');
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.byType(SeekerMatchingView), findsOneWidget, reason: 'the matching row opens the matching assistant');

    // back to the list: the row is still there, now read
    final nav = tester.state<NavigatorState>(find.byType(Navigator).first);
    nav.pop();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text('عروض مطابقة لرغبتك'), findsOneWidget);
    final row = notifications.list().firstWhere((n) => n.kind == NoteKind.match);
    expect(row.read, isTrue);
    expect(notifications.unreadCount(), before - 1);

    // and the bell on the home page shows the new count
    nav.pop();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(bellCount(tester), before - 1);
  });

  testWidgets('each type opens its own destination', (tester) async {
    await freshDevice();
    signUp('n01.dest', role: 'broker');
    final r = RequestItem.fromJson(<String, Object?>{
      'id': 'nr-1',
      'purpose': 'sale',
      'city': 'الرياض',
      'description': 'طلب للتجربة',
      'author': 'س',
      'role': 'seeker',
    });
    store.saveRequest(r);
    notifications.add(
      kind: NoteKind.message,
      title: 'رسالة جديدة من فهد العتيبي',
      body: 'العرض جاهز للمعاينة الخميس',
      target: '/chats/fahd',
      key: 't:msg',
      demo: true,
    );
    notifications.add(kind: NoteKind.listing, title: 'تحديث على عرض', body: 'فيلا', target: '/listings/l1', key: 't:listing');
    notifications.add(kind: NoteKind.request, title: 'طلب جديد في منطقتك', body: 'شراء', target: '/requests/nr-1', key: 't:request');
    notifications.add(kind: NoteKind.plan, title: 'تنبيه باقة', body: 'الرصيد', target: '/account/plans', key: 't:plan');

    final expected = <String, Type>{
      'رسالة جديدة من فهد العتيبي': ChatThreadScreen,
      'تحديث على عرض': ListingScreen,
      'طلب جديد في منطقتك': RequestDetailScreen,
      'تنبيه باقة': PlansView,
    };
    for (final e in expected.entries) {
      await pumpApp(tester, '/notifications');
      await tapText(tester, e.key);
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(e.value), findsOneWidget, reason: '${e.key} → ${e.value}');
    }
    expect(notifications.unreadCount(), 0);
  });

  testWidgets('a deleted target shows a short notice instead of a broken page', (tester) async {
    await freshDevice();
    signUp('n01.gone', role: 'broker');
    notifications.add(kind: NoteKind.listing, title: 'عرض محذوف', body: '—', target: '/listings/does-not-exist', key: 't:gone');
    await pumpApp(tester, '/notifications');
    await tapText(tester, 'عرض محذوف');
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('لم يعد هذا العنصر متاحًا'), findsOneWidget);
    expect(find.byType(ListingScreen), findsNothing);
  });

  testWidgets('empty state', (tester) async {
    await freshDevice();
    signUp('n01.empty', role: 'owner');
    await pumpApp(tester, '/notifications');
    expect(find.text('لا توجد إشعارات جديدة'), findsOneWidget);
  });

  test('stored rows keep id, type, target, time and read state; newest first', () async {
    await freshDevice();
    signUp('n01.model', role: 'broker');
    notifications.add(kind: NoteKind.plan, title: 'أول', body: '1', target: '/account/plans', key: 'a');
    await Future<void>.delayed(const Duration(milliseconds: 5));
    notifications.add(kind: NoteKind.plan, title: 'ثانٍ', body: '2', target: '/account/plans', key: 'b');
    final rows = notifications.list();
    expect(rows.first.title, 'ثانٍ');
    expect(rows.every((n) => n.id.isNotEmpty && n.kind.isNotEmpty && n.target.isNotEmpty && n.at.isNotEmpty), isTrue);
    notifications.markRead(rows.first.id);
    await restartApp();
    expect(notifications.list().firstWhere((n) => n.title == 'ثانٍ').read, isTrue);
    // one notification per event key
    notifications.add(kind: NoteKind.plan, title: 'مكرر', body: '', target: '/', key: 'a');
    expect(notifications.list().where((n) => n.key == 'a').length, 1);
  });

  test('a broker is told about new requests in his city from other accounts — and only his account', () async {
    await freshDevice();
    signUp('n01.broker', role: 'broker', city: 'الرياض');
    LocalAuth.signOut();
    signUp('n01.asker', role: 'seeker', city: 'الرياض');
    store.saveRequest(
      RequestItem.fromJson(<String, Object?>{
        'id': 'area-1',
        'purpose': 'sale',
        'city': 'الرياض',
        'neighborhoods': <String>['الملقا'],
        'types': <String>['villa'],
        'description': 'فيلا',
        'author': 'باحث',
        'role': 'seeker',
      }),
    );
    expect(notifications.list().where((n) => n.kind == NoteKind.request), isEmpty, reason: 'not for the author');
    LocalAuth.signOut();
    expect(LocalAuth.signIn('n01.broker', 'secret-123').ok, isTrue);
    final area = notifications.list().where((n) => n.kind == NoteKind.request).toList();
    expect(area.length, 1);
    expect(area.single.target, '/requests/area-1');
  });

  test('using the last of a quota adds a plan notification for the related service', () async {
    await freshDevice();
    signUp('n01.quota', role: 'broker');
    billing.startPeriod(Plans.brokerMonth, paymentRef: 'test');
    final l = publishTestListing('q-1');
    expect(billing.useQuota(BoostKind.featured, l.id), BoostOutcome.applied);
    final plan = notifications.list().where((n) => n.kind == NoteKind.plan).toList();
    expect(plan.length, 1);
    expect(plan.single.target, '/account/listings');
  });
}
