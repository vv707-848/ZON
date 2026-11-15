// The store's own sheet with a server (lib/data/server/store_sheet.dart, server_store.dart, store_purchase.dart):
// when the server does not simulate the store, the sheet is the confirmation. What the store gave goes to the
// server — the store, the product, its reference and what it was for — and the purchase is finished on the phone
// only once the server has it. Cancelled: nothing sent. Pending: applied when the store delivers it. A lost answer
// or a refusal leaves it with the store, and the store's next delivery sends it again. A delivery for another
// account is not sent. A phone where the store cannot sell sends nothing.
// The server's answers are the ones its tests recorded (docs/contracts/fixtures/api: store_products,
// store_check_plan, store_purchase_plan, store_purchase_boost, store_purchase_refused, packages, subscription*).

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/server/server_store.dart';
import 'package:zon/data/server/store_sheet.dart';
import 'package:zon/data/store.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

/// The store's sheet as a test drives it.
class FakeSheet implements StoreSheet {
  bool sells = true;
  SheetOutcome next = const SheetCancelled();
  final List<(String, String, bool)> opened = <(String, String, bool)>[];
  final List<String> finished = <String>[];
  final StreamController<StoreReceipt> _delivered = StreamController<StoreReceipt>.broadcast();

  void deliver(StoreReceipt r) => _delivered.add(r);

  @override
  Future<bool> available() async => sells;

  @override
  Future<SheetOutcome> buy(String productId, {required String accountToken, required bool subscription}) async {
    opened.add((productId, accountToken, subscription));
    return next;
  }

  @override
  Future<void> finish(StoreReceipt receipt) async => finished.add(receipt.reference);

  @override
  Stream<StoreReceipt> get delivered => _delivered.stream;
}

/// The products answer, with no store simulated on this server.
Map<String, Object?> _realStores() => ok(<String, Object?>{..._one('store_products'), 'simulated': <Object?>[]});

String get _token => '${_one('store_products')['account_token']}';

Future<void> _settle(WidgetTester tester, [int times = 8]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<(FakeServer, FakeSheet)> _plans(WidgetTester tester) async {
  await freshDevice();
  final server = serverDevice();
  final sheet = FakeSheet();
  ServerStore.sheet = sheet;
  addTearDown(ServerStore.reset);
  await serverSignIn(server, account: me(role: 'seeker'));
  server.on('GET', 'packages', 200, recorded('packages'));
  server.on('GET', 'subscription', 200, recorded('subscription_free'));
  server.on('GET', 'invoices', 200, ok(<Object?>[]));
  server.on('GET', 'store/products', 200, _realStores());
  server.on('POST', 'store/check', 200, recorded('store_check_plan'));
  await pumpApp(tester, '/account/plans');
  await _settle(tester);
  return (server, sheet);
}

const _plan = 'com.zon.app.seeker.month';
const _bought = StoreReceipt(productId: _plan, reference: 'play-token-0001');

void main() {
  group('the store\'s own sheet with a server', () {
    testWidgets('a plan: the sheet is the confirmation; the store\'s reference to the server; finished after it', (tester) async {
      final (server, sheet) = await _plans(tester);
      sheet.next = const SheetBought(_bought);
      server.on('POST', 'store/purchases', 200, recorded('store_purchase_plan'));
      server.on('GET', 'subscription', 200, recorded('subscription'));
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);

      expect(sheet.opened, <(String, String, bool)>[(_plan, _token, true)], reason: 'his store token goes with the purchase');
      expect(find.text('دفع تجريبي — لا يُخصم مبلغ فعلي'), findsNothing, reason: 'no trial dialog before the store\'s own');
      expect(FakeServer.body(server.sent('POST', 'store/purchases').single), <String, Object?>{
        'store': 'google',
        'product_id': _plan,
        'reference': 'play-token-0001',
      });
      expect(sheet.finished, <String>['play-token-0001'], reason: 'finished once the server has it');
      expect(find.text('الباقة مفعّلة'), findsOneWidget);
    });

    testWidgets('closed by him: nothing sent, nothing said', (tester) async {
      final (server, sheet) = await _plans(tester);
      sheet.next = const SheetCancelled();
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      expect(sheet.opened, hasLength(1));
      expect(server.sent('POST', 'store/purchases'), isEmpty);
      expect(find.byType(AppDialogCard), findsNothing);
    });

    testWidgets('the store refused it: said, nothing sent', (tester) async {
      final (server, sheet) = await _plans(tester);
      sheet.next = const SheetFailed('رفض المتجر الشراء.');
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      expect(find.text('لم يتم الشراء'), findsOneWidget);
      expect(find.text('رفض المتجر الشراء. لم يُخصم شيء ولم يتغير شيء.'), findsOneWidget);
      expect(server.sent('POST', 'store/purchases'), isEmpty);
    });

    testWidgets('pending: said; once the store delivers it, it goes to the server for what it was bought for', (tester) async {
      final (server, sheet) = await _plans(tester);
      ServerStore.listen();
      sheet.next = const SheetPending();
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      expect(find.text('بانتظار المتجر'), findsOneWidget);
      expect(server.sent('POST', 'store/purchases'), isEmpty);

      server.on('POST', 'store/purchases', 200, recorded('store_purchase_plan'));
      sheet.deliver(_bought);
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'store/purchases').single)['reference'], 'play-token-0001');
      expect(sheet.finished, <String>['play-token-0001']);
    });

    testWidgets('the server\'s answer lost: not finished; the store\'s next delivery sends the same purchase', (tester) async {
      final (server, sheet) = await _plans(tester);
      ServerStore.listen();
      sheet.next = const SheetBought(_bought);
      server.offline('POST', 'store/purchases');
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      expect(find.text('لم يصل تأكيد زون'), findsOneWidget);
      expect(find.text('دُفع في المتجر، ويُطبَّق الشراء حين يصل الخادم. لا تشترِه مرة ثانية.'), findsOneWidget);
      expect(sheet.finished, isEmpty, reason: 'it stays with the store until the server has it');

      server.on('POST', 'store/purchases', 200, recorded('store_purchase_plan'));
      sheet.deliver(_bought);
      await _settle(tester);
      final sent = server.sent('POST', 'store/purchases');
      expect(sent, hasLength(2));
      expect(FakeServer.body(sent.last), FakeServer.body(sent.first), reason: 'the same purchase');
      expect(sheet.finished, <String>['play-token-0001']);
    });

    testWidgets('the server refused it: its words; not finished', (tester) async {
      final (server, sheet) = await _plans(tester);
      sheet.next = const SheetBought(_bought);
      server.on('POST', 'store/purchases', 422, recorded('store_purchase_refused'));
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      expect(find.text('لم يؤكد المتجر هذا الشراء.'), findsOneWidget);
      expect(sheet.finished, isEmpty);
    });

    testWidgets('a delivery kept for another account is not sent', (tester) async {
      final (server, sheet) = await _plans(tester);
      ServerStore.listen();
      sheet.next = const SheetPending();
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      await tapText(tester, 'حسناً');
      await serverSignIn(
        server,
        account: me(id: 42, role: 'seeker', name: 'حساب آخر'),
        token: '7|second',
      );
      await _settle(tester);

      sheet.deliver(_bought);
      await _settle(tester);
      expect(server.sent('POST', 'store/purchases'), isEmpty);
      expect(sheet.finished, isEmpty, reason: 'it stays with the store for the account that bought it');
    });

    testWidgets('a boost: what it was for goes with it', (tester) async {
      final (server, sheet) = await _plans(tester);
      final target = <String, Object?>{'type': 'listing', 'id': 1};
      ServerStore.remember('com.zon.app.featured', account: store.readSession()!.id, target: target);
      server.on('POST', 'store/purchases', 200, recorded('store_purchase_boost'));
      final status = await ServerStore.deliver(
        const StoreReceipt(productId: 'com.zon.app.featured', reference: 'play-token-0002'),
        epoch: store.serverEpoch,
      );
      expect(status, 'paid');
      expect(FakeServer.body(server.sent('POST', 'store/purchases').single), <String, Object?>{
        'store': 'google',
        'product_id': 'com.zon.app.featured',
        'reference': 'play-token-0002',
        'target': target,
      });
      expect(sheet.finished, <String>['play-token-0002']);
    });

    testWidgets('a phone where the store cannot sell: not available, nothing sent', (tester) async {
      final (server, sheet) = await _plans(tester);
      sheet.sells = false;
      await tapText(tester, 'اشترك: الباحث — شهري');
      await _settle(tester);
      expect(sheet.opened, isEmpty);
      expect(find.text('الشراء من المتجر غير متاح في هذه النسخة بعد. لم يُخصم شيء ولم يتغير شيء.'), findsOneWidget);
      expect(server.sent('POST', 'store/purchases'), isEmpty);
    });
  });
}
