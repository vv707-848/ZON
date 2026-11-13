// A broker's own listings with a server (lib/data/server/server_my_listings.dart; listing_form.dart,
// listing_review_screen.dart, my_listings_view.dart, listing_editor.dart): the licence asked of REGA, the photos
// then the listing once, its wording and his decision, «عروضي» and its actions, an edit. The server's answers are
// the ones its tests recorded (docs/contracts/fixtures/api: licence_check, media, listing_created, listing_review,
// listings_mine, listing).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/services/photo_store.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_listings.dart';
import 'package:zon/data/server/server_my_listings.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/listing_card.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

List<Map<String, Object?>> _rows(String name) => <Map<String, Object?>>[
  for (final r in recorded(name)['data']! as List) (r as Map).cast<String, Object?>(),
];

/// A listing of the server as [status], advertised by the account [owner] (`me(id: …)`).
Map<String, Object?> _as(Map<String, Object?> row, {required String status, int owner = 41}) => <String, Object?>{
  ...row,
  'status': status,
  'advertiser': <String, Object?>{...(row['advertiser']! as Map).cast<String, Object?>(), 'id': owner},
};

String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Finder _field(String placeholder) => find.byWidgetPredicate((w) => w is TextField && w.decoration?.hintText == placeholder);

Future<void> _settle(WidgetTester tester, [int times = 6]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

void main() {
  group('the recorded answers, read as the app reads them', () {
    test('a new listing is his, «قيد الصياغة», not yet public; «عروضي» and the review likewise', () {
      final created = ServerListings.fromListing(_one('listing_created'));
      expect(created.id, 'L${_one('listing_created')['id']}');
      expect(created.status, 'indexing');
      expect(created.active, isFalse, reason: 'not public until its wording is decided');
      expect(created.ownerId, 'srv-${(_one('listing_created')['advertiser']! as Map)['id']}');
      expect(created.floorLevel, 'ground');

      expect(ServerListings.fromListing(_rows('listings_mine').single).status, 'pending_review');

      final j = _one('listing_review');
      final review = WordingReview(
        status: j['status']! as String,
        textReview: j['text_review']! as String,
        raw: j['raw']! as String,
        suggested: j['suggested'] as String?,
      );
      expect(review.waiting, isTrue);
      expect(review.preparing, isFalse);
    });

    test('a listing in detail knows its photos\' ids, in their order', () {
      final l = ServerListings.fromListing(_one('listing'));
      expect(l.photoIds, <int>[for (final p in _one('listing')['photos']! as List) (p as Map)['id']! as int]);
      expect(l.images, hasLength(l.photoIds!.length));
    });

    test('the form as the server reads it: only what the type uses, an empty field empty', () {
      final apartment = ServerListings.fromListing(_one('listing_created'));
      final body = ServerMyListings.form(apartment);
      expect(body['property_type'], 'apartment');
      expect(body['price'], 880000);
      expect(body['floor_level'], 'ground');
      expect(body.containsKey('area_land'), isFalse, reason: 'an apartment has no land area: not sent');
      expect(body.containsKey('floors'), isFalse);
      expect(body['floor_number'], isNull);

      final land = ServerMyListings.form(apartment.merged(<String, Object?>{'propertyType': 'land', 'landArea': 600.0, 'landUse': 'commercial'}));
      expect(land['area_land'], 600);
      expect(land['land_use'], 'commercial');
      expect(land.containsKey('bedrooms'), isFalse);
      expect(land.containsKey('area_built'), isFalse);
    });
  });

  test('photos on this phone are uploaded once each; those already there keep their ids', () async {
    await freshDevice();
    final server = serverDevice();
    final dir = Directory.systemTemp.createTempSync('zon-photos');
    addTearDown(() => dir.deleteSync(recursive: true));
    await PhotoStore.init(dir);
    File('${dir.path}/photos/drafts/a.jpg').writeAsBytesSync(<int>[0xFF, 0xD8, 0xFF, 0xD9]);
    server.on('POST', 'media', 201, recorded('media'));
    final known = <String, int>{'https://cdn.test/old.jpg': 7};
    final photos = <String>['https://cdn.test/old.jpg', '${PhotoStore.scheme}drafts/a.jpg', '/listings/villa-1.jpg'];

    expect(await ServerMyListings.upload(photos, known: known), <int>[7, _one('media')['id']! as int], reason: 'a sample photo is not his to upload');
    expect(server.sent('POST', 'media').single.headers['Content-Type'], startsWith('multipart/form-data'));
    await ServerMyListings.upload(photos, known: known);
    expect(server.sent('POST', 'media'), hasLength(1), reason: 'tried again: not uploaded again');
  });

  group('a broker publishes', () {
    Future<void> toForm(WidgetTester tester, FakeServer server) async {
      await serverSignIn(server, account: me(id: 41));
      await pumpApp(tester, '/add?type=listing');
      await _settle(tester);
      await tester.enterText(_field('72001122'), '7200123456');
      await tester.pump();
      await tapText(tester, 'تحقق');
      await _settle(tester);
    }

    testWidgets('«تم التحقق» only when REGA says so', (tester) async {
      await freshDevice();
      final server = serverDevice();
      server.on(
        'POST',
        'listings/licence-check',
        422,
        apiFail(
          'VALIDATION_FAILED',
          'البيانات غير صحيحة.',
          details: <String, Object?>{
            'fields': <String, Object?>{
              'licence_number': <String>['رقم الترخيص الإعلاني غير صالح لدى الهيئة.'],
            },
          },
        ),
      );
      await toForm(tester, server);

      expect(FakeServer.body(server.sent('POST', 'listings/licence-check').single)['licence_number'], '7200123456');
      expect(find.text('رقم الترخيص الإعلاني غير صالح لدى الهيئة.'), findsOneWidget);
      expect(find.text('تم التحقق'), findsNothing);

      server.on('POST', 'listings/licence-check', 200, recorded('licence_check'));
      await tapText(tester, 'تحقق');
      await _settle(tester);
      expect(find.text('تم التحقق'), findsOneWidget);
    });

    testWidgets('the listing once, the owner\'s card, then its wording, decided: published', (tester) async {
      await freshDevice();
      final server = serverDevice();
      final id = _one('listing_created')['id'];
      server.on('POST', 'listings/licence-check', 200, recorded('licence_check'));
      server.offline('POST', 'listings'); // the first try: its answer lost
      server.on('POST', 'property-owners', 201, ok(<String, Object?>{'id': 5}));
      server.on('POST', 'property-owners/5/notes', 201, ok(<String, Object?>{'id': 9}));
      server.on('GET', 'listings/$id/review', 200, recorded('listing_review'));
      server.on('POST', 'listings/$id/review', 200, ok(_as(_one('listing'), status: 'published')));
      server.on('GET', 'listings/$id', 200, ok(_as(_one('listing'), status: 'published')));
      await toForm(tester, server);
      await tapText(tester, 'متابعة');
      await _settle(tester);

      for (final (i, text) in <(int, String)>[(0, 'عبدالله المالكي'), (1, '0551234567'), (2, 'يفضّل البيع كاش')]) {
        await tester.enterText(find.byType(EditableText).at(i), text);
      }
      await tester.pump();
      await tapText(tester, 'حفظ والمتابعة');
      await _settle(tester);
      expect(find.text('تنسيق الوصف بالـ AI'), findsNothing, reason: 'this phone\'s wording is not shown with a server');

      await tester.enterText(_field('أدخل عنوان العقار'), 'شقة في النرجس');
      await tester.enterText(_field('ر.س'), '880000');
      await tester.enterText(_field('أضف وصفًا للعقار'), 'شقة واسعة قريبة من الخدمات والمدارس');
      await tester.pump();
      await tapText(tester, 'متابعة');
      await _settle(tester);
      expect(find.text('تعذّر الاتصال بزون. تحقق من الإنترنت وحاول مرة أخرى.'), findsOneWidget);

      server.on('POST', 'listings', 201, recorded('listing_created'));
      await tapText(tester, 'متابعة');
      await _settle(tester);

      final sent = server.sent('POST', 'listings');
      expect(sent, hasLength(2));
      expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key'], reason: 'one listing, however often sent');
      final body = FakeServer.body(sent.last);
      expect(body['licence_number'], '7200123456');
      expect(body['title'], 'شقة في النرجس');
      expect(body['price'], 880000);
      expect(body['media_ids'], isEmpty);
      final card = FakeServer.body(server.sent('POST', 'property-owners').single);
      expect(card['listing_id'], id);
      expect(card['name'], 'عبدالله المالكي');
      expect(FakeServer.body(server.sent('POST', 'property-owners/5/notes').single), <String, Object?>{'category': 'followup', 'body': 'يفضّل البيع كاش'});
      expect(store.owners().where((o) => o.listingId == 'L$id'), isEmpty, reason: 'the card is the server\'s, not a copy here');

      expect(_path(), '/account/listings/L$id/review');
      expect(find.text(_one('listing_review')['suggested']! as String), findsOneWidget);
      await tapText(tester, 'قبول ونشر');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'listings/$id/review').single), <String, Object?>{'decision': 'approve'});
      expect(_path(), '/listings/L$id');
    });

    testWidgets('a wording that takes longer: said so, and «عروضي»', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(id: 41));
      server.on('GET', 'listings/1/review', 200, ok(<String, Object?>{..._one('listing_review'), 'status': 'indexing', 'text_review': 'pending', 'suggested': null}));

      await pumpApp(tester, '/account/listings/L1/review');
      await _settle(tester);
      expect(find.text('نصوغ وصف عرضك…'), findsOneWidget);
      for (var i = 0; i < 12; i++) {
        await tester.pump(const Duration(seconds: 3));
      }
      expect(server.sent('GET', 'listings/1/review'), hasLength(10));
      expect(find.text('الصياغة تأخذ وقتاً'), findsOneWidget);
    });

    testWidgets('a wording with nothing to change: the listing itself', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(id: 41));
      server.on('GET', 'listings/1/review', 200, ok(<String, Object?>{..._one('listing_review'), 'status': 'published', 'text_review': 'auto', 'suggested': null}));
      server.on('GET', 'listings/1', 200, ok(_as(_one('listing'), status: 'published')));

      await pumpApp(tester, '/account/listings/L1/review');
      await _settle(tester);
      expect(_path(), '/listings/L1');
    });
  });

  group('«عروضي» with a server', () {
    Future<FakeServer> open(WidgetTester tester, List<Map<String, Object?>> rows) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(id: 41));
      server.on('GET', 'listings/mine', 200, ok(rows));
      await pumpApp(tester, '/account/listings');
      await _settle(tester);
      return server;
    }

    testWidgets('his listings from the server; one waiting for his decision opens its review', (tester) async {
      final server = await open(tester, <Map<String, Object?>>[_as(_rows('listings_mine').single, status: 'pending_review')]);
      server.on('GET', 'listings/1/review', 200, recorded('listing_review'));
      expect(find.text('صياغة الوصف بانتظار قرارك'), findsNothing, reason: 'it is not public: under «غير النشط»');

      await tapText(tester, 'غير النشط 1');
      await _settle(tester);
      expect(find.byType(ListingCard), findsOneWidget);
      await tapText(tester, 'صياغة الوصف بانتظار قرارك');
      await _settle(tester);
      expect(_path(), '/account/listings/L1/review');
    });

    testWidgets('«إخفاء» pauses it on the server; «مباع» is asked first and final', (tester) async {
      final row = _as(_rows('listings_mine').single, status: 'published');
      final server = await open(tester, <Map<String, Object?>>[row]);
      server.on('POST', 'listings/1/pause', 200, ok(_as(_one('listing'), status: 'paused')..['id'] = 1));
      server.on('POST', 'listings/1/sold', 200, ok(_as(_one('listing'), status: 'sold')..['id'] = 1));

      await tapText(tester, 'إخفاء');
      await _settle(tester);
      expect(server.sent('POST', 'listings/1/pause'), hasLength(1));
      await tapText(tester, 'غير النشط 1');
      await _settle(tester);
      expect(find.text('مخفي — أوقفته أنت'), findsOneWidget);

      await tapText(tester, 'مباع');
      await _settle(tester);
      expect(find.text('تأكيد البيع'), findsOneWidget);
      await tapText(tester, 'تم البيع', last: true);
      await _settle(tester);
      expect(server.sent('POST', 'listings/1/sold'), hasLength(1));
      expect(find.text('تم البيع'), findsWidgets);
    });

    testWidgets('«تمييز» without balance: nothing changes, and its price is said', (tester) async {
      final server = await open(tester, <Map<String, Object?>>[_as(_rows('listings_mine').single, status: 'published')]);
      server.on(
        'POST',
        'listings/1/boost',
        402,
        apiFail(
          'PAYMENT_REQUIRED',
          'يتطلب دفعًا.',
          details: <String, Object?>{
            'purpose': 'featured',
            'price': '99.00',
            'target': <String, Object?>{'type': 'listing', 'id': 1},
          },
        ),
      );

      await tapText(tester, 'تمييز');
      await _settle(tester);
      expect(FakeServer.body(server.sent('POST', 'listings/1/boost').single), <String, Object?>{'kind': 'featured'});
      expect(find.text('لا رصيد للتمييز'), findsOneWidget);
      expect(find.textContaining('السعر 99 ريال'), findsOneWidget);
    });

    testWidgets('without a connection it says so', (tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server, account: me(id: 41));
      server.offline('GET', 'listings/mine');
      await pumpApp(tester, '/account/listings');
      await _settle(tester);
      expect(find.text('تعذّر تحميل عروضك'), findsOneWidget);
    });
  });

  testWidgets('an edit is the server\'s: his photos keep their ids, a new text opens its review', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server, account: me(id: 41));
    final detail = _as(_one('listing'), status: 'published');
    final id = detail['id'];
    server.on('GET', 'listings/$id', 200, ok(detail));
    server.on('PATCH', 'listings/$id', 200, ok(<String, Object?>{...detail, 'title': 'فيلا عائلية في النرجس'}));

    await pumpApp(tester, '/account/listings/L$id');
    await _settle(tester);
    final title = find.byWidgetPredicate((w) => w is EditableText && w.controller.text == detail['title']);
    await tester.enterText(title, 'فيلا عائلية في النرجس');
    await tester.pump();
    await tapText(tester, 'حفظ وتحديث العرض');
    await _settle(tester);

    final body = FakeServer.body(server.sent('PATCH', 'listings/$id').single);
    expect(body['title'], 'فيلا عائلية في النرجس');
    expect(body['media_ids'], <int>[for (final p in detail['photos']! as List) (p as Map)['id']! as int]);
    expect(server.sent('POST', 'media'), isEmpty, reason: 'its photos are on the server already');
    expect(_path(), '/account/listings');
    expect(store.listingById('L$id')!.title, 'فيلا عائلية في النرجس');
  });

  testWidgets('another account\'s listing is not editable here', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server, account: me(id: 41));
    server.on('GET', 'listings/16', 200, ok(_as(_one('listing'), status: 'published', owner: 99)));
    await pumpApp(tester, '/account/listings/L16');
    await _settle(tester);
    expect(find.text('لا يمكنك تعديل هذا العرض'), findsOneWidget);
  });
}
