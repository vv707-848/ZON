// A list of the server read page by page («عرض المزيد»), for one query at a time: «الرئيسية» (Feed), «الطلبات»
// (RequestsBoard).
//
// A new query starts again from its first page. A page that arrives after the query changed, or after the screen
// closed, is dropped. The list shows the rows of the latest answers as they came, never another copy of them (a
// row opened earlier is held in the store at that moment's values); [ServerPager.keep] hands them to the store as
// well, for the screens that look a row up by its id.

import 'package:flutter/foundation.dart';

import 'package:zon/core/net/api_error.dart';

/// One page of a list: its rows and whether another follows.
class ServerPage<T> {
  const ServerPage(this.items, {required this.page, required this.last});

  final List<T> items;
  final int page;
  final int last;

  bool get hasMore => page < last;

  /// The page of an answer's `meta.page` ({current, last}); [asked] when the server did not say.
  static ({int page, int last}) paging(Map<String, Object?> meta, int asked) {
    final p = meta['page'];
    final page = p is Map ? p['current'] : null;
    final last = p is Map ? p['last'] : null;
    return (page: page is num ? page.toInt() : asked, last: last is num ? last.toInt() : asked);
  }
}

/// The pages of [fetch] for one query at a time.
class ServerPager<Q, T> extends ChangeNotifier {
  ServerPager({required this.fetch, required this.idOf, this.keep});

  /// One page of the query.
  final Future<ServerPage<T>> Function(Q query, int page) fetch;

  /// The row's id (a row met twice across pages is shown once).
  final String Function(T row) idOf;

  /// Hands the rows of each page to the store.
  final void Function(List<T> rows)? keep;

  Q? _query;
  final List<T> _rows = <T>[];
  int _page = 0;
  int _last = 0;
  int _generation = 0;
  bool _disposed = false;
  bool _loading = false;
  String? _error;

  /// The query shown.
  Q? get query => _query;

  /// The rows loaded so far, in the server's order.
  List<T> get items => List<T>.unmodifiable(_rows);

  /// A page is on its way.
  bool get loading => _loading;

  /// Why the last page did not come (the server's message, or «no connection»); null when it came.
  String? get error => _error;

  /// The first page has not come yet.
  bool get firstLoad => _page == 0 && _error == null;

  /// Another page follows.
  bool get hasMore => _page > 0 && _page < _last;

  /// Shows [query] from its first page (nothing happens when it is already shown).
  Future<void> show(Q query) {
    if (query == _query) return Future<void>.value();
    _query = query;
    _rows.clear();
    _page = 0;
    _last = 0;
    return _load(1);
  }

  /// The first page again (a row was added, changed or closed).
  Future<void> reload() => _query == null ? Future<void>.value() : _load(1);

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
      final result = await fetch(query, page);
      if (generation != _generation || _disposed) return;
      keep?.call(result.items);
      if (page == 1) _rows.clear();
      final shown = <String>{for (final r in _rows) idOf(r)};
      _rows.addAll(result.items.where((r) => shown.add(idOf(r))));
      _page = result.page;
      _last = result.last;
    } on ApiError catch (e) {
      if (generation != _generation || _disposed) return;
      _error = e.forUser;
    } finally {
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
