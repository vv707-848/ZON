// Helpers computed from a listing (map link, "starting from" price).

import 'package:zon/core/utils/format.dart';
import 'package:zon/data/models/models.dart';

/// Google Maps link: the saved link when present, else the coordinates, else
/// a search for "neighbourhood city".
String mapsUrl({double? lat, double? lng, required String city, required String neighborhood, String? mapsLink}) {
  final link = mapsLink?.trim();
  if (link != null && link.isNotEmpty) {
    if (RegExp(r'^https?:\/\/', caseSensitive: false).hasMatch(link)) return link;
    return 'https://www.google.com/maps/search/?api=1&query=${Uri.encodeComponent(link)}';
  }
  if (lat != null && lng != null) return 'https://www.google.com/maps?q=${plainNumber(lat)},${plainNumber(lng)}';
  final q = <String>[neighborhood, city].where((s) => s.isNotEmpty).join(' ');
  return 'https://www.google.com/maps/search/?api=1&query=${Uri.encodeComponent(q.isEmpty ? 'السعودية' : q)}';
}

/// [mapsUrl] for a listing.
String listingMapsUrl(Listing l) => mapsUrl(lat: l.lat, lng: l.lng, city: l.city, neighborhood: l.neighborhood, mapsLink: l.mapsLink);

/// "ابتداءً من" price: for a project, the cheapest unit that is still
/// available (computed every time, never stored); for a single listing, its price.
double fromPrice(Listing l) {
  if (l.kind != 'project') return l.price;
  final open = (l.units ?? const <UnitModel>[]).where((u) => !u.sold && u.price > 0).map((u) => u.price).toList();
  if (open.isNotEmpty) return open.reduce((a, b) => a < b ? a : b);
  return l.price;
}

/// Whether a project is "ready" (جاهز): land projects always are; otherwise
/// its delivery is not off-plan. Ready projects carry a licence per unit.
bool projectIsReady(Listing p) => p.propertyType == 'land' || (p.delivery ?? 'ready') == 'ready';
