// `/projects/:id` — a developer project and its units. A project of the
// server is fetched fresh in detail first (ServerDetail); its sold units are
// not known there, so its panel shows the total and what is left only.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/sort.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/details/widgets/detail_widgets.dart';
import 'package:zon/features/details/widgets/unit_row.dart';
import 'package:zon/shared/widgets/server_detail.dart';

class ProjectScreen extends StatefulWidget {
  const ProjectScreen({super.key, required this.id});

  final String id;

  @override
  State<ProjectScreen> createState() => _ProjectScreenState();
}

class _ProjectScreenState extends State<ProjectScreen> {
  /// Key of [kUnitFilters].
  String _filter = 'all';

  /// Key of [kUnitSorts].
  String _sort = 'price-asc';
  bool _sortOpen = false;
  int _photo = 0;

  List<UnitModel> _visibleUnits(List<UnitModel> units) {
    final rows = units.where((u) => _filter == 'available' ? !u.sold : (_filter == 'sold' ? u.sold : true)).toList();
    int cmp(UnitModel a, UnitModel b) {
      final aa = a.area ?? 0;
      final bb = b.area ?? 0;
      return switch (_sort) {
        'price-asc' => a.price.compareTo(b.price),
        'price-desc' => b.price.compareTo(a.price),
        'area-asc' => aa.compareTo(bb),
        _ => bb.compareTo(aa),
      };
    }

    return stableSort(rows, cmp);
  }

  @override
  Widget build(BuildContext context) {
    if (Api.on && ServerListings.isServer(widget.id)) {
      return ServerDetail(id: widget.id, title: 'المشروع', missing: 'المشروع غير موجود.', builder: _page);
    }
    return _page(context);
  }

  Widget _page(BuildContext context) {
    final l = store.listingById(widget.id);
    if (l == null) {
      return AppShell(
        title: 'المشروع',
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Tx('المشروع غير موجود.', style: ts(15, lh: 21.5, c: AppColors.ink)),
        ),
      );
    }
    final units = l.units ?? const <UnitModel>[];
    final rows = _visibleUnits(units);
    final server = ServerListings.isServer(l.id);
    final available = l.available ?? units.where((u) => !u.sold).length.toDouble();
    // the server knows a project's total and what is left, not what was sold
    final sold = server ? null : (l.sold ?? units.where((u) => u.sold).length.toDouble());
    final totalSum = available + (sold ?? 0);
    final total = server ? (l.totalUnits ?? available) : (totalSum != 0 ? totalSum : units.length.toDouble());
    final pct = sold != null && total != 0 ? (sold / total * 100).round() : 0;
    final photos = l.images.isNotEmpty ? l.images : <String>[if (server) kNoPhoto else '/listings/tower-1.jpg'];

    return DetailScaffold(
      actions: OfferReact(id: l.id),
      gallery: PhotoGallery(photos: photos, index: _photo, onIndex: (n) => _photo = n),
      children: <Widget>[
        // title, location and delivery status
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            DetailTitle(l.title),
            const SizedBox(height: 12),
            Row(
              children: <Widget>[
                Expanded(
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: MapJumpPill(listing: l),
                  ),
                ),
                const SizedBox(width: 8),
                _StatusPill(listing: l),
              ],
            ),
          ],
        ),
        // type / city / neighbourhood
        Container(
          decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(20)),
          child: IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                _meta(Lu.building2, 'النوع', kTypePlural[l.propertyType] ?? '', divider: true),
                _meta(Lu.mapPin, 'المدينة', l.city, divider: true),
                _meta(Lu.mapPin, 'الحي', l.neighborhood),
              ],
            ),
          ),
        ),
        _SalesPanel(total: total, sold: sold, available: available, pct: pct),
        // description
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            const BarHeading('ما يميز المشروع', size: 18, weight: 700, lh: 27),
            const SizedBox(height: 12),
            Tx(l.description, style: ts(16, w: 500, lh: 27, c: AppColors.deep)),
          ],
        ),
        // units
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            BarHeading('وحدات ${kTypePlural[l.propertyType] ?? ''}', size: 18, weight: 700, lh: 27),
            const SizedBox(height: 12),
            UnitsFilterBar(
              filter: _filter,
              onFilter: (v) => setState(() => _filter = v),
              sort: _sort,
              sortOpen: _sortOpen,
              onToggleSort: () => setState(() => _sortOpen = !_sortOpen),
              onSort: (v) => setState(() {
                _sort = v;
                _sortOpen = false;
              }),
            ),
            const SizedBox(height: 14),
            for (var i = 0; i < rows.length; i++) ...<Widget>[
              if (i > 0) const SizedBox(height: 10),
              UnitRow(u: rows[i], projectId: l.id, propertyType: l.propertyType),
            ],
          ],
        ),
        if (!(Api.on && store.ownsListing(l)))
          AdvertiserCard(name: l.advertiser, role: l.advertiserRole, listingId: l.id, phones: l.phones, username: l.advertiserUsername),
      ],
    );
  }

  Widget _meta(String icon, String label, String value, {bool divider = false}) {
    return Expanded(
      child: Container(
        decoration: divider
            ? const BoxDecoration(
                border: BorderDirectional(end: BorderSide(color: AppColors.line)),
              )
            : null,
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
        child: Column(
          children: <Widget>[
            LucideIcon(icon, size: 15, strokeWidth: 2, color: AppColors.stone),
            const SizedBox(height: 4),
            Tx(
              label,
              style: ts(13, w: 500, lh: 19.5, c: AppColors.muted),
              align: TextAlign.center,
            ),
            const SizedBox(height: 2),
            Tx(
              value,
              style: ts(16, w: 600, lh: 24, c: AppColors.deep),
              align: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

/// "جاهز" / "على الخارطة" (or the land use for land projects).
class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.listing});

  final Listing listing;

  @override
  Widget build(BuildContext context) {
    final l = listing;
    final land = l.propertyType == 'land';
    final tone = land ? 'land' : (l.delivery ?? 'ready');
    final status = land ? (kLandUseLabel[l.landUse ?? 'residential'] ?? '') : (kDeliveryLabel[l.delivery ?? 'ready'] ?? '');
    final icon = land ? Lu.landmark : ((tone == 'offplan' || tone == 'soon') ? Lu.calendarClock : Lu.check);
    final ready = tone == 'ready';
    return Container(
      height: 34,
      padding: const EdgeInsetsDirectional.fromSTEB(6, 0, 12, 0),
      decoration: BoxDecoration(
        color: ready ? AppColors.forest : AppColors.white,
        borderRadius: BorderRadius.circular(999),
        border: ready ? null : Border.all(color: AppColors.forest, width: 1.4),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Container(
            width: 22,
            height: 22,
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(color: ready ? AppColors.white : AppColors.mist, shape: BoxShape.circle),
            child: LucideIcon(icon, size: 14, strokeWidth: 2.4, color: AppColors.forest),
          ),
          const SizedBox(width: 6),
          Tx(status, style: ts(14, w: 600, lh: 20, c: ready ? AppColors.white : AppColors.forest)),
        ],
      ),
    );
  }
}

/// Dark green panel: total / sold / remaining units and the sales bar.
class _SalesPanel extends StatelessWidget {
  const _SalesPanel({required this.total, required this.sold, required this.available, required this.pct});

  final double total;

  /// Null when it is not known (a project of the server): no sold count and no bar.
  final double? sold;
  final double available;
  final int pct;

  @override
  Widget build(BuildContext context) {
    Widget stat(String k, String v) => Expanded(
      child: Column(
        children: <Widget>[
          Tx(
            k,
            style: ts(13, w: 500, lh: 19.5, c: const Color(0xFFC9D4CC)),
            align: TextAlign.center,
          ),
          const SizedBox(height: 4),
          Tx(
            v,
            style: ts(24, w: 700, lh: 32, c: AppColors.white),
            align: TextAlign.center,
          ),
        ],
      ),
    );
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 16, 14, 16),
      decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(20)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(children: <Widget>[stat('الإجمالي', sar(total)), if (sold != null) stat('المباع', sar(sold!)), stat('المتبقي', sar(available))]),
          if (sold != null) const SizedBox(height: 14),
          if (sold != null)
            Row(
              children: <Widget>[
                Tx('نسبة البيع $pct%', style: ts(13, w: 600, lh: 19.5, c: const Color(0xFFC9D4CC))),
                const SizedBox(width: 10),
                Expanded(
                  child: Container(
                    height: 6,
                    decoration: BoxDecoration(color: const Color(0xFF2E4A40), borderRadius: BorderRadius.circular(999)),
                    clipBehavior: Clip.antiAlias,
                    alignment: AlignmentDirectional.centerStart,
                    child: FractionallySizedBox(
                      widthFactor: pct.clamp(0, 100) / 100,
                      child: Container(
                        decoration: BoxDecoration(color: const Color(0xFFC4D4A8), borderRadius: BorderRadius.circular(999)),
                      ),
                    ),
                  ),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
