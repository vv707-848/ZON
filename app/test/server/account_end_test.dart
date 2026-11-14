// «حذف حسابي» and «بيانات من الإصدار السابق» with a server (lib/data/auth/server_auth.dart; settings_view.dart,
// login_screen.dart): deleting asks for the password and says the server's refusal; once the server took it he is
// told the day it gave and this phone signs out; a try whose answer was lost is the same operation; signing in
// before that day says the deletion was cancelled. The records app 1.1 left without an owner are claimed by their
// ids — requests too — once, and nothing moves on the phone.
// The server's answers are the ones its tests recorded (docs/contracts/fixtures/api: account_deleted,
// account_delete_wrong_password, login_cancels_deletion, legacy_claim).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/store.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

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

/// A listing and a request as app 1.1 saved them: no owner field.
Map<String, Object?> _listing11(String id) =>
    const Listing(
      id: '',
      kind: 'unit',
      title: 'عرض من الإصدار السابق',
      purpose: 'sale',
      city: 'الرياض',
      neighborhood: 'الملقا',
      propertyType: 'villa',
      source: 'broker',
      price: 2500000,
      rooms: 5,
      baths: 4,
      area: 400,
      images: <String>[],
      description: 'وصف',
      advertiser: 'سالم القحطاني',
      advertiserRole: 'broker',
      license: '7200000001',
      active: true,
    ).toJson()
      ..['id'] = id;

Map<String, Object?> _request11(String id) => const RequestItem(
  id: '',
  purpose: 'sale',
  city: 'الرياض',
  neighborhoods: <String>['الملقا'],
  types: <String>['villa'],
  priceMin: 2000000,
  priceMax: 3000000,
  areaMin: 300,
  areaMax: 500,
  roomsMin: 4,
  roomsMax: 6,
  description: 'طلب من الإصدار السابق',
  author: '',
  role: 'broker',
).merged(<String, Object?>{'id': id, 'author': 'نورة الشهري', 'role': 'seeker'}).toJson();

void main() {
  group('«حذف حسابي» with a server', () {
    Future<FakeServer> open(WidgetTester tester) async {
      await freshDevice();
      final server = serverDevice();
      await serverSignIn(server);
      server.on('GET', 'support-tickets', 200, <String, Object?>{
        'success': true,
        'data': <Object?>[],
        'meta': <String, Object?>{
          'page': <String, Object?>{'current': 1, 'per_page': 20, 'total': 0, 'last': 1},
        },
      });
      await pumpApp(tester, '/account/settings?tab=support');
      await _settle(tester);
      await tapText(tester, 'حذف حسابي');
      return server;
    }

    Future<void> confirm(WidgetTester tester, String password) async {
      await tester.enterText(find.byType(EditableText).last, password);
      await tester.pump();
      await tapText(tester, 'تأكيد الحذف');
      await _settle(tester);
    }

    testWidgets('asks for the password; the server\'s refusal is said and nothing ends', (tester) async {
      final server = await open(tester);
      await tapText(tester, 'تأكيد الحذف');
      expect(find.text('اكتب كلمة السر'), findsOneWidget);
      expect(server.sent('POST', 'account/delete'), isEmpty);

      server.on('POST', 'account/delete', 422, recorded('account_delete_wrong_password'));
      await confirm(tester, 'wrong-secret');
      expect(FakeServer.body(server.sent('POST', 'account/delete').single), <String, Object?>{'password': 'wrong-secret'});
      expect(find.text('كلمة السر غير صحيحة.'), findsOneWidget);
      expect(ServerSession.token, isNotNull);
      expect(store.readSession(), isNotNull);

      await tapText(tester, 'إلغاء');
      expect(find.text('تأكيد الحذف'), findsNothing);
      await tapText(tester, 'حذف حسابي');
      expect(tester.widget<EditableText>(find.byType(EditableText).last).controller.text, isEmpty, reason: 'a cancelled deletion keeps no password');
    });

    testWidgets('taken by the server: the day it gave is said, then this phone signs out; a lost answer is the same operation', (tester) async {
      final server = await open(tester);
      server.offline('POST', 'account/delete');
      await confirm(tester, 'a-good-secret');
      expect(find.byType(FormError), findsOneWidget);
      expect(store.readSession(), isNotNull, reason: 'not taken: still signed in');

      server.on('POST', 'account/delete', 202, recorded('account_deleted'));
      await tapText(tester, 'تأكيد الحذف');
      await _settle(tester);
      final sent = server.sent('POST', 'account/delete');
      expect(sent, hasLength(2));
      expect(sent.last.headers['Idempotency-Key'], isNotNull);
      expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key'], reason: 'one deletion, however often sent');

      final at = DateTime.parse('${(recorded('account_deleted')['data']! as Map)['purge_at']}').toLocal();
      expect(find.text('سُجّل طلب حذف حسابك'), findsOneWidget);
      expect(find.textContaining('يُحذف نهائياً في ${arDate(at, year: true)}، والدخول قبل ذلك يلغي الحذف.'), findsOneWidget);
      expect(store.readSession(), isNotNull, reason: 'told first');
      await tapText(tester, 'حسناً');
      await _settle(tester);
      expect(ServerSession.token, isNull);
      expect(store.readSession(), isNull);
      expect(_path(), '/login');
    });

    testWidgets('signing in before that day says the deletion was cancelled; any other sign-in says nothing', (tester) async {
      await freshDevice();
      final server = serverDevice();
      final back = recorded('login_cancels_deletion');
      expect((back['data']! as Map)['deletion_cancelled'], isTrue);
      server.on('POST', 'auth/login', 200, back);
      await pumpApp(tester, '/login');
      await tapText(tester, 'تسجيل الدخول');
      await tester.enterText(find.byType(EditableText).at(0), 'faisal');
      await tester.enterText(find.byType(EditableText).at(1), 'a-good-secret');
      await tapText(tester, 'دخول');
      await _settle(tester);
      expect(find.text('أُلغي حذف حسابك'), findsOneWidget);
      expect(store.readSession(), isNotNull);
      await tapText(tester, 'حسناً');
      await _settle(tester);
      expect(_path(), '/');

      await ServerAuth.signOut();
      server.on('POST', 'auth/login', 200, ok(<String, Object?>{'token': '9|again', 'me': me(), 'deletion_cancelled': false}));
      await pumpApp(tester, '/login');
      await tapText(tester, 'تسجيل الدخول');
      await tester.enterText(find.byType(EditableText).at(0), 'faisal');
      await tester.enterText(find.byType(EditableText).at(1), 'a-good-secret');
      await tapText(tester, 'دخول');
      await _settle(tester);
      expect(find.text('أُلغي حذف حسابك'), findsNothing);
      expect(_path(), '/');
    });
  });

  group('«بيانات من الإصدار السابق» with a server', () {
    testWidgets('the unowned records\' ids are claimed once — requests too — and nothing moves on the phone', (tester) async {
      await freshDevice(<String, Object>{
        'zon.extra.listings.v1': jsonEncode(<Object?>[_listing11('u-1700000000040'), _listing11('u-1700000000041')]),
        'zon.extra.requests.v1': jsonEncode(<Object?>[_request11('r-1700000000042')]),
      });
      final server = serverDevice();
      await serverSignIn(server);
      await pumpApp(tester, '/account/settings');
      await _settle(tester);
      expect(find.text('عروض: 2 · طلبات: 1'), findsOneWidget, reason: 'the phone\'s own requests, not the server\'s board');
      expect(find.textContaining('يراجع فريق زون الطلب'), findsOneWidget);

      server.offline('POST', 'account/legacy-claims');
      await tapText(tester, 'طلب استعادة');
      await _settle(tester);
      expect(find.byType(FormError), findsOneWidget);
      expect(store.legacyRecoveryRequested(), isFalse, reason: 'not asked until the server has it');

      server.on('POST', 'account/legacy-claims', 201, recorded('legacy_claim'));
      await tapText(tester, 'طلب استعادة');
      await _settle(tester);
      final sent = server.sent('POST', 'account/legacy-claims');
      expect(sent, hasLength(2));
      expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key'], reason: 'one claim, however often sent');
      expect(FakeServer.body(sent.last), <String, Object?>{
        'listings': <String>['u-1700000000040', 'u-1700000000041'],
        'requests': <String>['r-1700000000042'],
      });
      expect(find.text('سُجّل طلبك برقم ${(recorded('legacy_claim')['data']! as Map)['id']} — بانتظار التحقق، ولم يُنقل شيء.'), findsOneWidget);
      expect(find.text('طلب استعادة'), findsNothing, reason: 'asked once');
      expect(store.listingById('u-1700000000040')!.ownerId, isNull, reason: 'a claim is not a transfer');
      expect(store.myListings(), isEmpty);
    });
  });
}
