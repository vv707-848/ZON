// Subscriptions, included quotas and paid boosts of the signed-in account.
//
// Rules (brief P02 / R01):
// * a quota belongs to the account AND to one paid period of its plan: a
//   yearly plan's quota is not refilled every month, opening the app or
//   signing out and in never refills it, another account never sees it;
// * a quota is used only when the operation succeeds — choosing an option,
//   cancelling the confirmation or a failed payment takes nothing;
// * a second tap while an operation runs is ignored (no double use, no
//   second payment), and a listing that is already featured is not charged
//   again;
// * when the quota is used up, the price is shown and confirmed BEFORE the
//   (simulated) payment, and the boost is applied only after it succeeds.
//
// Everything is stored on the device under `zon.billing.v1@<accountId>`.
// When the server is connected it becomes the source of entitlements, usage
// and payment verification; keep the method names.

import 'dart:math' as math;

import 'package:zon/core/utils/clock.dart';
import 'package:zon/data/billing/billing_models.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/billing/payment_simulator.dart';
import 'package:zon/data/billing/pricing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/store.dart';

export 'billing_models.dart';
export 'payment_simulator.dart';
export 'pricing.dart';

/// The billing of the signed-in account (see the file comment).
final Billing billing = Billing._();

class Billing {
  Billing._();

  static const String _key = 'zon.billing.v1';

  /// Operations running now (`<accountId>:<kind>:<target>`), against double
  /// taps — per account, so one account's payment never blocks another's.
  final Set<String> _running = <String>{};

  // ---------------------------------------------------------------- state

  /// The book of [accountId] (the signed-in account when null): paid
  /// periods, used / paid boosts and every payment record.
  ({List<Subscription> subs, List<LedgerEntry> ledger, List<PaymentRecord> payments}) _state({String? accountId}) {
    if (accountId == null || accountId == store.accountId) _migrateLegacyPlan();
    final raw = store.readAccountJson(_key, accountId: accountId);
    if (raw is! Map) return (subs: <Subscription>[], ledger: <LedgerEntry>[], payments: <PaymentRecord>[]);
    final m = raw.cast<String, Object?>();
    final subs = (m['subs'] as List? ?? const <Object?>[]).whereType<Map<String, Object?>>().map(Subscription.fromJson).toList();
    final ledger = (m['ledger'] as List? ?? const <Object?>[])
        .whereType<Map<String, Object?>>()
        .map(LedgerEntry.fromJson)
        .whereType<LedgerEntry>()
        .toList();
    final payments = (m['payments'] as List? ?? const <Object?>[])
        .whereType<Map<String, Object?>>()
        .map(PaymentRecord.fromJson)
        .whereType<PaymentRecord>()
        .toList();
    return (subs: subs, ledger: ledger, payments: payments);
  }

  void _save({required List<Subscription> subs, required List<LedgerEntry> ledger, required List<PaymentRecord> payments, String? accountId}) {
    store.writeAccountJson(_key, <String, Object?>{
      'subs': subs.map((s) => s.toJson()).toList(),
      'ledger': ledger.map((e) => e.toJson()).toList(),
      'payments': payments.map((e) => e.toJson()).toList(),
    }, accountId: accountId);
  }

  /// Version 1.1 saved only a plan name, activated by a tap. A broker or
  /// seeker plan becomes one period starting now; the owner plan is not
  /// carried over (it now needs a contract and a published listing).
  void _migrateLegacyPlan() {
    if (store.accountId == null) return;
    final legacy = store.getItem(store.scopedKey(Store.legacyPlanKey));
    if (legacy == null) return;
    store.removeItem(store.scopedKey(Store.legacyPlanKey));
    String? plan;
    if (legacy == 'true') plan = Plans.brokerYear;
    if (legacy == '"${Plans.brokerYear}"') plan = Plans.brokerYear;
    if (legacy == '"${Plans.brokerMonth}"') plan = Plans.brokerMonth;
    if (legacy == '"${Plans.seeker}"') plan = Plans.seeker;
    if (plan != null) startPeriod(plan, paymentRef: 'migrated-1.1');
  }

  // ---------------------------------------------------------------- payments (QA F02)

  int _paySeq = 0;

  /// Writes a `pending` payment under [accountId] before the gateway is
  /// called (see [PaymentRecord]).
  PaymentRecord beginPayment(String accountId, String purpose, int amount) {
    final now = appNow();
    final rec = PaymentRecord(id: 'pay-${now.microsecondsSinceEpoch}-${_paySeq++}', purpose: purpose, amount: amount, startedAt: now);
    final st = _state(accountId: accountId);
    _save(subs: st.subs, ledger: st.ledger, payments: <PaymentRecord>[...st.payments, rec], accountId: accountId);
    return rec;
  }

  /// Closes [rec] on the account that started it.
  void endPayment(String accountId, PaymentRecord rec, PaymentReceipt receipt, {String? status}) {
    final st = _state(accountId: accountId);
    final done = rec.ended(status ?? (receipt.ok ? PaymentRecord.paid : PaymentRecord.failed), reference: receipt.reference, at: appNow());
    _save(subs: st.subs, ledger: st.ledger, payments: <PaymentRecord>[for (final p in st.payments) p.id == rec.id ? done : p], accountId: accountId);
  }

  /// Every payment of the signed-in account (oldest first).
  List<PaymentRecord> payments() => _state().payments;

  /// When the answer of a payment arrives while ANOTHER account is signed in,
  /// the result still goes to the paying account, and that account finds a
  /// notification about it when it signs in again.
  void _tellIfSignedOut(String accountId, PaymentRecord rec, {required String title, required String body, required String target}) {
    if (store.accountId == accountId) return;
    notifications.add(kind: NoteKind.plan, title: title, body: body, target: target, key: 'paid:${rec.id}', accountId: accountId);
  }

  // ---------------------------------------------------------------- subscriptions

  /// Every paid period of the account (oldest first).
  List<Subscription> subscriptions({String? accountId}) => _state(accountId: accountId).subs;

  /// The running period (null when none or expired).
  Subscription? activeSubscription({String? accountId}) {
    final subs = subscriptions(accountId: accountId); // may open a migrated period, so read first
    final now = appNow();
    Subscription? best;
    for (final s in subs) {
      if (s.activeAt(now) && (best == null || s.startedAt.isAfter(best.startedAt))) best = s;
    }
    return best;
  }

  /// `broker-year` | `broker-month` | `seeker` | `owner` | null.
  ///
  /// The owner plan is active only after an approved brokerage contract and
  /// a published listing (see [OwnerCases]).
  String? subPlan() {
    final sub = activeSubscription();
    if (sub != null) return sub.plan;
    if (store.readSession()?.role == 'owner' && ownerCases.planActive()) return Plans.owner;
    return null;
  }

  /// Whether paid services are unlocked (developers always are — their
  /// account comes with the marketing contract).
  bool isSubscribed() {
    if (store.readSession()?.role == 'developer') return true;
    return subPlan() != null;
  }

  /// Opens a new paid period of [plan] (after a successful payment) for the
  /// signed-in account or [accountId].
  Subscription startPeriod(String plan, {String? paymentRef, String? accountId}) {
    final st = _state(accountId: accountId);
    final now = appNow();
    final sub = Subscription(
      id: 'sub-${now.microsecondsSinceEpoch}',
      plan: plan,
      startedAt: now,
      endsAt: now.add(Plans.periodOf(plan)),
      paymentRef: paymentRef,
    );
    _save(subs: <Subscription>[...st.subs, sub], ledger: st.ledger, payments: st.payments, accountId: accountId);
    return sub;
  }

  /// Pays one period of [plan] (simulated) and opens it — for the account
  /// that pressed «ادفع», whichever account is signed in when the answer
  /// arrives.
  Future<PaymentReceipt> buyPlan(String plan) async {
    final acc = store.accountId;
    if (acc == null) return const PaymentReceipt(PaymentStatus.failed);
    final key = '$acc:plan:$plan';
    if (_running.contains(key)) return const PaymentReceipt(PaymentStatus.failed);
    if (activeSubscription(accountId: acc) != null) return const PaymentReceipt(PaymentStatus.failed);
    _running.add(key);
    try {
      final rec = beginPayment(acc, 'plan:$plan', Plans.priceOf(plan));
      final receipt = await PaymentSimulator.charge(amount: Plans.priceOf(plan), purpose: 'plan:$plan');
      endPayment(acc, rec, receipt);
      if (receipt.ok) {
        startPeriod(plan, paymentRef: receipt.reference, accountId: acc);
        _tellIfSignedOut(acc, rec, title: 'تم تفعيل اشتراكك', body: 'اكتمل دفع ${_planName(plan)}.', target: '/account/plans');
      }
      return receipt;
    } finally {
      _running.remove(key);
    }
  }

  static String _planName(String plan) => switch (plan) {
    Plans.brokerYear => 'باقة الوسيط السنوية',
    Plans.brokerMonth => 'باقة الوسيط الشهرية',
    Plans.seeker => 'باقة الباحث',
    _ => 'الباقة',
  };

  // ---------------------------------------------------------------- quotas

  /// Quotas of the running period (none without a plan).
  PlanQuota currentQuota({String? accountId}) => kPlanQuotas[activeSubscription(accountId: accountId)?.plan] ?? PlanQuota.none;

  int included(BoostKind kind, {String? accountId}) => kind.includedIn(currentQuota(accountId: accountId));

  /// Quota taken in the running period.
  int used(BoostKind kind, {String? accountId}) {
    final sub = activeSubscription(accountId: accountId);
    if (sub == null) return 0;
    return _state(accountId: accountId).ledger.where((e) => e.fromQuota && e.kind == kind && e.periodId == sub.id).length;
  }

  int remaining(BoostKind kind, {String? accountId}) => math.max(0, included(kind, accountId: accountId) - used(kind, accountId: accountId));

  /// Every successful boost of the account (newest last).
  List<LedgerEntry> ledger() => _state().ledger;

  // ---------------------------------------------------------------- boosts

  /// What boosting [targetId] costs now. For a request that is not saved
  /// yet, pass its id and [draft].
  BoostQuote quote(BoostKind kind, String targetId, {RequestItem? draft}) {
    final now = appNow();
    var active = false;
    DateTime? until;
    switch (kind) {
      case BoostKind.featured:
        final l = store.listingById(targetId);
        if (l != null && l.featuredAt(now)) {
          active = true;
          until = l.premiumUntil == null ? null : DateTime.tryParse(l.premiumUntil!);
        }
      case BoostKind.urgentListing:
        final l = store.listingById(targetId);
        if (l != null && l.urgentAt(now)) {
          active = true;
          until = DateTime.tryParse(l.urgentUntil!);
        }
      case BoostKind.urgentRequest:
        final r = store.requestById(targetId);
        active = r?.urgent == true;
    }
    return BoostQuote(
      kind: kind,
      targetId: targetId,
      included: included(kind),
      remaining: remaining(kind),
      alreadyActive: active,
      activeUntil: until,
    );
  }

  /// Whether an operation of the signed-in account on [targetId] is running
  /// (the button shows busy).
  bool isBusy(BoostKind kind, String targetId) => _running.contains('${store.accountId}:${kind.name}:$targetId');

  /// Takes one from the quota and applies the boost — no payment.
  BoostOutcome useQuota(BoostKind kind, String targetId, {RequestItem? draft}) {
    final acc = store.accountId;
    if (acc == null) return BoostOutcome.failed;
    final key = '$acc:${kind.name}:$targetId';
    if (_running.contains(key)) return BoostOutcome.busy;
    _running.add(key);
    try {
      final q = quote(kind, targetId, draft: draft);
      if (q.alreadyActive) return BoostOutcome.alreadyActive;
      if (!q.covered) return BoostOutcome.needsPayment;
      if (!_belongsTo(kind, targetId, draft, acc)) return BoostOutcome.missingTarget;
      final sub = activeSubscription(accountId: acc)!;
      final now = appNow();
      _apply(kind, targetId, draft, now, acc);
      _record(LedgerEntry(id: _opId(kind, now), kind: kind, targetId: targetId, via: 'quota', amount: 0, at: now, periodId: sub.id), acc);
      if (remaining(kind, accountId: acc) == 0) _quotaUsedUp(kind);
      return BoostOutcome.applied;
    } finally {
      _running.remove(key);
    }
  }

  /// Pays the price of [kind] (simulated) and applies the boost. Call only
  /// after the user confirmed the price. The payment, the boost and the
  /// ledger entry belong to the account that started it (QA F02).
  Future<BoostOutcome> payAndApply(BoostKind kind, String targetId, {RequestItem? draft}) async {
    final acc = store.accountId;
    if (acc == null) return BoostOutcome.failed;
    final key = '$acc:${kind.name}:$targetId';
    if (_running.contains(key)) return BoostOutcome.busy;
    _running.add(key);
    try {
      if (quote(kind, targetId, draft: draft).alreadyActive) return BoostOutcome.alreadyActive;
      if (!_belongsTo(kind, targetId, draft, acc)) return BoostOutcome.missingTarget;
      final rec = beginPayment(acc, '${kind.name}:$targetId', kind.price);
      final receipt = await PaymentSimulator.charge(amount: kind.price, purpose: '${kind.name}:$targetId');
      if (!receipt.ok) {
        endPayment(acc, rec, receipt);
        return BoostOutcome.failed;
      }
      // checked again: the item may have gone while the payment was on its way
      if (!_belongsTo(kind, targetId, draft, acc)) {
        endPayment(acc, rec, receipt, status: PaymentRecord.unapplied);
        return BoostOutcome.missingTarget;
      }
      endPayment(acc, rec, receipt);
      final now = appNow();
      _apply(kind, targetId, draft, now, acc);
      _record(
        LedgerEntry(id: _opId(kind, now), kind: kind, targetId: targetId, via: 'payment', amount: kind.price, at: now, paymentRef: receipt.reference),
        acc,
      );
      _tellIfSignedOut(
        acc,
        rec,
        title: kind == BoostKind.urgentRequest ? 'نُشر طلبك العاجل' : 'تم تطبيق ${kind.label}',
        body: 'اكتمل الدفع (${kind.price} ريال).',
        target: kind == BoostKind.urgentRequest ? '/requests/$targetId' : '/listings/$targetId',
      );
      return BoostOutcome.applied;
    } finally {
      _running.remove(key);
    }
  }

  /// The item exists and belongs to [accountId]: a listing it published, a
  /// request it wrote, or a new request [draft] it is publishing now.
  bool _belongsTo(BoostKind kind, String targetId, RequestItem? draft, String accountId) {
    if (kind == BoostKind.urgentRequest) {
      if (draft != null) return draft.ownerId == null || draft.ownerId == accountId;
      return store.requestById(targetId)?.ownerId == accountId;
    }
    return store.listingById(targetId)?.ownerId == accountId;
  }

  void _apply(BoostKind kind, String targetId, RequestItem? draft, DateTime now, String accountId) {
    switch (kind) {
      case BoostKind.featured:
        store.patchListing(targetId, <String, Object?>{'isPremium': true, 'premiumUntil': now.add(kind.duration!).toIso8601String()});
      case BoostKind.urgentListing:
        store.patchListing(targetId, <String, Object?>{'urgentUntil': now.add(kind.duration!).toIso8601String()});
      case BoostKind.urgentRequest:
        if (draft != null) {
          // saved for the account that paid, whoever is signed in now
          store.saveRequest(draft.merged(<String, Object?>{'urgent': true, 'ownerId': accountId}));
        } else {
          store.patchRequest(targetId, <String, Object?>{'urgent': true});
        }
    }
  }

  void _record(LedgerEntry e, String accountId) {
    final st = _state(accountId: accountId);
    _save(subs: st.subs, ledger: <LedgerEntry>[...st.ledger, e], payments: st.payments, accountId: accountId);
  }

  int _seq = 0;

  String _opId(BoostKind kind, DateTime now) => 'op-${kind.name}-${now.microsecondsSinceEpoch}-${_seq++}';

  void _quotaUsedUp(BoostKind kind) {
    final sub = activeSubscription();
    if (sub == null) return;
    notifications.add(
      kind: NoteKind.plan,
      title: 'انتهى رصيد ${kind.label}',
      body: 'العملية التالية بـ ${kind.price} ريال بعد تأكيدك.',
      target: kind == BoostKind.urgentRequest ? '/account/requests' : '/account/listings',
      key: 'quota-out:${kind.name}:${sub.id}',
    );
  }
}
