// «الرئيسية» with a server: the feed's pages for the filter card's query (GET /feed, ServerListings.feed).
//
// A new query starts again from its first page; «عرض المزيد» fetches the next. A page that arrives after the query
// changed belongs to the old one and is dropped. Rows go to the store (Store.keepServer), so a card opened next
// already has its row while its detail loads.

import 'package:flutter/foundation.dart';

import 'package:zon/core/net/api_error.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';

/// The feed of the home screen, one query at a time.
class Feed extends ChangeNotifier {
  FeedQuery? _query;
  final List<String> _ids = <String>[];
  int _page = 0;
  int _last = 0;
  int _generation = 0;
  bool _loading = false;
  String? _error;

  /// The query shown.
  FeedQuery? get query => _query;

  /// The rows loaded so far, in the server's order.
  List<Listing> get items => <Listing>[
    for (final id in _ids) ?store.listingById(id),
  ];

  /// A page is on its way.
  bool get loading => _loading;

  /// Why the last page did not come (the server's message, or «no connection»); null when it came.
  String? get error => _error;

  /// The first page has not come yet.
  bool get firstLoad => _page == 0 && _error == null;

  /// Another page follows.
  bool get hasMore => _page > 0 && _page < _last;

  /// Shows [query] from its first page (nothing happens when it is already shown).
  Future<void> show(FeedQuery query) {
    if (query == _query) return Future<void>.value();
    _query = query;
    _ids.clear();
    _page = 0;
    _last = 0;
    return _load(1);
  }

  /// The next page.
  Future<void> more() => hasMore && !_loading ? _load(_page + 1) : Future<void>.value();

  /// The page that failed, again.
  Future<void> retry() => _load(_page + 1);

  Future<void> _load(int page) async {
    final query = _query;
    if (query == null) return;
    final generation = ++_generation;
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      final result = await ServerListings.feed(query, page: page);
      if (generation != _generation) return;
      store.keepServer(result.items);
      if (page == 1) _ids.clear();
      final fresh = <String>[for (final l in result.items) if (!_ids.contains(l.id)) l.id];
      _ids.addAll(fresh);
      _page = result.page;
      _last = result.last;
    } on ApiError catch (e) {
      if (generation != _generation) return;
      _error = e.forUser;
    } finally {
      if (generation == _generation) {
        _loading = false;
        notifyListeners();
      }
    }
  }
}
