// `/users/:name` — a public profile: identity card, follow / rate, and the
// person's listings and requests.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/simulations.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/ask_card.dart';
import 'package:zon/shared/widgets/barq_card.dart';
import 'package:zon/shared/widgets/listing_card.dart';

/// Stable number from a name (used to vary the sample profile stats).
int _hash(String s) {
  var n = 0;
  for (final r in s.runes) {
    final cu = r > 0xFFFF ? String.fromCharCode(r).codeUnitAt(0) : r;
    n = (n * 31 + cu) & 0xFFFFFFFF;
  }
  return n;
}

/// `/users/$id` — public profile.
class UserProfileScreen extends StatefulWidget {
  const UserProfileScreen({super.key, required this.id, this.listing});

  final String id;
  final String? listing;

  @override
  State<UserProfileScreen> createState() => _UserProfileScreenState();
}

class _UserProfileScreenState extends State<UserProfileScreen> {
  late final String name = _decode(widget.id);
  String tab = 'offers';
  int stars = 0;
  bool sent = false;
  late bool follow = store.isFollowing(name);

  static String _decode(String s) {
    try {
      return Uri.decodeComponent(s);
    } catch (_) {
      return s;
    }
  }

  @override
  Widget build(BuildContext context) {
    final listings = store.allListings().where((l) => l.advertiser == name).toList();
    final asks = store.allRequests().where((r) => r.author == name).toList();
    final role = listings.isNotEmpty ? listings.first.advertiserRole : (asks.isNotEmpty ? asks.first.role : 'broker');
    final n = _hash(name);
    final verified = role == 'broker' || role == 'developer';

    Widget feed(List<Widget> items, String empty) {
      return Padding(
        padding: const EdgeInsets.only(top: 8, bottom: 20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            for (var i = 0; i < items.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 18), items[i]],
            if (items.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 20),
                child: Tx(
                  empty,
                  style: ts(15, w: 500, lh: 22.5, c: AppColors.stone),
                  align: TextAlign.center,
                ),
              ),
          ],
        ),
      );
    }

    return AppShell(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 48),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            BarqCard(name: name, verified: verified),
            if (kSimulations) ...<Widget>[
            const SizedBox(height: 14),
            Row(
              children: <Widget>[
                Expanded(
                  child: MeStat(n: loc(8 + (n % 40)), label: 'المتابِعون'),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: MeStat(n: loc(4 + (n % 20)), label: 'يتابِع'),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: MeStat(n: loc(3 + (n % 12)), label: 'المفضلة'),
                ),
              ],
            ),
            ],
            const SizedBox(height: 14),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
              child: Tap(
                onTap: () {
                  store.toggleFollow(Person(id: '$n', n: name, r: kRoleLabel[role] ?? '', role: role));
                  setState(() => follow = store.isFollowing(name));
                },
                child: Container(
                  height: 44,
                  decoration: BoxDecoration(color: follow ? AppColors.mist : AppColors.forest, borderRadius: BorderRadius.circular(999)),
                  foregroundDecoration: follow
                      ? BoxDecoration(
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(color: AppColors.forest, width: 1.4),
                        )
                      : null,
                  alignment: Alignment.center,
                  child: Tx(follow ? 'يتابع' : 'متابعة', style: ts(15, w: 700, lh: 22.5, c: follow ? AppColors.forest : AppColors.white)),
                ),
              ),
            ),
            if (kSimulations && !sent) ...<Widget>[
              const SizedBox(height: 14),
              Container(
                constraints: const BoxConstraints(minHeight: 56),
                padding: const EdgeInsets.fromLTRB(12, 8, 10, 8),
                decoration: BoxDecoration(
                  color: AppColors.white,
                  borderRadius: BorderRadius.circular(22),
                  boxShadow: const <BoxShadow>[
                    BoxShadow(color: AppColors.mistLine, spreadRadius: 1),
                    ...AppShadows.card,
                  ],
                ),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Row(
                        children: <Widget>[
                          for (var i = 1; i <= 5; i++) ...<Widget>[
                            if (i > 1) const SizedBox(width: 4),
                            Tap(
                              onTap: () => setState(() => stars = i),
                              child: SizedBox(
                                width: 36,
                                height: 36,
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
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(width: 12),
                    Tap(
                      onTap: stars == 0 ? null : () => setState(() => sent = true),
                      child: Opacity(
                        opacity: stars == 0 ? 0.35 : 1,
                        child: Container(
                          height: 40,
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
                          alignment: Alignment.center,
                          child: Tx('تقييم', style: ts(16, w: 700, lh: 24, c: AppColors.white)),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.all(4),
              decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(16)),
              child: Row(
                children: <Widget>[
                  for (final e in const <List<String>>[
                    <String>['offers', 'العروض'],
                    <String>['asks', 'الطلبات'],
                  ]) ...<Widget>[
                    if (e[0] == 'asks') const SizedBox(width: 4),
                    Expanded(
                      child: Tap(
                        onTap: () => setState(() => tab = e[0]),
                        child: Container(
                          height: 40,
                          decoration: BoxDecoration(
                            color: tab == e[0] ? AppColors.forest : const Color(0x00000000),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          alignment: Alignment.center,
                          child: Tx(e[1], style: ts(16, w: 600, lh: 24, c: tab == e[0] ? AppColors.white : AppColors.deep)),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 14),
            if (tab == 'offers')
              feed(<Widget>[for (final l in listings) ListingCard(listing: l)], 'لا عروض ظاهرة')
            else
              feed(<Widget>[for (final r in asks) AskCard(item: r)], 'لا طلبات منشورة'),
          ],
        ),
      ),
    );
  }
}
