// An advertiser's own listings on the server (docs/api.md «عروضي»), in the app's own model (Listing):
//
//   POST   /listings/licence-check     «تحقق»: the broker's licence asked of REGA before the form (nothing saved)
//   POST   /media                      a photo, before the listing (its id goes in `media_ids`)
//   POST   /listings                   a new listing: the licence checked with REGA, then «قيد الصياغة»
//   GET    /listings/{id}/review       the AI's wording beside his text, once it is ready
//   POST   /listings/{id}/review       his decision: `approve` · `edit` (his text) · `reject` (his own) ← published
//   GET    /listings/mine              «عروضي», every state
//   PATCH  /listings/{id}              an edit (a new text goes back to the wording)
//   POST   /listings/{id}/pause        «إخفاء» · `/resume` «إظهار» · `/sold` «مباع» (final)
//   POST   /listings/{id}/boost        «تمييز» · «عاجل» from the plan's balance (402 with the price otherwise)
//
// What a type does not use is not sent (the server refuses it); what the advertiser left empty is sent empty
// (null), never 0.

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_pages.dart';
import 'package:zon/data/store.dart';

/// The wording of a listing waiting for its advertiser (GET /listings/{id}/review).
class WordingReview {
  const WordingReview({required this.status, required this.textReview, required this.raw, this.suggested, this.autoPublishAt});

  /// The listing's status (`indexing`, `pending_review`, `published`…). An edited listing stays published while
  /// the wording of its new text is prepared and waits for him.
  final String status;

  /// The wording: `pending` while it is prepared, `ready` when a suggestion waits for him; decided otherwise.
  final String textReview;
  final String raw;
  final String? suggested;
  final DateTime? autoPublishAt;

  bool get preparing => textReview == 'pending';
  bool get waiting => textReview == 'ready' && suggested != null;
}

/// His decision on the wording.
enum WordingDecision { approve, edit, reject }

/// An advertiser's own listings on the server.
abstract final class ServerMyListings {
  /// «عروضي»: every listing of his, every page.
  static Future<List<Listing>> mine() async {
    final rows = <Listing>[];
    for (var page = 1; page <= 50; page++) {
      final r = await Api.client!.get('listings/mine', query: <String, String>{'page': '$page'});
      rows.addAll(<Listing>[for (final row in r.list) ServerListings.fromListing(row)]);
      if (ServerPage.paging(r.meta, page).last <= page) break;
    }
    return rows;
  }

  /// «تحقق»: [number] asked of REGA — its last day when it is valid and current, else an ApiError 422 whose field
  /// message says why (unknown to REGA, or past its last day).
  static Future<DateTime?> checkLicence(String number) async =>
      DateTime.tryParse('${(await Api.client!.post('listings/licence-check', <String, Object?>{'licence_number': number})).map['expires_at'] ?? ''}');

  /// Uploads each photo of [photos] that is still on this phone, and gives every photo's server id in order:
  /// the ones already there keep theirs ([known]: a photo → its id, and each one uploaded is added to it, so a
  /// second try does not upload it again). A photo that is neither (a sample's) is left out. [epoch] is the
  /// account the sequence began under: when it changes none of the rest is uploaded ([AccountChanged]).
  static Future<List<int>> upload(List<String> photos, {required Map<String, int> known, required int epoch}) async {
    final ids = <int>[];
    for (final photo in photos) {
      final id = known[photo];
      if (id != null) {
        ids.add(id);
        continue;
      }
      final file = PhotoStore.isRef(photo) ? PhotoStore.fileOf(photo) : null;
      if (file == null || !file.existsSync()) continue;
      final bytes = await file.readAsBytes();
      store.stillAccount(epoch);
      final r = await Api.client!.upload('media', field: 'photo', bytes: bytes, filename: file.uri.pathSegments.last);
      store.stillAccount(epoch); // its id is the account's that uploaded it, not the next one's
      final uploaded = r.map['id'];
      if (uploaded is int) {
        known[photo] = uploaded;
        ids.add(uploaded);
      }
    }
    return ids;
  }

  /// A new listing from [l] (a broker's, with his [licence]). [operation] is the same on every try of one publish.
  static Future<Listing> create(Listing l, {required List<int> mediaIds, required String licence, required String operation, required int epoch}) async {
    store.stillAccount(epoch);
    final r = await Api.client!.post('listings', <String, Object?>{...form(l), ...photos(mediaIds), 'licence_number': licence}, operation);
    return ServerListings.fromListing(r.map);
  }

  static Future<Listing> update(String appId, Listing l, {required List<int> mediaIds, required int epoch}) async {
    store.stillAccount(epoch);
    return ServerListings.fromListing((await Api.client!.patch('listings/${ServerListings.rawId(appId)}', <String, Object?>{...form(l), ...photos(mediaIds)})).map);
  }

  static Future<WordingReview> review(String appId) async {
    final j = (await Api.client!.get('listings/${ServerListings.rawId(appId)}/review')).map;
    return WordingReview(
      status: j['status'] is String ? j['status']! as String : '',
      textReview: j['text_review'] is String ? j['text_review']! as String : '',
      raw: j['raw'] is String ? j['raw']! as String : '',
      suggested: j['suggested'] is String && (j['suggested']! as String).isNotEmpty ? j['suggested']! as String : null,
      autoPublishAt: DateTime.tryParse('${j['auto_publish_at'] ?? ''}')?.toLocal(),
    );
  }

  static Future<Listing> decide(String appId, WordingDecision decision, {String? text}) async => ServerListings.fromListing(
    (await Api.client!.post('listings/${ServerListings.rawId(appId)}/review', <String, Object?>{'decision': decision.name, 'text': ?text})).map,
  );

  static Future<Listing> pause(String appId) => _act(appId, 'pause');
  static Future<Listing> resume(String appId) => _act(appId, 'resume');
  static Future<Listing> sold(String appId) => _act(appId, 'sold');

  /// «تمييز» (`featured`) or «عاجل» (`urgent_listing`) from the plan's balance; without balance an ApiError 402
  /// whose `details.price` is its price.
  static Future<void> boost(String appId, String kind) =>
      Api.client!.post('listings/${ServerListings.rawId(appId)}/boost', <String, Object?>{'kind': kind});

  static Future<Listing> _act(String appId, String action) async =>
      ServerListings.fromListing((await Api.client!.post('listings/${ServerListings.rawId(appId)}/$action')).map);

  /// The price in a 402 as the app writes it (`50`); null when the server did not say.
  static String? priceOf(ApiError e) => e.details['price'] == null ? null : '${e.details['price']}'.replaceFirst(RegExp(r'\.0+$'), '');

  /// [l]'s fields as the server reads them: only those its type uses, an empty one empty.
  static Map<String, Object?> form(Listing l) {
    final s = specsForType(l.propertyType);
    num? n(double? v) => v == null || v.isNaN ? null : (v == v.roundToDouble() ? v.toInt() : v);
    int? i(double? v) => v == null || v.isNaN ? null : v.round();
    return <String, Object?>{
      'purpose': l.purpose,
      'property_type': l.propertyType,
      'title': l.title,
      'city': l.city,
      'neighborhood': l.neighborhood,
      'price': n(l.price),
      'negotiable': ?l.negotiable,
      'has_deed': ?l.hasDeed,
      'bank_accepted': ?l.bankOk,
      'description': l.description,
      'maps_link': l.mapsLink,
      if (s.land) 'area_land': n(l.landArea),
      if (s.built) 'area_built': n(l.builtArea),
      if (s.rooms) ...<String, Object?>{'bedrooms': i(l.rooms), 'bathrooms': i(l.baths), 'halls': i(l.halls)},
      if (s.age) 'age': i(l.ageYears),
      if (s.floorLevel) ...<String, Object?>{'floor_level': l.floorLevel, 'floor_number': i(l.floorNumber)},
      if (s.floors) 'floors': i(l.floors),
      if (s.occupancy) 'occupancy': l.occupancy,
      if (s.landUse) 'land_use': l.landUse,
      if (s.income) 'annual_income': n(l.annualIncome),
    };
  }

  static Map<String, Object?> photos(List<int> ids) => <String, Object?>{'media_ids': ids, if (ids.isNotEmpty) 'cover_id': ids.first};
}
