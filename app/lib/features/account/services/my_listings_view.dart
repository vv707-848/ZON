// العروض والمشاريع — the account's own listings / projects (active / hidden)
// with the boosts «مميز» and «عاجل» (quota first, then a confirmed and
// simulated payment), edit, sold and hide / show actions.

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/desk.dart';
import 'package:zon/shared/widgets/billing_flow.dart';
import 'package:zon/shared/widgets/boost.dart';
import 'package:zon/shared/widgets/listing_card.dart';

/// عروضي / مشاريعي — the user's own listings and projects.
class MyListingsView extends StatefulWidget {
  const MyListingsView({super.key, required this.role, required this.name});

  final String role;
  final String name;

  @override
  State<MyListingsView> createState() => _MyListingsViewState();
}

class _MyListingsViewState extends State<MyListingsView> {
  String tab = 'on';
  late final TapGestureRecognizer _addTap = TapGestureRecognizer()..onTap = () => Nav.go(context, '/add');

  @override
  void dispose() {
    _addTap.dispose();
    super.dispose();
  }

  void bump() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final isDev = widget.role == 'developer';
    final isBroker = widget.role == 'broker';
    // Only the listings this account published (QA ACCESS-02 / ACCESS-03).
    final mine = store.myListings().where((l) => isDev ? l.kind == 'project' : l.kind == 'unit').toList();
    final active = mine.where((l) => l.active != false).toList();
    final hidden = mine.where((l) => l.active == false).toList();
    final rows = tab == 'on' ? active : hidden;
    var avail = 0.0;
    for (final l in mine) {
      avail += l.available ?? (l.units?.where((u) => !u.sold).length.toDouble() ?? 0);
    }
    final soldN = mine.where((l) => l.soldFlag).length;
    final noun = isDev ? 'مشاريع' : 'عروض';
    final addLabel = isDev ? 'أضف مشروعاً' : 'أضف عرضاً';

    final children = <Widget>[
      DeskCard(
        showScan: false,
        children: <Widget>[
          DeskJewel(value: '${mine.length}', small: noun, title: isDev ? 'دفتر المشاريع' : 'دفتر العروض'),
          DeskMeters(
            items: <DeskMeter>[
              DeskMeter(value: '${active.length}', label: 'نشط', tone: 'ok', on: tab == 'on', onTap: () => setState(() => tab = 'on')),
              DeskMeter(value: isDev ? _num(avail) : '$soldN', label: isDev ? 'وحدات متاحة' : 'مباع', tone: 'hold'),
              DeskMeter(value: '${hidden.length}', label: 'مخفي', tone: 'hot', on: tab == 'off', onTap: () => setState(() => tab = 'off')),
            ],
          ),
        ],
      ),
      DeskToolbar(title: isDev ? 'قائمة المشاريع' : 'قائمة العروض', open: false, addLabel: addLabel, onToggle: () => Nav.go(context, '/add')),
      OwnStrip(
        items: <StripItem>[
          StripItem(label: 'النشط ${active.length}', on: tab == 'on', onTap: () => setState(() => tab = 'on')),
          StripItem(label: 'غير النشط ${hidden.length}', on: tab == 'off', onTap: () => setState(() => tab = 'off')),
        ],
      ),
      if (isBroker) Tx('عروض الوسطاء تنتقل لغير النشط كل أسبوعين ما لم تُحدَّث.', style: ts(13, lh: 21, c: AppColors.stone)),
      // usage of the plan's quota and the price after it (P02)
      _QuotaStrip(lines: <String>[quotaLine(BoostKind.featured), quotaLine(BoostKind.urgentListing)]),
      if (rows.isEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Builder(
            builder: (context) {
              final eff = DefaultTextStyle.of(context).style.merge(ts(15, lh: 22.5, c: AppColors.stone));
              return Text.rich(
                TextSpan(
                  children: <InlineSpan>[
                    TextSpan(text: tab == 'on' ? (isDev ? 'لا مشاريع نشطة' : 'لا عروض نشطة') : (isDev ? 'لا مشاريع مخفية' : 'لا عروض غير نشطة')),
                    const TextSpan(text: ' — '),
                    TextSpan(
                      text: addLabel,
                      style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.forest),
                      recognizer: _addTap,
                    ),
                  ],
                ),
                style: eff,
                textAlign: TextAlign.center,
                strutStyle: StrutStyle(
                  fontFamily: eff.fontFamily,
                  fontFamilyFallback: eff.fontFamilyFallback,
                  fontSize: eff.fontSize,
                  height: eff.height,
                  leadingDistribution: TextLeadingDistribution.even,
                  forceStrutHeight: true,
                ),
              );
            },
          ),
        ),
      // the list (empty when there is nothing yet)
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < rows.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 14), _item(rows[i])],
        ],
      ),
    ];

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 16), children[i]],
        ],
      ),
    );
  }

  Future<void> _boost(Listing l, BoostKind kind) async {
    final out = await runBoost(context, kind, l.id);
    if (!mounted) return;
    if (out == BoostOutcome.failed) {
      await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يتم التمييز', message: 'تعذّر الدفع — لم يُخصم شيء.');
    }
    bump();
  }

  Widget _item(Listing l) {
    final project = l.kind == 'project';
    final now = appNow();
    final featured = l.featuredAt(now);
    final urgentOn = l.urgentAt(now);
    final live = l.active != false;
    final buttons = <Widget>[
      if (featured)
        _barBox('مميّز', icon: Lu.crown, bg: const Color(0xFFF6E7B4), fg: const Color(0xFF6B5310), onTap: () => _boost(l, BoostKind.featured))
      else
        _barBox('تمييز', icon: Lu.crown, onTap: live ? () => _boost(l, BoostKind.featured) : null),
      if (urgentOn)
        _barBox('عاجل', icon: Lu.zap, bg: kUrgentRed, fg: AppColors.white, onTap: () => _boost(l, BoostKind.urgentListing))
      else
        _barBox('عاجل', icon: Lu.zap, onTap: live ? () => _boost(l, BoostKind.urgentListing) : null),
      _barBox('تعديل', onTap: () => Nav.go(context, '/account/listings/${Uri.encodeComponent(l.id)}')),
      if (!project)
        _barBox(
          l.soldFlag ? 'إعادة' : 'مباع',
          onTap: () {
            store.patchListing(l.id, <String, Object?>{'sold': l.soldFlag ? 0 : 1, 'active': l.soldFlag ? true : (l.active ?? kUndefined)});
            bump();
          },
        ),
      if (l.active == false)
        _barBox(
          isBroker && !project ? 'تنشيط' : 'إظهار',
          onTap: () {
            store.patchListing(l.id, <String, Object?>{'active': true});
            bump();
          },
        )
      else
        _barBox(
          'إخفاء',
          onTap: () {
            store.patchListing(l.id, <String, Object?>{'active': false});
            bump();
          },
        ),
    ];

    final bar = Container(
      color: AppColors.offerPaper,
      padding: const EdgeInsets.all(8),
      child: Row(
        children: <Widget>[
          for (var i = 0; i < buttons.length; i++) ...<Widget>[if (i > 0) const SizedBox(width: 6), Expanded(child: buttons[i])],
        ],
      ),
    );

    final card = Container(
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[
          BoxShadow(color: AppColors.mistLine, spreadRadius: 1),
          ...AppShadows.card,
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(22),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            ListingCard(listing: l),
            bar,
          ],
        ),
      ),
    );
    return KeyedSubtree(
      key: ValueKey<String>(l.id),
      child: tab == 'off' ? Opacity(opacity: 0.55, child: card) : card,
    );
  }

  bool get isBroker => widget.role == 'broker';

  Widget _barBox(String label, {VoidCallback? onTap, String? icon, Color bg = AppColors.mist, Color fg = AppColors.forest}) {
    final text = Tx(
      label,
      style: ts(13, w: 600, lh: 19.5, c: fg),
      align: TextAlign.center,
      softWrap: false,
    );
    final box = Container(
      height: 40,
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(12)),
      alignment: Alignment.center,
      child: icon == null
          ? text
          : FittedBox(
              fit: BoxFit.scaleDown,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  LucideIcon(icon, size: 13, strokeWidth: 2, color: fg),
                  const SizedBox(width: 3),
                  text,
                ],
              ),
            ),
    );
    if (onTap == null) return Opacity(opacity: 0.45, child: box);
    return Semantics(
      button: true,
      label: label,
      child: Tap(onTap: onTap, child: box),
    );
  }
}

/// Small card with the usage of the plan's quotas (or the price per boost).
class _QuotaStrip extends StatelessWidget {
  const _QuotaStrip({required this.lines});

  final List<String> lines;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(16)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[for (final l in lines) Tx(l, style: ts(13, w: 600, lh: 21, c: AppColors.forest))],
      ),
    );
  }
}

String _num(double v) => v == v.roundToDouble() ? v.toInt().toString() : v.toString();
