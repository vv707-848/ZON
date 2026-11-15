// «الباقات» with a server (lib/data/server/server_store.dart; plans_view.dart, store_purchase.dart, account_screen):
// the plans on sale for his role with the server's prices and what each includes; his plan now, its last day and
// what is left of its balances; his invoices. A plan is bought through the store — checked first, paid only when
// he taps pay, sent unchanged on every try — and the page reads his plan again once the server says it is paid.
// No service tile is locked on the phone: what a plan gives is the server's to say. Another account: nothing of
// the one before stays.
// The answers are the ones the server's tests recorded (docs/contracts/fixtures/api: packages, subscription,
// subscription_free, invoices, store_products, store_check_plan, store_purchase_plan, store_purchase_refused).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/data/auth/server_auth.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

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

Future<FakeServer> _open(WidgetTester tester, {String role = 'seeker', String plan = 'subscription_free'}) async {
  await freshDevice();
  final server = serverDevice();
  await serverSignIn(server, account: me(role: role));
  server.on('GET', 'packages', 200, recorded('packages'));
  server.on('GET', 'subscription', 200, recorded(plan));
  server.on('GET', 'invoices', 200, ok(<Object?>[]));
  await pumpApp(tester, '/account/plans');
  await _settle(tester);
  return server;
}

void main() {
  group('«الباقات» with a server', () {
    testWidgets('his plan now and the plans his role can buy, as the server sells them', (tester) async {
      await _open(tester);
      expect(find.text('الباحث — مجاني'), findsOneWidget, reason: 'his plan now');
      expect(find.text('الباحث — شهري'), findsOneWidget);
      expect(find.text('199'), findsOneWidget, reason: "the server's price");
      for (final f in <String>['مساعد المطابقة يومياً', 'حتى 5 رغبات', 'البحث الدلالي']) {
        expect(find.text(f), findsOneWidget);
      }
      expect(find.text('الوسيط — شهري'), findsNothing, reason: "another role's plan");
      expect(find.text('اشترك: الباحث — شهري'), findsOneWidget);
      expect(find.text('فواتيري'), findsNothing, reason: 'no invoice');
    });

    testWidgets('a broker sees both his plans; the one running is marked, with its last day and its balances', (tester) async {
      final running = <String, Object?>{
        ..._one('subscription'),
        'plan': <String, Object?>{'code': 'broker-month', 'name': 'الوسيط — شهري', 'subscribed': true},
        'balances': <String, Object?>{'featured': 1, 'urgent_listing': 0, 'urgent_request': 0},
      };
      final server = await _open(tester, role: 'broker');
      server.on('GET', 'subscription', 200, ok(running));
      server.on('GET', 'invoices', 200, recorded('invoices'));
      await pumpApp(tester, '/account');
      await pumpApp(tester, '/account/plans');
      await _settle(tester);

      final ends = DateTime.parse('${running['ends_at']}').toLocal();
      expect(find.textContaining('تنتهي في ${arDate(ends, year: true)}'), findsOneWidget);
      expect(find.textContaining('المتبقي من رصيدها — تمييز: 1'), findsOneWidget);
      expect(find.text('مفعّلة'), findsOneWidget);
      expect(find.text('الباقة مفعّلة'), findsOneWidget);
      expect(find.text('اشترك: الوسيط — سنوي'), findsOneWidget);
      expect(find.text('اشترك: الوسيط — شهري'), findsNothing);
      final invoice = ((recorded('invoices')['data']! as List).single as Map).cast<String, Object?>();
      expect(find.text('فواتيري'), findsOneWidget);
      expect(find.text('${invoice['number']}'), findsOneWidget);
      expect(find.textContaining('100 ريال'), findsOneWidget);
    });

    testWidgets('a plan bought through the store: checked, paid on his tap, sent once; then his plan is read again', (tester) async {
      final server = await _open(tester);
      server.on('GET', 'store/products', 200, recorded('store_products'));
      server.on('POST', 'store/check', 200, recorded('store_check_plan'));
      server.on('POST', 'store/purchases', 422, recorded('store_purchase_refused'));
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'store/check').single), <String, Object?>{'product_id': 'com.zon.app.seeker.month'});
      expect(find.text('الباحث — شهري — 199 ريالًا'), findsOneWidget);
      expect(find.text('دفع تجريبي — لا يُخصم مبلغ فعلي'), findsOneWidget);
      expect(server.sent('POST', 'store/purchases'), isEmpty);

      await tapText(tester, 'ادفع 199 ريالًا');
      await _settle(tester);
      expect(find.text('تعذّر إتمام الدفع — لم يُخصم شيء ولم يتغير شيء.'), findsOneWidget, reason: 'the store did not vouch for it');
      expect(ServerSession.subscribed, isFalse);

      server.on('POST', 'store/purchases', 200, recorded('store_purchase_plan'));
      server.on('GET', 'subscription', 200, recorded('subscription'));
      server.on('GET', 'me', 200, ok(<String, Object?>{...me(role: 'seeker'), 'plan': (_one('subscription')['plan']! as Map).cast<String, Object?>()}));
      await tapText(tester, 'أعد المحاولة');
      await _settle(tester);
      final sent = server.sent('POST', 'store/purchases');
      expect(sent, hasLength(2));
      expect(FakeServer.body(sent.last), FakeServer.body(sent.first), reason: 'one purchase, however often sent');
      expect(FakeServer.body(sent.last)['product_id'], 'com.zon.app.seeker.month');
      expect(FakeServer.body(sent.last).containsKey('target'), isFalse);

      expect(find.text('الباقة مفعّلة'), findsOneWidget, reason: 'his plan as the server has it after');
      expect(find.text('اشترك: الباحث — شهري'), findsNothing);
      expect(ServerSession.subscribed, isTrue, reason: '«حسابي» reads it from the account');
    });

    testWidgets('no answer: said, with a way to ask again; nothing of the phone\'s own plans', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'seeker'));
      server.offline('GET', 'packages');
      await pumpApp(tester, '/account/plans');
      await _settle(tester);
      expect(find.byType(FormError), findsOneWidget);
      expect(find.text('اشترك: الباحث — شهري'), findsNothing);

      server.on('GET', 'packages', 200, recorded('packages'));
      server.on('GET', 'subscription', 200, recorded('subscription_free'));
      server.on('GET', 'invoices', 200, ok(<Object?>[]));
      await tapText(tester, 'أعد المحاولة');
      await _settle(tester);
      expect(find.text('اشترك: الباحث — شهري'), findsOneWidget);
    });

    testWidgets('another account signed in: its own plan, nothing of the one before', (tester) async {
      final server = await _open(tester, plan: 'subscription');
      expect(find.text('الباقة مفعّلة'), findsOneWidget);
      server.onFor('7|second', 'GET', 'subscription', 200, recorded('subscription_free'));
      await serverSignIn(server, account: me(id: 42, role: 'seeker', name: 'حساب آخر'), token: '7|second');
      await _settle(tester);
      expect(find.text('الباقة مفعّلة'), findsNothing);
      expect(find.text('الباحث — مجاني'), findsOneWidget);
    });

    testWidgets('«حسابي»: no service locked on the phone; the plans offered only while none is running', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(role: 'seeker'));
      await pumpApp(tester, '/account');
      await _settle(tester);
      expect(find.text('شاهد الباقات'), findsOneWidget);
      await tapText(tester, 'البحث الدلالي');
      await _settle(tester);
      expect(_path(), '/account/search', reason: "the server says what his plan gives, on the page");

      await serverSignIn(server, account: <String, Object?>{...me(role: 'seeker'), 'plan': (_one('subscription')['plan']! as Map).cast<String, Object?>()});
      await pumpApp(tester, '/account');
      await _settle(tester);
      expect(find.text('شاهد الباقات'), findsNothing);
    });
  });
}
