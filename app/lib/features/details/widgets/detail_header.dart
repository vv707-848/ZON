// Title and location of a detail page. Tapping the location asks before
// opening Google Maps.

import 'package:flutter/material.dart';

import 'package:zon/app/external_links.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';

/// Page title (listing / project / unit name).
class DetailTitle extends StatelessWidget {
  const DetailTitle(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Tx(text, style: ts(22, w: 700, lh: 31, c: AppColors.deep));
}

/// "هل تود الذهاب للموقع؟" — opens [url] in Google Maps after confirmation.
Future<void> askOpenMap(BuildContext context, String url) async {
  final ok = await showAppDialog(context, icon: Lu.mapPin, title: 'هل تود الذهاب للموقع؟', message: 'سيتم فتح خرائط Google');
  if (ok) await openExternal(url);
}

/// "المدينة · الحي" link with a pin (listing pages).
class MapJump extends StatelessWidget {
  const MapJump({super.key, required this.city, required this.neighborhood, this.lat, this.lng, this.href});

  final String city;
  final String neighborhood;
  final double? lat;
  final double? lng;

  /// A saved maps link; when empty the link is built from the coordinates or
  /// the city/neighbourhood names.
  final String? href;

  String get url => (href != null && href!.isNotEmpty) ? href! : mapsUrl(city: city, neighborhood: neighborhood, lat: lat, lng: lng, mapsLink: href);

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: () => askOpenMap(context, url),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const LucideIcon(Lu.mapPin, size: 15, strokeWidth: 2.2, color: AppColors.forest),
          const SizedBox(width: 5),
          Tx('$city · $neighborhood', style: ts(14, w: 600, lh: 21, c: AppColors.forest)),
        ],
      ),
    );
  }
}

/// Outlined location pill (project pages).
class MapJumpPill extends StatelessWidget {
  const MapJumpPill({super.key, required this.listing});

  final Listing listing;

  @override
  Widget build(BuildContext context) {
    final l = listing;
    final url = (l.mapsLink != null && l.mapsLink!.isNotEmpty) ? l.mapsLink! : listingMapsUrl(l);
    return Tap(
      onTap: () => askOpenMap(context, url),
      child: Container(
        height: 34,
        padding: const EdgeInsetsDirectional.fromSTEB(6, 0, 12, 0),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: AppColors.forest, width: 1.4),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Container(
              width: 22,
              height: 22,
              padding: const EdgeInsets.all(4),
              decoration: const BoxDecoration(color: AppColors.forest, shape: BoxShape.circle),
              child: const LucideIcon(Lu.mapPin, size: 14, strokeWidth: 2.2, color: AppColors.white),
            ),
            Flexible(
              child: Container(
                margin: const EdgeInsetsDirectional.only(start: 8),
                padding: const EdgeInsetsDirectional.only(start: 8),
                decoration: const BoxDecoration(
                  border: BorderDirectional(start: BorderSide(color: Color(0xFFC9D4CC))),
                ),
                child: Tx(
                  '${l.city} · ${l.neighborhood}',
                  style: ts(14, w: 600, lh: 20, c: AppColors.forest),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
