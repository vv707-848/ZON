// Subscriptions, boost quotas and the usage ledger of one account.

import 'package:zon/data/billing/pricing.dart';

/// What a quota or a payment buys.
enum BoostKind {
  /// Listing shown as «مميز».
  featured,

  /// Listing shown as «عاجل».
  urgentListing,

  /// Search request shown as «عاجل».
  urgentRequest;

  /// Price when the plan's quota does not cover it.
  int get price => switch (this) {
    BoostKind.featured => Pricing.featuredListingPrice,
    BoostKind.urgentListing => Pricing.urgentListingPrice,
    BoostKind.urgentRequest => Pricing.urgentRequestPrice,
  };

  /// How many one period of [quota] includes.
  int includedIn(PlanQuota quota) => switch (this) {
    BoostKind.featured => quota.featured,
    BoostKind.urgentListing => quota.urgentListing,
    BoostKind.urgentRequest => quota.urgentRequests,
  };

  /// How long the boost stays on a listing (requests: until edited).
  Duration? get duration => switch (this) {
    BoostKind.featured => Pricing.featuredDuration,
    BoostKind.urgentListing => Pricing.urgentListingDuration,
    BoostKind.urgentRequest => null,
  };

  /// Arabic name, e.g. for "المتبقي من رصيدك السنوي: 14 تمييزًا مميزًا".
  String get label => switch (this) {
    BoostKind.featured => 'تمييز «مميز»',
    BoostKind.urgentListing => 'تمييز «عاجل»',
    BoostKind.urgentRequest => 'طلب عاجل',
  };

  static BoostKind? fromName(String? name) {
    for (final k in BoostKind.values) {
      if (k.name == name) return k;
    }
    return null;
  }
}

/// One paid period of a plan. Quotas and their usage belong to the period:
/// a new period starts with the full quota, the same period never refills.
class Subscription {
  const Subscription({required this.id, required this.plan, required this.startedAt, required this.endsAt, this.paymentRef});

  /// Period id (`sub-…`).
  final String id;

  /// `broker-year` | `broker-month` | `seeker` (see [Plans]).
  final String plan;
  final DateTime startedAt;
  final DateTime endsAt;

  /// Reference of the (simulated) payment that opened the period.
  final String? paymentRef;

  bool activeAt(DateTime t) => !t.isBefore(startedAt) && t.isBefore(endsAt);

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'plan': plan,
    'startedAt': startedAt.toIso8601String(),
    'endsAt': endsAt.toIso8601String(),
    if (paymentRef != null) 'paymentRef': paymentRef,
  };

  factory Subscription.fromJson(Map<String, Object?> j) => Subscription(
    id: j['id'] as String? ?? '',
    plan: j['plan'] as String? ?? '',
    startedAt: DateTime.tryParse(j['startedAt'] as String? ?? '') ?? DateTime(2000),
    endsAt: DateTime.tryParse(j['endsAt'] as String? ?? '') ?? DateTime(2000),
    paymentRef: j['paymentRef'] as String?,
  );
}

/// One successful boost: taken from the quota or paid.
class LedgerEntry {
  const LedgerEntry({
    required this.id,
    required this.kind,
    required this.targetId,
    required this.via,
    required this.amount,
    required this.at,
    this.periodId,
    this.paymentRef,
  });

  /// Operation id (unique).
  final String id;
  final BoostKind kind;

  /// Listing id or request id.
  final String targetId;

  /// `quota` | `payment`
  final String via;

  /// SAR paid (0 when taken from the quota).
  final int amount;
  final DateTime at;

  /// Subscription period whose quota covered it (quota entries).
  final String? periodId;

  /// Simulated payment reference (paid entries).
  final String? paymentRef;

  bool get fromQuota => via == 'quota';

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'kind': kind.name,
    'targetId': targetId,
    'via': via,
    'amount': amount,
    'at': at.toIso8601String(),
    if (periodId != null) 'periodId': periodId,
    if (paymentRef != null) 'paymentRef': paymentRef,
  };

  static LedgerEntry? fromJson(Map<String, Object?> j) {
    final kind = BoostKind.fromName(j['kind'] as String?);
    if (kind == null) return null;
    return LedgerEntry(
      id: j['id'] as String? ?? '',
      kind: kind,
      targetId: j['targetId'] as String? ?? '',
      via: j['via'] as String? ?? 'payment',
      amount: (j['amount'] as num?)?.toInt() ?? 0,
      at: DateTime.tryParse(j['at'] as String? ?? '') ?? DateTime(2000),
      periodId: j['periodId'] as String?,
      paymentRef: j['paymentRef'] as String?,
    );
  }
}

/// What a boost would cost right now (read before asking the user).
class BoostQuote {
  const BoostQuote({
    required this.kind,
    required this.targetId,
    required this.included,
    required this.remaining,
    this.activeUntil,
    this.alreadyActive = false,
  });

  final BoostKind kind;
  final String targetId;

  /// Size of the quota in the current period (0: the plan has none).
  final int included;

  /// What is left of it.
  final int remaining;

  /// The target already has this boost (nothing to take or pay).
  final bool alreadyActive;

  /// End of the running boost on a listing (null for requests / none).
  final DateTime? activeUntil;

  /// Taken from the quota without payment.
  bool get covered => !alreadyActive && remaining > 0;

  /// Price shown before the user confirms (when not covered).
  int get price => kind.price;
}

/// Result of a boost attempt.
enum BoostOutcome {
  /// Applied — taken from the quota or paid once.
  applied,

  /// The target already had it; nothing taken, nothing paid.
  alreadyActive,

  /// The quota is used up — the price must be confirmed first.
  needsPayment,

  /// The listing / request no longer exists.
  missingTarget,

  /// Another attempt on the same target is still running (double tap).
  busy,

  /// The user closed the payment step.
  cancelled,

  /// The payment failed; nothing changed.
  failed,
}

/// One payment from the moment it starts (QA F02). It is written under the
/// account that started it BEFORE the gateway is called, so its result
/// always lands on that account — even if another account is signed in by
/// the time the answer arrives — and a payment whose answer never came stays
/// visible as `pending` instead of being taken for a cancellation.
class PaymentRecord {
  const PaymentRecord({
    required this.id,
    required this.purpose,
    required this.amount,
    required this.startedAt,
    this.status = PaymentRecord.pending,
    this.reference,
    this.endedAt,
  });

  static const String pending = 'pending';
  static const String paid = 'paid';
  static const String failed = 'failed';

  /// Paid, but the item it was for was gone when the answer came: nothing was
  /// applied — the server must refund it once payments are real.
  static const String unapplied = 'unapplied';

  /// `pay-…` — also the idempotency key to send to a real gateway.
  final String id;

  /// What it pays for, e.g. `plan:broker-year`, `featured:<listingId>`,
  /// `licence:<caseId>`.
  final String purpose;
  final int amount;
  final DateTime startedAt;
  final String status;

  /// Gateway reference (`SIM-…` in this build).
  final String? reference;
  final DateTime? endedAt;

  PaymentRecord ended(String status, {String? reference, required DateTime at}) => PaymentRecord(
    id: id,
    purpose: purpose,
    amount: amount,
    startedAt: startedAt,
    status: status,
    reference: reference ?? this.reference,
    endedAt: at,
  );

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'purpose': purpose,
    'amount': amount,
    'startedAt': startedAt.toIso8601String(),
    'status': status,
    if (reference != null) 'reference': reference,
    if (endedAt != null) 'endedAt': endedAt!.toIso8601String(),
  };

  static PaymentRecord? fromJson(Map<String, Object?> j) {
    final started = DateTime.tryParse(j['startedAt'] as String? ?? '');
    final id = j['id'] as String?;
    if (started == null || id == null) return null;
    return PaymentRecord(
      id: id,
      purpose: j['purpose'] as String? ?? '',
      amount: (j['amount'] as num?)?.toInt() ?? 0,
      startedAt: started,
      status: j['status'] as String? ?? PaymentRecord.pending,
      reference: j['reference'] as String?,
      endedAt: DateTime.tryParse(j['endedAt'] as String? ?? ''),
    );
  }
}
