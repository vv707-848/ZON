// المتابعون — followers, following and favourite listings.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
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
  late var book = store.follows();

  @override
  void initState() {
    super.initState();
    _q.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
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
    final favs = store.allListings().where((l) => ids.contains(l.id)).toList();

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

    if (kind == 'favs') {
      children.add(
        favs.isNotEmpty
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  for (var i = 0; i < favs.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 18), ListingCard(listing: favs[i])],
                ],
              )
            : _empty('لا مفضلة بعد — اضغط القلب على أي عرض.'),
      );
    } else {
      children.add(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < rows.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 8), _row(rows[i])],
            if (rows.isEmpty) _empty('لا نتائج بهذا الاسم'),
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
              onTap: () => Nav.go(context, '/users/${slugName(x.n)}'),
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
              onTap: () {
                store.toggleFollow(x);
                setState(() => book = store.follows());
              },
              child: Container(
                height: 32,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(999)),
                alignment: Alignment.center,
                child: Tx('إلغاء', style: ts(13, w: 700, lh: 19.5, c: AppColors.forest)),
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
