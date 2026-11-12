// طلباتي — the account's own search requests: edit, make urgent.
//
// A normal request is free. Making one urgent uses the plan's quota of
// urgent requests when there is one, else costs Pricing.urgentRequestPrice
// (50 SAR) after a confirmation; cancelling or a failed payment changes
// nothing (brief R01).
//
// With a server: «طلباتي» and the broker's area are the server's (GET /requests/mine, /requests/area); an edit, «عاجل»
// and «أغلق الطلب» are its too. «عاجل» comes from the plan's balance; without balance the server says its price and
// nothing changes. A closed request stays here, off the board.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_requests.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/desk.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/request_form.dart' show RequestPriceNote;
import 'package:zon/shared/widgets/ask_card.dart';
import 'package:zon/shared/widgets/billing_flow.dart';
import 'package:zon/shared/widgets/boost.dart';

/// طلباتي — the user's own search requests.
class MyRequestsView extends StatefulWidget {
  const MyRequestsView({super.key, required this.city, required this.role});

  final String city;
  final String role;

  @override
  State<MyRequestsView> createState() => _MyRequestsViewState();
}

class _MyRequestsViewState extends State<MyRequestsView> {
  String tab = 'mine';
  String? editId;
  final TextEditingController _q = TextEditingController();
  String purpose = 'all';
  String fcity = 'all';
  String hood = 'all';
  String type = 'all';

  /// With a server: the ids of «طلباتي» and of the broker's area, as it sent them (null until they come).
  List<String>? _mineIds;
  List<String>? _areaIds;
  bool _areaLocked = true;
  String? _loadError;

  @override
  void initState() {
    super.initState();
    _q.addListener(() => setState(() {}));
    if (Api.on) unawaited(_load());
  }

  Future<void> _load() async {
    setState(() => _loadError = null);
    try {
      final mine = await ServerRequests.mine();
      final area = widget.role == 'broker' ? await ServerRequests.area() : null;
      store.keepServerRequests(<RequestItem>[...mine, ...?area?.items]);
      if (!mounted) return;
      setState(() {
        _mineIds = <String>[for (final r in mine) r.id];
        _areaIds = <String>[for (final r in area?.items ?? const <RequestItem>[]) r.id];
        _areaLocked = area?.locked ?? true;
      });
    } on ApiError catch (e) {
      if (mounted) setState(() => _loadError = e.forUser);
    }
  }

  List<RequestItem> _rows(List<String> ids) => <RequestItem>[for (final id in ids) ?store.requestById(id)];

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final broker = widget.role == 'broker';
    final server = Api.on;
    if (server && _mineIds == null) {
      return Padding(
        padding: const EdgeInsets.all(20),
        child: _loadError == null
            ? const LoadingBox()
            : EmptyBox(
                title: 'تعذّر تحميل طلباتك',
                text: _loadError!,
                textStyle: emptyTextStyle(),
                action: 'أعد المحاولة',
                onAction: () => unawaited(_load()),
              ),
      );
    }
    // Mine = written by this account (not matched by display name).
    final mine = server ? _rows(_mineIds!) : store.myRequests();
    final area = server
        ? _rows(_areaIds ?? const <String>[])
        : store.allRequests().where((r) => r.city == widget.city && !store.ownsRequest(r)).toList();
    final pool = broker && tab == 'area' ? area : mine;
    final saleN = pool.where((r) => r.purpose == 'sale').length;
    final rentN = pool.where((r) => r.purpose == 'rent').length;
    final urgentN = pool.where((r) => r.urgent == true).length;
    final hoods = fcity == 'all' ? const <String>[] : (kCities[fcity] ?? const <String>[]);
    final ringN = broker && tab == 'area' ? area.length : mine.length;
    final ringLabel = broker && tab == 'area' ? 'عملاء' : 'طلباتي';
    final q = _q.text.trim();
    final rows = pool.where((r) {
      if (purpose == 'sale' && r.purpose != 'sale') return false;
      if (purpose == 'rent' && r.purpose != 'rent') return false;
      if (purpose == 'urgent' && r.urgent != true) return false;
      if (fcity != 'all' && r.city != fcity) return false;
      if (hood != 'all' && !r.neighborhoods.contains(hood)) return false;
      if (type != 'all' && !r.types.contains(type)) return false;
      if (q.isNotEmpty && !'${r.description} ${r.city} ${r.neighborhoods.join(' ')} ${r.author}'.contains(q)) return false;
      return true;
    }).toList();
    final locked = broker && (server ? _areaLocked : !billing.isSubscribed());
    RequestItem? editing;
    for (final r in mine) {
      if (r.id == editId) editing = r;
    }
    if (editing != null) {
      return AskEdit(
        key: ValueKey<String>(editing.id),
        item: editing,
        onCancel: () => setState(() => editId = null),
        onDone: () => setState(() => editId = null),
      );
    }

    final children = <Widget>[
      DeskCard(
        showScan: false,
        children: <Widget>[
          DeskJewel(value: '$ringN', small: ringLabel, title: 'دفتر الطلبات'),
          CrmMeters(
            tiles: <(Widget, String)>[
              (
                MeterSelect(
                  value: fcity,
                  color: kDeskAccent,
                  options: <Opt>[const Opt('all', 'الكل'), ...optsOf(kCities.keys)],
                  onChanged: (v) => setState(() {
                    fcity = v;
                    hood = 'all';
                  }),
                ),
                'مدينة',
              ),
              (
                MeterSelect(
                  value: hood,
                  color: kDeskAccent,
                  enabled: fcity != 'all',
                  options: <Opt>[const Opt('all', 'الكل'), ...optsOf(hoods)],
                  onChanged: (v) => setState(() => hood = v),
                ),
                'حي',
              ),
              (
                MeterSelect(
                  value: type,
                  color: kDeskAccent,
                  options: <Opt>[const Opt('all', 'الكل'), for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
                  onChanged: (v) => setState(() => type = v),
                ),
                'نوع عقار',
              ),
            ],
          ),
        ],
      ),
      Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: <Widget>[
          Tx('قائمة الطلبات', style: ts(16, w: 700, lh: 24, c: AppColors.forest)),
          if (tab == 'mine')
            Tap(
              onTap: () => Nav.go(context, '/add'),
              child: Container(
                height: 36,
                padding: const EdgeInsets.symmetric(horizontal: 14),
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    const LucideIcon(Lu.plus, size: 16, color: AppColors.white),
                    const SizedBox(width: 6),
                    Tx('أضف طلباً', style: ts(14, w: 700, lh: 21, c: AppColors.white)),
                  ],
                ),
              ),
            ),
        ],
      ),
      FindBox(controller: _q),
      if (broker)
        OwnStrip(
          items: <StripItem>[
            StripItem(
              label: 'طلباتي ${mine.length}',
              on: tab == 'mine',
              onTap: () => setState(() {
                tab = 'mine';
                purpose = 'all';
              }),
            ),
            StripItem(
              label: 'عملاء ${area.length}',
              on: tab == 'area',
              onTap: () => setState(() {
                tab = 'area';
                purpose = 'all';
              }),
            ),
          ],
        ),
      OwnStrip(
        items: <StripItem>[
          for (final e in <(String, String, int)>[
            ('all', 'الكل', pool.length),
            ('sale', 'شراء', saleN),
            ('rent', 'إيجار', rentN),
            ('urgent', 'عاجل', urgentN),
          ])
            StripItem(label: e.$1 == 'all' ? e.$2 : '${e.$2} ${e.$3}', on: purpose == e.$1, onTap: () => setState(() => purpose = e.$1)),
        ],
      ),
      if (tab == 'mine') const RequestPriceNote(),
      if (tab == 'area' && locked)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
          child: Tx('العدد ظاهر. الاسم والجوال والتواصل ضمن باقة الوسيط.', style: ts(16, lh: 25, c: AppColors.muted)),
        ),
    ];

    if (rows.isNotEmpty) {
      for (final r in rows) {
        Widget? actions;
        if (tab == 'mine' && r.closed == true) {
          actions = Container(
            height: 48,
            decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
            alignment: Alignment.center,
            child: Tx('طلب مغلق — لا يظهر في «الطلبات»', style: ts(15, w: 600, lh: 22, c: AppColors.muted)),
          );
        } else if (tab == 'mine') {
          actions = Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              _crm('تعديل الطلب', () => setState(() => editId = r.id)),
              const SizedBox(height: 8),
              // An urgent request stays urgent (nothing to refund or re-charge).
              _crm(r.urgent == true ? 'طلب عاجل' : 'اجعله طلبًا عاجلًا', r.urgent == true ? null : () => _makeUrgent(r), on: r.urgent == true),
              if (server) ...<Widget>[const SizedBox(height: 8), _crm('أغلق الطلب', () => _close(r))],
            ],
          );
        } else if (tab == 'area' && locked) {
          actions = AppButton(label: 'فعّل الخدمة لفتح الطلب', tone: ButtonTone.tonal, onTap: () => Nav.go(context, '/account/plans'));
        }
        children.add(AskCard(item: r, own: tab == 'mine', locked: tab == 'area' && locked, actions: actions));
      }
    } else {
      children.add(
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
            child: Tx(tab == 'mine' ? 'لا طلبات منشورة' : 'لا طلبات في ${widget.city}', style: ts(16, lh: 25, c: AppColors.muted)),
          ),
        ),
      );
    }

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

  Future<void> _makeUrgent(RequestItem r) async {
    if (Api.on) return _makeUrgentOnServer(r);
    final out = await runBoost(context, BoostKind.urgentRequest, r.id);
    if (!mounted) return;
    if (out == BoostOutcome.failed) {
      await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يتحول الطلب إلى عاجل', message: 'تعذّر الدفع — لم يُخصم شيء.');
    }
    setState(() {});
  }

  /// «عاجل» from the plan's balance; without it the server's price, and the plans to get a balance.
  Future<void> _makeUrgentOnServer(RequestItem r) async {
    try {
      store.keepServerRequests(<RequestItem>[await ServerRequests.makeUrgent(r.id)]);
      if (mounted) setState(() {});
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.status == 402) {
        final price = ServerRequests.priceOf(e);
        final plans = await showAppDialog(
          context,
          icon: Lu.zap,
          title: 'لا رصيد للطلبات العاجلة',
          message: 'لم يتغير الطلب ولم يُخصم شيء.${price == null ? '' : ' سعر «عاجل» $price ريال.'} الرصيد يأتي مع الباقة.',
          confirmLabel: 'الباقات',
        );
        if (plans && mounted) unawaited(Nav.go(context, '/account/plans'));
      } else {
        await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يتحول الطلب إلى عاجل', message: e.forUser);
      }
    }
  }

  /// «أغلق الطلب»: off the board for good (it stays here, closed).
  Future<void> _close(RequestItem r) async {
    final ok = await showAppDialog(
      context,
      icon: Lu.circleAlert,
      title: 'إغلاق الطلب',
      message: 'لن يظهر في «الطلبات» ولن يصلك عليه وسطاء بعد الآن. لا يُعاد فتحه.',
      confirmLabel: 'أغلق الطلب',
      destructive: true,
    );
    if (!ok) return;
    try {
      store.keepServerRequests(<RequestItem>[await ServerRequests.close(r.id)]);
      if (mounted) setState(() {});
    } on ApiError catch (e) {
      if (mounted) await showAppNotice(context, icon: Lu.circleAlert, title: 'لم يُغلق الطلب', message: e.forUser);
    }
  }

  /// An action under one of my requests; [on] marks an active state (urgent).
  Widget _crm(String label, VoidCallback? onTap, {bool on = false}) {
    if (on) {
      // the urgent state: shown, not tappable
      return Container(
        height: 48,
        decoration: BoxDecoration(color: kUrgentRed, borderRadius: BorderRadius.circular(14)),
        alignment: Alignment.center,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const LucideIcon(Lu.zap, size: 18, color: AppColors.white),
            const SizedBox(width: 6),
            Tx(label, style: ts(16, w: 700, lh: 24, c: AppColors.white)),
          ],
        ),
      );
    }
    return AppButton(label: label, tone: ButtonTone.tonal, icon: label.contains('عاجل') ? Lu.zap : null, onTap: onTap);
  }
}

/// Edit one of my requests.
class AskEdit extends StatefulWidget {
  const AskEdit({super.key, required this.item, required this.onDone, required this.onCancel});

  final RequestItem item;
  final VoidCallback onDone;
  final VoidCallback onCancel;

  @override
  State<AskEdit> createState() => _AskEditState();
}

class _AskEditState extends State<AskEdit> {
  late String purpose = widget.item.purpose;
  late String city = widget.item.city;
  late List<String> hoods = widget.item.neighborhoods.isNotEmpty
      ? List<String>.of(widget.item.neighborhoods)
      : <String>[
          (kCities[widget.item.city] ?? const <String>['']).first,
        ];
  late List<String> types = widget.item.types.isNotEmpty ? List<String>.of(widget.item.types) : <String>['villa'];
  bool openHoods = false;
  bool openTypes = false;
  late final TextEditingController pmin = TextEditingController(text: _num(widget.item.priceMin));
  late final TextEditingController pmax = TextEditingController(text: _num(widget.item.priceMax));
  late final TextEditingController amin = TextEditingController(text: _num(widget.item.areaMin));
  late final TextEditingController amax = TextEditingController(text: _num(widget.item.areaMax));
  late final TextEditingController rmin = TextEditingController(text: _num(widget.item.roomsMin));
  late final TextEditingController rmax = TextEditingController(text: _num(widget.item.roomsMax));
  late final TextEditingController desc = TextEditingController(text: widget.item.description);

  /// Already urgent: stays urgent (nothing is charged again).
  late final bool wasUrgent = widget.item.urgent == true;
  late bool urgent = wasUrgent;
  bool busy = false;
  String error = '';

  /// The number as text, or empty when it is 0 / missing.
  static String _num(double v) {
    if (v == 0 || v.isNaN) return '';
    return v == v.roundToDouble() ? v.toInt().toString() : v.toString();
  }

  /// Arabic digits accepted (QA REQ-03).
  static double _n(String s) => parseUserNumber(s) ?? 0;

  @override
  void dispose() {
    for (final c in <TextEditingController>[pmin, pmax, amin, amax, rmin, rmax, desc]) {
      c.dispose();
    }
    super.dispose();
  }

  List<String> _toggle(List<String> arr, String v) => arr.contains(v) ? arr.where((x) => x != v).toList() : <String>[...arr, v];

  RequestItem _edited() => widget.item.merged(<String, Object?>{
    'purpose': purpose,
    'city': city,
    'neighborhoods': hoods.isNotEmpty
        ? hoods
        : <String>[
            (kCities[city] ?? const <String>['']).first,
          ],
    'types': types.isNotEmpty ? types : <String>['villa'],
    'priceMin': _n(pmin.text),
    'priceMax': _n(pmax.text),
    'areaMin': _n(amin.text),
    'areaMax': _n(amax.text),
    'roomsMin': _n(rmin.text),
    'roomsMax': _n(rmax.text),
    'description': desc.text.trim(),
    'urgent': wasUrgent,
  });

  Future<void> _submit() async {
    if (busy) return;
    final problem =
        (desc.text.trim().isEmpty ? 'اكتب وصف الطلب' : null) ??
        rangeError('الميزانية', pmin.text, pmax.text) ??
        rangeError('المساحة', amin.text, amax.text) ??
        rangeError('الغرف', rmin.text, rmax.text);
    setState(() => error = problem ?? '');
    if (problem != null) return;
    final edited = _edited();
    if (Api.on) return _saveOnServer(edited);
    if (!urgent || wasUrgent) {
      store.saveRequest(edited);
      widget.onDone();
      return;
    }
    // Turning a normal request urgent: quota first, else the confirmed price.
    setState(() => busy = true);
    final out = await runBoost(context, BoostKind.urgentRequest, edited.id, draft: edited);
    if (!mounted) return;
    setState(() => busy = false);
    if (out == BoostOutcome.applied || out == BoostOutcome.alreadyActive) {
      widget.onDone();
      return;
    }
    setState(
      () => error = out == BoostOutcome.failed
          ? 'تعذّر الدفع — لم يتغير الطلب ولم يُخصم شيء.'
          : 'لم يُحفظ التعديل بعد. ألغِ «طلب عاجل» للحفظ مجانًا أو أكمل الدفع.',
    );
  }

  /// The edit is the server's; «عاجل» turned on comes after it, from the plan's balance (else its price).
  Future<void> _saveOnServer(RequestItem edited) async {
    setState(() => busy = true);
    try {
      store.keepServerRequests(<RequestItem>[await ServerRequests.update(edited.id, edited)]);
      if (urgent && !wasUrgent) {
        try {
          store.keepServerRequests(<RequestItem>[await ServerRequests.makeUrgent(edited.id)]);
        } on ApiError catch (e) {
          if (e.status != 402) rethrow;
          final price = ServerRequests.priceOf(e);
          if (mounted) {
            setState(() {
              urgent = false;
              error = 'حُفظ التعديل، وبقي الطلب عاديًا: لا رصيد للطلبات العاجلة في باقتك.${price == null ? '' : ' سعر «عاجل» $price ريال.'}';
            });
          }
          return;
        }
      }
      widget.onDone();
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.forUser);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget _label(String t, Widget child) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: <Widget>[
      Tx(t, style: ts(14, w: 600, lh: 21, c: AppColors.muted)),
      const SizedBox(height: 6),
      child,
    ],
  );

  Widget _numField(TextEditingController c) => FieldBox(
    controller: c,
    style: ts(18, lh: 27, c: AppColors.ink),
    height: 52,
    padding: const EdgeInsets.symmetric(horizontal: 14),
    decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
    dir: TextDirection.ltr,
    keyboardType: TextInputType.number,
    inputFormatters: const <AsciiDigitsFormatter>[AsciiDigitsFormatter()],
  );

  Widget _pair(String a, TextEditingController ca, String b, TextEditingController cb) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: <Widget>[
      Expanded(child: _label(a, _numField(ca))),
      const SizedBox(width: 8),
      Expanded(child: _label(b, _numField(cb))),
    ],
  );

  Widget _pick({
    required String icon,
    required String title,
    required String sub,
    required bool open,
    required VoidCallback onToggle,
    required List<String> selectedLabels,
    required List<Opt> all,
    required Set<String> on,
    required ValueChanged<String> onPick,
  }) {
    return Container(
      decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(16)),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tap(
            onTap: onToggle,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              child: Row(
                children: <Widget>[
                  SizedBox(
                    width: 22,
                    child: Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: LucideIcon(icon, size: 20, strokeWidth: 1.8, color: AppColors.muted),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Tx(title, style: ts(18, w: 600, lh: 27, c: AppColors.deep)),
                        const SizedBox(height: 2),
                        Tx(sub, style: ts(14, w: 500, lh: 21, c: AppColors.muted)),
                      ],
                    ),
                  ),
                  const SizedBox(width: 10),
                  Container(
                    constraints: const BoxConstraints(minWidth: 28),
                    height: 28,
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                    alignment: Alignment.center,
                    child: Tx('${on.length}', style: ts(14, w: 700, lh: 21, c: AppColors.white)),
                  ),
                  const SizedBox(width: 10),
                  SizedBox(
                    width: 18,
                    height: 20,
                    child: OverflowBox(
                      maxWidth: 20,
                      alignment: AlignmentDirectional.centerStart,
                      child: Transform.rotate(
                        angle: open ? 3.14159265 : 0,
                        child: const LucideIcon(Lu.chevronDown, size: 20, color: AppColors.muted),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (!open && selectedLabels.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: <Widget>[
                  for (final l in selectedLabels)
                    Container(
                      height: 28,
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[Tx(l, style: ts(14, w: 600, lh: 21, c: AppColors.white))],
                      ),
                    ),
                ],
              ),
            ),
          if (open)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: <Widget>[
                  for (final o in all)
                    Tap(
                      onTap: () => onPick(o.value),
                      child: Container(
                        height: 36,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        decoration: BoxDecoration(
                          color: on.contains(o.value) ? AppColors.forest : AppColors.white,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        foregroundDecoration: on.contains(o.value)
                            ? null
                            : BoxDecoration(
                                borderRadius: BorderRadius.circular(999),
                                border: Border.all(color: const Color(0x1F121214)),
                              ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[Tx(o.label, style: ts(15, w: 600, lh: 22.5, c: on.contains(o.value) ? AppColors.white : AppColors.ink))],
                        ),
                      ),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final pad = <Widget>[
      // buy / rent choice
      Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(16)),
        child: Row(
          children: <Widget>[
            for (final e in const <List<String>>[
              <String>['sale', 'شراء'],
              <String>['rent', 'إيجار'],
            ]) ...<Widget>[
              if (e[0] == 'rent') const SizedBox(width: 4),
              Expanded(
                child: Tap(
                  onTap: () => setState(() => purpose = e[0]),
                  child: Container(
                    height: 44,
                    decoration: BoxDecoration(
                      color: purpose == e[0] ? AppColors.forest : const Color(0x00000000),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    alignment: Alignment.center,
                    child: Tx(e[1], style: ts(18, w: 600, lh: 27, c: purpose == e[0] ? AppColors.white : AppColors.muted)),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          UrgentToggle(on: urgent, locked: wasUrgent, onTap: () => setState(() => urgent = !urgent)),
          const SizedBox(height: 8),
          const RequestPriceNote(),
        ],
      ),
      _label(
        'المدينة',
        SelectBox(
          value: city,
          options: optsOf(kCities.keys),
          onChanged: (v) => setState(() {
            city = v;
            hoods = <String>[
              (kCities[v] ?? const <String>['']).first,
            ];
          }),
          style: ts(18, lh: 25.5, c: AppColors.ink),
          height: 52,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
        ),
      ),
      _pick(
        icon: Lu.mapPin,
        title: 'الأحياء',
        sub: 'اختيار متعدد',
        open: openHoods,
        onToggle: () => setState(() => openHoods = !openHoods),
        selectedLabels: hoods,
        all: optsOf(kCities[city] ?? const <String>[]),
        on: hoods.toSet(),
        onPick: (h) => setState(() => hoods = _toggle(hoods, h)),
      ),
      _pick(
        icon: Lu.home,
        title: 'نوع العقار',
        sub: 'واحد أو أكثر',
        open: openTypes,
        onToggle: () => setState(() => openTypes = !openTypes),
        selectedLabels: <String>[for (final t in types) kTypeLabel[t] ?? t],
        all: <Opt>[for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
        on: types.toSet(),
        onPick: (t) => setState(() => types = _toggle(types, t)),
      ),
      _pair('الميزانية من', pmin, 'إلى', pmax),
      _pair('المساحة من م²', amin, 'إلى', amax),
      _pair('الغرف من', rmin, 'إلى', rmax),
      _label(
        'وصف نص حر',
        FieldBox(
          controller: desc,
          placeholder: 'اكتب الوصف بشكل واضح',
          style: ts(18, lh: 26, c: AppColors.ink),
          height: 110,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(14)),
          multiline: true,
        ),
      ),
      if (error.isNotEmpty) FormError(error),
      Row(
        children: <Widget>[
          Expanded(child: _btn('إلغاء', false, busy ? null : widget.onCancel)),
          const SizedBox(width: 8),
          Expanded(child: _btn(busy ? 'جارٍ الحفظ…' : 'حفظ التعديل', true, busy ? null : _submit)),
        ],
      ),
    ];

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 48),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          BackLink(label: 'طلباتي', onTap: widget.onCancel),
          const SizedBox(height: 16),
          Container(
            decoration: BoxDecoration(
              color: AppColors.white,
              borderRadius: BorderRadius.circular(26),
              boxShadow: const <BoxShadow>[BoxShadow(color: Color(0x1A121214), spreadRadius: 1)],
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Padding(
                  padding: const EdgeInsets.fromLTRB(18, 18, 18, 8),
                  child: Tx('تعديل الطلب', style: ts(22, w: 700, lh: 33, c: AppColors.deep)),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(14, 8, 14, 18),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      for (var i = 0; i < pad.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 12), pad[i]],
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _btn(String label, bool solid, VoidCallback? onTap) =>
      AppButton(label: label, tone: solid ? ButtonTone.primary : ButtonTone.tonal, onTap: onTap);
}
