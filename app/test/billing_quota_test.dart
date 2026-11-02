// P02 / R01 — yearly quotas, prices after the quota, normal vs urgent
// requests, cancel / failure / double tap, account switch and restart.

import 'package:flutter_test/flutter_test.dart';

import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';

import 'support/test_env.dart';

RequestItem _request(String id, {bool urgent = false}) => RequestItem(
  id: id,
  purpose: 'sale',
  city: 'الرياض',
  neighborhoods: const <String>['الملقا'],
  types: const <String>['villa'],
  priceMin: 1000000,
  priceMax: 2000000,
  areaMin: 0,
  areaMax: 0,
  roomsMin: 3,
  roomsMax: 5,
  description: 'طلب اختبار',
  author: 'حساب اختبار',
  role: 'broker',
  urgent: urgent,
);

void main() {
  group('the numbers live in one place', () {
    test('prices and quotas', () {
      expect(Pricing.brokerYearly, 2300);
      expect(Pricing.brokerMonthly, 280);
      expect(Pricing.brokerYearlySaving, 1060);
      expect(Pricing.seekerMonthly, 199);
      expect(Pricing.urgentRequestPrice, 50);
      expect(Pricing.ownerCommissionPercent, 0.5);
      expect(Pricing.adLicenceFee, 50);
      // the 50 of urgent requests is not applied to listing boosts
      expect(Pricing.featuredListingPrice, isNot(50));
      const year = PlanQuota(featured: 15, urgentListing: 10, urgentRequests: 5);
      final q = kPlanQuotas[Plans.brokerYear]!;
      expect(<int>[q.featured, q.urgentListing, q.urgentRequests], <int>[year.featured, year.urgentListing, year.urgentRequests]);
      // the monthly plan never gets the yearly quotas
      final m = kPlanQuotas[Plans.brokerMonth]!;
      expect(m.featured < q.featured && m.urgentListing < q.urgentListing && m.urgentRequests < q.urgentRequests, isTrue);
    });
  });

  group('yearly broker plan — «مميز» 15', () {
    test('the 15th is included, the 16th asks for payment', () async {
      await freshDevice();
      signUp('quota.feat');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      for (var i = 1; i <= 15; i++) {
        final l = publishTestListing('f-$i');
        expect(billing.useQuota(BoostKind.featured, l.id), BoostOutcome.applied, reason: 'boost $i');
      }
      expect(billing.remaining(BoostKind.featured), 0);
      final l16 = publishTestListing('f-16');
      expect(billing.quote(BoostKind.featured, l16.id).covered, isFalse);
      expect(billing.useQuota(BoostKind.featured, l16.id), BoostOutcome.needsPayment);
      expect(store.listingById(l16.id)!.featuredAt(DateTime.now()), isFalse, reason: 'not featured before paying');
      expect(await billing.payAndApply(BoostKind.featured, l16.id), BoostOutcome.applied);
      final paid = billing.ledger().where((e) => !e.fromQuota).toList();
      expect(paid.length, 1);
      expect(paid.single.amount, Pricing.featuredListingPrice);
    });

    test('re-boosting a featured listing takes nothing', () async {
      await freshDevice();
      signUp('quota.again');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      final l = publishTestListing('again-1');
      expect(billing.useQuota(BoostKind.featured, l.id), BoostOutcome.applied);
      expect(billing.useQuota(BoostKind.featured, l.id), BoostOutcome.alreadyActive);
      expect(await billing.payAndApply(BoostKind.featured, l.id), BoostOutcome.alreadyActive);
      expect(billing.remaining(BoostKind.featured), 14);
      expect(billing.ledger().length, 1);
    });

    test('a featuring ends after its own duration, not after a year', () async {
      await freshDevice();
      signUp('quota.duration');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      final l = publishTestListing('dur-1');
      billing.useQuota(BoostKind.featured, l.id);
      final now = DateTime.now();
      final boosted = store.listingById(l.id)!;
      expect(DateTime.parse(boosted.premiumUntil!).isBefore(now.add(const Duration(days: 30))), isTrue);
      expect(boosted.featuredAt(now.add(const Duration(days: 1))), isTrue);
      expect(boosted.featuredAt(now.add(Pricing.featuredDuration + const Duration(hours: 1))), isFalse);
    });
  });

  group('yearly broker plan — «عاجل» 10', () {
    test('the 10th is included, the 11th asks for payment', () async {
      await freshDevice();
      signUp('quota.urgent');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      for (var i = 1; i <= 10; i++) {
        final l = publishTestListing('u-$i');
        expect(billing.useQuota(BoostKind.urgentListing, l.id), BoostOutcome.applied, reason: 'urgent $i');
      }
      final l11 = publishTestListing('u-11');
      expect(billing.useQuota(BoostKind.urgentListing, l11.id), BoostOutcome.needsPayment);
      // the urgent-listing quota and the urgent-request quota are separate
      expect(billing.remaining(BoostKind.urgentRequest), 5);
      expect(billing.remaining(BoostKind.featured), 15);
    });
  });

  group('requests — normal free, urgent 50 (R01)', () {
    test('5 urgent requests are included, the 6th costs 50', () async {
      await freshDevice();
      signUp('quota.req');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      for (var i = 1; i <= 5; i++) {
        final draft = _request('rq-$i');
        expect(billing.useQuota(BoostKind.urgentRequest, draft.id, draft: draft), BoostOutcome.applied);
        expect(store.requestById(draft.id)!.urgent, isTrue);
      }
      final sixth = _request('rq-6');
      final quote = billing.quote(BoostKind.urgentRequest, sixth.id, draft: sixth);
      expect(quote.covered, isFalse);
      expect(quote.price, 50);
      expect(await billing.payAndApply(BoostKind.urgentRequest, sixth.id, draft: sixth), BoostOutcome.applied);
      final paid = billing.ledger().where((e) => !e.fromQuota).single;
      expect(paid.amount, 50);
      // no double fee: the quota-covered ones cost nothing
      expect(billing.ledger().where((e) => e.fromQuota).every((e) => e.amount == 0), isTrue);
    });

    test('a normal request stays free after every quota is used up', () async {
      await freshDevice();
      signUp('normal.free');
      // no plan at all: the urgent price is 50, a normal request is still free
      store.saveRequest(_request('normal-1'));
      expect(store.requestById('normal-1')!.urgent, isFalse);
      expect(billing.ledger(), isEmpty);
      expect(billing.quote(BoostKind.urgentRequest, 'x', draft: _request('x')).price, 50);
    });

    test('cancel or failed payment: not urgent, nothing taken', () async {
      await freshDevice();
      signUp('pay.fail');
      final draft = _request('fail-1');
      PaymentSimulator.nextStatus = PaymentStatus.failed;
      expect(await billing.payAndApply(BoostKind.urgentRequest, draft.id, draft: draft), BoostOutcome.failed);
      expect(store.requestById(draft.id), isNull, reason: 'nothing published');
      expect(billing.ledger(), isEmpty);
      // an existing normal request that fails to become urgent stays as it was
      store.saveRequest(_request('keep-1'));
      PaymentSimulator.nextStatus = PaymentStatus.failed;
      expect(await billing.payAndApply(BoostKind.urgentRequest, 'keep-1'), BoostOutcome.failed);
      expect(store.requestById('keep-1')!.urgent, isFalse);
      expect(store.requestById('keep-1')!.description, 'طلب اختبار');
    });

    test('a double tap pays once', () async {
      await freshDevice();
      signUp('double.tap');
      store.saveRequest(_request('dbl-1'));
      PaymentSimulator.latency = const Duration(milliseconds: 20);
      final a = billing.payAndApply(BoostKind.urgentRequest, 'dbl-1');
      final b = billing.payAndApply(BoostKind.urgentRequest, 'dbl-1');
      final results = await Future.wait(<Future<BoostOutcome>>[a, b]);
      expect(results, containsAll(<BoostOutcome>[BoostOutcome.applied, BoostOutcome.busy]));
      expect(billing.ledger().length, 1);
      // a third attempt later: already urgent, nothing charged
      expect(await billing.payAndApply(BoostKind.urgentRequest, 'dbl-1'), BoostOutcome.alreadyActive);
      expect(billing.ledger().length, 1);
    });
  });

  group('the quota belongs to the account and the yearly period', () {
    test('restart, sign out / in and 40 days later: no refill', () async {
      await freshDevice();
      signUp('period.check');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      final l = publishTestListing('p-1');
      billing.useQuota(BoostKind.featured, l.id);
      await restartApp();
      expect(billing.remaining(BoostKind.featured), 14);
      LocalAuth.signOut();
      expect(LocalAuth.signIn('period.check', 'secret-123').ok, isTrue);
      expect(billing.remaining(BoostKind.featured), 14);
      advanceClock(const Duration(days: 40));
      expect(billing.subPlan(), Plans.brokerYear);
      expect(billing.remaining(BoostKind.featured), 14, reason: 'a yearly quota is not refilled every month');
    });

    test('another account on the same device gets nothing from it', () async {
      await freshDevice();
      signUp('first.acc');
      billing.startPeriod(Plans.brokerYear, paymentRef: 'test');
      LocalAuth.signOut();
      signUp('second.acc');
      expect(billing.included(BoostKind.featured), 0);
      expect(billing.included(BoostKind.urgentRequest), 0);
    });

    test('the monthly plan keeps the approved 1 featuring per month only', () async {
      await freshDevice();
      signUp('monthly.acc');
      billing.startPeriod(Plans.brokerMonth, paymentRef: 'test');
      expect(billing.included(BoostKind.featured), 1);
      expect(billing.included(BoostKind.urgentListing), 0);
      expect(billing.included(BoostKind.urgentRequest), 0);
    });

    test('buying a plan goes through the simulated payment', () async {
      await freshDevice();
      signUp('buy.plan');
      PaymentSimulator.nextStatus = PaymentStatus.failed;
      expect((await billing.buyPlan(Plans.brokerYear)).ok, isFalse);
      expect(billing.subPlan(), isNull);
      final receipt = await billing.buyPlan(Plans.brokerYear);
      expect(receipt.ok, isTrue);
      expect(receipt.reference, startsWith('SIM-'));
      expect(billing.subPlan(), Plans.brokerYear);
    });
  });
}
