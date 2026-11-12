// The seeker's wish on the server (lib/data/server/server_wishes.dart; the sign-up's wish step and «مساعد المطابقة»):
// saved there at sign-up — the server asks a seeker for one before the rest of the app — then shown, changed and
// stopped there. Its answers are the ones the server's tests recorded (docs/contracts/fixtures/api: preference,
// preferences).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/data/models/models.dart';
import 'package:zon/data/server/server_wishes.dart';
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

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 6; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

void main() {
  test('a recorded wish, read as the app reads it, and sent back as the server reads it', () {
    final w = ServerWishes.fromJson(_one('preference'));
    expect(w.id, '${_one('preference')['id']}');
    expect(w.city, 'الرياض');
    expect(w.hoods, <String>['النرجس']);
    expect(w.types, <String>['villa']);
    expect(w.text, 'فيلا عوائل قرب المدارس');
    expect(ServerWishes.form(w), <String, Object?>{
      'city': 'الرياض',
      'neighborhoods': <String>['النرجس'],
      'property_types': <String>['villa'],
      'notes': 'فيلا عوائل قرب المدارس',
    });
    final listed = (recorded('preferences')['data']! as List).cast<Map<Object?, Object?>>();
    expect(ServerWishes.fromJson(listed.single.cast<String, Object?>()).types, isEmpty, reason: 'none = every type');
  });

  testWidgets('a seeker\'s sign-up saves his wish on the server, then opens «مساعد المطابقة» on it', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('POST', 'auth/register', 202, ok(<String, Object?>{'phone': '0551234567', 'expires_in': 900}));
    server.on('POST', 'auth/otp/verify', 201, ok(<String, Object?>{'token': '8|new-token', 'me': me(id: 52, role: 'seeker')}));
    server.offline('POST', 'preferences'); // the first try: its answer lost
    server.on('GET', 'preferences', 200, ok(<Object?>[_one('preference')]));

    await pumpApp(tester, '/login');
    await tapText(tester, 'حساب جديد');
    await tapText(tester, 'متابعة'); // «باحث» is the first role
    final fields = find.byType(EditableText);
    for (final (i, text) in <(int, String)>[(0, 'فيصل العتيبي'), (1, '0551234567'), (2, 'faisal'), (3, 'a-good-secret')]) {
      await tester.enterText(fields.at(i), text);
    }
    await tester.pump();
    await tapText(tester, 'تحقق من الجوال');
    await _settle(tester);
    for (var i = 0; i < 6; i++) {
      await tester.enterText(find.byType(EditableText).at(i), '204815'[i]);
    }
    await _settle(tester); // the sixth digit sends the code

    await tester.enterText(find.byType(EditableText).last, 'فيلا عوائل قرب المدارس');
    await tester.pump();
    await tapText(tester, 'حفظ الرغبة والدخول');
    await _settle(tester);
    expect(find.text('تعذّر الاتصال بزون. تحقق من الإنترنت وحاول مرة أخرى.'), findsOneWidget, reason: 'not saved: said so, still here');
    expect(_path(), '/login');

    server.on('POST', 'preferences', 201, recorded('preference'));
    await tapText(tester, 'حفظ الرغبة والدخول');
    await _settle(tester);

    final sent = server.sent('POST', 'preferences');
    expect(sent, hasLength(2));
    expect(sent.last.headers['Idempotency-Key'], sent.first.headers['Idempotency-Key'], reason: 'one wish, however often sent');
    expect(FakeServer.body(sent.last)['notes'], 'فيلا عوائل قرب المدارس');
    expect(sent.last.headers['Authorization'], 'Bearer 8|new-token');
    expect(_path(), '/account/matching');
    expect(store.loadWish()!.id, '${_one('preference')['id']}');
  });

  testWidgets('«مساعد المطابقة» shows the wish kept on the server, and «حذف» stops it there', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server, account: me(role: 'seeker'));
    final id = _one('preference')['id'];
    server.on('GET', 'preferences', 200, ok(<Object?>[_one('preference')]));
    server.on('DELETE', 'preferences/$id', 200, ok());

    await pumpApp(tester, '/account/matching');
    await _settle(tester);
    expect(find.text('فيلا عوائل قرب المدارس'), findsWidgets, reason: 'the server\'s wish, not a sample');
    expect(store.loadWish()!.id, '$id');

    await tapText(tester, 'حذف');
    await _settle(tester);
    expect(server.sent('DELETE', 'preferences/$id'), hasLength(1));
    expect(store.loadWish(), isNull);
    expect(find.text('حفظ الرغبة'), findsOneWidget, reason: 'asked for a wish again');
  });

  testWidgets('a changed wish is saved over the one on the server', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server, account: me(role: 'seeker'));
    final id = _one('preference')['id'];
    server.on('GET', 'preferences', 200, ok(<Object?>[_one('preference')]));
    server.on('PUT', 'preferences/$id', 200, ok(<String, Object?>{..._one('preference'), 'notes': 'فيلا قرب الحديقة'}));

    await pumpApp(tester, '/account/matching');
    await _settle(tester);
    await tapText(tester, 'تعديل الرغبة');
    await tester.enterText(find.byType(EditableText).last, 'فيلا قرب الحديقة');
    await tester.pump();
    await tapText(tester, 'حفظ الرغبة');
    await _settle(tester);

    expect(server.sent('POST', 'preferences'), isEmpty, reason: 'the same wish, not a second one');
    expect(FakeServer.body(server.sent('PUT', 'preferences/$id').single)['notes'], 'فيلا قرب الحديقة');
    expect(store.loadWish()!.text, 'فيلا قرب الحديقة');
  });

  testWidgets('a seeker whose wish is not on this phone gets it from the server', (tester) async {
    await freshDevice();
    final server = serverDevice();
    await serverSignIn(server, account: me(role: 'seeker'));
    server.on('GET', 'preferences', 200, ok(<Object?>[]));
    store.saveWish(const Wish(city: 'الرياض', hoods: <String>['الملقا'], types: <String>[], budget: '', text: 'رغبة قديمة على الجوال'));

    await pumpApp(tester, '/account/matching');
    await _settle(tester);
    expect(store.loadWish(), isNull, reason: 'the server has none: none is shown');
    expect(find.text('رغبة قديمة على الجوال'), findsNothing);
  });
}
