// Listings and projects from the server (docs/api.md «التصفح العام»), in the app's own model, so every screen
// that shows a listing shows a server's without knowing where it came from.
//
//   GET /feed                 «الرئيسية»: listings and projects in one list, filtered and sorted (FeedQuery)
//   GET /listings/{id}        a listing in detail: every photo, the licence, the phones (signed in)
//   GET /projects/{id}        a project in detail, with its unit models
//
// A server listing's id in the app is `L<id>`, a project's `P<id>` (a listing and a project may share a number);
// a link from outside (`/listings/12`) is turned into it by the router. What the server does not know is left
// empty, never filled in: a project's sold units, a listing without a photo (no stock photo in its place).

import 'package:zon/core/net/api.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_pages.dart';

/// The filter card of «الرئيسية», as the server reads it (GET /feed).
class FeedQuery {
  const FeedQuery({this.purpose, this.city, this.neighborhood, this.source, this.propertyType, this.sort = 'new'});

  final String? purpose;
  final String? city;
  final String? neighborhood;
  final String? source;
  final String? propertyType;

  /// The home screen's sort key (`new` · `old` · `feat` · `price-asc` · `price-desc` · `area-asc` · `area-desc`).
  final String sort;

  static const Map<String, String> _sorts = <String, String>{
    'new': 'new',
    'old': 'old',
    'feat': 'featured',
    'price-asc': 'price_asc',
    'price-desc': 'price_desc',
    'area-asc': 'area_asc',
    'area-desc': 'area_desc',
  };

  /// The query string of GET /feed for [page].
  Map<String, String> params(int page, int perPage) => <String, String>{
    'purpose': ?purpose,
    'city': ?city,
    'neighborhood': ?neighborhood,
    'source': ?source,
    'property_type': ?propertyType,
    'sort': _sorts[sort] ?? 'new',
    'page': '$page',
    'per_page': '$perPage',
  };

  @override
  bool operator ==(Object other) =>
      other is FeedQuery &&
      other.purpose == purpose &&
      other.city == city &&
      other.neighborhood == neighborhood &&
      other.source == source &&
      other.propertyType == propertyType &&
      other.sort == sort;

  @override
  int get hashCode => Object.hash(purpose, city, neighborhood, source, propertyType, sort);
}

/// One page of the feed.
typedef FeedPage = ServerPage<Listing>;

/// The server's listings and projects.
abstract final class ServerListings {
  static final RegExp _id = RegExp(r'^[LP]\d+$');

  /// The app's id of a server listing or project.
  static String listingId(Object id) => 'L$id';
  static String projectId(Object id) => 'P$id';

  /// Whether [appId] names a row of the server (not a sample or a listing kept on this phone).
  static bool isServer(String appId) => _id.hasMatch(appId);

  /// The server's own number of [appId].
  static String rawId(String appId) => appId.substring(1);

  /// One page of «الرئيسية».
  static Future<FeedPage> feed(FeedQuery query, {int page = 1, int perPage = 18}) async {
    final r = await Api.client!.get('feed', query: query.params(page, perPage));
    final p = ServerPage.paging(r.meta, page);
    return FeedPage(<Listing>[for (final row in r.list) fromFeed(row)], page: p.page, last: p.last);
  }

  /// A listing (`L…`) or a project (`P…`) in detail.
  static Future<Listing> detail(String appId) async {
    if (appId.startsWith('P')) return fromProject((await Api.client!.get('projects/${rawId(appId)}')).map);
    return fromListing((await Api.client!.get('listings/${rawId(appId)}')).map);
  }

  /// A row of GET /feed (its `kind` says which).
  static Listing fromFeed(Map<String, Object?> j) => j['kind'] == 'project' ? fromProject(j) : fromListing(j);

  /// A listing of the server (a card, or a detail with `photos`, `licence` and `phones`).
  static Listing fromListing(Map<String, Object?> j) {
    final advertiser = _map(j['advertiser']);
    final licence = _map(j['licence']);
    final role = _str(advertiser['role']) ?? 'broker';
    final status = _str(j['status']);
    return Listing(
      id: listingId(j['id'] ?? ''),
      kind: 'unit',
      title: _str(j['title']) ?? '',
      purpose: _str(j['purpose']) ?? 'sale',
      city: _str(j['city']) ?? '',
      neighborhood: _str(j['neighborhood']) ?? '',
      propertyType: normalizeType(_str(j['property_type']) ?? 'apartment'),
      source: role,
      price: numOrNull(j['price']) ?? 0,
      rooms: numOrNull(j['bedrooms']),
      baths: numOrNull(j['bathrooms']),
      halls: numOrNull(j['halls']),
      area: numOrNull(j['area_built']) ?? numOrNull(j['area_land']),
      builtArea: numOrNull(j['area_built']),
      landArea: numOrNull(j['area_land']),
      images: _photos(j),
      description: _str(j['description']) ?? '',
      advertiser: _str(advertiser['name']) ?? '',
      advertiserRole: role,
      license: licence['verified'] == true ? (_str(licence['number']) ?? '') : '',
      negotiable: _bool(j['negotiable']),
      hasDeed: _bool(j['has_deed']),
      bankOk: _bool(j['bank_accepted']),
      occupancy: _str(j['occupancy']),
      floorLevel: _str(j['floor_level']),
      floorNumber: numOrNull(j['floor_number']),
      floors: numOrNull(j['floors']),
      ageYears: numOrNull(j['age']),
      landUse: _str(j['land_use']),
      annualIncome: numOrNull(j['annual_income']),
      lat: numOrNull(j['lat']),
      lng: numOrNull(j['lng']),
      mapsLink: _str(j['maps_link']),
      phones: _strings(j['phones']),
      isPremium: j['featured'] == true,
      premiumUntil: j['featured'] == true ? _str(j['featured_until']) : null,
      urgentUntil: j['urgent'] == true ? _str(j['urgent_until']) : null,
      // whose it is (his own: «عروضي», the editor), and where it is in its life
      ownerId: advertiser['id'] == null ? null : '${ServerSession.idPrefix}${advertiser['id']}',
      status: status,
      active: status == null ? null : status == 'published',
      sold: status == 'sold' ? 1 : null,
      photoIds: _photoIds(j),
    );
  }

  /// A project of the server: its units (models) in detail only. Its sold units are not known to the server, so
  /// [Listing.sold] stays empty; [Listing.available] is the units still available, [Listing.totalUnits] its total.
  static Listing fromProject(Map<String, Object?> j) {
    final developer = _map(j['developer']);
    final units = j['units'];
    final status = _str(j['status']);
    return Listing(
      status: status,
      active: status == null ? null : status == 'published',
      id: projectId(j['id'] ?? ''),
      kind: 'project',
      title: _str(j['title']) ?? '',
      purpose: 'sale',
      city: _str(j['city']) ?? '',
      neighborhood: _str(j['neighborhood']) ?? '',
      propertyType: normalizeType(_str(j['property_type']) ?? 'villa'),
      source: 'developer',
      price: numOrNull(j['start_price']) ?? 0,
      rooms: null,
      baths: null,
      area: null,
      images: _photos(j),
      description: _str(j['description']) ?? '',
      advertiser: _str(developer['name']) ?? '',
      advertiserRole: 'developer',
      license: '',
      available: numOrNull(j['available_units']),
      totalUnits: numOrNull(j['total_units']),
      delivery: _str(j['delivery']),
      landUse: _str(j['land_use']),
      units: units is List
          ? <UnitModel>[
              for (final u in units)
                if (u is Map) _unit(u.cast<String, Object?>()),
            ]
          : null,
      lat: numOrNull(j['lat']),
      lng: numOrNull(j['lng']),
      mapsLink: _str(j['maps_link']),
      phones: _strings(j['phones']),
      isPremium: j['featured'] == true,
    );
  }

  static UnitModel _unit(Map<String, Object?> j) {
    final licence = _map(j['licence']);
    final photos = _photos(j);
    return UnitModel(
      id: '${j['id'] ?? ''}',
      name: _str(j['model_name']) ?? '',
      rooms: numOrNull(j['bedrooms']),
      baths: numOrNull(j['bathrooms']),
      halls: numOrNull(j['halls']),
      area: numOrNull(j['area']) ?? numOrNull(j['area_built']) ?? numOrNull(j['area_land']),
      price: numOrNull(j['price']) ?? 0,
      sold: (numOrNull(j['available_count']) ?? 0) <= 0,
      image: photos.isEmpty ? '' : photos.first,
      description: _str(j['description']) ?? '',
      propertyType: _str(j['property_type']) == null ? null : normalizeType(_str(j['property_type'])!),
      landUse: _str(j['land_use']),
      landArea: numOrNull(j['area_land']),
      builtArea: numOrNull(j['area_built']),
      paymentMethod: _str(j['payment_method']),
      license: _str(licence['number']),
      licenseVerified: licence.isEmpty ? null : licence['verified'] == true,
    );
  }

  /// Every photo of a detail (large first choice), or the card's cover; none when the server has none.
  static List<String> _photos(Map<String, Object?> j) {
    final photos = j['photos'];
    if (photos is List) {
      final urls = <String>[];
      for (final p in photos) {
        final url = p is Map ? (_str(p['large_url']) ?? _str(p['url'])) : null;
        if (url != null) urls.add(url);
      }
      return urls;
    }
    final cover = _str(j['cover_url']);
    return cover == null ? const <String>[] : <String>[cover];
  }

  /// The server's id of each photo of a detail, in [_photos]' order; null for a card (it carries its cover only).
  static List<int>? _photoIds(Map<String, Object?> j) {
    final photos = j['photos'];
    if (photos is! List) return null;
    final ids = <int>[
      for (final p in photos)
        if (p is Map && (_str(p['large_url']) ?? _str(p['url'])) != null && p['id'] is int) p['id']! as int,
    ];
    return ids.length == _photos(j).length ? ids : null; // one photo without its id: none can be matched
  }

  static Map<String, Object?> _map(Object? v) => v is Map ? v.cast<String, Object?>() : const <String, Object?>{};

  static bool? _bool(Object? v) => v is bool ? v : null;

  static String? _str(Object? v) => v is String && v.isNotEmpty ? v : null;

  static List<String>? _strings(Object? v) => v is List
      ? <String>[
          for (final e in v)
            if (e is String) e,
        ]
      : null;
}
