// `/listings/:id` — تفاصيل العرض (a single property for sale or rent). A
// listing of the server is fetched fresh in detail first (ServerDetail).

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

class ListingScreen extends StatelessWidget {
  const ListingScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context) {
    if (Api.on && ServerListings.isServer(id)) {
      return ServerDetail(id: id, title: 'العرض', missing: 'العرض غير موجود.', builder: _page);
    }
    return _page(context);
  }

  Widget _page(BuildContext context) {
    final l = store.listingById(id);
    if (l == null) {
      return AppShell(
        title: 'العرض',
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Tx('العرض غير موجود.', style: ts(15, lh: 21.5, c: AppColors.ink)),
        ),
      );
    }
    final rent = l.purpose == 'rent';
    final photos = l.images.isNotEmpty ? l.images : <String>[if (ServerListings.isServer(l.id)) kNoPhoto else '/listings/villa-1.jpg'];
    return DetailScaffold(
      actions: OfferReact(id: l.id),
      gallery: PhotoGallery(photos: photos, badge: DetailSeal(rent ? 'للإيجار' : 'للبيع')),
      children: <Widget>[
        // title + location
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            DetailTitle(l.title),
            const SizedBox(height: 6),
            MapJump(city: l.city, neighborhood: l.neighborhood, lat: l.lat, lng: l.lng, href: l.mapsLink),
          ],
        ),
        FactsStrip(
          items: <MapEntry<String, String>>[
            MapEntry('العرض', kPurposeLabel[l.purpose] ?? ''),
            MapEntry('المدينة', l.city),
            MapEntry('الحي', l.neighborhood),
          ],
        ),
        PriceDuo(
          cells: <DuoCell>[DuoCell('السعر', sar(l.price), sar: true, valueSize: 22, flex: 3), DuoCell('قابل للتفاوض', _yesNo(l.negotiable), flex: 2)],
        ),
        DescCard(title: 'ما يميز العرض', text: l.description),
        const InfoHeading('المواصفات'),
        SpecGrid(items: listingSpecs(l)),
        if (l.license.isNotEmpty) LicensePanel(license: l.license),
        if (!(Api.on && store.ownsListing(l))) AdvertiserCard(name: l.advertiser, role: l.advertiserRole, listingId: l.id, phones: l.phones, username: l.advertiserUsername),
      ],
    );
  }
}

String _yesNo(bool? v) => v == true ? 'نعم' : 'لا';

/// The specification cells of a listing, chosen by its property type
/// (see `specsForType` in data/catalog/property_types.dart).
List<SpecItem> listingSpecs(Listing l) {
  final rent = l.purpose == 'rent';
  final b = specsForType(l.propertyType);
  return <SpecItem>[
    if (b.age) SpecItem('عمر العقار', l.ageYears == null ? '—' : '${sar(l.ageYears!)} سنة'),
    if (b.land) SpecItem('مساحة الأرض', areaLabel(l.landArea ?? l.area)),
    if (b.built) SpecItem('مساحة البناء', areaLabel(l.builtArea ?? l.area)),
    if (b.floors && l.floors != null) SpecItem('عدد الطوابق', sar(l.floors!)),
    if (b.income && l.annualIncome != null) SpecItem('الدخل السنوي', sar(l.annualIncome!), sar: true),
    SpecItem('نوع العقار', kTypeLabel[l.propertyType] ?? ''),
    if (b.landUse) SpecItem('نوع الأرض', kLandUseLabel[l.landUse ?? 'residential'] ?? ''),
    if (b.floorLevel) SpecItem('الطابق', floorLabel(l.floorLevel, l.floorNumber)),
    if (rent && b.occupancy) SpecItem('نوع السكن', kOccupancyLabel[l.occupancy ?? 'families'] ?? ''),
    if (b.rooms && l.rooms != null) SpecItem('عدد الغرف', sar(l.rooms!)),
    if (b.rooms && l.halls != null) SpecItem('عدد الصالات', sar(l.halls!)),
    if (b.rooms && l.baths != null) SpecItem('دورات المياه', sar(l.baths!)),
    SpecItem('بصك', _yesNo(l.hasDeed)),
    if (!rent && l.hasDeed == true) SpecItem('يقبل البنك؟', _yesNo(l.bankOk)),
  ];
}
