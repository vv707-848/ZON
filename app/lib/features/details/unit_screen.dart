// `/projects/:id/units/:unitId` — one unit model of a project.
//
// The licence shown is THIS unit's own verified number, and only while the
// project is ready (brief L01). The project-wide number of older data is
// never shown here; an off-plan project shows no licence field. A unit of a
// server project comes with its project, fetched fresh (ServerDetail).

import 'package:flutter/material.dart';

import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/details/widgets/detail_widgets.dart';
import 'package:zon/shared/widgets/server_detail.dart';

/// `/projects/:id/units/:unitId` — one unit of a project.
class UnitScreen extends StatelessWidget {
  const UnitScreen({super.key, required this.id, required this.unitId});

  /// Project id.
  final String id;
  final String unitId;

  @override
  Widget build(BuildContext context) {
    if (Api.on && ServerListings.isServer(id)) {
      return ServerDetail(id: id, title: 'الوحدة', missing: 'الوحدة غير موجودة.', builder: _page);
    }
    return _page(context);
  }

  Widget _page(BuildContext context) {
    final project = store.listingById(id);
    UnitModel? unit;
    for (final u in project?.units ?? const <UnitModel>[]) {
      if (u.id == unitId) unit = u;
    }
    if (project == null || unit == null) {
      return AppShell(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Tx('الوحدة غير موجودة.', style: ts(15, lh: 21.5, c: AppColors.ink)),
        ),
      );
    }
    final u = unit;
    final kind = u.propertyType ?? project.propertyType;
    final flags = specsForUnit(kind);
    final own = <String>[u.image, ...project.images].where((s) => s.isNotEmpty).toList();
    final photos = ServerListings.isServer(project.id)
        ? (own.isEmpty ? const <String>[kNoPhoto] : own)
        : (u.image.isNotEmpty ? own : const <String>['/listings/apt-1.jpg']);
    String dash(double? v) => v == null ? '—' : sar(v);
    final specs = <SpecItem>[
      if (flags.built) SpecItem('مساحة البناء', areaLabel(u.builtArea ?? u.area)),
      if (flags.land) SpecItem('مساحة الأرض', areaLabel(u.landArea ?? u.area)),
      if (flags.landUse && (u.landUse != null || project.landUse != null))
        SpecItem('نوع الأرض', kLandUseLabel[u.landUse ?? project.landUse ?? 'residential'] ?? ''),
      if (flags.rooms) ...<SpecItem>[SpecItem('الغرف', dash(u.rooms)), SpecItem('الصالات', dash(u.halls)), SpecItem('دورات المياه', dash(u.baths))],
      if (u.paymentMethod != null && u.paymentMethod!.isNotEmpty) SpecItem('الدفع', u.paymentMethod!),
    ];
    return DetailScaffold(
      backRoute: '/projects/$id',
      actions: OfferReact(id: u.id, offer: project.id, favorite: project.favorite),
      gallery: PhotoGallery(photos: photos, badge: u.sold ? const DetailSeal('تم البيع', sold: true) : DetailSeal(kTypeLabel[kind] ?? '')),
      children: <Widget>[
        DetailTitle('وحدة ${u.name}'),
        PriceDuo(
          trio: true,
          cells: <DuoCell>[
            DuoCell('السعر', sar(u.price), sar: true, valueSize: 18, flex: 11),
            DuoCell('نوع الوحدة', kTypeLabel[kind] ?? '', valueWeight: 700, flex: 10),
            DuoCell('الحالة', u.sold ? 'مباع' : 'متاح', flex: 9),
          ],
        ),
        const InfoHeading('مواصفات الوحدة'),
        SpecGrid(items: specs),
        if (u.description.isNotEmpty) DescCard(title: 'وصف الوحدة', text: u.description),
        if (u.shownLicense(projectReady: projectIsReady(project)) case final String lic)
          LicensePanel(license: lic)
        else if (projectIsReady(project) && store.ownsListing(project))
          // only the publisher sees what is missing
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: const Color(0xFFFFF8E8), borderRadius: BorderRadius.circular(18)),
              child: Tx(
                'رقم الترخيص الإعلاني لهذه الوحدة غير مكتمل — أكمله من تعديل المشروع.',
                style: ts(14, w: 600, lh: 21, c: const Color(0xFF5C3B06)),
              ),
            ),
          ),
      ],
    );
  }
}
