// Browsing with a server (lib/data/server/server_listings.dart, feed.dart, shared/widgets/server_detail.dart):
// «الرئيسية» shows GET /feed, a card opens the row fresh from GET /listings/{id} or /projects/{id}. The server's
// answers here are the ones its own tests recorded (docs/contracts/fixtures/api, FeedTest): when the server
// changes what it says, its tests fail first, and these replay what it says now.

import 'dart:convert';
import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/core/widgets/app_image.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/feed.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/shared/widgets/listing_card.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

/// The server's recorded answer [name] (docs/contracts/fixtures/api/[name].json).
Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

List<Map<String, Object?>> _rows(Map<String, Object?> answer) => <Map<String, Object?>>[
  for (final r in answer['data']! as List) (r as Map).cast<String, Object?>(),
];

/// [answer] (a feed page) with its paging set to [current] of [last].
Map<String, Object?> _paged(Map<String, Object?> answer, {required int current, required int last}) => <String, Object?>{
  ...answer,
  'meta': <String, Object?>{
    'page': <String, Object?>{...((answer['meta']! as Map)['page']! as Map).cast<String, Object?>(), 'current': current, 'last': last},
  },
};

/// Lets the post-frame load, the answer and the rebuild go through (no pumpAndSettle: a loader spins).
Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 6; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

/// No stock photo anywhere: every photo shown is the server's own, or «الصورة غير متاحة».
void _noStockPhoto() {
  expect(
    find.byWidgetPredicate((w) => w is AppImage && !(w.src.startsWith('http') || w.src == kNoPhoto)),
    findsNothing,
    reason: 'a server row never shows a sample photo in place of its own',
  );
}

void main() {
  group('the recorded answers, read as the app reads them', () {
    test('the feed: listings and projects apart, the «from» price, no sold count, no photo when there is none', () {
      final rows = <String, Listing>{for (final l in _rows(recorded('feed')).map(ServerListings.fromFeed)) l.id: l};
      expect(rows.keys, <String>['L4', 'L3', 'L2', 'L1', 'P1'], reason: 'a listing and a project may share a number');

      final project = ServerListings.fromFeed(_rows(recorded('feed')).last);
      expect(project.kind, 'project');
      expect(project.source, 'developer');
      expect(project.price, 1800000, reason: 'its cheapest available unit');
      expect(project.available, 6);
      expect(project.totalUnits, 40);
      expect(project.sold, isNull, reason: 'the server does not know it');
      expect(project.units, isNull, reason: 'a card carries no units');

      final withPhoto = ServerListings.fromFeed(_rows(recorded('feed'))[1]);
      expect(withPhoto.images.single, startsWith('http://localhost/media/'));
      expect(withPhoto.source, 'owner');
      final noPhoto = ServerListings.fromFeed(_rows(recorded('feed')).first);
      expect(noPhoto.images, isEmpty);
      expect(noPhoto.source, 'broker');
      expect(noPhoto.license, isEmpty, reason: 'a card carries no licence');
      expect(ServerListings.fromFeed(_rows(recorded('feed'))[2]).purpose, 'rent');
    });

    test('a listing in detail: its large photos, its verified licence, its phones', () {
      final l = ServerListings.fromListing((recorded('listing')['data']! as Map).cast<String, Object?>());
      expect(l.id, 'L16');
      expect(l.images.single, contains('-large.jpg'));
      expect(l.license, '7100000016');
      expect(l.phones, <String>['0537322581']);
      expect(l.negotiable, isFalse);
      expect(l.hasDeed, isTrue);
      expect(l.builtArea, 400);
      expect(l.rooms, 3);
    });

    test('a licence the server has not verified is not shown as one', () {
      final j = (recorded('listing')['data']! as Map).cast<String, Object?>();
      final l = ServerListings.fromListing(<String, Object?>{
        ...j,
        'licence': <String, Object?>{...(j['licence']! as Map).cast<String, Object?>(), 'verified': false},
      });
      expect(l.license, isEmpty);
    });

    test('a project in detail: its models, the sold-out one sold, a model without a photo without one', () {
      final p = ServerListings.fromProject((recorded('project')['data']! as Map).cast<String, Object?>());
      expect(p.id, 'P4');
      expect(p.images.single, contains('-large.jpg'));
      expect(p.phones, <String>['0525774313']);
      final units = <String, UnitModel>{for (final u in p.units!) u.id: u};
      expect(units.keys, unorderedEquals(<String>['7', '8']));
      expect(units['8']!.sold, isTrue);
      expect(units['8']!.image, isEmpty);
      expect(units['7']!.sold, isFalse);
      expect(units['7']!.image, contains('-large.jpg'));
      expect(units['7']!.name, 'h6');
      expect(units['7']!.builtArea, 380);
    });

    test('the filter card, as the server reads it', () {
      expect(const FeedQuery().params(1, 18), <String, String>{'sort': 'new', 'page': '1', 'per_page': '18'});
      expect(
        const FeedQuery(
          purpose: 'rent',
          city: 'الرياض',
          neighborhood: 'الملقا',
          source: 'owner',
          propertyType: 'villa',
          sort: 'price-asc',
        ).params(2, 18),
        <String, String>{
          'purpose': 'rent',
          'city': 'الرياض',
          'neighborhood': 'الملقا',
          'source': 'owner',
          'property_type': 'villa',
          'sort': 'price_asc',
          'page': '2',
          'per_page': '18',
        },
      );
      expect(const FeedQuery(sort: 'feat').params(1, 18)['sort'], 'featured');
      expect(const FeedQuery(sort: 'area-desc'), const FeedQuery(sort: 'area-desc'));
      expect(const FeedQuery(sort: 'area-desc') == const FeedQuery(sort: 'area-asc'), isFalse);
    });
  });

  test('a page that comes after the screen closed is dropped', () async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'feed', 200, recorded('feed'));
    final feed = Feed();
    final loading = feed.show(const FeedQuery());
    feed.dispose(); // the answer is still on its way
    await loading;
    expect(server.sent('GET', 'feed'), hasLength(1));
  });

  group('«الرئيسية» with a server', () {
    testWidgets('shows the server\'s feed, and nothing of the samples', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'feed', 200, recorded('feed'));

      await pumpApp(tester, '/');
      await _settle(tester);

      expect(find.byType(ListingCard), findsNWidgets(5));
      expect(find.text('شقة للإيجار في الملقا'), findsOneWidget);
      expect(find.text('مشروع النرجس السكني'), findsOneWidget);
      expect(find.text('ابتداءً من'), findsOneWidget, reason: 'a project\'s card carries its «from» price');
      expect(find.text('المباع'), findsNothing, reason: 'an unknown sold count is not shown as a number');
      expect(find.text('الصورة غير متاحة'), findsWidgets);
      _noStockPhoto();
      // (pumpApp opens «الرئيسية» on the start and again on its route: each asks once)
      expect(
        <Map<String, String>>[for (final r in server.sent('GET', 'feed')) r.url.queryParameters],
        allOf(isNotEmpty, everyElement(<String, String>{'sort': 'new', 'page': '1', 'per_page': '18'})),
      );
      expect(find.byKey(const ValueKey<String>('home-more')), findsNothing, reason: 'one page only');
    });

    testWidgets('the filter card asks the server again, from the first page', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'feed', 200, recorded('feed'));
      await pumpApp(tester, '/');
      await _settle(tester);

      final before = server.sent('GET', 'feed').length;
      await tapText(tester, 'للإيجار');
      await _settle(tester);
      expect(server.sent('GET', 'feed'), hasLength(before + 1), reason: 'one request a change');
      expect(server.sent('GET', 'feed').last.url.queryParameters, <String, String>{'purpose': 'rent', 'sort': 'new', 'page': '1', 'per_page': '18'});

      await tapText(tester, 'الأقدم');
      await _settle(tester);
      expect(server.sent('GET', 'feed').last.url.queryParameters, <String, String>{'purpose': 'rent', 'sort': 'old', 'page': '1', 'per_page': '18'});
      expect(server.sent('GET', 'feed'), hasLength(before + 2));
    });

    testWidgets('«عرض المزيد» brings the next page under the first', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'feed', 200, _paged(recorded('feed'), current: 1, last: 2));
      await pumpApp(tester, '/');
      await _settle(tester);
      expect(find.byType(ListingCard), findsNWidgets(5));

      final next = <String, Object?>{..._rows(recorded('feed'))[2], 'id': 102, 'title': 'شقة للإيجار في العارض'};
      server.on(
        'GET',
        'feed',
        200,
        _paged(
          <String, Object?>{
            ...recorded('feed'),
            'data': <Object?>[next],
          },
          current: 2,
          last: 2,
        ),
      );
      await tapText(tester, 'عرض المزيد');
      await _settle(tester);

      expect(server.sent('GET', 'feed').last.url.queryParameters['page'], '2');
      expect(find.byType(ListingCard), findsNWidgets(6));
      expect(find.text('شقة للإيجار في العارض'), findsOneWidget);
      expect(find.text('شقة للإيجار في الملقا'), findsOneWidget, reason: 'the first page stays');
      expect(find.byKey(const ValueKey<String>('home-more')), findsNothing, reason: 'that was the last page');
    });

    testWidgets('without a connection it says so, and the retry brings the feed', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.offline('GET', 'feed');
      await pumpApp(tester, '/');
      await _settle(tester);

      expect(find.text('تعذّر تحميل العروض'), findsOneWidget);
      expect(find.text('تعذّر الاتصال بزون. تحقق من الإنترنت وحاول مرة أخرى.'), findsOneWidget);
      expect(find.byType(ListingCard), findsNothing, reason: 'no samples in its place');

      server.on('GET', 'feed', 200, recorded('feed'));
      await tapText(tester, 'أعد المحاولة');
      await _settle(tester);
      expect(find.text('تعذّر تحميل العروض'), findsNothing);
      expect(find.byType(ListingCard), findsNWidgets(5));
    });

    testWidgets('a card opens its row, fetched fresh', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'feed', 200, recorded('feed'));
      server.on('GET', 'projects/1', 200, recorded('project'));
      await pumpApp(tester, '/');
      await _settle(tester);

      await tapText(tester, 'مشروع النرجس السكني');
      await _settle(tester);
      await _settle(tester);

      expect(server.sent('GET', 'projects/1'), hasLength(1), reason: 'P1 is the server\'s project 1');
      expect(find.text('الإجمالي'), findsOneWidget);
    });
  });

  group('a row of the server in detail', () {
    testWidgets('a listing: the router turns /listings/16 into L16; its licence and price', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'listings/16', 200, recorded('listing'));

      await pumpApp(tester, '/listings/16');
      await _settle(tester);

      expect(server.sent('GET', 'listings/16'), hasLength(1));
      expect(find.text('رقم الترخيص الإعلاني: 7100000016'), findsOneWidget);
      expect(find.textContaining('2,400,000'), findsWidgets);
      expect(find.text('لؤي السماعيل'), findsOneWidget);
      expect(find.text('اتصال'), findsOneWidget);
      _noStockPhoto();
    });

    testWidgets('a listing the server no longer shows says so', (tester) async {
      await freshDevice();
      serverDevice(); // nothing answers listings/99: the server's 404

      await pumpApp(tester, '/listings/99');
      await _settle(tester);

      expect(find.text('العرض غير موجود.'), findsOneWidget);
    });

    testWidgets('without a connection the detail offers to try again', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.offline('GET', 'listings/16');

      await pumpApp(tester, '/listings/16');
      await _settle(tester);
      expect(find.text('تعذّر التحميل'), findsOneWidget);
      expect(find.text('العرض غير موجود.'), findsNothing, reason: 'offline is not «gone»');

      server.on('GET', 'listings/16', 200, recorded('listing'));
      await tapText(tester, 'أعد المحاولة');
      await _settle(tester);
      expect(find.text('رقم الترخيص الإعلاني: 7100000016'), findsOneWidget);
    });

    testWidgets('a project: its total and what is left, never a sold count', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'projects/4', 200, recorded('project'));

      await pumpApp(tester, '/projects/4');
      await _settle(tester);

      expect(find.text('مشروع النرجس السكني'), findsOneWidget);
      expect(find.text('الإجمالي'), findsOneWidget);
      expect(find.text('40'), findsOneWidget);
      expect(find.text('المتبقي'), findsOneWidget);
      expect(find.text('المباع'), findsNothing);
      expect(find.textContaining('نسبة البيع'), findsNothing);
      _noStockPhoto();
    });

    testWidgets('a unit of a project: available, and the sold-out model sold', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on('GET', 'projects/4', 200, recorded('project'));

      await pumpApp(tester, '/projects/4/units/7');
      await _settle(tester);
      expect(find.text('وحدة h6'), findsOneWidget);
      expect(find.text('متاح'), findsOneWidget);
      expect(find.textContaining('1,800,000'), findsWidgets);
      _noStockPhoto();

      await pumpApp(tester, '/projects/4/units/8');
      await _settle(tester);
      expect(find.text('وحدة p6'), findsOneWidget);
      expect(find.text('مباع'), findsOneWidget);
      expect(
        find.byWidgetPredicate((w) => w is AppImage && w.src.contains('01JTEST4-large')),
        findsWidgets,
        reason: 'the model has none, so the project\'s photo is shown',
      );
    });
  });
}
