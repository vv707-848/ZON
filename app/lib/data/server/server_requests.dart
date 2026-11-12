// Search requests on the server (docs/api.md «الطلبات»), in the app's own model (RequestItem):
//
//   GET  /requests                 «الطلبات»: the board, filtered and sorted (RequestQuery), page by page
//   GET  /requests/{id}            a request (who posted it: as the viewer's plan lets him see)
//   POST /requests                 a new request; «عاجل» from the plan's balance, else it is published normal
//   GET  /requests/mine            «طلباتي», closed ones too
//   GET  /requests/area            a broker's city, with what his plan opens of it (`meta.access`)
//   PUT  /requests/{id}            the whole form again («عاجل» is not edited)
//   POST /requests/{id}/urgent     «اجعله طلبًا عاجلًا»: from the balance, else 402 with the price
//   POST /requests/{id}/close      «أغلق الطلب»
//   POST /requests/{id}/to-client  «أضف إلى إدارة العملاء»
//
// A request's id in the app is `R<id>`. Who posted it is empty when the server hides him; its author's account
// (`srv-<id>`) is what makes it «mine».

import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_pages.dart';

/// The filter card of «الطلبات», as the server reads it.
class RequestQuery {
  const RequestQuery({this.role, this.purpose, this.city, this.neighborhood, this.propertyType, this.sort = 'new'});

  final String? role;
  final String? purpose;
  final String? city;
  final String? neighborhood;
  final String? propertyType;

  /// `new` · `urgent` · `old` · `cheap` (the screen's own keys, which the server shares).
  final String sort;

  Map<String, String> params(int page, int perPage) => <String, String>{
    'role': ?role,
    'purpose': ?purpose,
    'city': ?city,
    'neighborhood': ?neighborhood,
    'property_type': ?propertyType,
    'sort': sort,
    'page': '$page',
    'per_page': '$perPage',
  };

  @override
  bool operator ==(Object other) =>
      other is RequestQuery &&
      other.role == role &&
      other.purpose == purpose &&
      other.city == city &&
      other.neighborhood == neighborhood &&
      other.propertyType == propertyType &&
      other.sort == sort;

  @override
  int get hashCode => Object.hash(role, purpose, city, neighborhood, propertyType, sort);
}

/// What became of «عاجل» on a new request.
enum UrgentOutcome {
  /// From the plan's balance: it is urgent.
  applied,

  /// No balance: it is published normal, and urgent costs [NewRequest.price].
  paymentRequired,
}

/// A request just published, and what became of its «عاجل».
class NewRequest {
  const NewRequest(this.item, {this.urgent, this.price});

  final RequestItem item;
  final UrgentOutcome? urgent;

  /// The price of «عاجل» in riyals (`50`).
  final String? price;
}

/// The broker's area: its requests, and what his plan opens of them (`none` · `preview` · `full`).
class AreaRequests {
  const AreaRequests(this.items, {required this.access});

  final List<RequestItem> items;
  final String access;

  /// Who posted them is hidden: only their number shows.
  bool get locked => access == 'none';
}

/// The server's search requests.
abstract final class ServerRequests {
  static final RegExp _id = RegExp(r'^R\d+$');

  static String requestId(Object id) => 'R$id';

  /// Whether [appId] names a request of the server.
  static bool isServer(String appId) => _id.hasMatch(appId);

  static String rawId(String appId) => appId.substring(1);

  /// One page of «الطلبات».
  static Future<ServerPage<RequestItem>> board(RequestQuery query, {int page = 1, int perPage = 20}) async {
    final r = await Api.client!.get('requests', query: query.params(page, perPage));
    final p = ServerPage.paging(r.meta, page);
    return ServerPage<RequestItem>(<RequestItem>[for (final row in r.list) fromJson(row)], page: p.page, last: p.last);
  }

  static Future<RequestItem> detail(String appId) async => fromJson((await Api.client!.get('requests/${rawId(appId)}')).map);

  /// Every request of mine (its pages, newest first).
  static Future<List<RequestItem>> mine() async => (await _all('requests/mine')).rows;

  /// The requests of a broker's city, by others.
  static Future<AreaRequests> area() async {
    final all = await _all('requests/area');
    return AreaRequests(all.rows, access: '${all.meta['access'] ?? 'none'}');
  }

  /// Publishes [draft]; with [urgent], «عاجل» from the plan's balance when there is one. [operation] is the same
  /// for every try of one publish (a retry after a lost answer): the server publishes it once.
  static Future<NewRequest> create(RequestItem draft, {required bool urgent, required String operation}) async {
    final r = await Api.client!.post('requests', <String, Object?>{...form(draft), 'urgent': urgent}, operation);
    final outcome = r.meta['urgent'];
    final status = outcome is Map ? outcome['status'] : null;
    return NewRequest(
      fromJson(r.map),
      urgent: switch (status) {
        'applied' => UrgentOutcome.applied,
        'payment_required' => UrgentOutcome.paymentRequired,
        _ => null,
      },
      price: outcome is Map && outcome['price'] != null ? riyals(outcome['price']) : null,
    );
  }

  static Future<RequestItem> update(String appId, RequestItem draft) async =>
      fromJson((await Api.client!.put('requests/${rawId(appId)}', form(draft))).map);

  /// «عاجل» from the plan's balance; without balance an ApiError 402 whose `details.price` is its price.
  static Future<RequestItem> makeUrgent(String appId) async => fromJson((await Api.client!.post('requests/${rawId(appId)}/urgent')).map);

  static Future<RequestItem> close(String appId) async => fromJson((await Api.client!.post('requests/${rawId(appId)}/close')).map);

  /// Adds the request to the broker's clients; the client's id on the server.
  static Future<Object?> toClient(String appId) async => (await Api.client!.post('requests/${rawId(appId)}/to-client')).map['client_id'];

  /// A price the server says (`50.00`) as the app writes it (`50`).
  static String riyals(Object? price) => '${price ?? ''}'.replaceFirst(RegExp(r'\.0+$'), '');

  /// The price of «عاجل» in a 402 (`details.price`), as the app writes it; null when it did not say.
  static String? priceOf(ApiError e) => e.details['price'] == null ? null : riyals(e.details['price']);

  /// The form as the server reads it: an empty number is no number (not zero).
  static Map<String, Object?> form(RequestItem r) {
    num? amount(double v) => v.isNaN || v <= 0 ? null : (v == v.roundToDouble() ? v.toInt() : v);
    int? rooms(double v) => v.isNaN || v <= 0 ? null : v.round();
    return <String, Object?>{
      'purpose': r.purpose,
      'city': r.city,
      'neighborhoods': r.neighborhoods,
      'property_types': r.types,
      'budget_min': amount(r.priceMin),
      'budget_max': amount(r.priceMax),
      'area_min': amount(r.areaMin),
      'area_max': amount(r.areaMax),
      'rooms_min': rooms(r.roomsMin),
      'rooms_max': rooms(r.roomsMax),
      'description': r.description,
    };
  }

  /// A request of the server. A range the author left empty is 0, as the app's own forms keep it.
  static RequestItem fromJson(Map<String, Object?> j) {
    final author = j['author'] is Map ? (j['author']! as Map).cast<String, Object?>() : null;
    return RequestItem(
      id: requestId(j['id'] ?? ''),
      purpose: j['purpose'] is String ? j['purpose']! as String : 'sale',
      city: j['city'] is String ? j['city']! as String : '',
      neighborhoods: _strings(j['neighborhoods']),
      types: <String>{for (final t in _strings(j['property_types'])) normalizeType(t)}.toList(),
      priceMin: numOrNull(j['budget_min']) ?? 0,
      priceMax: numOrNull(j['budget_max']) ?? 0,
      areaMin: numOrNull(j['area_min']) ?? 0,
      areaMax: numOrNull(j['area_max']) ?? 0,
      roomsMin: numOrNull(j['rooms_min']) ?? 0,
      roomsMax: numOrNull(j['rooms_max']) ?? 0,
      description: j['description'] is String ? j['description']! as String : '',
      author: author != null && author['name'] is String ? author['name']! as String : '',
      role: j['author_role'] is String ? j['author_role']! as String : 'seeker',
      urgent: j['urgent'] == true,
      ownerId: author != null && author['id'] != null ? '${ServerSession.idPrefix}${author['id']}' : null,
      createdAt: j['created_at'] is String ? j['created_at']! as String : null,
      closed: j['status'] == 'closed',
      canAddToClients: j['can_add_to_clients'] == true,
    );
  }

  /// Every page of [path] (the first page's `meta` with them).
  static Future<({List<RequestItem> rows, Map<String, Object?> meta})> _all(String path) async {
    final rows = <RequestItem>[];
    var meta = const <String, Object?>{};
    for (var page = 1; page <= 50; page++) {
      final r = await Api.client!.get(path, query: <String, String>{'page': '$page'});
      if (page == 1) meta = r.meta;
      rows.addAll(<RequestItem>[for (final row in r.list) fromJson(row)]);
      if (ServerPage.paging(r.meta, page).last <= page) break;
    }
    return (rows: rows, meta: meta);
  }

  static List<String> _strings(Object? v) => v is List
      ? <String>[
          for (final e in v)
            if (e is String) e,
        ]
      : const <String>[];
}
