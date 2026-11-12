// The request flow of `/add` (طلب عرض): one form describing what the user is
// looking for; brokers in the area see it and reply.
//
// Two kinds only (brief R01): a normal request is free; an urgent request
// costs Pricing.urgentRequestPrice (50 SAR) unless an included quota covers
// it. The price is confirmed before the (simulated) payment; cancelling or a
// failed payment publishes nothing and keeps everything typed in the form.

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/app_identity.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_requests.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_kit.dart';
import 'package:zon/shared/widgets/billing_flow.dart';
import 'package:zon/shared/widgets/boost.dart';

/// The search-request form.
class RequestForm extends StatefulWidget {
  const RequestForm({super.key, required this.name, required this.city, required this.onBack});

  /// Author name shown on the request.
  final String name;

  /// The user's city (pre-selects the city and the first neighbourhood).
  final String city;

  /// Leaves the form.
  final VoidCallback onBack;

  @override
  State<RequestForm> createState() => _RequestFormState();
}

class _RequestFormState extends State<RequestForm> {
  String purpose = 'sale';
  late String c = widget.city.isNotEmpty ? widget.city : 'الرياض';
  late List<String> hoods = <String>[firstHood(widget.city)];
  List<PType> types = <PType>['apartment'];
  bool openHoods = false;
  bool openTypes = false;
  String pmin = '';
  String pmax = '';
  String amin = '';
  String amax = '';
  String rmin = '2';
  String rmax = '4';
  String desc = '';

  /// The user wants an urgent request (charged or taken from the quota
  /// only when publishing succeeds).
  bool urgent = false;
  bool busy = false;
  String error = '';

  /// Id of this request — the same for every attempt of this form.
  final String _id = 'r-${DateTime.now().millisecondsSinceEpoch}';

  /// [list] with [v] added, or removed when already there.
  List<T> _toggle<T>(List<T> list, T v) => list.contains(v) ? list.where((x) => x != v).toList() : <T>[...list, v];

  /// The first problem of the form, or null when it can be published.
  String? _problem() {
    if (desc.trim().isEmpty) return 'اكتب وصف الطلب';
    return rangeError('الميزانية', pmin, pmax) ?? rangeError('المساحة', amin, amax) ?? rangeError('الغرف', rmin, rmax);
  }

  RequestItem _draft() {
    final user = store.readSession();
    return RequestItem(
      id: _id,
      purpose: purpose,
      city: c,
      neighborhoods: hoods.isNotEmpty ? hoods : <String>[kCities[c]![0]],
      types: types.isNotEmpty ? types : const <PType>['apartment'],
      priceMin: numOr0(pmin),
      priceMax: numOr0(pmax),
      areaMin: numOr0(amin),
      areaMax: numOr0(amax),
      roomsMin: numOr0(rmin),
      roomsMax: numOr0(rmax),
      description: desc.trim(),
      author: widget.name,
      role: user?.role ?? 'seeker',
      urgent: false,
    );
  }

  Future<void> _submit() async {
    if (busy) return;
    final problem = _problem();
    setState(() => error = problem ?? '');
    if (problem != null) return;
    final draft = _draft();
    if (Api.on) return _publish(draft);
    if (!urgent) {
      // A normal request is free — whatever the quota says.
      store.saveRequest(draft);
      unawaited(Nav.go(context, '/requests/$_id'));
      return;
    }
    setState(() => busy = true);
    final out = await runBoost(context, BoostKind.urgentRequest, _id, draft: draft);
    if (!mounted) return;
    setState(() => busy = false);
    if (out == BoostOutcome.applied || out == BoostOutcome.alreadyActive) {
      unawaited(Nav.go(context, '/requests/$_id'));
      return;
    }
    // Cancelled or failed: nothing was published or charged; the form keeps
    // everything the user typed.
    setState(
      () => error = out == BoostOutcome.failed
          ? 'تعذّر الدفع — لم يُنشر الطلب ولم يُخصم شيء.'
          : 'لم يُنشر الطلب. بياناتك باقية — انشره عاديًا مجانًا أو أكمل الدفع.',
    );
  }

  /// With a server: it publishes the request (a normal one is free); «عاجل» comes from the plan's balance, and
  /// without balance the request is published normal and the person is told so, with its price.
  String? _operation;
  String? _operationBody;

  Future<void> _publish(RequestItem draft) async {
    setState(() => busy = true);
    try {
      final epoch = store.serverEpoch;
      // one publish, one key: tried again after a lost answer, it is the same operation; changed, it is another
      final body = jsonEncode(<String, Object?>{...ServerRequests.form(draft), 'urgent': urgent});
      if (body != _operationBody) {
        _operationBody = body;
        _operation = 'req-${newId(24)}';
      }
      final made = await ServerRequests.create(draft, urgent: urgent, operation: _operation!);
      store.keepServerRequests(<RequestItem>[made.item], epoch: epoch);
      if (!mounted) return;
      if (made.urgent == UrgentOutcome.paymentRequired) {
        await showAppNotice(
          context,
          icon: Lu.zap,
          title: 'نُشر طلبك عاديًا',
          message: 'لا رصيد للطلبات العاجلة في باقتك، فنُشر الطلب عاديًا دون أي خصم.${made.price == null ? '' : ' سعر «عاجل» ${made.price} ريال.'}',
        );
        if (!mounted) return;
      }
      unawaited(Nav.go(context, '/requests/${made.item.id}'));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.forUser);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget purposeBtn(String label, String key) {
      final on = purpose == key;
      return Tap(
        onTap: () => setState(() => purpose = key),
        child: Container(
          height: 52,
          decoration: BoxDecoration(color: on ? AppColors.forest : kStoneBg, borderRadius: BorderRadius.circular(18)),
          alignment: Alignment.center,
          child: Tx(label, style: ts(16, w: 700, lh: 24, c: on ? AppColors.white : AppColors.deep)),
        ),
      );
    }

    Widget half(String label, String icon, String value, ValueChanged<String> set, String placeholder) => IosField(
      icon: icon,
      label: label,
      child: iosInput(
        value: value,
        placeholder: placeholder,
        dir: TextDirection.ltr,
        keyboard: TextInputType.number,
        onChanged: (v) => setState(() => set(digitsOnly(v))),
      ),
    );

    final cells = <GCell>[
      GCell(equalRow(<Widget>[purposeBtn('شراء', 'sale'), purposeBtn('إيجار', 'rent')], columns: 2, gap: 10), span: true),
      GCell(UrgentToggle(on: urgent, onTap: () => setState(() => urgent = !urgent)), span: true),
      const GCell(RequestPriceNote(), span: true),
      GCell(
        IosField(
          icon: Lu.building2,
          label: 'المدينة',
          child: iosSelect(
            value: c,
            options: optsOf(kCities.keys),
            onChanged: (v) => setState(() {
              c = v;
              hoods = <String>[kCities[v]![0]];
            }),
          ),
        ),
        span: true,
      ),
      GCell(
        _MultiPick(
          icon: Lu.mapPin,
          label: 'الأحياء',
          value: hoods.isNotEmpty ? hoods.join(' · ') : 'اختيار متعدد',
          open: openHoods,
          onToggle: () => setState(() => openHoods = !openHoods),
          items: <(String, String, bool)>[for (final h in kCities[c] ?? const <String>[]) (h, h, hoods.contains(h))],
          onItem: (h) => setState(() => hoods = _toggle(hoods, h)),
        ),
        span: true,
      ),
      GCell(
        _MultiPick(
          icon: Lu.home,
          label: 'نوع العقار',
          value: types.isNotEmpty ? types.map((t) => kTypeLabel[t] ?? t).join(' · ') : 'اختيار متعدد',
          open: openTypes,
          onToggle: () => setState(() => openTypes = !openTypes),
          items: <(String, String, bool)>[for (final t in kPropertyTypes) (t, kTypeLabel[t]!, types.contains(t))],
          onItem: (t) => setState(() => types = _toggle(types, t)),
        ),
        span: true,
      ),
      GCell(half('الميزانية من', Lu.wallet, pmin, (v) => pmin = v, '0')),
      GCell(half('إلى', Lu.wallet, pmax, (v) => pmax = v, '0')),
      GCell(half('المساحة من', Lu.maximize2, amin, (v) => amin = v, 'م²')),
      GCell(half('إلى', Lu.maximize2, amax, (v) => amax = v, 'م²')),
      GCell(half('الغرف من', Lu.bedDouble, rmin, (v) => rmin = v, '0')),
      GCell(half('إلى', Lu.bedDouble, rmax, (v) => rmax = v, '0')),
      GCell(
        IosField(
          icon: Lu.fileCheck,
          label: 'الوصف',
          textarea: true,
          child: iosTextarea(value: desc, onChanged: (v) => setState(() => desc = v), placeholder: 'اكتب الوصف بشكل واضح لجلب عروض مطابقة'),
        ),
        span: true,
      ),
    ];

    return ColoredBox(
      color: kSheetIos,
      child: Stack(
        children: <Widget>[
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: _Hero(image: typeImage(types.isNotEmpty ? types[0] : 'apartment'), onClose: widget.onBack),
          ),
          Padding(
            // the form sheet overlaps the 220 px photo header by 36 px
            padding: const EdgeInsets.only(top: 184),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Container(
                  padding: const EdgeInsets.fromLTRB(16, 18, 16, 20),
                  decoration: const BoxDecoration(
                    color: AppColors.white,
                    borderRadius: BorderRadius.vertical(top: Radius.circular(32)),
                  ),
                  child: formGrid(cells, colGap: 10, rowGap: 10),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      if (error.isNotEmpty) ...<Widget>[FormError(error), const SizedBox(height: 10)],
                      AppButton(
                        label: busy ? 'جارٍ النشر…' : (urgent ? 'نشر الطلب العاجل' : 'نشر الطلب'),
                        size: ButtonSize.large,
                        onTap: busy ? null : _submit,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 120),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The darkened photo header with the title and the close button.
class _Hero extends StatelessWidget {
  const _Hero({required this.image, required this.onClose});

  final String image;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 220,
      child: ColoredBox(
        color: AppColors.deep,
        child: Stack(
          fit: StackFit.expand,
          children: <Widget>[
            // the photo at 72 % brightness so the white title reads clearly
            ColorFiltered(
              colorFilter: const ColorFilter.matrix(<double>[
                0.72, 0, 0, 0, 0, //
                0, 0.72, 0, 0, 0, //
                0, 0, 0.72, 0, 0, //
                0, 0, 0, 1, 0,
              ]),
              child: AppImage(image),
            ),
            PositionedDirectional(
              top: 14,
              start: 16,
              child: Tap(
                onTap: onClose,
                child: const SizedBox(
                  width: 36,
                  height: 36,
                  child: Center(child: LucideIcon(Lu.x, size: 20, color: AppColors.white)),
                ),
              ),
            ),
            Positioned(
              left: 20,
              right: 20,
              bottom: 52,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Tx('طلب بحث', style: ts(28, w: 700, lh: 40, c: AppColors.white)),
                  const SizedBox(height: 6),
                  Tx('حدد مواصفات طلبك بدقة للحصول على أفضل النتائج', style: ts(14, w: 500, lh: 21, c: const Color(0xCCFFFFFF))),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Multi-select card field: shows the picked values and opens a chip list.
class _MultiPick extends StatelessWidget {
  const _MultiPick({
    required this.icon,
    required this.label,
    required this.value,
    required this.open,
    required this.onToggle,
    required this.items,
    required this.onItem,
  });

  final String icon;
  final String label;

  /// The picked values joined for display.
  final String value;
  final bool open;
  final VoidCallback onToggle;

  /// (key, label, selected) of every option.
  final List<(String, String, bool)> items;
  final ValueChanged<String> onItem;

  @override
  Widget build(BuildContext context) {
    final head = Tap(
      onTap: onToggle,
      child: Row(
        children: <Widget>[
          SizedBox(
            width: 40,
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: iosIconTile(icon, flipped: open),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: StretchRows(
              gap: 2,
              minHeight: 52,
              children: <Widget>[
                Tx(label, style: ts(14, w: 600, lh: 21, c: AppColors.deep)),
                Tx(value, style: kIosText, maxLines: 1, softWrap: false, overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Transform.rotate(
            angle: open ? pi : 0,
            child: const LucideIcon(Lu.chevronDown, size: 18, color: AppColors.deep),
          ),
        ],
      ),
    );
    return Container(
      constraints: const BoxConstraints(minHeight: 76),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: kStoneBox,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          head,
          if (open) ...<Widget>[
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: <Widget>[
                for (final it in items)
                  Tap(
                    onTap: () => onItem(it.$1),
                    child: Container(
                      height: 32,
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      decoration: BoxDecoration(color: it.$3 ? AppColors.forest : AppColors.white, borderRadius: BorderRadius.circular(999)),
                      child: Center(
                        widthFactor: 1,
                        child: Tx(it.$2, style: ts(14, w: 600, lh: 21, c: it.$3 ? AppColors.white : AppColors.deep), softWrap: false),
                      ),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The two kinds of request and what they cost (brief R01 / P03): shown in
/// the request forms, not repeated on the plans page.
class RequestPriceNote extends StatelessWidget {
  const RequestPriceNote({super.key});

  @override
  Widget build(BuildContext context) {
    final left = billing.remaining(BoostKind.urgentRequest);
    Widget cell(String title, String value, {bool strong = false}) => Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Tx(title, style: ts(13, w: 600, lh: 19.5, c: AppColors.muted)),
          Tx(value, style: ts(14, w: 700, lh: 21, c: strong ? AppColors.forest : AppColors.deep)),
        ],
      ),
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(14)),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          cell('طلب عادي', 'مجاني', strong: true),
          const SizedBox(width: 12),
          cell('طلب عاجل', left > 0 ? 'من رصيدك — المتبقي: ${boostCount(BoostKind.urgentRequest, left)}' : riyals(Pricing.urgentRequestPrice)),
        ],
      ),
    );
  }
}
