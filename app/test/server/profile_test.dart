// An advertiser's public page with a server (lib/data/server/server_people.dart; server_user_profile.dart,
// offer_react.dart, people_view.dart): who he is and his live listings as the server counts them, follow, stars,
// «بلاغ» on him or on an offer, «حظر» and its undoing, a page that is not there, «إعجاب» on an offer, and
// «المتابعون» with the liked offers from the server.
// The server's answers are the ones its tests recorded (docs/contracts/fixtures/api: profile_public,
// profile_listings, profile_mine, follow, rating, block, unblock, following, listing, favorite_added,
// favorite_removed, favorites).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/data/store.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Future<void> _settle(WidgetTester tester, [int times = 8]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Finder _semantic(String label) => find.byWidgetPredicate((w) => w is Semantics && w.properties.label == label);

Future<void> _tapSemantic(WidgetTester tester, String label) async {
  await tester.ensureVisible(_semantic(label).first);
  await tester.tap(_semantic(label).first);
  await _settle(tester);
}

void main() {
  final him = _one('profile_public');
  final username = him['username']! as String;
  final at = 'customers/$username';

  Future<FakeServer> visit(WidgetTester tester, {bool signedIn = true, void Function(FakeServer)? stubs}) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', at, 200, recorded('profile_public'));
    server.on('GET', '$at/listings', 200, recorded('profile_listings'));
    stubs?.call(server);
    if (signedIn) await serverSignIn(server, account: me(id: 41, role: 'broker', name: 'مكتب الريادة'));
    await pumpApp(tester, '/users/$username');
    await _settle(tester);
    return server;
  }

  testWidgets('his page: who he is and his live listings, as the server counts them', (tester) async {
    await visit(tester);
    expect(find.text('رخصة فال ${him['fal_number']}'), findsOneWidget);
    expect(find.text('موثّق بنفاذ'), findsOneWidget);
    expect(find.text('وسيط · ${him['city']} · عضو منذ 2026'), findsOneWidget);
    expect(find.text('لا تقييمات'), findsOneWidget);
    expect(find.text('شقة منشورة'), findsOneWidget);
    expect(find.text('متابعة'), findsOneWidget);
    expect(find.text('المزيد'), findsNothing, reason: 'one page of listings');
  });

  testWidgets('follow, then stars: sent to the server, and its own count shown', (tester) async {
    final server = await visit(tester, stubs: (s) {
      s.on('POST', '$at/follow', 200, recorded('follow'));
      s.on('POST', '$at/rating', 200, recorded('rating'));
    });
    await tapText(tester, 'متابعة');
    await _settle(tester);
    expect(server.sent('POST', '$at/follow'), hasLength(1));
    expect(find.text('تتابعه'), findsOneWidget);

    server.on('GET', at, 200, recorded('profile_mine'));
    await _tapSemantic(tester, '5 نجوم');
    await tapText(tester, 'تقييم');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', '$at/rating').single), <String, Object?>{'stars': 5});
    expect(find.text('تقييمك: 5 من 5'), findsOneWidget);
    expect(find.text('من 1 تقييم'), findsOneWidget, reason: "the server's average and count, not reckoned on the phone");
  });

  testWidgets('a refused follow says why and changes nothing', (tester) async {
    final server = await visit(tester, stubs: (s) => s.on('POST', '$at/follow', 404, apiFail('NOT_FOUND', 'العنصر غير موجود.')));
    await tapText(tester, 'متابعة');
    await _settle(tester);
    expect(server.sent('POST', '$at/follow'), hasLength(1));
    expect(find.text('متابعة'), findsOneWidget);
    expect(find.text('تتابعه'), findsNothing);
  });

  testWidgets('«بلاغ» on him: his reason to the server, once taken «وصل البلاغ»', (tester) async {
    final server = await visit(tester, stubs: (s) => s.on('POST', 'reports', 201, <String, Object?>{'success': true, 'data': <String, Object?>{'id': 1}}));
    await tapText(tester, 'بلاغ');
    expect(find.text('الإبلاغ عن الحساب'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, 'ينشر عروضاً غير موجودة');
    await tester.pump();
    await tapText(tester, 'إرسال البلاغ');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', 'reports').single), <String, Object?>{
      'target_type': 'customer',
      'target_id': him['id'],
      'details': 'ينشر عروضاً غير موجودة',
    });
    expect(find.text('وصل البلاغ'), findsOneWidget);
  });

  testWidgets('«حظر»: asked first, then his page is gone; «تراجع عن الحظر» brings it back', (tester) async {
    final server = await visit(tester, stubs: (s) {
      s.on('POST', '$at/block', 200, recorded('block'));
      s.on('DELETE', '$at/block', 200, recorded('unblock'));
    });
    await tapText(tester, 'حظر');
    expect(find.text('حظر ${him['name']}؟'), findsOneWidget);
    expect(server.sent('POST', '$at/block'), isEmpty, reason: 'nothing before he confirms');
    await tapText(tester, 'حظر', last: true);
    await _settle(tester);
    expect(server.sent('POST', '$at/block'), hasLength(1));
    expect(find.text('شقة منشورة'), findsNothing);
    expect(find.textContaining('لن تظهر لك عروضه'), findsOneWidget);

    await tapText(tester, 'تراجع عن الحظر');
    await _settle(tester);
    expect(server.sent('DELETE', '$at/block'), hasLength(1));
    expect(find.text('شقة منشورة'), findsOneWidget);
  });

  testWidgets('a page that is not there (a seeker, or one who blocked him)', (tester) async {
    await visit(tester, stubs: (s) => s.on('GET', at, 404, apiFail('NOT_FOUND', 'العنصر غير موجود.')));
    expect(find.text('الصفحة غير متاحة.'), findsOneWidget);
    expect(find.text('متابعة'), findsNothing);
  });

  testWidgets('signed out: his page is seen, and following asks to sign in', (tester) async {
    final server = await visit(tester, signedIn: false);
    expect(find.text('شقة منشورة'), findsOneWidget);
    await tapText(tester, 'متابعة');
    await _settle(tester);
    expect(_path(), '/login');
    expect(server.sent('POST', '$at/follow'), isEmpty);
  });

  testWidgets('«بلاغ» on an offer goes to the server with the offer', (tester) async {
    final listing = _one('listing');
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'listings/${listing['id']}', 200, recorded('listing'));
    server.on('POST', 'reports', 201, <String, Object?>{'success': true, 'data': <String, Object?>{'id': 1}});
    await serverSignIn(server, account: me(id: 41, role: 'broker', name: 'مكتب الريادة'));
    await pumpApp(tester, '/listings/L${listing['id']}');
    await _settle(tester);
    await _tapSemantic(tester, 'بلاغ');
    await tester.enterText(find.byType(TextField).last, 'العقار مباع');
    await tester.pump();
    await tapText(tester, 'إرسال البلاغ');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', 'reports').single), <String, Object?>{
      'target_type': 'listing',
      'target_id': listing['id'],
      'details': 'العقار مباع',
    });
    expect(find.text('وصل البلاغ'), findsOneWidget);
  });

  testWidgets('a refused report keeps the dialog and his reason, with why', (tester) async {
    final server = await visit(tester, stubs: (s) => s.on('POST', 'reports', 429, apiFail('RATE_LIMITED', 'محاولات كثيرة، حاول لاحقاً.')));
    await tapText(tester, 'بلاغ');
    await tester.enterText(find.byType(TextField).last, 'ينشر عروضاً غير موجودة');
    await tester.pump();
    await tapText(tester, 'إرسال البلاغ');
    await _settle(tester);
    expect(server.sent('POST', 'reports'), hasLength(1));
    expect(find.text('الإبلاغ عن الحساب'), findsOneWidget);
    expect(find.text('ينشر عروضاً غير موجودة'), findsOneWidget);
    expect(find.text('وصل البلاغ'), findsNothing);
  });

  testWidgets('«المتابعون» from the server: who he follows, and «إلغاء» there', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'me/followers', 200, <String, Object?>{'success': true, 'data': <Object?>[]});
    server.on('GET', 'me/following', 200, recorded('following'));
    server.on('DELETE', '$at/follow', 200, recorded('unfollow'));
    await serverSignIn(server, account: me(id: 41, role: 'broker', name: 'مكتب الريادة'));
    await pumpApp(tester, '/account/people?kind=following');
    await _settle(tester);
    expect(find.text(him['name']! as String), findsOneWidget);
    expect(find.text('محمد الشهري'), findsNothing, reason: 'no sample people with a server');
    await tapText(tester, 'إلغاء');
    await _settle(tester);
    expect(server.sent('DELETE', '$at/follow'), hasLength(1));
    expect(find.text(him['name']! as String), findsNothing);
    expect(find.text('لا تتابع أحداً بعد.'), findsOneWidget);
    await tapText(tester, 'المتابِعون');
    expect(find.text('لا متابِعين بعد.'), findsOneWidget);
  });

  testWidgets('«إعجاب» on an offer: on the server, shown once taken; again undoes it', (tester) async {
    final listing = _one('listing');
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'listings/${listing['id']}', 200, recorded('listing'));
    server.on('POST', 'favorites', 200, recorded('favorite_added'));
    server.on('DELETE', 'favorites/listing/${listing['id']}', 200, recorded('favorite_removed'));
    await serverSignIn(server, account: me(id: 41, role: 'broker', name: 'مكتب الريادة'));
    await pumpApp(tester, '/listings/L${listing['id']}');
    await _settle(tester);
    expect(listing['is_favorite'], isFalse);
    await _tapSemantic(tester, 'إعجاب');
    expect(FakeServer.body(server.sent('POST', 'favorites').single), <String, Object?>{'type': 'listing', 'id': listing['id']});
    expect(_semantic('إلغاء الإعجاب'), findsOneWidget);
    await _tapSemantic(tester, 'إلغاء الإعجاب');
    expect(server.sent('DELETE', 'favorites/listing/${listing['id']}'), hasLength(1));
    expect(_semantic('إعجاب'), findsOneWidget);
    expect(store.likedIds(), isEmpty, reason: "the server's like, not the phone's");
  });

  testWidgets('«إعجاب» refused: said, and not shown liked', (tester) async {
    final listing = _one('listing');
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'listings/${listing['id']}', 200, recorded('listing'));
    server.on('POST', 'favorites', 404, apiFail('NOT_FOUND', 'العنصر غير موجود.'));
    await serverSignIn(server, account: me(id: 41, role: 'broker', name: 'مكتب الريادة'));
    await pumpApp(tester, '/listings/L${listing['id']}');
    await _settle(tester);
    await _tapSemantic(tester, 'إعجاب');
    expect(find.text('لم يُحفظ الإعجاب'), findsOneWidget);
    expect(_semantic('إلغاء الإعجاب'), findsNothing);
  });

  testWidgets('«المفضلة» with a server: the offers he liked, listings and projects', (tester) async {
    final favs = _one('favorites');
    final listing = ((favs['listings']! as List).first as Map)['title']! as String;
    final project = ((favs['projects']! as List).first as Map)['title']! as String;
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'me/followers', 200, <String, Object?>{'success': true, 'data': <Object?>[]});
    server.on('GET', 'me/following', 200, <String, Object?>{'success': true, 'data': <Object?>[]});
    server.on('GET', 'favorites', 200, recorded('favorites'));
    await serverSignIn(server, account: me(id: 41, role: 'broker', name: 'مكتب الريادة'));
    await pumpApp(tester, '/account/people?kind=favs');
    await _settle(tester);
    expect(find.text(listing), findsOneWidget);
    expect(find.text(project), findsOneWidget);
  });
}
