// `/account` — حسابي: the identity card, counters, the services the user's
// role can open, and the settings row. Guests see a sign-in invitation.
// With a server the counters are the server's (his followers, the accounts he follows, his likes).

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/billing/billing.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_people.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/barq_card.dart';

/// `/account` — حسابي
class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    if (user == null) return const _Guest();
    return const _Me();
  }
}

class _Guest extends StatelessWidget {
  const _Guest();

  @override
  Widget build(BuildContext context) {
    return AppShell(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 24, 20, 0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            const BarqCard(name: 'زائر زون', locked: true),
            const SizedBox(height: 24),
            Tx(
              'بطاقة زون هي هويتك داخل المنصة — وسيط، مالك، باحث أو مطوّر. من هنا تدير عروضك وطلباتك ومفضلتك.',
              style: ts(15, lh: 30, c: AppColors.muted),
            ),
            const SizedBox(height: 24),
            AppButton(label: 'دخول أو إنشاء حساب', size: ButtonSize.large, onTap: () => Nav.go(context, '/login')),
            if (Api.on) ...<Widget>[
              const SizedBox(height: 12),
              AppButton(label: 'تواصل مع الدعم', tone: ButtonTone.ghost, onTap: () => Nav.go(context, '/support')),
            ],
          ],
        ),
      ),
    );
  }
}

class _Svc {
  const _Svc(this.svc, this.label, this.icon, {this.locked = false});

  final String svc;
  final String label;
  final String icon;
  final bool locked;
}

class _Me extends StatelessWidget {
  const _Me();

  @override
  Widget build(BuildContext context) {
    final user = store.readSession()!;
    final sub = billing.isSubscribed();
    final alerts = store.matchBadge();
    final c = user.role;
    final t = sub || c == 'developer';
    final verified = c == 'broker' || c == 'developer';
    final services = <_Svc>[
      _Svc('matching', 'مساعد المطابقة', Lu.sparkles, locked: !t),
      _Svc('search', 'البحث الدلالي', Lu.search, locked: !t),
      const _Svc('clients', 'إدارة العملاء', Lu.users),
      _Svc('owners', 'إدارة الملاك', Lu.keyRound, locked: c == 'broker' && !t),
      const _Svc('inventory', 'المخزون', Lu.warehouse),
      const _Svc('tasks', 'مهامي', Lu.clipboardList),
      const _Svc('requests', 'طلباتي', Lu.inbox),
      _Svc('listings', c == 'developer' ? 'مشاريعي' : 'عروضي المنشورة', c == 'developer' ? Lu.building2 : Lu.layoutGrid),
    ].where((s) => kSvcRoles[s.svc]?.contains(c) ?? false).toList();

    final rows = <Widget>[];
    for (var i = 0; i < services.length; i += 2) {
      if (i > 0) rows.add(const SizedBox(height: 8));
      rows.add(
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Expanded(
                child: SvcTile(
                  svc: services[i].svc,
                  icon: services[i].icon,
                  label: services[i].label,
                  locked: services[i].locked,
                  badge: services[i].svc == 'matching' ? alerts : 0,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: i + 1 < services.length
                    ? SvcTile(
                        svc: services[i + 1].svc,
                        icon: services[i + 1].icon,
                        label: services[i + 1].label,
                        locked: services[i + 1].locked,
                        badge: services[i + 1].svc == 'matching' ? alerts : 0,
                      )
                    : const SizedBox.shrink(),
              ),
            ],
          ),
        ),
      );
    }

    return AppShell(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            BarqCard(name: user.name, verified: verified),
            const SizedBox(height: 16),
            const _Counts(),
            if (!sub && c != 'developer') ...<Widget>[
              const SizedBox(height: 16),
              Tap(
                onTap: () => Nav.go(context, '/account/plans'),
                child: Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(28)),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Tx('ZONE+', style: ts(11, w: 600, lh: 16.5, c: const Color(0xFFE0B84A))),
                      const SizedBox(height: 4),
                      Tx('خدمة تسهيل البحث', style: ts(18, w: 600, lh: 28, c: AppColors.white)),
                      const SizedBox(height: 4),
                      Tx('الصفقة خارج التطبيق. الباقة تفتح أدوات البحث وطلبات العملاء.', style: ts(15, lh: 21.5, c: const Color(0xA6FFFFFF))),
                      const SizedBox(height: 16),
                      Container(
                        height: 48,
                        decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(16)),
                        alignment: Alignment.center,
                        child: Tx(
                          'شاهد الباقات',
                          style: ts(16, w: 700, lh: 24, c: AppColors.forest),
                          align: TextAlign.center,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
            const SizedBox(height: 16),
            ...rows,
            const SizedBox(height: 10),
            const SvcTile(svc: 'settings', icon: Lu.settings, label: 'الإعدادات'),
          ],
        ),
      ),
    );
  }
}

/// A service tile of the account page (or the settings row).
class SvcTile extends StatelessWidget {
  const SvcTile({super.key, required this.svc, required this.icon, required this.label, this.locked = false, this.badge = 0});

  final String svc;
  final String icon;
  final String label;
  final bool locked;
  final int badge;

  @override
  Widget build(BuildContext context) {
    final set = svc == 'settings';
    void go() => Nav.go(context, '/account/${locked ? 'plans' : svc}');
    if (set) {
      return Tap(
        onTap: go,
        child: Container(
          constraints: const BoxConstraints(minHeight: 56),
          padding: const EdgeInsets.symmetric(horizontal: 16),
          decoration: BoxDecoration(
            color: AppColors.white,
            borderRadius: BorderRadius.circular(24),
            boxShadow: const <BoxShadow>[BoxShadow(color: AppColors.mistLine, spreadRadius: 1)],
          ),
          child: Row(
            children: <Widget>[
              Container(
                width: 30,
                height: 30,
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(8)),
                alignment: Alignment.center,
                child: LucideIcon(icon, size: 15, strokeWidth: 2.1, color: AppColors.white),
              ),
              const SizedBox(width: 12),
              Tx(label, style: ts(16, w: 600, lh: 21, c: AppColors.deep)),
              const Spacer(),
              const Opacity(opacity: 0.45, child: LucideIcon(Lu.chevronLeft, size: 18, color: AppColors.forest)),
            ],
          ),
        ),
      );
    }
    final tile = Container(
      constraints: const BoxConstraints(minHeight: 104),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: const <BoxShadow>[
          BoxShadow(color: AppColors.mistLine, spreadRadius: 1),
          ...AppShadows.card,
        ],
      ),
      child: Stack(
        clipBehavior: Clip.none,
        children: <Widget>[
          Column(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(10)),
                alignment: Alignment.center,
                child: LucideIcon(icon, size: 18, strokeWidth: 2.1, color: AppColors.white),
              ),
              const SizedBox(height: 16),
              Tx(label, style: ts(14, w: 600, lh: 18, c: AppColors.deep)),
            ],
          ),
          if (badge > 0)
            // badge in the tile's top-left corner
            PositionedDirectional(
              top: 12 - 14,
              end: 12 - 14,
              child: Container(
                constraints: const BoxConstraints(minWidth: 18),
                height: 18,
                padding: const EdgeInsets.symmetric(horizontal: 5),
                decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(9)),
                alignment: Alignment.center,
                child: Tx(badge > 9 ? '9+' : '$badge', style: ts(11, w: 700, lh: 16.5, c: AppColors.white)),
              ),
            ),
        ],
      ),
    );
    return Tap(
      onTap: go,
      child: locked ? Opacity(opacity: 0.4, child: tile) : tile,
    );
  }
}

/// المتابِعون · يتابِع · المفضلة — each opens its list.
class _Counts extends StatefulWidget {
  const _Counts();

  @override
  State<_Counts> createState() => _CountsState();
}

class _CountsState extends State<_Counts> {
  /// With a server: its counts once it answered (null until then, or when it could not).
  ({int followers, int following, int favs})? _server;
  int _epoch = store.serverEpoch;

  @override
  void initState() {
    super.initState();
    if (Api.on) unawaited(_load());
  }

  Future<void> _load() async {
    final epoch = _epoch = store.serverEpoch;
    try {
      final followers = await ServerPeople.people(followers: true);
      final following = await ServerPeople.people(followers: false);
      final favs = await ServerFavorites.list();
      if (mounted && epoch == store.serverEpoch) {
        setState(() => _server = (followers: followers.length, following: following.length, favs: favs.length));
      }
    } on ApiError {
      // shown as «—»: not a made-up zero
    }
  }

  @override
  Widget build(BuildContext context) {
    if (Api.on && _epoch != store.serverEpoch) {
      _server = null;
      unawaited(_load());
    }
    final book = Api.on ? null : store.follows();
    final n = Api.on ? _server : (followers: book!.followers.length, following: book.following.length, favs: store.likedIds().length);
    Widget stat(int? count, String l, String kind) => Tap(
      onTap: () => Nav.go(context, '/account/people?kind=$kind'),
      child: MeStat(n: count == null ? '—' : loc(count), label: l),
    );
    return Row(
      children: <Widget>[
        Expanded(child: stat(n?.followers, 'المتابِعون', 'followers')),
        const SizedBox(width: 8),
        Expanded(child: stat(n?.following, 'يتابِع', 'following')),
        const SizedBox(width: 8),
        Expanded(child: stat(n?.favs, 'المفضلة', 'favs')),
      ],
    );
  }
}
