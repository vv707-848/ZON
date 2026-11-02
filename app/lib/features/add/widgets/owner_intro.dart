// First step of an owner's listing: the owner plan path (brief P04) —
// brokerage contract → approval at REGA (Nafath) → licence fee → listing.

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/pricing.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';
import 'package:zon/features/add/widgets/form_buttons.dart';
import 'package:zon/shared/widgets/billing_flow.dart' show riyals;

/// Explains the licence path (المسار) and what the owner needs (يلزمك)
/// before the deed form. [onContinue] opens the deed step, [onClose] goes
/// back to the "what do you want to add" choice.
class OwnerIntro extends StatelessWidget {
  const OwnerIntro({super.key, required this.onContinue, required this.onClose});

  final VoidCallback onContinue;
  final VoidCallback onClose;

  /// The owner path, in order.
  static List<String> get path => <String>[
    'بيانات الصك والهوية — يُرسل عقد الوساطة',
    'موافقتك على العقد في منصة الهيئة العامة للعقار عبر نفاذ',
    'رسوم الترخيص الإعلاني: ${riyals(Pricing.adLicenceFee)} للهيئة العامة للعقار',
    'تفاصيل العرض ونشره — تتفعّل الباقة بعد النشر',
  ];

  /// What the owner must have ready.
  static const List<String> needs = <String>['صك ساري وهوية فعالة', 'حساب في نفاذ لموافقة العقد', 'رسوم الترخيص الإعلاني'];

  @override
  Widget build(BuildContext context) {
    return Container(
      color: kSheetIos,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: CloseCircle(onTap: onClose),
          ),
          const SizedBox(height: 20),
          const _Header(),
          const SizedBox(height: 20),
          _Section(
            title: 'المسار',
            child: Column(
              children: <Widget>[for (var i = 0; i < path.length; i++) _PathStep(number: i + 1, text: path[i], last: i == path.length - 1)],
            ),
          ),
          const SizedBox(height: 12),
          _Section(
            title: 'يلزمك',
            child: Column(
              children: <Widget>[
                for (var i = 0; i < needs.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 10), _Need(text: needs[i])],
              ],
            ),
          ),
          const SizedBox(height: 20),
          AppButton(label: 'استمرار', size: ButtonSize.large, onTap: onContinue),
        ],
      ),
    );
  }
}

/// Logo, kicker and headline.
class _Header extends StatelessWidget {
  const _Header();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const ZonLogo(size: 56),
          const SizedBox(height: 16),
          Tx('باقة المالك', style: ts(14, w: 600, lh: 21, c: AppColors.success)),
          const SizedBox(height: 6),
          Tx('انشر عقارك بسعي واضح', style: ts(24, w: 700, lh: 36, c: AppColors.deep)),
          const SizedBox(height: 6),
          Tx(
            '${Pricing.ownerCommissionPercent}٪ سعي بعد بيع العقار المعلن. تتفعّل الباقة بعد إتمام عقد الوساطة ونشر العرض.',
            style: ts(15, w: 500, lh: 23, c: AppColors.muted),
          ),
        ],
      ),
    );
  }
}

/// White card with a small heading.
class _Section extends StatelessWidget {
  const _Section({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 18),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: AppColors.mistLine),
        boxShadow: AppShadows.card,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx(title, style: ts(16, w: 700, lh: 24, c: AppColors.deep)),
          const SizedBox(height: 14),
          child,
        ],
      ),
    );
  }
}

/// One numbered step of the path, joined to the next by a thin line.
class _PathStep extends StatelessWidget {
  const _PathStep({required this.number, required this.text, required this.last});

  final int number;
  final String text;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SizedBox(
            width: 28,
            child: Column(
              children: <Widget>[
                Container(
                  width: 28,
                  height: 28,
                  decoration: const BoxDecoration(color: AppColors.forest, shape: BoxShape.circle),
                  alignment: Alignment.center,
                  child: Tx('$number', style: ts(13, w: 700, lh: 18, c: AppColors.white)),
                ),
                if (!last) Expanded(child: Container(width: 2, color: const Color(0xFFCFD8D2))),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(top: 3, bottom: last ? 0 : 14),
              child: Tx(text, style: ts(15, w: 500, lh: 22, c: AppColors.ink)),
            ),
          ),
        ],
      ),
    );
  }
}

/// One requirement with a check mark.
class _Need extends StatelessWidget {
  const _Need({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Container(
          width: 24,
          height: 24,
          margin: const EdgeInsets.only(top: 1),
          decoration: const BoxDecoration(color: AppColors.mist, shape: BoxShape.circle),
          alignment: Alignment.center,
          child: const LucideIcon(Lu.check, size: 14, strokeWidth: 2.6, color: AppColors.forest),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Tx(text, style: ts(15, w: 500, lh: 22, c: AppColors.ink)),
        ),
      ],
    );
  }
}
