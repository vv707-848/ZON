// The confirmation and (simulated) payment steps shared by every paid action:
// listing boosts, urgent requests, plans and the owner's licence fee.
//
// * covered by the plan's quota → one confirmation, then the quota is used;
// * not covered → the price is shown first; nothing is charged or changed
//   until the user taps pay and the (simulated) payment succeeds;
// * cancel / failure → nothing changes; a second tap while it runs is
//   ignored.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';

// ------------------------------------------------------------------ wording

/// Arabic count phrase: 1 → "طلب عاجل واحد", 2 → "طلبان عاجلان",
/// 3–10 → "4 طلبات عاجلة", 11+ → "14 طلبًا عاجلًا", 0 → "لا يوجد".
String arCount(int n, {required String one, required String two, required String few, required String many}) {
  if (n <= 0) return 'لا يوجد';
  if (n == 1) return one;
  if (n == 2) return two;
  final r = n % 100;
  if (r >= 3 && r <= 10) return '$n $few';
  return '$n $many';
}

/// "14 تمييزًا مميزًا", "4 طلبات عاجلة"…
String boostCount(BoostKind kind, int n) => switch (kind) {
  BoostKind.featured => arCount(n, one: 'تمييز مميز واحد', two: 'تمييزان مميزان', few: 'تمييزات مميزة', many: 'تمييزًا مميزًا'),
  BoostKind.urgentListing => arCount(n, one: 'تمييز عاجل واحد', two: 'تمييزان عاجلان', few: 'تمييزات عاجلة', many: 'تمييزًا عاجلًا'),
  BoostKind.urgentRequest => arCount(n, one: 'طلب عاجل واحد', two: 'طلبان عاجلان', few: 'طلبات عاجلة', many: 'طلبًا عاجلًا'),
};

/// "50 ريالًا", "100 ريال", "5 ريالات" (Arabic number agreement).
String riyals(int n) {
  final r = n % 100;
  if (n == 0 || r == 0 || r == 1 || r == 2) return '${loc(n)} ريال';
  if (r <= 10) return '${loc(n)} ريالات';
  return '${loc(n)} ريالًا';
}

/// "السنوي" / "الشهري" — the period of the running plan.
String _periodWord() => billing.activeSubscription()?.plan == Plans.brokerYear ? 'السنوي' : 'الشهري';

/// Usage line of a service screen, e.g.
/// "المتبقي من رصيدك السنوي: 14 تمييزًا مميزًا" or
/// "تمييز «مميز» — 100 ريال" when the plan has no quota for it.
String quotaLine(BoostKind kind) {
  final included = billing.included(kind);
  if (included == 0) return '${kind.label} — ${riyals(kind.price)}';
  final left = billing.remaining(kind);
  if (left == 0) return 'انتهى رصيدك ${_periodWord()} — ${kind.label} بـ ${riyals(kind.price)}';
  return 'المتبقي من رصيدك ${_periodWord()}: ${boostCount(kind, left)}';
}

// ------------------------------------------------------------------ boost flow

/// Runs one boost on [targetId] (a listing, or a request — pass [draft] for
/// a request that is not saved yet). Returns what happened; the caller
/// refreshes its screen.
Future<BoostOutcome> runBoost(BuildContext context, BoostKind kind, String targetId, {RequestItem? draft}) async {
  if (billing.isBusy(kind, targetId)) return BoostOutcome.busy;
  final q = billing.quote(kind, targetId, draft: draft);
  final icon = kind == BoostKind.featured ? Lu.crown : Lu.zap;
  if (q.alreadyActive) {
    final until = q.activeUntil;
    await showAppNotice(
      context,
      icon: icon,
      title: kind == BoostKind.urgentRequest ? 'الطلب عاجل بالفعل' : (kind == BoostKind.featured ? 'العرض مميز حاليًا' : 'العرض عاجل حاليًا'),
      message: until != null ? 'يستمر حتى ${arDate(until)} — لا يُخصم شيء.' : 'لا يُخصم شيء.',
    );
    return BoostOutcome.alreadyActive;
  }
  if (!context.mounted) return BoostOutcome.cancelled;
  if (q.covered) {
    final days = kind.duration?.inDays;
    final ok = await showAppDialog(
      context,
      icon: icon,
      title: _title(kind),
      message:
          'من رصيدك ${_periodWord()} — يبقى بعده ${boostCount(kind, q.remaining - 1)}.'
          '${days != null ? ' يستمر $days أيام.' : ''}',
      confirmLabel: 'استخدم الرصيد',
    );
    if (!ok) return BoostOutcome.cancelled;
    return billing.useQuota(kind, targetId, draft: draft);
  }
  if (!context.mounted) return BoostOutcome.cancelled;
  BoostOutcome result = BoostOutcome.cancelled;
  final paid = await showPayDialog(
    context,
    icon: icon,
    title: '${_title(kind)} — ${riyals(kind.price)}',
    message: q.included > 0
        ? 'انتهى رصيدك ${_periodWord()} لهذه الخدمة.'
        : (billing.isSubscribed() ? 'باقتك لا تشمل رصيدًا لهذه الخدمة.' : 'تُدفع مرة واحدة لهذه العملية.'),
    amount: kind.price,
    pay: () async {
      result = await billing.payAndApply(kind, targetId, draft: draft);
      return result == BoostOutcome.applied || result == BoostOutcome.alreadyActive;
    },
  );
  return paid ? result : (result == BoostOutcome.failed ? BoostOutcome.failed : BoostOutcome.cancelled);
}

String _title(BoostKind kind) => switch (kind) {
  BoostKind.featured => 'تمييز العرض «مميز»',
  BoostKind.urgentListing => 'تمييز العرض «عاجل»',
  BoostKind.urgentRequest => 'طلب عاجل',
};

// ------------------------------------------------------------------ pay dialog

/// Shows the price and pays only when the user taps pay. [pay] runs the
/// (simulated) payment and the action; it returns whether both succeeded.
/// Resolves to true when paid, false when cancelled or failed (nothing
/// changed in that case).
///
/// While the payment runs the dialog cannot be closed — not by a tap
/// outside, not by the system back button or gesture (QA F10) — and if the
/// app itself removes it meanwhile (navigation), the caller still receives
/// the real outcome of the payment, never a cancellation it did not get.
Future<bool> showPayDialog(
  BuildContext context, {
  required String icon,
  required String title,
  required String message,
  required int amount,
  required Future<bool> Function() pay,
  String? payLabel,
  bool? simulated,
}) async {
  final run = _PayRun();
  final ok = await showAppFrame<bool>(
    context,
    label: title,
    dismissible: false,
    builder: (ctx) => _PayCard(
      icon: icon,
      title: title,
      message: message,
      amount: amount,
      pay: pay,
      payLabel: payLabel,
      simulated: simulated ?? kSimulations,
      run: run,
    ),
  );
  if (ok != null) return ok;
  // closed without an answer while the payment was on its way: wait for it
  final inFlight = run.inFlight;
  return inFlight == null ? false : await inFlight;
}

/// The payment started from one dialog (see [showPayDialog]).
class _PayRun {
  Future<bool>? inFlight;
}

class _PayCard extends StatefulWidget {
  const _PayCard({
    required this.icon,
    required this.title,
    required this.message,
    required this.amount,
    required this.pay,
    required this.run,
    required this.simulated,
    this.payLabel,
  });

  final String icon;
  final String title;
  final String message;
  final int amount;
  final Future<bool> Function() pay;
  final _PayRun run;
  final String? payLabel;

  /// The payment is a simulation (this build's, or a server's): said plainly. Otherwise it is not available —
  /// the provider's sheet is not in this build yet.
  final bool simulated;

  @override
  State<_PayCard> createState() => _PayCardState();
}

class _PayCardState extends State<_PayCard> {
  bool busy = false;
  bool failed = false;

  static Future<bool> _safely(Future<bool> Function() pay) async {
    try {
      return await pay();
    } catch (_) {
      return false;
    }
  }

  Future<void> _pay() async {
    if (busy) return;
    setState(() {
      busy = true;
      failed = false;
    });
    final running = _safely(widget.pay);
    widget.run.inFlight = running;
    final ok = await running;
    if (!mounted) return;
    if (ok) {
      Navigator.of(context).pop(true);
    } else {
      setState(() {
        busy = false;
        failed = true;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final unavailable = !widget.simulated;
    // the system back button / gesture cannot close it while paying (F10)
    return PopScope(canPop: !busy, child: _card(unavailable));
  }

  Widget _card(bool unavailable) {
    return AppDialogCard(
      icon: failed ? Lu.circleAlert : widget.icon,
      accent: failed ? AppColors.danger : AppColors.forest,
      title: widget.title,
      message: failed
          ? 'تعذّر إتمام الدفع — لم يُخصم شيء ولم يتغير شيء.'
          : (unavailable ? 'الدفع غير متاح في هذه النسخة حتى ربط بوابة الدفع.' : widget.message),
      messageColor: failed ? AppColors.danger : null,
      // The payment is simulated in this build — said plainly to the tester.
      extra: unavailable
          ? null
          : Center(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(999)),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    const LucideIcon(Lu.creditCard, size: 14, color: AppColors.sage),
                    const SizedBox(width: 6),
                    Tx('دفع تجريبي — لا يُخصم مبلغ فعلي', style: ts(12, w: 600, lh: 18, c: AppColors.sage)),
                  ],
                ),
              ),
            ),
      // the amount stays readable in full (one button per line if needed)
      buttons: ButtonPair(
        secondary: AppButton(label: 'إلغاء', tone: ButtonTone.tonal, onTap: busy ? null : () => Navigator.of(context).pop(false)),
        primary: AppButton(
          label: busy ? 'جارٍ الدفع…' : (failed ? 'أعد المحاولة' : (widget.payLabel ?? 'ادفع ${riyals(widget.amount)}')),
          onTap: busy || unavailable ? null : _pay,
        ),
      ),
    );
  }
}
