// The feed card for a listing or a project.

import 'package:flutter/material.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/icons/svg_path.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/shared/widgets/boost.dart';

const Map<String, String> _factIcon = <String, String>{
  'النوع': Lu.building2,
  'الحالة': Lu.calendarDays,
  'المتاح': Lu.layoutGrid,
  'المباع': Lu.tag,
  'الاستخدام': Lu.landPlot,
  'المساحة': Lu.maximize2,
  'طوابق': Lu.layers,
  'دخل سنوي': Lu.wallet,
  'الغرف': Lu.bedDouble,
};

class _Fact {
  const _Fact(this.k, this.text, {this.sar = false});

  final String k;
  final String text;
  final bool sar;
}

final Path _joinPath = parseSvgPath('M0 32V20H158C178 20 190 6 214 5H360V32H0z');

/// The offer / project card of the feeds:
///
///   photo with a "عرض/مشروع" tag and a curved white edge
///   title · location · "مميز" badge
///   green facts strip (type, rooms, area… or the project's units)
///   price row with the "go" arrow
///
/// Tapping opens the listing or project page (or calls [onSelect]).
class ListingCard extends StatelessWidget {
  const ListingCard({super.key, required this.listing, this.onSelect, this.mini = false});

  final Listing listing;
  final VoidCallback? onSelect;

  /// Compact variant used inside the matching results (more room at the end
  /// of the title for the rating mark).
  final bool mini;

  @override
  Widget build(BuildContext context) {
    final e = listing;
    final img = e.images.isNotEmpty ? e.images.first : '/listings/villa-1.jpg';
    final now = appNow();
    final featured = e.featuredAt(now);
    final urgent = e.urgentAt(now);
    final project = e.kind == 'project';
    final startFrom = project && (e.units ?? const <UnitModel>[]).any((u) => !u.sold && u.price > 0);
    final shownPrice = project ? fromPrice(e) : e.price;
    final facts = <_Fact>[];
    if (project) {
      final sold = e.sold ?? (e.units?.where((u) => u.sold).length.toDouble()) ?? 0;
      final available = e.available ?? (e.units?.where((u) => !u.sold).length.toDouble()) ?? 0;
      facts.add(_Fact('النوع', kTypePlural[e.propertyType] ?? ''));
      facts.add(
        _Fact(
          e.propertyType == 'land' ? 'الاستخدام' : 'الحالة',
          e.propertyType == 'land' ? (kLandUseLabel[e.landUse ?? 'residential'] ?? '') : (kDeliveryLabel[e.delivery ?? 'ready'] ?? ''),
        ),
      );
      facts.add(_Fact('المتاح', sar(available)));
      facts.add(_Fact('المباع', sar(sold)));
    } else if (e.propertyType == 'land') {
      facts.add(_Fact('النوع', kTypeLabel[e.propertyType] ?? ''));
      if (e.landUse != null) facts.add(_Fact('الاستخدام', kLandUseLabel[e.landUse] ?? ''));
      final a = e.landArea ?? e.area;
      if (a != null && a != 0) facts.add(_Fact('المساحة', areaLabel(a)));
    } else if (e.propertyType == 'building') {
      facts.add(_Fact('النوع', kTypeLabel[e.propertyType] ?? ''));
      if (e.floors != null && e.floors != 0) facts.add(_Fact('طوابق', sar(e.floors!)));
      final a = e.builtArea ?? e.landArea ?? e.area;
      if (a != null && a != 0) facts.add(_Fact('المساحة', areaLabel(a)));
      if (e.annualIncome != null && e.annualIncome != 0) facts.add(_Fact('دخل سنوي', sar(e.annualIncome!), sar: true));
    } else {
      facts.add(_Fact('النوع', kTypeLabel[e.propertyType] ?? ''));
      if (e.rooms != null && e.rooms != 0) facts.add(_Fact('الغرف', sar(e.rooms!)));
      final a = e.builtArea ?? e.area;
      if (a != null && a != 0) facts.add(_Fact('المساحة', areaLabel(a)));
    }
    final r4 = project && facts.length >= 4;
    final shown = facts.take(4).toList();

    final card = Container(
      decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(26), boxShadow: AppShadows.card),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(26),
        child: Stack(
          children: <Widget>[
            // photo (152) with the join sheet over its bottom 32px
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              height: 152,
              child: Stack(
                fit: StackFit.expand,
                children: <Widget>[
                  const ColoredBox(color: AppColors.ink),
                  AppImage(img, fallback: '/listings/villa-1.jpg'),
                  const Positioned(
                    left: 0,
                    right: 0,
                    bottom: 0,
                    height: 152 * 0.46,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: <Color>[Color(0x001C3228), Color(0x4D1C3228)],
                        ),
                      ),
                    ),
                  ),
                  PositionedDirectional(
                    top: 12,
                    start: 12,
                    child: Container(
                      height: 24,
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(999)),
                      alignment: Alignment.center,
                      child: Tx(project ? 'مشروع' : 'عرض', style: ts(12, w: 600, lh: 18, c: AppColors.white)),
                    ),
                  ),
                  const Positioned(left: 0, right: 0, bottom: 0, height: 32, child: CustomPaint(painter: _JoinPainter())),
                ],
              ),
            ),
            // body: starts 18px above the photo's bottom edge
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 152 - 18, 14, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Expanded(
                        child: Padding(
                          padding: EdgeInsetsDirectional.only(end: mini ? 12 : 0),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: <Widget>[
                              Tx(
                                e.title,
                                style: ts(16, w: 600, lh: 22.5, c: AppColors.deep),
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                              ),
                              const SizedBox(height: 5),
                              Tap(
                                onTap: () => openExternal(listingMapsUrl(e)),
                                child: Row(
                                  children: <Widget>[
                                    const LucideIcon(Lu.mapPin, size: 13, color: AppColors.stone),
                                    const SizedBox(width: 4),
                                    Flexible(
                                      child: Tx('${e.city} ، ${e.neighborhood}', style: ts(13, w: 400, lh: 19.5, c: AppColors.stone)),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      if (featured || urgent) ...<Widget>[
                        if (!mini) const SizedBox(width: 10),
                        Padding(
                          padding: const EdgeInsets.only(top: 22),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: <Widget>[
                              if (featured) const PremiumBadge(),
                              if (featured && urgent) const SizedBox(height: 6),
                              if (urgent) const UrgentBadge(),
                            ],
                          ),
                        ),
                      ],
                    ],
                  ),
                  if (shown.isNotEmpty) _Split(facts: shown, r4: r4),
                  const SizedBox(height: 12),
                  _PriceFoot(startFrom: startFrom, price: shownPrice),
                ],
              ),
            ),
          ],
        ),
      ),
    );

    return Tap(onTap: onSelect ?? () => Nav.go(context, project ? '/projects/${e.id}' : '/listings/${e.id}'), child: card);
  }
}

/// The white curved edge where the photo meets the card body.
class _JoinPainter extends CustomPainter {
  const _JoinPainter();

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.scale(size.width / 360, size.height / 32);
    canvas.drawPath(_joinPath, Paint()..color = AppColors.white);
    canvas.restore();
  }

  @override
  bool shouldRepaint(_JoinPainter oldDelegate) => false;
}

/// Gold "مميز" badge of premium listings.
class PremiumBadge extends StatelessWidget {
  const PremiumBadge({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 26,
      padding: const EdgeInsets.symmetric(horizontal: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: <Color>[Color(0xFFF8E7B0), Color(0xFFE0B84A), Color(0xFFC8961C)],
          stops: <double>[0, 0.46, 1],
        ),
        boxShadow: <BoxShadow>[decorShadow(const Color(0x55C4A35A), 0, 3, 8)],
      ),
      foregroundDecoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        border: const Border(top: BorderSide(color: Color(0x99FFFFFF), width: 1)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const LucideIcon(Lu.crown, size: 12, strokeWidth: 2.2, color: Color(0xFF6B5310)),
          const SizedBox(width: 4),
          Tx('مميز', style: ts(12, w: 700, lh: 18, c: const Color(0xFF4A3808))),
        ],
      ),
    );
  }
}

/// The green strip of up to four facts (icon + label over a value).
class _Split extends StatelessWidget {
  const _Split({required this.facts, required this.r4});

  final List<_Fact> facts;
  final bool r4;

  @override
  Widget build(BuildContext context) {
    final cells = <Widget>[];
    for (var i = 0; i < facts.length; i++) {
      final f = facts[i];
      final icon = _factIcon[f.k];
      final valueStyle = ts(13, w: 600, lh: 19.5, c: AppColors.white);
      if (i > 0 && r4) cells.add(const SizedBox(width: 1));
      cells.add(
        Expanded(
          child: Container(
            decoration: i == 0
                ? null
                : const BoxDecoration(
                    border: BorderDirectional(start: BorderSide(color: Color(0x33FFFFFF))),
                  ),
            padding: const EdgeInsets.fromLTRB(6, 6, 6, 7),
            child: Column(
              children: <Widget>[
                Wrap(
                  alignment: WrapAlignment.center,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 4,
                  children: <Widget>[
                    if (icon != null) LucideIcon(icon, size: 12, strokeWidth: 1.8, color: AppColors.white),
                    Tx(f.k, style: ts(11, w: 400, lh: 16.5, c: AppColors.white)),
                  ],
                ),
                const SizedBox(height: 3),
                if (f.sar)
                  SizedBox(
                    height: 19,
                    child: Center(
                      child: SarText(f.text, style: valueStyle, iconW: 10, iconH: 12, gap: 4, iconColor: AppColors.white),
                    ),
                  )
                else
                  Tx(f.text, style: valueStyle, align: TextAlign.center),
              ],
            ),
          ),
        ),
      );
    }
    return Container(
      margin: EdgeInsets.only(top: r4 ? 12 : 10),
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(r4 ? 14 : 16)),
      child: IntrinsicHeight(
        child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: cells),
      ),
    );
  }
}

/// Price row: optional "ابتداءً من", the price with the riyal sign, and the arrow.
class _PriceFoot extends StatelessWidget {
  const _PriceFoot({required this.startFrom, required this.price});

  final bool startFrom;
  final double price;

  @override
  Widget build(BuildContext context) {
    const green = Color(0xFF16382C);
    return Container(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 6, 6, 6),
      decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(18)),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Row(
              children: <Widget>[
                if (startFrom) ...<Widget>[
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(999)),
                    child: Tx('ابتداءً من', style: ts(12, w: 600, lh: 14.5, c: green)),
                  ),
                  const SizedBox(width: 8),
                ],
                Flexible(
                  child: SarText(
                    sar(price),
                    style: ts(16, w: 700, lh: 18.5, c: green),
                    iconW: 13,
                    iconH: 13,
                    gap: 6,
                    iconColor: green,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Container(
            width: 32,
            height: 32,
            decoration: const BoxDecoration(color: AppColors.forest, shape: BoxShape.circle),
            alignment: Alignment.center,
            child: const LucideIcon(Lu.chevronLeft, size: 16, strokeWidth: 2.4, color: AppColors.white),
          ),
        ],
      ),
    );
  }
}
