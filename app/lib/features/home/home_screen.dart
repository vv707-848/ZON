// `/` — الرئيسية: header, the filter card and the feed of listings and
// projects (sorted, filtered, 18 at a time). With a server the feed is its
// own (GET /feed through data/server/feed.dart): the same filters and orders,
// page by page; without one, the samples and this phone's listings.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/clock.dart';
import 'package:zon/core/utils/sort.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/notifications/notification_center.dart';
import 'package:zon/data/server/feed.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/lens.dart';
import 'package:zon/shared/widgets/listing_card.dart';

/// `/` — الرئيسية
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  String purpose = 'all';
  String city = 'all';
  String hood = 'all';
  String source = 'all';
  String ptype = 'all';
  String sort = 'new';
  bool sortOpen = false;
  int limit = 18;

  /// The server's feed (a build with a server only).
  final Feed _feed = Feed();

  @override
  void dispose() {
    _feed.dispose();
    super.dispose();
  }

  /// The filter card as the server reads it.
  FeedQuery _query() => FeedQuery(
    purpose: purpose == 'all' ? null : purpose,
    city: city == 'all' ? null : city,
    neighborhood: hood == 'all' ? null : hood,
    source: source == 'all' ? null : source,
    propertyType: ptype == 'all' ? null : ptype,
    sort: sort,
  );

  List<Listing> _list(List<Listing> seed) {
    final now = appNow();
    final rows = seed.where((l) {
      if (purpose != 'all' && l.purpose != purpose) return false;
      if (purpose == 'rent' && l.kind == 'project') return false;
      if (city != 'all' && l.city != city) return false;
      if (hood != 'all' && l.neighborhood != hood) return false;
      if (source != 'all' && l.source != source) return false;
      if (ptype != 'all' && l.propertyType != ptype) return false;
      if (sort == 'feat' && !l.featuredAt(now)) return false;
      return true;
    }).toList();
    double area(Listing x) => x.area ?? x.builtArea ?? x.landArea ?? 0;
    int cmp(Listing a, Listing b) {
      if (sort == 'new' || sort == 'old' || sort == 'feat') {
        final prem = (b.featuredAt(now) ? 1 : 0) - (a.featuredAt(now) ? 1 : 0);
        if (prem != 0) return prem;
      }
      if (sort == 'price-asc') return fromPrice(a).compareTo(fromPrice(b));
      if (sort == 'price-desc') return fromPrice(b).compareTo(fromPrice(a));
      if (sort == 'area-asc') return area(a).compareTo(area(b));
      if (sort == 'area-desc') return area(b).compareTo(area(a));
      if (sort == 'old') return compareIds(a.id, b.id);
      return compareIds(b.id, a.id);
    }

    return stableSort(rows, cmp);
  }

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    final hoods = city == 'all' ? const <String>[] : (kCities[city] ?? const <String>[]);
    final server = Api.on;
    return AppShell(
      child: ListenableBuilder(
        listenable: Listenable.merge(<Listenable>[store, _feed, notifications]),
        builder: (context, _) {
          // a new filter, or another account (its rows are not shown): the server is asked again
          if (server && (_feed.query != _query() || _feed.stale)) {
            final query = _query();
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted) unawaited(_feed.show(query));
            });
          }
          final list = server ? _feed.items : _list(store.allListings());
          final shown = server ? list : list.take(limit).toList();
          final waiting = server && list.isEmpty && (_feed.loading || _feed.firstLoad);
          final failed = server && list.isEmpty && _feed.error != null;
          final more = server ? _feed.hasMore || (_feed.error != null && list.isNotEmpty) : list.length > limit;
          // unread notifications of the signed-in account (bell badge); with a server, asked of it now and then
          final alerts = user != null ? notifications.unreadCount() : 0;
          if (server && user != null) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted) unawaited(notifications.refresh());
            });
          }
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              // header
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 8),
                child: Row(
                  children: <Widget>[
                    const ZonLogo(size: 44),
                    const SizedBox(width: 12),
                    Tx('زون', style: ts(24, w: 700, lh: 24, c: AppColors.ink)),
                    const Spacer(),
                    Tap(
                      onTap: () => Nav.go(context, user != null ? '/notifications' : '/login'),
                      child: SizedBox(
                        key: const ValueKey<String>('home-bell'),
                        width: 44,
                        height: 44,
                        child: Stack(
                          clipBehavior: Clip.none,
                          children: <Widget>[
                            Container(
                              width: 44,
                              height: 44,
                              decoration: const BoxDecoration(color: Color(0x0D121214), shape: BoxShape.circle),
                              alignment: Alignment.center,
                              child: const LucideIcon(Lu.bell, size: 20, strokeWidth: 1.7, color: AppColors.ink),
                            ),
                            if (user != null && alerts > 0)
                              PositionedDirectional(
                                top: 6,
                                start: 6,
                                child: Container(
                                  constraints: const BoxConstraints(minWidth: 16),
                                  height: 16,
                                  padding: const EdgeInsets.symmetric(horizontal: 4),
                                  decoration: BoxDecoration(color: const Color(0xFFC24B28), borderRadius: BorderRadius.circular(8)),
                                  alignment: Alignment.center,
                                  child: Tx(alerts > 9 ? '9+' : '$alerts', style: ts(11, w: 700, lh: 16.5, c: AppColors.white)),
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              // filters
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 10),
                child: Lens(
                  mode: LensMode(
                    buttons: <LensModeButton>[
                      LensModeButton(
                        label: 'للبيع',
                        icon: Lu.house,
                        on: purpose == 'sale',
                        onTap: () => setState(() => purpose = purpose == 'sale' ? 'all' : 'sale'),
                      ),
                      LensModeButton(
                        label: 'للإيجار',
                        icon: Lu.keyRound,
                        on: purpose == 'rent',
                        onTap: () => setState(() => purpose = purpose == 'rent' ? 'all' : 'rent'),
                      ),
                    ],
                  ),
                  fields: <LensField>[
                    LensField(
                      label: 'المدينة',
                      value: city,
                      options: <Opt>[const Opt('all', 'الكل'), ...optsOf(kCities.keys)],
                      onChanged: (v) => setState(() {
                        city = v;
                        hood = 'all';
                      }),
                    ),
                    LensField(
                      label: 'الحي',
                      value: hood,
                      options: <Opt>[const Opt('all', 'الكل'), ...optsOf(hoods)],
                      onChanged: (v) => setState(() => hood = v),
                    ),
                    LensField(
                      label: 'المصدر',
                      value: source,
                      options: <Opt>[
                        const Opt('all', 'الكل'),
                        Opt('broker', kSourceLabel['broker']!),
                        Opt('developer', kSourceLabel['developer']!),
                        Opt('owner', kSourceLabel['owner']!),
                      ],
                      onChanged: (v) => setState(() => source = v),
                    ),
                    LensField(
                      label: 'النوع',
                      value: ptype,
                      options: <Opt>[const Opt('all', 'الكل'), for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
                      onChanged: (v) => setState(() => ptype = v),
                    ),
                  ],
                  sort: LensSort(
                    buttons: <LensSortButton>[
                      LensSortButton(
                        label: 'الأحدث',
                        on: sort == 'new',
                        onTap: () => setState(() {
                          sort = 'new';
                          sortOpen = false;
                        }),
                      ),
                      LensSortButton(
                        label: 'الأقدم',
                        on: sort == 'old',
                        onTap: () => setState(() {
                          sort = 'old';
                          sortOpen = false;
                        }),
                      ),
                      LensSortButton(
                        label: 'المميز',
                        on: sort == 'feat',
                        onTap: () => setState(() {
                          sort = 'feat';
                          sortOpen = false;
                        }),
                      ),
                    ],
                    metricOn: !(sort == 'new' || sort == 'old' || sort == 'feat'),
                    metricOpen: sortOpen,
                    onMetric: () => setState(() => sortOpen = !sortOpen),
                    menu: <PickItem>[
                      for (final e in const <List<String>>[
                        <String>['price-desc', 'أعلى سعر'],
                        <String>['price-asc', 'أقل سعر'],
                        <String>['area-desc', 'أعلى مساحة'],
                        <String>['area-asc', 'أقل مساحة'],
                      ])
                        PickItem(
                          label: e[1],
                          on: sort == e[0],
                          onTap: () => setState(() {
                            sort = e[0];
                            sortOpen = false;
                          }),
                        ),
                    ],
                  ),
                ),
              ),
              // feed
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    if (waiting)
                      const LoadingBox()
                    else if (failed)
                      EmptyBox(
                        title: 'تعذّر تحميل العروض',
                        text: _feed.error!,
                        textStyle: emptyTextStyle(),
                        action: 'أعد المحاولة',
                        onAction: () => unawaited(_feed.retry()),
                      )
                    else if (shown.isNotEmpty)
                      for (var i = 0; i < shown.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 18), ListingCard(listing: shown[i])]
                    else
                      EmptyBox(
                        title: 'لا نتائج بهذا الفلتر',
                        text: 'وسّع البحث أو ارفع طلب بحث ليصلك الوسطاء.',
                        textStyle: emptyTextStyle(),
                        action: 'ارفع طلب بحث',
                        onAction: () => Nav.go(context, user != null ? '/add' : '/login'),
                      ),
                  ],
                ),
              ),
              if (more)
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 32),
                  child: Tap(
                    onTap: () {
                      if (!server) {
                        setState(() => limit = (limit + 18) > 80 ? 80 : limit + 18);
                      } else if (_feed.error != null) {
                        unawaited(_feed.retry());
                      } else {
                        unawaited(_feed.more());
                      }
                    },
                    child: Container(
                      key: const ValueKey<String>('home-more'),
                      height: 48,
                      decoration: BoxDecoration(color: const Color(0x0D121214), borderRadius: BorderRadius.circular(999)),
                      alignment: Alignment.center,
                      child: Tx(
                        server && _feed.loading ? 'جارٍ التحميل…' : (server && _feed.error != null ? 'تعذّر التحميل — أعد المحاولة' : 'عرض المزيد'),
                        style: ts(15, w: 600, lh: 21.5, c: AppColors.ink),
                      ),
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
