// الاشتراكات — the plan of each role, short and plain (brief P01 / P03 / P04).
//
// * broker: yearly / monthly broker plan and a short comparison with no
//   subscription;
// * seeker: the seeker plan and what stays free;
// * owner: the owner plan — 0.5 % commission after the advertised property
//   is sold; active only after an approved brokerage contract AND a
//   published listing (see data/billing/owner_cases.dart).
//
// Every price comes from data/billing/pricing.dart. Usage counters and
// prices after the quota are shown in the service screens, not here.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/billing/owner_cases.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/billing_flow.dart';

const Color _pale = Color(0xFFC8D4CC);

/// Card shown instead of a paid service when not subscribed.
class PayGate extends StatelessWidget {
  const PayGate({super.key, required this.title, required this.text});

  final String title;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 28),
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(24)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx(
            title,
            style: ts(20, w: 700, lh: 30, c: AppColors.white),
            align: TextAlign.center,
          ),
          const SizedBox(height: 8),
          Tx(
            text,
            style: ts(15, lh: 25.5, c: _pale),
            align: TextAlign.center,
          ),
          const SizedBox(height: 18),
          AppButton(label: 'اشترك في باقة الوسيط', tone: ButtonTone.secondary, onTap: () => Nav.go(context, '/account/plans')),
        ],
      ),
    );
  }
}

/// Feature row of a plan: ✓ included / × not included.
class PlanLi extends StatelessWidget {
  const PlanLi({super.key, this.ok = false, required this.title, this.last = false});

  final bool ok;
  final String title;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: Color(0xFFF2F4F3))),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Container(
            width: 20,
            height: 20,
            margin: const EdgeInsets.only(top: 1),
            decoration: BoxDecoration(color: ok ? const Color(0xFFE7F0EA) : const Color(0xFFF2F4F3), shape: BoxShape.circle),
            alignment: Alignment.center,
            child: Tx(ok ? '✓' : '×', style: ts(12, w: 700, lh: 19, c: ok ? AppColors.forest : const Color(0xFFB4BDB8))),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Tx(
              title,
              style: ts(14, w: ok ? 700 : 600, lh: 22.5, c: ok ? AppColors.forest : const Color(0xFFA4ADA8)),
            ),
          ),
        ],
      ),
    );
  }
}

/// `/account/plans` — الاشتراكات
class PlansView extends StatefulWidget {
  const PlansView({super.key});

  @override
  State<PlansView> createState() => _PlansViewState();
}

class _PlansViewState extends State<PlansView> {
  String cycle = 'year';

  static const TextStyle _tnum = TextStyle(fontFeatures: <FontFeature>[FontFeature.tabularFigures()]);

  // ------------------------------------------------------------------ actions

  Future<void> _subscribe(String plan, String name) async {
    final paid = await showPayDialog(
      context,
      icon: Lu.crown,
      title: '$name — ${riyals(Plans.priceOf(plan))}',
      message: plan == Plans.brokerYear ? 'اشتراك سنوي يبدأ اليوم.' : 'اشتراك شهري يبدأ اليوم.',
      amount: Plans.priceOf(plan),
      pay: () async => (await billing.buyPlan(plan)).ok,
    );
    if (!mounted) return;
    setState(() {});
    if (paid && plan == Plans.seeker) unawaited(Nav.go(context, '/account/matching'));
  }

  // ------------------------------------------------------------------ pieces

  /// Big number with the riyal sign (or a percent) and a small label.
  Widget _price(String n, {String? small, required bool onDark, bool riyal = true}) {
    final fg = onDark ? AppColors.white : AppColors.forest;
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          Tx(n, style: ts(32, w: 700, lh: 37, c: fg).merge(_tnum)),
          if (riyal) ...<Widget>[const SizedBox(width: 8), RiyalIcon(width: 18, height: 18, color: fg)],
          if (small != null) ...<Widget>[
            const SizedBox(width: 8),
            Tx(small, style: ts(15, w: 600, lh: 18, c: onDark ? _pale : const Color(0xFF8A918C))),
          ],
        ],
      ),
    );
  }

  Widget _list(String heading, List<String> items) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 14, 16, 6),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx(heading, style: ts(13, w: 700, lh: 19.5, c: AppColors.sage)),
        const SizedBox(height: 2),
        for (var i = 0; i < items.length; i++) PlanLi(ok: true, title: items[i], last: i == items.length - 1),
      ],
    ),
  );

  Widget _card({String? tag, required List<Widget> head, required List<Widget> body}) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: <BoxShadow>[
          const BoxShadow(color: AppColors.forest, spreadRadius: 1.6),
          decorShadow(AppColors.forest, 0, 14, 28, -20),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(22),
        child: Stack(
          children: <Widget>[
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Container(
                  color: AppColors.forest,
                  padding: const EdgeInsets.fromLTRB(18, 24, 18, 18),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: head),
                ),
                ...body,
              ],
            ),
            if (tag != null)
              PositionedDirectional(
                top: 0,
                end: 16,
                child: Container(
                  height: 22,
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  decoration: const BoxDecoration(
                    color: Color(0xFFC6A15B),
                    borderRadius: BorderRadius.only(bottomLeft: Radius.circular(12), bottomRight: Radius.circular(12)),
                  ),
                  alignment: Alignment.center,
                  child: Tx(tag, style: ts(11, w: 700, lh: 24, c: AppColors.forest)),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _name(String t) => Tx(
    t,
    style: ts(16, w: 700, lh: 24, c: AppColors.white),
    align: TextAlign.center,
  );

  Widget _tagline(String t) => Padding(
    padding: const EdgeInsets.only(top: 4),
    child: Tx(
      t,
      style: ts(14, w: 600, lh: 21, c: const Color(0xFFC8E6D0)),
      align: TextAlign.center,
    ),
  );

  Widget _onDarkLine(String t) => Padding(
    padding: const EdgeInsets.only(top: 8),
    child: Tx(
      t,
      style: ts(13, w: 600, lh: 19.5, c: _pale),
      align: TextAlign.center,
    ),
  );

  /// Light card with a title and one line (e.g. "بدون اشتراك").
  Widget _plainCard(String title, String text) => Container(
    margin: const EdgeInsets.only(bottom: 12),
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    decoration: BoxDecoration(
      color: AppColors.white,
      borderRadius: BorderRadius.circular(18),
      boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx(title, style: ts(14, w: 700, lh: 21, c: AppColors.forest)),
        const SizedBox(height: 4),
        Tx(text, style: ts(14, lh: 22, c: AppColors.deep)),
      ],
    ),
  );

  Widget _cta(String label, VoidCallback? onTap, {String? note}) => Padding(
    padding: const EdgeInsets.only(top: 2),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        AppButton(label: label, size: ButtonSize.large, onTap: onTap),
        if (note != null) ...<Widget>[
          const SizedBox(height: 8),
          Tx(
            note,
            style: ts(13, lh: 19.5, c: AppColors.muted),
            align: TextAlign.center,
          ),
        ],
      ],
    ),
  );

  // ------------------------------------------------------------------ broker

  List<Widget> _broker() {
    final active = billing.activeSubscription();
    final brokerOn = active != null && Plans.isBroker(active.plan);
    final yearly = cycle == 'year';
    final plan = yearly ? Plans.brokerYear : Plans.brokerMonth;
    final price = Plans.priceOf(plan);
    final samePlan = brokerOn && active.plan == plan;
    return <Widget>[
      // yearly / monthly
      Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(999)),
        child: Row(
          children: <Widget>[
            Expanded(
              child: _togButton(
                on: yearly,
                onTap: () => setState(() => cycle = 'year'),
                label: 'سنوي',
                small: 'وفّر ${loc(Pricing.brokerYearlySaving)}',
              ),
            ),
            const SizedBox(width: 4),
            Expanded(
              child: _togButton(on: !yearly, onTap: () => setState(() => cycle = 'month'), label: 'شهري'),
            ),
          ],
        ),
      ),
      _card(
        tag: samePlan ? 'مفعّلة' : null,
        head: <Widget>[
          _name('باقة الوسيط'),
          _tagline('سعيك كامل. وأعمالك أكثر تنظيمًا.'),
          _price(loc(price), small: yearly ? 'سنويًا' : 'شهريًا', onDark: true),
          if (yearly)
            _onDarkLine('وفّر ${loc(Pricing.brokerYearlySaving)} ريال مقارنة بالاشتراك الشهري')
          else
            _onDarkLine('${loc(Pricing.brokerMonthly * 12)} ريال في السنة — السنوي ${loc(Pricing.brokerYearly)}'),
        ],
        body: <Widget>[
          _list('تشمل الباقة', const <String>[
            'إعلانات غير محدودة',
            'الاحتفاظ بكامل السعي',
            'الوصول إلى طلبات العملاء في مدينتك',
            'بحث متقدم في العروض والطلبات',
            'تنظيم الملاحظات ومتابعة الملاك',
          ]),
          const SizedBox(height: 8),
        ],
      ),
      const _Compare(
        headA: 'باقة الوسيط',
        headB: 'بدون اشتراك',
        rows: <(String, String, String)>[
          ('نشر الإعلانات', 'غير محدود', 'غير محدود'),
          ('السعي', 'تحتفظ به كاملًا', 'مشاركة زون بـ${Pricing.brokerFreeCommissionSharePercent}٪ من السعي'),
          ('طلبات العملاء والبحث المتقدم', 'متاحة', 'غير متاحة'),
          ('تلخيص الملاحظات ومتابعة الملاك', 'متاحة', 'غير متاحة'),
        ],
        foot: 'بدون اشتراك: إعلانات غير محدودة مع مشاركة زون بـ${Pricing.brokerFreeCommissionSharePercent}٪ من السعي عند إتمام الصفقة.',
      ),
      if (brokerOn)
        _cta(
          samePlan ? 'الباقة مفعّلة' : 'لديك باقة ${active.plan == Plans.brokerYear ? 'سنوية' : 'شهرية'} مفعّلة',
          null,
          note: 'تنتهي في ${arDate(active.endsAt, year: true)}',
        )
      else
        _cta('اشترك في باقة الوسيط', () => _subscribe(plan, yearly ? 'باقة الوسيط السنوية' : 'باقة الوسيط الشهرية')),
    ];
  }

  // ------------------------------------------------------------------ seeker

  List<Widget> _seeker() {
    final active = billing.activeSubscription();
    final on = active?.plan == Plans.seeker;
    return <Widget>[
      _card(
        tag: on ? 'مفعّلة' : null,
        head: <Widget>[
          _name('باقة الباحث'),
          _tagline('عروض أقرب إلى رغبتك'),
          _price(loc(Pricing.seekerMonthly), small: 'شهريًا', onDark: true),
        ],
        body: <Widget>[
          _list('تشمل الباقة', const <String>[
            'مساعد المطابقة',
            'تنبيهات بالعروض المناسبة',
            'بحث عقاري بلغتك',
            'عروض مطابقة وخيارات إضافية',
            'وصول مباشر إلى المطورين والملاك',
          ]),
          const SizedBox(height: 8),
        ],
      ),
      _plainCard('بدون اشتراك', 'تصفح العروض والمشاريع، والفلاتر، والطلبات العادية، والمفضلة والتواصل.'),
      if (on)
        _cta('الباقة مفعّلة', null, note: 'تنتهي في ${arDate(active!.endsAt, year: true)}')
      else
        _cta('اشترك في باقة الباحث', () => _subscribe(Plans.seeker, 'باقة الباحث')),
    ];
  }

  // ------------------------------------------------------------------ owner

  List<Widget> _owner() {
    final activeCase = ownerCases.activeCase();
    final current = ownerCases.current();
    final active = activeCase != null;
    final listingId = activeCase?.listingId;
    final listing = listingId != null ? store.listingById(listingId) : null;
    return <Widget>[
      _card(
        tag: active ? 'مفعّلة' : null,
        head: <Widget>[
          _name('باقة المالك'),
          _tagline('انشر عقارك بسعي واضح'),
          _price('${Pricing.ownerCommissionPercent}٪', riyal: false, onDark: true),
          _onDarkLine('سعي بعد بيع العقار المعلن'),
        ],
        body: <Widget>[
          _list('تشمل الباقة', const <String>[
            'عقد وساطة موثّق',
            'ظهور عرضك في البحث والمطابقة',
            'تنسيق وصف العقار قبل النشر',
            'تواصل مباشر مع المهتمين',
          ]),
          Container(
            margin: const EdgeInsets.fromLTRB(16, 4, 16, 16),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(14)),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Tx('رسوم الترخيص الإعلاني: ${riyals(Pricing.adLicenceFee)} للهيئة العامة للعقار', style: ts(14, w: 700, lh: 21, c: AppColors.forest)),
                const SizedBox(height: 4),
                Tx('تتفعّل الباقة بعد إتمام عقد الوساطة ونشر العرض.', style: ts(14, lh: 21, c: AppColors.deep)),
              ],
            ),
          ),
        ],
      ),
      if (active)
        _plainCard(
          'الباقة مفعّلة',
          '${Pricing.ownerCommissionPercent}٪ سعي بعد بيع «${listing?.title ?? 'عقارك المعلن'}» — حسب عقد الوساطة الخاص به.',
        )
      else if (current != null)
        OwnerCaseSteps(item: current),
      if (active)
        _cta('أضف عقارًا آخر', () => Nav.go(context, '/add?type=listing'))
      else
        _cta(current != null ? 'أكمل نشر عقارك' : 'ابدأ نشر عقارك', () => Nav.go(context, '/add?type=listing')),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final role = store.readSession()?.role ?? 'seeker';
    if (role == 'developer') return const RedirectTo('/account');
    final children = <Widget>[
      Padding(
        padding: const EdgeInsets.only(top: 4, bottom: 10),
        child: BackLink(label: 'الإعدادات', onTap: () => Nav.go(context, '/account/settings')),
      ),
      if (role == 'broker') ..._broker(),
      if (role == 'seeker') ..._seeker(),
      if (role == 'owner') ..._owner(),
    ];
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
    );
  }

  Widget _togButton({required bool on, required VoidCallback onTap, required String label, String? small}) {
    return Tap(
      onTap: onTap,
      child: Container(
        constraints: const BoxConstraints(minHeight: 42),
        padding: const EdgeInsets.symmetric(vertical: 2),
        decoration: BoxDecoration(
          color: on ? AppColors.white : const Color(0x00000000),
          borderRadius: BorderRadius.circular(999),
          boxShadow: on ? AppShadows.card : null,
        ),
        alignment: Alignment.center,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Tx(
              label,
              style: ts(15, w: 700, lh: 22.5, c: on ? AppColors.forest : AppColors.sage),
              align: TextAlign.center,
            ),
            if (small != null) ...<Widget>[
              const SizedBox(height: 1),
              Tx(
                small,
                style: ts(11, w: 700, lh: 16.5, c: AppColors.forest),
                align: TextAlign.center,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Short two-column comparison (plan vs. no subscription).
class _Compare extends StatelessWidget {
  const _Compare({required this.headA, required this.headB, required this.rows, this.foot});

  final String headA;
  final String headB;

  /// (feature, with the plan, without)
  final List<(String, String, String)> rows;
  final String? foot;

  @override
  Widget build(BuildContext context) {
    Widget cell(String t, {bool head = false, bool strong = false, int flex = 3}) => Expanded(
      flex: flex,
      child: Tx(
        t,
        style: head
            ? ts(12, w: 700, lh: 18, c: AppColors.sage)
            : ts(13, w: strong ? 700 : 500, lh: 19.5, c: strong ? AppColors.forest : AppColors.deep),
      ),
    );
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx('مقارنة مختصرة', style: ts(14, w: 700, lh: 21, c: AppColors.forest)),
          const SizedBox(height: 8),
          Row(children: <Widget>[cell('', head: true, flex: 4), cell(headA, head: true), cell(headB, head: true)]),
          for (final r in rows)
            Container(
              padding: const EdgeInsets.symmetric(vertical: 8),
              decoration: const BoxDecoration(
                border: Border(top: BorderSide(color: Color(0xFFF2F4F3))),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[cell(r.$1, flex: 4), const SizedBox(width: 6), cell(r.$2, strong: true), const SizedBox(width: 6), cell(r.$3)],
              ),
            ),
          if (foot != null) ...<Widget>[const SizedBox(height: 6), Tx(foot!, style: ts(13, lh: 19.5, c: AppColors.muted))],
        ],
      ),
    );
  }
}

/// The owner's progress: contract → licence fee → listing (each its own
/// state, see OwnerCase.stage).
class OwnerCaseSteps extends StatelessWidget {
  const OwnerCaseSteps({super.key, required this.item});

  final OwnerCase item;

  @override
  Widget build(BuildContext context) {
    Widget step(String title, String state, bool done) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: <Widget>[
          Container(
            width: 22,
            height: 22,
            decoration: BoxDecoration(color: done ? AppColors.forest : AppColors.mist, shape: BoxShape.circle),
            alignment: Alignment.center,
            child: done ? const LucideIcon(Lu.check, size: 13, strokeWidth: 2.4, color: AppColors.white) : null,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Tx(title, style: ts(14, w: 600, lh: 21, c: AppColors.deep)),
          ),
          Tx(state, style: ts(13, w: 600, lh: 19.5, c: done ? AppColors.forest : AppColors.muted)),
        ],
      ),
    );
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx('حالة الباقة: غير مفعّلة بعد', style: ts(14, w: 700, lh: 21, c: AppColors.forest)),
          const SizedBox(height: 4),
          step('عقد الوساطة', item.contractApproved ? 'موثّق' : 'بانتظار موافقتك', item.contractApproved),
          step('رسوم الترخيص الإعلاني', item.feePaid ? 'مدفوعة' : 'لم تُدفع', item.feePaid),
          step('نشر العرض', item.published ? 'منشور' : 'لم يُنشر', item.published),
        ],
      ),
    );
  }
}
