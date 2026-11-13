// العروض والمشاريع — the account's own listings / projects (active / hidden)
// with the boosts «مميز» and «عاجل» (quota first, then a confirmed and
// simulated payment), edit, sold and hide / show actions.
//
// With a server, a broker's or an owner's listings are its (GET /listings/mine, ServerMyListings): every state,
// «نشط» being the published ones; hiding is a pause, «مباع» is final (asked first), «تمييز» and «عاجل» come from
// the plan's balance (without it, the price — paying in the app comes with the plans). A listing whose wording
// waits for him opens its review.
//
// A developer's projects with a server are its too (GET /projects/mine, ServerMyProjects): a draft opens its
// wording review, which publishes; hiding is a pause, showing asks publishing again. Editing a project, and
// «تمييز» · «عاجل» for one, are not on the server yet: their buttons are off rather than local.

import 'dart:async';

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/server/server_my_projects.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/sale_price_dialog.dart';
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

  /// With a server: the ids of «عروضي» as it sent them (null until they come), for the account that asked.
  List<String>? _ids;
  String? _loadError;
  bool get _server => Api.on;
  bool get _projects => widget.role == 'developer';

  @override
  void initState() {
    super.initState();
    if (_server) unawaited(_load());
  }

  Future<void> _load() async {
    final epoch = store.serverEpoch;
    setState(() => _loadError = null);
    try {
      final rows = _projects ? await ServerMyProjects.mine() : await ServerMyListings.mine();
      if (!mounted || epoch != store.serverEpoch) return; // signed out, or another account, meanwhile
      store.keepServer(rows, epoch: epoch);
      setState(() => _ids = <String>[for (final l in rows) l.id]);
    } on ApiError catch (e) {
      if (mounted && epoch == store.serverEpoch) setState(() => _loadError = e.forUser);
    }
  }

  /// A change made on the server, shown as it answered (for the account that asked).
  Future<void> _change(Listing l, Future<Listing> Function(String id) act, String failed) async {
    final epoch = store.serverEpoch;
    try {
      store.keepServer(<Listing>[await act(l.id)], epoch: epoch, detailed: true);
      if (mounted) bump();
    } on ApiError catch (e) {
      if (mounted && epoch == store.serverEpoch) await showAppNotice(context, icon: Lu.circleAlert, title: failed, message: e.forUser);
    }
  }

  /// «مباع» with a server is final: asked first.
  Future<void> _sold(Listing l) async {
    if (_server && widget.role == 'owner') {
      final price = await askSalePrice(context, listed: l.price);
      if (price != null && mounted) await _change(l, (id) => ServerMyListings.sold(id, salePrice: price), 'لم يُسجَّل البيع');
      return;
    }
    final ok = await showAppDialog(
      context,
      icon: Lu.circleAlert,
      title: 'تأكيد البيع',
      message:
          'يُسجَّل العرض مباعاً ويخرج من البحث نهائياً، ولا يُعاد نشره.'
          '${widget.role == 'owner' ? ' ويُستحق سعي زون المتفق عليه في عقد الوساطة.' : ''}',
      confirmLabel: 'تم البيع',
    );
    if (ok && mounted) await _change(l, ServerMyListings.sold, 'لم يُسجَّل البيع');
  }

  /// «تمييز» or «عاجل» from the plan's balance; without it, its price and the plans.
  Future<void> _boostOnServer(Listing l, BoostKind kind) async {
    final epoch = store.serverEpoch;
    try {
      await ServerMyListings.boost(l.id, kind == BoostKind.featured ? 'featured' : 'urgent_listing');
      if (mounted) unawaited(_load());
    } on ApiError catch (e) {
      if (!mounted || epoch != store.serverEpoch) return;
      if (e.status == 402) {
        final price = ServerMyListings.priceOf(e);
        final plans = await showAppDialog(
          context,
          icon: kind == BoostKind.featured ? Lu.crown : Lu.zap,
          title: kind == BoostKind.featured ? 'لا رصيد للتمييز' : 'لا رصيد للعروض العاجلة',
          message: 'لم يتغير العرض ولم يُخصم شيء.${price == null ? '' : ' السعر $price ريال.'} الرصيد يأتي مع الباقة.',
          confirmLabel: 'الباقات',
        );
        if (plans && mounted) unawaited(Nav.go(context, '/account/plans'));
      } else {
        await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يتم التمييز', message: e.forUser);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDev = widget.role == 'developer';
    final isBroker = widget.role == 'broker';
    if (_server && _ids == null) {
      return Padding(
        padding: const EdgeInsets.all(20),
        child: _loadError == null
            ? const LoadingBox()
            : EmptyBox(
                title: 'تعذّر تحميل عروضك',
                text: _loadError!,
                textStyle: emptyTextStyle(),
                action: 'أعد المحاولة',
                onAction: () => unawaited(_load()),
              ),
      );
    }
    // Only the listings this account published (QA ACCESS-02 / ACCESS-03).
    final mine = _server
        ? <Listing>[for (final id in _ids!) ?store.listingById(id)]
        : store.myListings().where((l) => isDev ? l.kind == 'project' : l.kind == 'unit').toList();
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
      // usage of the plan's quota and the price after it (P02) — this phone's; the server's comes with the plans
      if (!_server) _QuotaStrip(lines: <String>[quotaLine(BoostKind.featured), quotaLine(BoostKind.urgentListing)]),
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
    if (_server) return _boostOnServer(l, kind);
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
    final noProjectOnServer = _server && project; // not on the server yet: off, not local
    final buttons = <Widget>[
      if (noProjectOnServer) ...<Widget>[_barBox('تمييز', icon: Lu.crown), _barBox('عاجل', icon: Lu.zap)] else if (featured)
        _barBox('مميّز', icon: Lu.crown, bg: const Color(0xFFF6E7B4), fg: const Color(0xFF6B5310), onTap: () => _boost(l, BoostKind.featured))
      else
        _barBox('تمييز', icon: Lu.crown, onTap: live ? () => _boost(l, BoostKind.featured) : null),
      if (!noProjectOnServer)
        if (urgentOn)
          _barBox('عاجل', icon: Lu.zap, bg: kUrgentRed, fg: AppColors.white, onTap: () => _boost(l, BoostKind.urgentListing))
        else
          _barBox('عاجل', icon: Lu.zap, onTap: live ? () => _boost(l, BoostKind.urgentListing) : null),
      _barBox('تعديل', onTap: noProjectOnServer ? null : () => Nav.go(context, '/account/listings/${Uri.encodeComponent(l.id)}')),
      if (!noProjectOnServer)
        if (_server && l.soldFlag)
          _barBox('مباع')
        else if (_server)
          _barBox('مباع', onTap: _resumable(l) || l.status == 'published' ? () => _sold(l) : null)
        else if (!project)
          _barBox(
            l.soldFlag ? 'إعادة' : 'مباع',
            onTap: () {
              store.patchListing(l.id, <String, Object?>{'sold': l.soldFlag ? 0 : 1, 'active': l.soldFlag ? true : (l.active ?? kUndefined)});
              bump();
            },
          ),
      if (_server && l.status == 'published')
        _barBox(
          'إخفاء',
          onTap: () => _change(l, project ? ServerMyProjects.pause : ServerMyListings.pause, project ? 'لم يُخفَ المشروع' : 'لم يُخفَ العرض'),
        )
      else if (_server)
        _barBox(
          isBroker ? 'تنشيط' : 'إظهار',
          onTap: _resumable(l)
              ? () => _change(l, project ? ServerMyProjects.resume : ServerMyListings.resume, project ? 'لم يُظهَر المشروع' : 'لم يُنشَّط العرض')
              : null,
        )
      else if (l.active == false)
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
            if (_server && l.status != 'published') _statusLine(l),
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

  /// Only what the advertiser stopped himself, or the two weeks without an update, he starts again; the rest
  /// (moderation, an expired licence, the wording) comes back by its own way.
  static bool _resumable(Listing l) => l.status == 'paused' || l.status == 'inactive';

  static const Map<String, String> _statusLabels = <String, String>{
    'indexing': 'قيد الصياغة — يُنشر حين تجهز',
    'pending_review': 'صياغة الوصف بانتظار قرارك',
    'paused': 'مخفي — أوقفته أنت',
    'inactive': 'غير نشط — لم يُحدَّث منذ أسبوعين',
    'sold': 'تم البيع',
    'hidden': 'مخفي من فريق زون',
    'rejected': 'مرفوض من فريق زون',
    'licence_expired': 'انتهى الترخيص الإعلاني',
    'draft': 'مسودة — راجع الأوصاف وانشر',
  };

  /// Where a listing that is not published is, and the way back when there is one (the wording's review).
  Widget _statusLine(Listing l) {
    final review = l.status == 'pending_review' || (l.kind == 'project' && l.status == 'draft');
    final line = Container(
      key: ValueKey<String>('status-${l.id}'),
      color: review ? const Color(0xFFF6E7B4) : AppColors.mist,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Tx(_statusLabels[l.status] ?? '', style: ts(14, w: 600, lh: 20, c: review ? const Color(0xFF6B5310) : AppColors.forest)),
          ),
          if (review) const LucideIcon(Lu.chevronLeft, size: 18, color: Color(0xFF6B5310)),
        ],
      ),
    );
    final to = l.kind == 'project' ? '/account/projects/${ServerListings.rawId(l.id)}/review' : '/account/listings/${l.id}/review';
    return review ? Tap(onTap: () => Nav.go(context, to), child: line) : line;
  }

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
