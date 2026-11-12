// «الرئيسية» with a server: the feed's pages for the filter card's query (GET /feed, ServerListings.feed).
//
// A new query starts again from its first page; «عرض المزيد» fetches the next. A page that arrives after the query
// changed belongs to the old one and is dropped. The cards show the rows of the latest answers as they came, never
// another copy: a listing opened earlier is held in the store in detail (Store.keepServer), at that moment's
// price. The rows go to the store as well, for the screens that look a row up by its id.

import 'package:flutter/foundation.dart';

import 'package:zon/core/net/api_error.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';

/// The feed of the home screen, one query at a time.
class Feed extends ChangeNotifier {
  FeedQuery? _query;
  final List<Listing> _rows = <Listing>[];
  int _page = 0;
  int _last = 0;
  int _generation = 0;
  bool _disposed = false;
  bool _loading = false;
  String? _error;

  /// The query shown.
  FeedQuery? get query => _query;

  /// The rows loaded so far, in the server's order.
  List<Listing> get items => List<Listing>.unmodifiable(_rows);

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
    _rows.clear();
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
      if (generation != _generation || _disposed) return;
      store.keepServer(result.items);
      if (page == 1) _rows.clear();
      final shown = <String>{for (final l in _rows) l.id};
      _rows.addAll(result.items.where((l) => shown.add(l.id)));
      _page = result.page;
      _last = result.last;
    } on ApiError catch (e) {
      if (generation != _generation || _disposed) return;
      _error = e.forUser;
    } finally {
      // a page that comes after the screen closed, or after the query changed, is dropped
      if (generation == _generation && !_disposed) {
        _loading = false;
        notifyListeners();
      }
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
