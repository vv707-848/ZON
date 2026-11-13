// `/users/<username>` with a server (ServerPeople): an advertiser's public page as the server tells it.
//
// * Who he is: name, role, his FAL licence when verified, Nafath, city, since when — and his stars, followers and
//   live listings as counted by the server. Nothing is made up: no sample numbers.
// * Follow, stars (one per pair, changeable), «بلاغ» and «حظر» go to the server; until it answers, the button
//   waits, and when it cannot, it says why and nothing changes. Signed out, they lead to «تسجيل الدخول».
// * A block hides him at once (his page, listings and messages); «تراجع» undoes it while still here.
// * His listings come page by page (20).
// * A seeker has no public page, and an account that blocked him neither: «الصفحة غير متاحة».
// * Another account signed in under it: the page is asked again for it.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_people.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/details/widgets/offer_react.dart';
import 'package:zon/shared/widgets/barq_card.dart';
import 'package:zon/shared/widgets/listing_card.dart';

class ServerUserProfile extends StatefulWidget {
  const ServerUserProfile({super.key, required this.username});

  final String username;

  @override
  State<ServerUserProfile> createState() => _ServerUserProfileState();
}

class _ServerUserProfileState extends State<ServerUserProfile> {
  Profile? _p;
  final List<Listing> _listings = <Listing>[];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = true;
  bool _more = false;
  bool _gone = false;
  bool _blocked = false;
  String? _busy;
  String? _error;
  int _stars = 0;
  bool _reported = false;
  int _epoch = store.serverEpoch;

  bool get _signedIn => store.readSession() != null;
  bool get _mine => store.readSession()?.username == widget.username;

  @override
  void initState() {
    super.initState();
    store.addListener(_onAccount);
    unawaited(_load());
  }

  @override
  void dispose() {
    store.removeListener(_onAccount);
    super.dispose();
  }

  void _onAccount() {
    if (!mounted || _epoch == store.serverEpoch) return;
    _epoch = store.serverEpoch;
    setState(() {
      _p = null;
      _listings.clear();
      _loading = true;
      _gone = false;
      _blocked = false;
      _busy = null;
      _error = null;
      _reported = false;
    });
    unawaited(_load());
  }

  bool _current(int epoch) => mounted && epoch == _epoch && epoch == store.serverEpoch;

  Future<void> _load() async {
    final epoch = _epoch;
    try {
      final p = await ServerPeople.profile(widget.username);
      final first = await ServerPeople.listings(widget.username);
      if (!_current(epoch)) return;
      setState(() {
        _p = p;
        _stars = p.myRating ?? 0;
        _listings
          ..clear()
          ..addAll(first.items);
        _page = 1;
        _hasMore = first.hasMore;
        _loading = false;
      });
    } on ApiError catch (e) {
      if (!_current(epoch)) return;
      setState(() {
        _loading = false;
        if (e.status == 404) {
          _gone = true;
        } else {
          _error = e.forUser;
        }
      });
    }
  }

  Future<void> _loadMore() async {
    final epoch = _epoch;
    setState(() => _more = true);
    try {
      final next = await ServerPeople.listings(widget.username, page: _page + 1);
      if (!_current(epoch)) return;
      setState(() {
        _page++;
        final have = <String>{for (final l in _listings) l.id};
        _listings.addAll(next.items.where((l) => !have.contains(l.id)));
        _hasMore = next.hasMore;
      });
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } finally {
      if (_current(epoch)) setState(() => _more = false);
    }
  }

  /// Runs [action] as [what] (one at a time); its error is said and nothing changes.
  Future<void> _do(String what, Future<void> Function(int epoch) action) async {
    if (_busy != null) return;
    if (!_signedIn) {
      unawaited(Nav.go(context, '/login'));
      return;
    }
    final epoch = _epoch;
    setState(() {
      _busy = what;
      _error = null;
    });
    try {
      await action(epoch);
    } on ApiError catch (e) {
      if (_current(epoch)) setState(() => _error = e.forUser);
    } on AccountChanged {
      // the next account asks for itself
    } finally {
      if (_current(epoch)) setState(() => _busy = null);
    }
  }

  Future<void> _follow(Profile p) => _do('follow', (epoch) async {
    final now = await ServerPeople.follow(p.username, on: !p.isFollowing, epoch: epoch);
    if (!_current(epoch) || now == p.isFollowing) return;
    setState(() => _p = p.copyWith(isFollowing: now, followers: (p.followers + (now ? 1 : -1)).clamp(0, 1 << 30)));
  });

  Future<void> _rate(Profile p) => _do('rate', (epoch) async {
    final kept = await ServerPeople.rate(p.username, _stars, epoch: epoch);
    if (!_current(epoch)) return;
    // the average and count are the server's: asked again rather than reckoned here
    final again = await ServerPeople.profile(p.username);
    if (_current(epoch)) setState(() => _p = again.copyWith(myRating: kept));
  });

  Future<void> _block(Profile p) async {
    if (!_signedIn) {
      unawaited(Nav.go(context, '/login'));
      return;
    }
    final sure = await showAppDialog(
      context,
      icon: Lu.shield,
      title: 'حظر ${p.name}؟',
      message: 'لن تظهر لك عروضه ولا رسائله، ولن يستطيع مراسلتك. لا يُبلَّغ بذلك.',
      confirmLabel: 'حظر',
      destructive: true,
    );
    if (!sure || !mounted) return;
    await _do('block', (epoch) async {
      await ServerPeople.block(p.username, on: true, epoch: epoch);
      if (_current(epoch)) setState(() => _blocked = true);
    });
  }

  Future<void> _unblock(Profile p) => _do('unblock', (epoch) async {
    await ServerPeople.block(p.username, on: false, epoch: epoch);
    if (!_current(epoch)) return;
    setState(() {
      _blocked = false;
      _loading = true;
    });
    await _load();
  });

  Future<void> _report(Profile p) async {
    if (!_signedIn) {
      unawaited(Nav.go(context, '/login'));
      return;
    }
    final epoch = _epoch;
    final sent = await showReportDialog(
      context,
      title: 'الإبلاغ عن الحساب',
      send: (reason) => ServerPeople.report(reason, account: p.id, epoch: epoch),
    );
    if (sent && _current(epoch)) setState(() => _reported = true);
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    return AppShell(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 48),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (_loading) const LoadingBox(),
            if (_gone) const _Note('الصفحة غير متاحة.'),
            if (!_loading && p == null && _error != null) FormError(_error!),
            if (p != null && _blocked) ...<Widget>[
              _Note('حظرت ${p.name}؛ لن تظهر لك عروضه ولا رسائله.'),
              const SizedBox(height: 12),
              AppButton(
                label: _busy == 'unblock' ? 'جارٍ التراجع…' : 'تراجع عن الحظر',
                tone: ButtonTone.tonal,
                onTap: _busy == null ? () => unawaited(_unblock(p)) : null,
              ),
              if (_error case final String e) ...<Widget>[const SizedBox(height: 10), FormError(e)],
            ] else if (p != null)
              ..._content(p),
          ],
        ),
      ),
    );
  }

  List<Widget> _content(Profile p) {
    final since = p.memberSince;
    final facts = <String>[
      kRoleLabel[p.role] ?? '',
      if (p.city case final String c when c.isNotEmpty) c,
      if (since != null) 'عضو منذ ${since.year}',
    ].where((s) => s.isNotEmpty).join(' · ');
    return <Widget>[
      BarqCard(name: p.name, verified: p.falVerified),
      const SizedBox(height: 10),
      Tx(
        facts,
        style: ts(14, w: 600, lh: 21, c: AppColors.sage),
        align: TextAlign.center,
      ),
      if (p.falVerified || p.nafathVerified) ...<Widget>[
        const SizedBox(height: 8),
        Wrap(
          alignment: WrapAlignment.center,
          spacing: 8,
          runSpacing: 8,
          children: <Widget>[
            if (p.falVerified) _Chip(icon: Lu.badgeCheck, text: p.falNumber == null ? 'رخصة فال موثّقة' : 'رخصة فال ${p.falNumber}'),
            if (p.nafathVerified) const _Chip(icon: Lu.shieldCheck, text: 'موثّق بنفاذ'),
          ],
        ),
      ],
      const SizedBox(height: 14),
      Row(
        children: <Widget>[
          Expanded(
            child: MeStat(n: loc(p.followers), label: 'المتابِعون'),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: MeStat(n: loc(p.listings), label: 'العروض'),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: MeStat(
              n: p.ratingCount == 0 ? '—' : p.ratingAverage.toStringAsFixed(1),
              label: p.ratingCount == 0 ? 'لا تقييمات' : 'من ${loc(p.ratingCount)} تقييم',
            ),
          ),
        ],
      ),
      if (!_mine) ...<Widget>[
        const SizedBox(height: 14),
        AppButton(
          label: _busy == 'follow' ? 'لحظة…' : (p.isFollowing ? 'تتابعه' : 'متابعة'),
          tone: p.isFollowing ? ButtonTone.tonal : ButtonTone.primary,
          icon: p.isFollowing ? Lu.check : Lu.userPlus,
          onTap: _busy == null ? () => unawaited(_follow(p)) : null,
        ),
        const SizedBox(height: 12),
        _Stars(
          stars: _stars,
          mine: p.myRating,
          busy: _busy == 'rate',
          onPick: (n) => setState(() => _stars = n),
          onSend: _busy == null && _stars > 0 && _stars != p.myRating ? () => unawaited(_rate(p)) : null,
        ),
      ],
      if (_error case final String e) ...<Widget>[const SizedBox(height: 10), FormError(e)],
      const SizedBox(height: 18),
      Tx('العروض', style: ts(18, w: 700, lh: 27, c: AppColors.deep)),
      const SizedBox(height: 8),
      for (var i = 0; i < _listings.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 18), ListingCard(listing: _listings[i])],
      if (_listings.isEmpty) const _Note('لا عروض ظاهرة'),
      if (_hasMore) ...<Widget>[
        const SizedBox(height: 14),
        AppButton(label: _more ? 'جارٍ الجلب…' : 'المزيد', tone: ButtonTone.tonal, onTap: _more ? null : () => unawaited(_loadMore())),
      ],
      if (!_mine) ...<Widget>[
        const SizedBox(height: 24),
        Row(
          children: <Widget>[
            Expanded(
              child: _reported
                  ? Center(
                      child: Tx('وصل البلاغ', style: ts(14, w: 600, lh: 21, c: AppColors.success)),
                    )
                  : AppButton(label: 'بلاغ', tone: ButtonTone.ghost, icon: Lu.flag, onTap: _busy == null ? () => unawaited(_report(p)) : null),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: AppButton(
                label: _busy == 'block' ? 'جارٍ الحظر…' : 'حظر',
                tone: ButtonTone.ghost,
                icon: Lu.shield,
                onTap: _busy == null ? () => unawaited(_block(p)) : null,
              ),
            ),
          ],
        ),
      ],
    ];
  }
}

class _Note extends StatelessWidget {
  const _Note(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 20),
    child: Tx(
      text,
      style: ts(15, w: 500, lh: 22.5, c: AppColors.stone),
      align: TextAlign.center,
    ),
  );
}

class _Chip extends StatelessWidget {
  const _Chip({required this.icon, required this.text});

  final String icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
    height: 30,
    padding: const EdgeInsets.symmetric(horizontal: 10),
    decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(999)),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        LucideIcon(icon, size: 15, strokeWidth: 2, color: AppColors.forest),
        const SizedBox(width: 6),
        Tx(text, style: ts(13, w: 600, lh: 19.5, c: AppColors.forest)),
      ],
    ),
  );
}

/// Five stars and «تقييم»; his stars on the server are said under them.
class _Stars extends StatelessWidget {
  const _Stars({required this.stars, required this.mine, required this.busy, required this.onPick, required this.onSend});

  final int stars;
  final int? mine;
  final bool busy;
  final ValueChanged<int> onPick;
  final VoidCallback? onSend;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 10, 8),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const <BoxShadow>[
          BoxShadow(color: AppColors.mistLine, spreadRadius: 1),
          ...AppShadows.card,
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Row(
                  children: <Widget>[
                    for (var i = 1; i <= 5; i++)
                      Semantics(
                        button: true,
                        selected: stars >= i,
                        label: '$i نجوم',
                        child: Tap(
                          onTap: () => onPick(i),
                          child: SizedBox(
                            width: 40,
                            height: 40,
                            child: Center(
                              child: LucideIcon(
                                Lu.star,
                                size: 22,
                                strokeWidth: stars >= i ? 0 : 1.7,
                                color: stars >= i ? AppColors.forest : const Color(0xFFC5C8C4),
                                fill: stars >= i ? AppColors.forest : null,
                              ),
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(
                width: 96,
                child: AppButton(label: busy ? 'لحظة…' : 'تقييم', onTap: onSend),
              ),
            ],
          ),
          if (mine case final int m) Tx('تقييمك: $m من 5', style: ts(13, w: 600, lh: 19.5, c: AppColors.sage)),
        ],
      ),
    );
  }
}
