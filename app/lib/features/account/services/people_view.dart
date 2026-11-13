// المتابعون — followers, following and favourite listings. With a server the followers and the followed are
// the server's (ServerPeople.people), and «إلغاء» stops following there; the liked offers are the server's too
// (ServerFavorites.list).

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_people.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/listing_card.dart';

/// المتابعون: followers, following and favourites.
class PeopleView extends StatefulWidget {
  const PeopleView({super.key, this.kind});

  final String? kind;

  @override
  State<PeopleView> createState() => _PeopleViewState();
}

class _PeopleViewState extends State<PeopleView> {
  late String kind = (widget.kind == 'following' || widget.kind == 'favs') ? widget.kind! : 'followers';
  final TextEditingController _q = TextEditingController();
  late var book = Api.on ? (followers: <Person>[], following: <Person>[]) : store.follows();

  /// With a server: asking it, and what it could not answer.
  bool _loading = Api.on;
  List<Listing> _favs = <Listing>[];
  String? _error;
  String? _busy;
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    _q.addListener(() => setState(() {}));
    if (Api.on) {
      store.addListener(_onAccount);
      unawaited(_load());
    }
  }

  @override
  void dispose() {
    if (Api.on) store.removeListener(_onAccount);
    _q.dispose();
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    setState(() {
      book = (followers: <Person>[], following: <Person>[]);
      _favs = <Listing>[];
      _loading = true;
      _error = null;
      _busy = null;
    });
    unawaited(_load());
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _load() async {
    final epoch = _epoch;
    try {
      final followers = await ServerPeople.people(followers: true);
      final following = await ServerPeople.people(followers: false);
      final favs = await ServerFavorites.list();
      if (!_current(epoch)) return;
      setState(() {
        book = (followers: followers, following: following);
        _favs = favs;
        _loading = false;
      });
    } on ApiError catch (e) {
      if (_current(epoch)) {
        setState(() {
          _loading = false;
          _error = e.forUser;
        });
      }
    }
  }

  /// «إلغاء» on someone he follows: on the server, or on this phone.
  Future<void> _unfollow(Person x) async {
    if (!Api.on) {
      store.toggleFollow(x);
      setState(() => book = store.follows());
      return;
    }
    if (_busy != null) return;
    final epoch = _epoch;
    setState(() {
      _busy = x.id;
      _error = null;
    });
    try {
      await ServerPeople.follow(x.id, on: false, epoch: epoch);
      if (_current(epoch)) {
        setState(
          () => book = (
            followers: book.followers,
            following: <Person>[
              for (final p in book.following)
                if (p.id != x.id) p,
            ],
          ),
        );
      }
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } on AccountChanged {
      // the next account's list is asked for itself
    } finally {
      if (_current(epoch)) setState(() => _busy = null);
    }
  }

  void _go(String next) {
    setState(() {
      kind = next;
      _q.clear();
    });
  }

  @override
  Widget build(BuildContext context) {
    final q = _q.text.trim();
    final rows = (kind == 'following' ? book.following : book.followers).where((x) => q.isEmpty || x.n.contains(q)).toList();
    final ids = store.likedIds().toSet();
    final favs = Api.on ? _favs : store.allListings().where((l) => ids.contains(l.id)).toList();

    Widget tab(String k, String label) => Expanded(
      child: Tap(
        onTap: () => _go(k),
        child: Container(
          height: 44,
          decoration: BoxDecoration(color: kind == k ? AppColors.forest : const Color(0x00000000), borderRadius: BorderRadius.circular(12)),
          alignment: Alignment.center,
          child: Tx(label, style: ts(14, w: 700, lh: 21, c: kind == k ? AppColors.white : AppColors.sage)),
        ),
      ),
    );

    final children = <Widget>[
      Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(16)),
        child: Row(
          children: <Widget>[
            tab('followers', 'المتابِعون'),
            const SizedBox(width: 4),
            tab('following', 'يتابع'),
            const SizedBox(width: 4),
            tab('favs', 'المفضلة'),
          ],
        ),
      ),
    ];

    if (kind != 'favs') {
      children.add(
        Container(
          height: 44,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(14)),
          foregroundDecoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AppColors.mistLine, width: 1.4),
          ),
          child: Row(
            children: <Widget>[
              const LucideIcon(Lu.search, size: 16, color: AppColors.sage),
              const SizedBox(width: 8),
              Expanded(
                child: FieldBox(
                  controller: _q,
                  placeholder: 'ابحث بالاسم…',
                  style: ts(15, w: 600, lh: 22.5, c: AppColors.forest),
                  placeholderColor: const Color(0xFF8A9690),
                  placeholderWeight: 500,
                  height: null,
                  padding: EdgeInsets.zero,
                ),
              ),
            ],
          ),
        ),
      );
    }

    if (Api.on && _loading) children.add(const LoadingBox());
    if (Api.on && _error != null) children.add(FormError(_error!));

    if (kind == 'favs') {
      children.add(
        favs.isNotEmpty
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  for (var i = 0; i < favs.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 18), ListingCard(listing: favs[i])],
                ],
              )
            : (_loading ? const SizedBox.shrink() : _empty('لا مفضلة بعد — اضغط القلب على أي عرض.')),
      );
    } else {
      children.add(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < rows.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 8), _row(rows[i])],
            if (rows.isEmpty && !_loading)
              _empty(q.isEmpty ? (kind == 'following' ? 'لا تتابع أحداً بعد.' : 'لا متابِعين بعد.') : 'لا نتائج بهذا الاسم'),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 12), children[i]],
        ],
      ),
    );
  }

  Widget _empty(String t) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 18),
    child: Tx(
      t,
      style: ts(14, w: 600, lh: 21, c: AppColors.sage),
      align: TextAlign.center,
    ),
  );

  Widget _row(Person x) {
    return Container(
      padding: const EdgeInsets.fromLTRB(10, 10, 12, 10),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Tap(
              onTap: () => Nav.go(context, '/users/${Api.on ? Uri.encodeComponent(x.id) : slugName(x.n)}'),
              child: Row(
                children: <Widget>[
                  CrmMark(letter: x.n.isEmpty ? '' : String.fromCharCode(x.n.codeUnitAt(0))),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Tx(x.n, style: ts(16, w: 700, lh: 21, c: AppColors.forest)),
                        const SizedBox(height: 2),
                        Tx(x.r, style: ts(13, w: 600, lh: 19.5, c: AppColors.sage)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(width: 8),
          if (kind == 'following')
            Tap(
              onTap: _busy == null ? () => unawaited(_unfollow(x)) : null,
              child: Container(
                height: 32,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(999)),
                alignment: Alignment.center,
                child: Tx(_busy == x.id ? 'لحظة…' : 'إلغاء', style: ts(13, w: 700, lh: 19.5, c: AppColors.forest)),
              ),
            )
          else
            Tx(x.r, style: ts(16, lh: 24, c: AppColors.ink)),
        ],
      ),
    );
  }
}

/// Square tile with a name's first letter.
class CrmMark extends StatelessWidget {
  const CrmMark({
    super.key,
    required this.letter,
    this.size = 48,
    this.radius = 16,
    this.bg = AppColors.forest,
    this.fg = AppColors.white,
    this.fontSize = 18,
    this.weight = 700,
  });

  final String letter;
  final double size;
  final double radius;
  final Color bg;
  final Color fg;
  final double fontSize;
  final int weight;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(radius)),
      alignment: Alignment.center,
      child: Tx(
        letter,
        style: ts(fontSize, w: weight, lh: fontSize * 1.5, c: fg),
      ),
    );
  }
}
