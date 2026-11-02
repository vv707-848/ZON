// QA F02 / F10 — a payment belongs to the account that started it. Here the
// payment is held at the simulator's gate while ANOTHER account signs in;
// when the answer arrives, the result must land on the paying account only
// (subscription, boost, request, ledger, owner licence), nothing may move to
// the account signed in now, and the system back button cannot close the
// pay dialog while the payment runs. All payments are simulated.

import 'dart:async';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/store.dart';

import 'support/test_env.dart';

/// Signs [username] in — creating the account the first time.
void switchTo(String username, {String role = 'broker'}) {
  LocalAuth.signOut();
  if (store.accountByUsername(username) == null) {
    signUp(username, role: role);
  } else {
    expect(LocalAuth.signIn(username, 'secret-123').ok, isTrue);
  }
}

/// Holds every simulated charge until the returned completer completes.
Completer<void> holdPayments() {
  final gate = Completer<void>();
  PaymentSimulator.gate = gate.future;
  return gate;
}

RequestItem _draft(String id, {String author = 'سارة القحطاني'}) => RequestItem(
  id: id,
  purpose: 'sale',
  city: 'الرياض',
  neighborhoods: const <String>['الملقا'],
  types: const <String>['villa'],
  priceMin: 2000000,
  priceMax: 3000000,
  areaMin: 0,
  areaMax: 0,
  roomsMin: 0,
  roomsMax: 0,
  description: 'فيلا عاجلة',
  author: author,
  role: 'seeker',
);

/// The system back button (Android) / back gesture, as the engine sends it.
Future<void> systemBack(WidgetTester tester) async {
  final message = const JSONMethodCodec().encodeMethodCall(const MethodCall('popRoute'));
  await tester.binding.defaultBinaryMessenger.handlePlatformMessage('flutter/navigation', message, (_) {});
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  group('F02 — the result goes to the account that paid', () {
    test('subscription: A pays, B signs in meanwhile → A has the plan, B has nothing', () async {
      await freshDevice();
      final a = signUp('pay.a', role: 'broker');
      final gate = holdPayments();
      final paying = billing.buyPlan(Plans.brokerYear);
      expect(billing.payments().single.status, PaymentRecord.pending, reason: 'recorded under A before the gateway answers');

      switchTo('pay.b');
      gate.complete();
      expect((await paying).ok, isTrue);
      expect(billing.activeSubscription(), isNull, reason: 'B did not pay');
      expect(billing.payments(), isEmpty);
      expect(billing.activeSubscription(accountId: a.id)!.plan, Plans.brokerYear);

      switchTo('pay.a');
      expect(billing.subPlan(), Plans.brokerYear);
      expect(billing.payments().single.status, PaymentRecord.paid);
      expect(notifications.list().map((n) => n.title), contains('تم تفعيل اشتراكك'));
    });

    test('listing boost: the featuring and its ledger entry stay with A', () async {
      await freshDevice();
      final a = signUp('boost.a', role: 'broker');
      publishTestListing('sw-l1');
      final gate = holdPayments();
      final paying = billing.payAndApply(BoostKind.featured, 'sw-l1');
      switchTo('boost.b');
      gate.complete();
      expect(await paying, BoostOutcome.applied);
      expect(store.listingById('sw-l1')!.featuredAt(DateTime.now()), isTrue);
      expect(store.listingById('sw-l1')!.ownerId, a.id);
      expect(billing.ledger(), isEmpty, reason: 'nothing on B');
      switchTo('boost.a');
      expect(billing.ledger().single.amount, Pricing.featuredListingPrice);
    });

    test('new urgent request: published as A’s with A’s ledger entry', () async {
      await freshDevice();
      final a = signUp('req.a', role: 'seeker', name: 'سارة القحطاني');
      final gate = holdPayments();
      final paying = billing.payAndApply(BoostKind.urgentRequest, 'r-sw-1', draft: _draft('r-sw-1'));
      switchTo('req.b', role: 'seeker');
      gate.complete();
      expect(await paying, BoostOutcome.applied);
      final r = store.requestById('r-sw-1')!;
      expect(r.ownerId, a.id);
      expect(r.author, 'سارة القحطاني');
      expect(r.urgent, isTrue);
      expect(store.myRequests(), isEmpty, reason: 'not B’s');
      expect(billing.ledger(), isEmpty);
      switchTo('req.a', role: 'seeker');
      expect(store.myRequests().single.id, 'r-sw-1');
      expect(billing.ledger().single.amount, Pricing.urgentRequestPrice);
    });

    test('upgrading A’s request to urgent: A’s request, A’s ledger', () async {
      await freshDevice();
      final a = signUp('up.a', role: 'seeker');
      store.saveRequest(_draft('r-sw-2'));
      expect(store.requestById('r-sw-2')!.ownerId, a.id);
      final gate = holdPayments();
      final paying = billing.payAndApply(BoostKind.urgentRequest, 'r-sw-2');
      switchTo('up.b', role: 'seeker');
      gate.complete();
      expect(await paying, BoostOutcome.applied);
      expect(store.requestById('r-sw-2')!.urgent, isTrue);
      expect(store.requestById('r-sw-2')!.ownerId, a.id);
      expect(billing.ledger(), isEmpty);
      switchTo('up.a', role: 'seeker');
      expect(billing.ledger().single.targetId, 'r-sw-2');
    });

    test('owner licence fee: completed on A’s case — no «unknown owner case»', () async {
      await freshDevice();
      final a = signUp('own.a', role: 'owner');
      final c = ownerCases.submitContract(deedNo: '310123456789');
      ownerCases.approveContract(c.id, reference: 'test');
      final gate = holdPayments();
      final paying = ownerCases.payLicenceFee(c.id);
      switchTo('own.b', role: 'owner');
      gate.complete();
      expect((await paying).ok, isTrue);
      expect(ownerCases.all(), isEmpty, reason: 'B has no case');
      final done = ownerCases.byId(c.id, accountId: a.id)!;
      expect(done.feePaid, isTrue);
      expect(done.licenceNumber, isNotNull);
      switchTo('own.a', role: 'owner');
      expect(ownerCases.byId(c.id)!.feePaid, isTrue);
    });

    test('the double-tap guard is per account: B’s own payment is not blocked by A’s', () async {
      await freshDevice();
      final a = signUp('dup.a', role: 'broker');
      final gate = holdPayments();
      final first = billing.buyPlan(Plans.brokerYear);
      expect((await billing.buyPlan(Plans.brokerYear)).ok, isFalse, reason: 'A’s second tap is ignored');
      switchTo('dup.b');
      final b = store.accountId!;
      final second = billing.buyPlan(Plans.brokerYear);
      gate.complete();
      expect((await first).ok, isTrue);
      expect((await second).ok, isTrue);
      expect(billing.subscriptions(accountId: a.id), hasLength(1));
      expect(billing.subscriptions(accountId: b), hasLength(1));
    });
  });

  group('F10 — system back during the payment', () {
    testWidgets('cannot close the dialog while paying; the plan is active when it ends', (tester) async {
      await freshDevice();
      signUp('back.pay', role: 'broker');
      await pumpApp(tester, '/account/plans');
      await tapText(tester, 'اشترك في باقة الوسيط');
      final gate = holdPayments();
      await tapText(tester, 'ادفع 2,300 ريال');
      expect(find.text('جارٍ الدفع…'), findsOneWidget);

      await systemBack(tester);
      expect(find.text('جارٍ الدفع…'), findsOneWidget, reason: 'back is blocked while the payment runs');
      expect(billing.payments().single.status, PaymentRecord.pending);

      gate.complete();
      // the answer arrives, then the dialog plays its closing transition
      for (var i = 0; i < 10 && find.text('جارٍ الدفع…').evaluate().isNotEmpty; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      expect(find.text('جارٍ الدفع…'), findsNothing);
      expect(billing.subPlan(), Plans.brokerYear);
      expect(find.text('الباقة مفعّلة'), findsWidgets, reason: 'the screen says what happened');
    });

    testWidgets('before paying, back simply closes it and nothing is charged', (tester) async {
      await freshDevice();
      signUp('back.free', role: 'broker');
      await pumpApp(tester, '/account/plans');
      await tapText(tester, 'اشترك في باقة الوسيط');
      expect(find.text('ادفع 2,300 ريال'), findsOneWidget);
      await systemBack(tester);
      expect(find.text('ادفع 2,300 ريال'), findsNothing);
      expect(billing.payments(), isEmpty);
      expect(billing.subPlan(), isNull);
    });
  });
}
