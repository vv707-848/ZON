// البحث الدلالي — free-text search over listings and requests with
// "match / no match" feedback.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/widgets/service_form.dart';
import 'package:zon/shared/widgets/dictation_field.dart';
import 'package:zon/shared/widgets/match_kit.dart';

/// البحث الدلالي — free-text search over listings and requests.
class SemanticSearchView extends StatefulWidget {
  const SemanticSearchView({super.key, required this.role});

  final String role;

  @override
  State<SemanticSearchView> createState() => _SemanticSearchViewState();
}

class _SemanticSearchViewState extends State<SemanticSearchView> with SemMarksState<SemanticSearchView> {
  /// "listings" | "requests"
  String target = 'listings';
  String city = '';
  String hood = '';
  String type = '';
  final TextEditingController q = TextEditingController();
  bool scan = false;

  /// `List<Listing>` or `List<RequestItem>`; null → no search yet.
  List<Object>? rows;

  @override
  void initState() {
    super.initState();
    q.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    q.dispose();
    super.dispose();
  }

  bool get ready => city.isNotEmpty && hood.isNotEmpty && type.isNotEmpty;

  void run() {
    if (scan || !ready) return;
    setState(() => scan = true);
    final t = target;
    final c = city;
    final h = hood;
    final ty = type;
    final text = q.text;
    final marks = mark;
    Future<void>.delayed(const Duration(milliseconds: 3400), () {
      if (!mounted) return;
      List<Object> list;
      if (t == 'listings') {
        final pool = store.allListings().where((l) => l.city == c && l.neighborhood == h && l.propertyType == ty).toList();
        list = sortByScore<Listing>(pool, (l) => scoreByFeedback(listingHay(l), text, marks, l.id));
      } else {
        final pool = store.allRequests().where((r) => r.city == c && r.neighborhoods.contains(h) && r.types.contains(ty)).toList();
        list = sortByScore<RequestItem>(
          pool,
          (r) => scoreByFeedback(
            '${r.city} ${r.neighborhoods.join(' ')} ${r.types.map((x) => kTypeLabel[x]).join(' ')} ${r.description}',
            text,
            marks,
            r.id,
          ),
        );
      }
      setState(() {
        rows = list;
        scan = false;
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final broker = widget.role == 'broker';
    final hoods = city.isEmpty ? const <String>[] : (kCities[city] ?? const <String>[]);
    final locked = !billing.isSubscribed() && widget.role != 'developer';

    if (locked) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(28)),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Tx('البحث الدلالي', style: ts(18, w: 600, lh: 28, c: AppColors.white)),
              const SizedBox(height: 4),
              Tx('خدمة تسهيل البحث داخل العروض والطلبات. الصفقة تتم خارج التطبيق.', style: ts(15, lh: 21.5, c: const Color(0xA6FFFFFF))),
              const SizedBox(height: 16),
              AppButton(label: 'فعّل الخدمة', tone: ButtonTone.secondary, onTap: () => Nav.go(context, '/account/plans')),
            ],
          ),
        ),
      );
    }

    const noOpt = Opt('', 'اختر', disabled: true);
    final children = <Widget>[
      if (broker)
        _Seg(
          items: <(String, bool, VoidCallback)>[
            (
              'بحث عروض',
              target == 'listings',
              () => setState(() {
                target = 'listings';
                rows = null;
              }),
            ),
            (
              'بحث طلبات',
              target == 'requests',
              () => setState(() {
                target = 'requests';
                rows = null;
              }),
            ),
          ],
        ),
      RadarDesk(
        on: scan,
        title: target == 'requests' ? 'الطلبات' : 'العروض',
        note: target == 'requests'
            ? 'اكتب وصف طلب العميل بوضوح — الذكاء يجيب الطلبات المطابقة. بعد النتائج علّم مطابق أو غير مطابق واكتب السبب.'
            : 'اكتب الوصف بشكل واضح لجلب عروض مطابقة — بعد النتائج علّم مطابق أو غير مطابق واكتب السبب، البحث الجاي أدق',
      ),
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            child: SvcField(
              label: 'المدينة',
              child: selSelect(
                value: city,
                options: <Opt>[noOpt, ...optsOf(kCities.keys)],
                onChanged: (v) => setState(() {
                  city = v;
                  hood = '';
                  rows = null;
                }),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: SvcField(
              label: 'الحي',
              child: selSelect(
                value: hood,
                enabled: city.isNotEmpty,
                options: <Opt>[noOpt, ...optsOf(hoods)],
                onChanged: (v) => setState(() {
                  hood = v;
                  rows = null;
                }),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: SvcField(
              label: 'النوع',
              child: selSelect(
                value: type,
                options: <Opt>[noOpt, for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
                onChanged: (v) => setState(() {
                  type = v;
                  rows = null;
                }),
              ),
            ),
          ),
        ],
      ),
      // description: label, then the text area with the dictation mic inside
      // its frame (disabled until city, district and type are chosen)
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx('الوصف', style: ts(13, w: 600, lh: 20, c: AppColors.muted)),
          const SizedBox(height: 6),
          DictationField(
            controller: q,
            enabled: ready,
            placeholder: ready
                ? (target == 'requests' ? 'مثال: عميل يبحث عن فيلا شمال الرياض…' : 'مثال: فيلا بحديقة قريبة من المدارس شمال الرياض…')
                : 'اختر المدينة والحي والنوع أولاً',
            placeholderColor: AppColors.muted,
            textStyle: ts(15, lh: 21.5, c: AppColors.ink),
            decoration: kSelDeco,
          ),
        ],
      ),
      AppButton(label: scan ? 'يفتّش…' : 'بحث', onTap: scan || !ready ? null : run),
      if (scan)
        Hunt(
          title: target == 'requests' ? 'الذكاء يبحث في الطلبات' : 'الذكاء يبحث في العروض',
          hint: 'يطابق الوصف ثم يصنّف مطابق أو غير مطابق',
          kind: target == 'requests' ? 'requests' : 'listings',
        ),
      if (!scan && rows != null && rows!.isEmpty) radarWish('لا نتائج في هذه المدينة والحي والنوع.'),
      if (!scan && rows != null)
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < rows!.length; i++) ...<Widget>[
              if (i > 0) const SizedBox(height: 10),
              if (rows![i] is Listing) folioListing(rows![i] as Listing) else folioRequest(rows![i] as RequestItem),
            ],
          ],
        ),
    ];

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 36),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 14), children[i]],
        ],
      ),
    );
  }
}

/// Segmented control with a white pill on the selected item.
class _Seg extends StatelessWidget {
  const _Seg({required this.items});

  final List<(String, bool, VoidCallback)> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: const Color(0x0D121214), borderRadius: BorderRadius.circular(16)),
      child: Row(
        children: <Widget>[
          for (var i = 0; i < items.length; i++) ...<Widget>[
            if (i > 0) const SizedBox(width: 4),
            Expanded(
              child: Tap(
                onTap: items[i].$3,
                child: Container(
                  height: 44,
                  decoration: BoxDecoration(
                    color: items[i].$2 ? AppColors.white : const Color(0x00000000),
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: items[i].$2 ? AppShadows.card : null,
                  ),
                  alignment: Alignment.center,
                  child: Tx(items[i].$1, style: ts(15, w: 700, lh: 22.5, c: items[i].$2 ? AppColors.ink : AppColors.muted)),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Dark header with a glowing orb and a rotating sweep.
class RadarDesk extends StatefulWidget {
  const RadarDesk({super.key, required this.on, required this.title, required this.note});

  /// True while scanning (faster, brighter sweep).
  final bool on;
  final String title;
  final String note;

  @override
  State<RadarDesk> createState() => _RadarDeskState();
}

class _RadarDeskState extends State<RadarDesk> with SingleTickerProviderStateMixin {
  late final Ticker _ticker;
  final ValueNotifier<double> _angle = ValueNotifier<double>(0);
  double _last = 0;

  @override
  void initState() {
    super.initState();
    _ticker = createTicker((d) {
      final now = d.inMicroseconds / 1e6;
      final dt = now - _last;
      _last = now;
      // radar-spin: 4.5s (1.6s while scanning) per turn
      _angle.value = (_angle.value + dt / (widget.on ? 1.6 : 4.5)) % 1;
    })..start();
  }

  @override
  void dispose() {
    _ticker.dispose();
    _angle.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(22)),
      clipBehavior: Clip.antiAlias,
      child: Stack(
        children: <Widget>[
          Positioned.fill(
            child: RepaintBoundary(child: CustomPaint(painter: _RadarFx(_angle, widget.on))),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 20, 18, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Tx('بحث دلالي', style: ts(12, w: 700, lh: 18, c: AppColors.gold)),
                const SizedBox(height: 8),
                Tx(widget.title, style: ts(28, w: 700, lh: 42, c: const Color(0xFFE8EEE9))),
                const SizedBox(height: 10),
                Tx(widget.note, style: ts(13, lh: 22, c: const Color(0x99F4F6F5))),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _RadarFx extends CustomPainter {
  _RadarFx(this.angle, this.on) : super(repaint: angle);

  final ValueNotifier<double> angle;
  final bool on;

  @override
  void paint(Canvas canvas, Size size) {
    // glowing orb, partly outside the top-left corner
    const orbC = Offset(-30 + 80, -60 + 80);
    canvas.drawCircle(
      orbC,
      80,
      Paint()
        ..shader = const RadialGradient(
          colors: <Color>[Color(0x730F5C4C), Color(0x000F5C4C), Color(0x000F5C4C)],
          stops: <double>[0, 0.7, 1],
        ).createShader(Rect.fromCircle(center: orbC, radius: 80 * math.sqrt2)),
    );

    // rotating sweep (a conic gradient), slightly larger than the card
    final box = Rect.fromLTRB(-size.width * 0.2, -size.height * 0.2, size.width * 1.2, size.height * 1.2);
    final c = box.center;
    canvas.save();
    canvas.translate(c.dx, c.dy);
    canvas.rotate(angle.value * 2 * math.pi);
    canvas.translate(-c.dx, -c.dy);
    canvas.clipRect(box);
    final paint = Paint()
      ..shader = const SweepGradient(
        colors: <Color>[Color(0x00C9A227), Color(0x00C9A227), Color(0x2EC9A227)],
        stops: <double>[0, 0.72, 1],
        // the sweep starts pointing down
        transform: GradientRotation(math.pi / 2),
      ).createShader(box);
    // opacity: .45 (.85 while scanning)
    canvas.saveLayer(box, Paint()..color = Color.fromRGBO(0, 0, 0, on ? 0.85 : 0.45));
    canvas.drawRect(box, paint);
    canvas.restore();
    canvas.restore();
  }

  @override
  bool shouldRepaint(_RadarFx old) => old.on != on;
}
